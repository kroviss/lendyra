<?php

return [

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
    'sms' => [
        'driver' => env('LMS_SMS_DRIVER', 'log'),
        'http' => [
            'url' => env('LMS_SMS_HTTP_URL'),
            'token' => env('LMS_SMS_HTTP_TOKEN'),
        ],
        'reminder_days_before' => (int) env('LMS_SMS_REMINDER_DAYS', 3),
    ],

];
