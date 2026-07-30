<?php

namespace App\Livewire\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\LoanScheduleService;
use App\Services\PayoffService;
use App\Services\PenaltyService;
use App\Services\RepaymentService;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LoanEngine\Money;
use Throwable;

class Show extends Component
{
    #[Locked]
    public int $loanId;

    // Payment modal state
    public bool $showPaymentModal = false;
    public ?float $paymentAmount = null;
    public string $paymentDate = '';
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';

    // Payoff modal state
    public bool $showPayoffModal = false;
    public string $payoffDate = '';

    // Collateral modal state
    public bool $showCollateralModal = false;
    public string $collateralType = '';
    public string $collateralDescription = '';
    public ?float $collateralValue = null;

    // Guarantor modal state
    public bool $showGuarantorModal = false;
    public string $guarantorName = '';
    public string $guarantorPhone = '';
    public string $guarantorIdNumber = '';

    public ?string $actionError = null;

    public function mount(int $loan): void
    {
        $this->loanId = $loan;
        $this->paymentDate = today()->format('Y-m-d');
        $this->payoffDate = today()->format('Y-m-d');
    }

    private function loan(): Loan
    {
        return Loan::with(['borrower', 'product', 'installments', 'payments.allocations'])
            ->findOrFail($this->loanId);
    }

    public function activate(): void
    {
        $this->actionError = null;
        $loan = $this->loan();

        try {
            if ($loan->status !== LoanStatus::Approved) {
                throw new \LogicException(__('Only approved loans can be activated.'));
            }

            app(LoanScheduleService::class)->generateAndPersist($loan);
            $loan->update(['status' => LoanStatus::Active, 'disbursed_by' => auth()->id()]);
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function accruePenalties(): void
    {
        $this->actionError = null;

        try {
            app(PenaltyService::class)->accrue($this->loan(), new DateTimeImmutable(today()->format('Y-m-d')));
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function recordPayment(): void
    {
        $this->actionError = null;

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01',
            'paymentDate' => 'required|date',
            'paymentMethod' => 'required|in:cash,bank,mobile',
            'paymentReference' => 'nullable|max:64',
        ]);

        $loan = $this->loan();

        try {
            app(RepaymentService::class)->record(
                $loan,
                Money::of((string) $this->paymentAmount, $loan->currency, (int) $loan->scale),
                new DateTimeImmutable($this->paymentDate),
                method: $this->paymentMethod,
                reference: $this->paymentReference ?: null,
                receivedBy: auth()->id(),
            );

            $this->reset('showPaymentModal', 'paymentAmount', 'paymentReference');
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function getPayoffQuoteProperty(): ?array
    {
        try {
            $quote = app(PayoffService::class)->quote($this->loan(), new DateTimeImmutable($this->payoffDate));

            return [
                'principal' => $quote->principalOutstanding->toDecimalString(),
                'pastDueInterest' => $quote->pastDueInterest->toDecimalString(),
                'accruedInterest' => $quote->accruedInterest->toDecimalString(),
                'penalty' => $quote->penalty->toDecimalString(),
                'total' => $quote->total()->toDecimalString(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function settlePayoff(): void
    {
        $this->actionError = null;
        $this->validate(['payoffDate' => 'required|date']);

        try {
            app(PayoffService::class)->settle(
                $this->loan(),
                new DateTimeImmutable($this->payoffDate),
                receivedBy: auth()->id(),
            );

            $this->showPayoffModal = false;
        } catch (Throwable $e) {
            $this->actionError = $e->getMessage();
        }
    }

    public function addCollateral(): void
    {
        $this->validate([
            'collateralType' => 'required|max:64',
            'collateralDescription' => 'nullable|max:2000',
            'collateralValue' => 'required|numeric|min:0',
        ]);

        $loan = $this->loan();

        $loan->collaterals()->create([
            'type' => $this->collateralType,
            'description' => $this->collateralDescription ?: null,
            'estimated_value_minor' => Money::of((string) $this->collateralValue, $loan->currency, (int) $loan->scale)->minor,
        ]);

        $this->reset('showCollateralModal', 'collateralType', 'collateralDescription', 'collateralValue');
    }

    public function releaseCollateral(int $collateralId): void
    {
        $this->loan()->collaterals()
            ->whereKey($collateralId)
            ->update(['status' => 'released', 'released_at' => today()]);
    }

    public function addGuarantor(): void
    {
        $this->validate([
            'guarantorName' => 'required|min:2|max:255',
            'guarantorPhone' => 'nullable|max:32',
            'guarantorIdNumber' => 'nullable|max:64',
        ]);

        $this->loan()->guarantors()->create([
            'name' => $this->guarantorName,
            'phone' => $this->guarantorPhone ?: null,
            'id_number' => $this->guarantorIdNumber ?: null,
        ]);

        $this->reset('showGuarantorModal', 'guarantorName', 'guarantorPhone', 'guarantorIdNumber');
    }

    public function removeGuarantor(int $guarantorId): void
    {
        $this->loan()->guarantors()->whereKey($guarantorId)->delete();
    }

    public function render(): View
    {
        return view('livewire.loans.show', [
            'loan' => $this->loan()->load(['collaterals', 'guarantors']),
        ]);
    }
}
