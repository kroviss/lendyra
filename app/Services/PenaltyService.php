<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPaymentAllocation;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LoanEngine\AllocationComponent;
use LoanEngine\BaseReduction;
use LoanEngine\Money;
use LoanEngine\PenaltyBase;
use LoanEngine\PenaltyCalculator;
use LoanEngine\PenaltyConfig;
use LogicException;

class PenaltyService
{
    /**
     * Recompute accrued penalties for every unsettled installment as of
     * $asOf. The figure is a pure function of (schedule, dated payment
     * history, waivers, $asOf) — see netPenalty() — and is stored as a
     * REPLACEMENT: running this any number of times for the same date
     * changes nothing (idempotent), a backdated effective date brings
     * the figure back DOWN to what it was on that date, and it never
     * drops below what was already paid.
     */
    public function accrue(Loan $loan, DateTimeImmutable $asOf): void
    {
        // Read the loan's OWN snapshot, not the product's live row — a later
        // product re-price must never rewrite this loan's penalty history.
        $config = $loan->penaltyConfig();

        if ($config->dailyRatePercent <= 0) {
            return;
        }

        $cutoff = $asOf->format('Y-m-d');

        // Lock the loan and its overdue rows before recomputing: racing
        // a payment must not ratchet penalty_minor up from a stale read
        // or write a penalty onto an installment that was just settled.
        DB::transaction(function () use ($loan, $config, $asOf, $cutoff) {
            $locked = Loan::lockForUpdate()->find($loan->id);

            if ($locked === null) {
                return;
            }

            // Rows due before $asOf accrue; rows already CARRYING a
            // penalty are included too, so a payment backdated to (or
            // before) the due date can pull a stale cron-written figure
            // back down to zero instead of leaving it billed.
            $installments = $locked->installments()
                ->lockForUpdate()
                ->where(fn ($q) => $q->where('due_date', '<', $cutoff)->orWhere('penalty_minor', '>', 0))
                ->whereNull('settled_at')
                ->get();

            // Pull the whole loan's allocation history in ONE query rather
            // than one per installment — the nightly cron touches thousands
            // of loans, and a per-row query melts at scale.
            $allocationsByInstallment = LoanPaymentAllocation::query()
                ->whereIn('loan_installment_id', $installments->pluck('id'))
                ->whereHas('payment', fn ($q) => $q->whereNull('reversed_at'))
                ->with('payment:id,paid_at')
                ->get()
                ->groupBy('loan_installment_id');

            $installments->each(function (LoanInstallment $installment) use ($config, $asOf, $allocationsByInstallment) {
                $penalty = self::netPenalty($installment, $config, $asOf, $allocationsByInstallment->get($installment->id, collect()));

                // Most nights most rows are unchanged — skip the write so the
                // cron does not issue a UPDATE per installment for no reason.
                if ((int) $installment->penalty_minor !== $penalty) {
                    $installment->update(['penalty_minor' => $penalty]);
                }
            });
        });

        $loan->unsetRelation('installments');
    }

    /**
     * The penalty billed on an installment as of $asOf: the engine's
     * segment-by-segment total over the dated payment history (penalty
     * accrued on the base outstanding DURING each stretch of overdue
     * days), minus everything ever waived.
     *
     * This is a pure function of (schedule, payment history, waivers,
     * $asOf), never a ratchet against the stored value — so a payment
     * backdated to the due date zeroes the penalty entirely, and a
     * partial payment keeps everything accrued on the old, larger base
     * while accrual continues on the reduced one.
     *
     * The only clamp: the figure never drops below what was already
     * PAID. When a backdated payment makes an earlier penalty charge
     * moot we keep the collected amount rather than refunding it —
     * clamping to paid keeps penaltyDue() at zero (never negative), so
     * the allocator and the ledger stay consistent. Waivers survive
     * because waive() records penalty_waived_minor at the same moment
     * it drops the stored value to the paid amount, so later accruals
     * bill only days after the waiver instead of re-billing the
     * forgiven amount.
     */
    private static function netPenalty(LoanInstallment $installment, PenaltyConfig $config, DateTimeImmutable $asOf, ?Collection $allocations = null): int
    {
        $loan = $installment->loan;

        $scheduledBase = Money::minor(
            (int) $installment->principal_minor
                + ($config->base === PenaltyBase::OverdueInstallment ? (int) $installment->interest_minor : 0),
            $loan->currency,
            (int) $loan->scale
        );

        $accrued = PenaltyCalculator::accruedWithHistory(
            new DateTimeImmutable($installment->due_date->format('Y-m-d')),
            $scheduledBase,
            self::baseReductions($installment, $config, $allocations),
            $config,
            $asOf,
        );

        return max(
            $accrued->minor - (int) $installment->penalty_waived_minor,
            (int) $installment->penalty_paid_minor
        );
    }

    /**
     * The dated payment history against this installment's penalty
     * base: every non-reversed allocation to principal (and to
     * interest, when the base is the whole installment), grouped by
     * payment date. Reversed payments are excluded entirely, so a
     * reversal followed by a re-accrual lands exactly where it would
     * have had the payment never happened.
     *
     * When $allocations is supplied (the batch accrual path) it is this
     * installment's already-loaded, non-reversed allocations across both
     * components — filtered here by the config's base — so no per-installment
     * query is issued. When null (the single-installment waive path) the
     * history is fetched directly.
     *
     * @return BaseReduction[]
     */
    private static function baseReductions(LoanInstallment $installment, PenaltyConfig $config, ?Collection $allocations = null): array
    {
        $components = [AllocationComponent::Principal->value];
        if ($config->base === PenaltyBase::OverdueInstallment) {
            $components[] = AllocationComponent::Interest->value;
        }

        $loan = $installment->loan;

        $rows = $allocations !== null
            // component is cast to an enum, so filter on its backing value —
            // a Collection whereIn('component', [...strings]) would never match.
            ? $allocations->filter(fn (LoanPaymentAllocation $a) => in_array($a->component->value, $components, true))
            : LoanPaymentAllocation::query()
                ->where('loan_installment_id', $installment->id)
                ->whereIn('component', $components)
                ->whereHas('payment', fn ($q) => $q->whereNull('reversed_at'))
                ->with('payment:id,paid_at')
                ->get();

        return $rows
            ->groupBy(fn (LoanPaymentAllocation $a) => $a->payment->paid_at->format('Y-m-d'))
            ->map(fn ($group, string $date) => new BaseReduction(
                new DateTimeImmutable($date),
                Money::minor((int) $group->sum('amount_minor'), $loan->currency, (int) $loan->scale),
            ))
            ->values()
            ->all();
    }

    /**
     * Waive the outstanding penalty on one installment, and close the
     * loan when that penalty was the last thing owed — the same
     * transition RepaymentService::record() performs.
     *
     * @return Money the waived amount
     */
    public function waive(Loan $loan, int $installmentId): Money
    {
        return DB::transaction(function () use ($loan, $installmentId) {
            // Lock + re-read: a concurrent payment must not race this
            // write into a negative penaltyDue or a missed auto-close.
            $loan = Loan::lockForUpdate()->findOrFail($loan->id);

            $installments = $loan->installments()->lockForUpdate()->get();
            $loan->setRelation('installments', $installments);

            /** @var LoanInstallment $installment */
            $installment = $installments->firstWhere('id', $installmentId)
                ?? throw (new ModelNotFoundException)->setModel(LoanInstallment::class, [$installmentId]);

            // Bring the accrual current first, so the waiver covers the
            // penalty through today — not just through last night's cron.
            $config = $loan->penaltyConfig();
            if ($config->dailyRatePercent > 0 && $installment->settled_at === null) {
                $installment->penalty_minor = self::netPenalty(
                    $installment,
                    $config,
                    new DateTimeImmutable(today()->format('Y-m-d'))
                );
            }

            $waived = $installment->penaltyDue();

            if ($waived->minor <= 0) {
                throw new LogicException(__('Nothing to waive on this installment.'));
            }

            $installment->penalty_waived_minor = (int) $installment->penalty_waived_minor + $waived->minor;
            $installment->penalty_minor = (int) $installment->penalty_paid_minor;

            if ($installment->isSettled() && $installment->settled_at === null) {
                $installment->settled_at = today()->format('Y-m-d');
            }

            $installment->save();

            if ($loan->status === LoanStatus::Active
                && $installments->every(fn (LoanInstallment $i) => $i->isSettled())) {
                $loan->update([
                    'status' => LoanStatus::Closed,
                    'closed_at' => today()->format('Y-m-d'),
                ]);
            }

            return $waived;
        });
    }
}
