<?php

namespace App\Services\Sms;

class SmsFactory
{
    public static function make(): SmsSender
    {
        return match (config('lms.sms.driver', 'log')) {
            'http' => new HttpSms,
            default => new LogSms,
        };
    }
}
