<?php

namespace App\Services;

use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use LoanEngine\Schedule;
use LoanEngine\ScheduleGenerator;
use LogicException;

class LoanScheduleService
{
    public function preview(Loan $loan): Schedule
    {
        return ScheduleGenerator::generate($loan->terms());
    }

    /**
     * Persist the schedule as installment rows. Only allowed while the
     * loan has not started living (draft/pending/approved) — an active
     * loan's schedule changes only through restructuring, never by
     * regeneration, or payment history would silently detach.
     */
    public function generateAndPersist(Loan $loan): Schedule
    {
        if (! $loan->status->scheduleIsMutable()) {
            throw new LogicException(__('The schedule cannot be regenerated for a :status loan.', ['status' => $loan->status->label()]));
        }

        $schedule = $this->preview($loan);

        DB::transaction(function () use ($loan, $schedule) {
            $loan->installments()->delete();

            $rows = [];
            $now = now();

            foreach ($schedule->installments as $installment) {
                $rows[] = [
                    'loan_id' => $loan->id,
                    'number' => $installment->number,
                    'due_date' => $installment->dueDate->format('Y-m-d'),
                    'opening_minor' => $installment->openingBalance->minor,
                    'principal_minor' => $installment->principal->minor,
                    'interest_minor' => $installment->interest->minor,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $loan->installments()->insert($rows);
        });

        $loan->unsetRelation('installments');

        return $schedule;
    }
}
