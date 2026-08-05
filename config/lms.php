<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    | Blocks account changes and enables the nightly demo:reset schedule.
    | Never enable in production.
    */
    'demo' => (bool) env('LMS_DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Branch scoping
    |--------------------------------------------------------------------------
    | When true, loan officers and cashiers only see records belonging to
    | their own branch. Admins and managers always see everything.
    */
    'branch_scoping' => (bool) env('LMS_BRANCH_SCOPING', false),

    /*
    |--------------------------------------------------------------------------
    | Payment backdating window (days)
    |--------------------------------------------------------------------------
    | How far back a cashier (a user without the write-off/waiver privilege)
    | may date a payment. Backdating a payment onto or before an overdue
    | installment's due date silently erases accrued penalties — a manager-only
    | waiver in disguise — so cashiers are capped to this window. Admins and
    | managers (the write-off-loans gate) may still backdate to disbursement.
    */
    'payment_backdate_days' => (int) env('LMS_PAYMENT_BACKDATE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Ledger account codes
    |--------------------------------------------------------------------------
    | Default chart-of-accounts codes the ledger posts against. They are
    | created automatically on first use if missing.
    */
    'accounts' => [
        'cash' => ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
        'portfolio' => ['code' => '1100', 'name' => 'Loan Portfolio', 'type' => 'asset'],
        'interest_income' => ['code' => '4000', 'name' => 'Interest Income', 'type' => 'income'],
        'penalty_income' => ['code' => '4100', 'name' => 'Penalty Income', 'type' => 'income'],
        'overpayment' => ['code' => '2100', 'name' => 'Borrower Overpayments', 'type' => 'liability'],
        'writeoff_expense' => ['code' => '5000', 'name' => 'Loan Write-off Expense', 'type' => 'expense'],
        'fee_income' => ['code' => '4200', 'name' => 'Fee Income', 'type' => 'income'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS reminders
    |--------------------------------------------------------------------------
    | driver: log | http
    | "log" writes to the Laravel log (safe default).
    | "http" POSTs JSON {to, message} to the configured URL — adapt to any
    | local SMS gateway or aggregator.
    */
    /*
    |--------------------------------------------------------------------------
    | Payment method labels
    |--------------------------------------------------------------------------
    */
    'payment_methods' => [
        'cash' => 'Cash',
        'bank' => 'Bank transfer',
        'mobile' => 'Mobile money',
    ],

    'sms' => [
        'driver' => env('LMS_SMS_DRIVER', 'log'),
        'http' => [
            'url' => env('LMS_SMS_HTTP_URL'),
            'token' => env('LMS_SMS_HTTP_TOKEN'),
        ],
        'reminder_days_before' => (int) env('LMS_SMS_REMINDER_DAYS', 3),
    ],

];
