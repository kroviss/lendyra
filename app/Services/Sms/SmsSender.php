<?php

namespace App\Services\Sms;

interface SmsSender
{
    /** Send a message; return true on success. Must not throw on failure. */
    public function send(string $to, string $message): bool;
}
