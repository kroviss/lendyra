<?php

namespace App\Console\Commands;

use App\Enums\LoanStatus;
use App\Models\LoanInstallment;
use App\Services\Sms\SmsFactory;
use App\Services\Sms\SmsSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendReminders extends Command
{
    protected $signature = 'loans:send-reminders {--date= : Treat this date as today (Y-m-d)}';

    protected $description = 'SMS borrowers about upcoming and overdue installments (deduplicated)';

    public function handle(): int
    {
        $today = $this->option('date') ? now()->parse($this->option('date')) : today();
        $daysBefore = (int) config('lms.sms.reminder_days_before', 3);

        $sender = $this->sender();
        $sent = 0;

        // Upcoming: due within the next N days. Matching a window instead
        // of one exact date means a missed cron day never silently skips a
        // borrower; the dedup key (installment + due date) keeps the
        // overlapping days from repeating anyone.
        $sent += $this->process(
            kind: 'upcoming',
            template: fn ($i, $due) => __('Reminder: installment of :amount for loan :loan is due on :date.', [
                'amount' => $due->totalDue()->formatted(),
                'loan' => $i->loan->loan_number,
                'date' => $i->due_date->format('Y-m-d'),
            ]),
            sender: $sender,
            query: fn ($q) => $q->whereBetween('due_date', [
                $today->format('Y-m-d'),
                $today->copy()->addDays($daysBefore)->format('Y-m-d'),
            ]),
            sentFor: fn (LoanInstallment $i) => $i->due_date->format('Y-m-d'),
        );

        // Overdue: any unpaid past-due installment with no overdue notice
        // in the last 7 days — the weekly cadence survives missed runs and
        // never ages out, no matter how old the arrears.
        $sent += $this->process(
            kind: 'overdue',
            template: fn ($i, $due) => __('OVERDUE: :amount for loan :loan was due :date. Please pay to avoid further penalties.', [
                'amount' => $due->totalDue()->formatted(),
                'loan' => $i->loan->loan_number,
                'date' => $i->due_date->format('Y-m-d'),
            ]),
            sender: $sender,
            query: fn ($q) => $q->where('due_date', '<', $today->format('Y-m-d')),
            sentFor: fn (LoanInstallment $i) => $today->format('Y-m-d'),
            notifiedSince: $today->copy()->subDays(6)->format('Y-m-d'),
        );

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }

    /**
     * @param  callable  $sentFor  maps an installment to its dedup date
     * @param  ?string  $notifiedSince  skip installments already notified on
     *                                  or after this date (null = ever)
     */
    private function process(string $kind, callable $template, SmsSender $sender, callable $query, callable $sentFor, ?string $notifiedSince = null): int
    {
        $count = 0;

        LoanInstallment::query()
            ->whereNull('settled_at')
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active))
            ->tap($query)
            ->with('loan.borrower')
            ->chunkById(100, function ($installments) use ($kind, $template, $sender, $sentFor, $notifiedSince, &$count) {
                // One dedup lookup per chunk instead of one per installment.
                $alreadySent = DB::table('sms_logs')
                    ->where('kind', $kind)
                    ->whereIn('loan_installment_id', $installments->pluck('id'))
                    ->when($notifiedSince !== null, fn ($q) => $q->where('sent_for', '>=', $notifiedSince))
                    ->pluck('loan_installment_id')
                    ->all();

                foreach ($installments as $installment) {
                    $phone = $installment->loan->borrower?->phone;

                    if (! $phone) {
                        continue;
                    }

                    if (in_array($installment->id, $alreadySent, true)) {
                        continue;
                    }

                    $message = $template($installment, $installment->toDue());
                    $for = $sentFor($installment);

                    // Log BEFORE sending: a crash between the two must never
                    // cause a duplicate SMS on the next run (logged-but-unsent
                    // is the safer failure mode). insertOrIgnore rides the
                    // sms_dedup unique index, so a manual run racing the cron
                    // loses this row quietly instead of dying mid-chunk.
                    $inserted = DB::table('sms_logs')->insertOrIgnore([
                        'loan_installment_id' => $installment->id,
                        'kind' => $kind,
                        'sent_for' => $for,
                        'to' => $phone,
                        'message' => $message,
                        'status' => 'failed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($inserted === 0) {
                        continue; // the racing run owns this reminder
                    }

                    if ($sender->send($phone, $message)) {
                        DB::table('sms_logs')
                            ->where('loan_installment_id', $installment->id)
                            ->where('kind', $kind)
                            ->where('sent_for', $for)
                            ->update(['status' => 'sent', 'updated_at' => now()]);
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function sender(): SmsSender
    {
        return SmsFactory::make();
    }
}
