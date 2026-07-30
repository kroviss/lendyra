<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A disabled account must lose access immediately, not just at next login. */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only an explicit false blocks: a model instance that never
        // loaded the column (null) must not be treated as disabled.
        if ($user && $user->is_active !== null && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('This account has been disabled.')]);
        }

        return $next($request);
    }
}
