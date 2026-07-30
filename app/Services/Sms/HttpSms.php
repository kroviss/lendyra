<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic HTTP driver: POSTs {to, message} as JSON to any gateway URL,
 * with an optional bearer token. Adapt almost any local SMS aggregator
 * by pointing LMS_SMS_HTTP_URL at it.
 */
class HttpSms implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        $url = config('lms.sms.http.url');

        if (! $url) {
            Log::warning('HttpSms: LMS_SMS_HTTP_URL is not configured.');

            return false;
        }

        try {
            $request = Http::timeout(10);

            if ($token = config('lms.sms.http.token')) {
                $request = $request->withToken($token);
            }

            return $request->post($url, ['to' => $to, 'message' => $message])->successful();
        } catch (Throwable $e) {
            Log::warning("HttpSms failed for {$to}: {$e->getMessage()}");

            return false;
        }
    }
}
