<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers on every web response. The most important one
 * is the anti-framing pair: money actions (approve, write-off, reverse) must
 * not be clickjackable by luring an admin to a page that frames this app.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'same-origin', false);

        // A minimal CSP whose only job is to forbid framing in browsers that
        // ignore X-Frame-Options. Deliberately not a full asset policy —
        // that would risk breaking self-hosted buyers' customisations.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        }

        return $response;
    }
}
