<?php

namespace App\Console\Commands;

use App\Enums\LoanStatus;
use App\Models\LoanInstallment;
use App\Services\Sms\HttpSms;
use App\Services\Sms\LogSms;
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

        // Upcoming: due exactly N days from now.
        $sent += $this->process(
            kind: 'upcoming',
            dueDate: $today->copy()->addDays($daysBefore)->format('Y-m-d'),
            template: fn ($i, $due) => __('Reminder: installment of :amount for loan :loan is due on :date.', [
                'amount' => $due->totalDue()->formatted(),
                'loan' => $i->loan->loan_number,
                'date' => $i->due_date->format('Y-m-d'),
            ]),
            sender: $sender,
            query: fn ($q) => $q->where('due_date', $today->copy()->addDays($daysBefore)->format('Y-m-d')),
        );

        // Overdue: anything unpaid and past due, one notice per week.
        $sent += $this->process(
            kind: 'overdue',
            dueDate: $today->format('Y-m-d'),
            template: fn ($i, $due) => __('OVERDUE: :amount for loan :loan was due :date. Please pay to avoid further penalties.', [
                'amount' => $due->totalDue()->formatted(),
                'loan' => $i->loan->loan_number,
                'date' => $i->due_date->format('Y-m-d'),
            ]),
            sender: $sender,
            // Weekly notices: compute the exact due-dates that are a
            // multiple of 7 days old, so the query stays index-eligible
            // (a DATEDIFF() % 7 predicate full-scans the table nightly).
            query: fn ($q) => $q->whereIn('due_date', collect(range(1, 52))
                ->map(fn (int $week) => $today->copy()->subWeeks($week)->format('Y-m-d'))
                ->all()),
        );

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }

    private function process(string $kind, string $dueDate, callable $template, SmsSender $sender, callable $query): int
    {
        $count = 0;

        LoanInstallment::query()
            ->whereNull('settled_at')
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active))
            ->tap($query)
            ->with('loan.borrower')
            ->chunkById(100, function ($installments) use ($kind, $dueDate, $template, $sender, &$count) {
                // One dedup lookup per chunk instead of one per installment.
                $alreadySent = DB::table('sms_logs')
                    ->where('kind', $kind)
                    ->where('sent_for', $dueDate)
                    ->whereIn('loan_installment_id', $installments->pluck('id'))
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

                    // Log BEFORE sending: a crash between the two must never
                    // cause a duplicate SMS on the next run (logged-but-unsent
                    // is the safer failure mode).
                    $logId = DB::table('sms_logs')->insertGetId([
                        'loan_installment_id' => $installment->id,
                        'kind' => $kind,
                        'sent_for' => $dueDate,
                        'to' => $phone,
                        'message' => $message,
                        'status' => 'failed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($sender->send($phone, $message)) {
                        DB::table('sms_logs')->where('id', $logId)->update(['status' => 'sent', 'updated_at' => now()]);
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function sender(): SmsSender
    {
        return \App\Services\Sms\SmsFactory::make();
    }
}
