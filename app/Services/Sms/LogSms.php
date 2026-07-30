<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Default driver: writes messages to the application log. */
class LogSms implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        Log::info("SMS to {$to}: {$message}");

        return true;
    }
}
