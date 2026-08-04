<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed = file_exists(storage_path('app/installed.lock'));

        if (! $installed) {
            // A fresh zip ships no .env and no APP_KEY. Bootstrap both so the
            // installer itself can render instead of a 500. This runs ONLY
            // while installed.lock is absent — once installed, the block above
            // guarantees this code is never reached again.
            if (($key = self::bootstrapEnv(base_path())) !== null) {
                // A key was just written to .env; redirect so the next request
                // boots from the fresh file.
                //
                // Also inject the key into the runtime config: this request
                // booted BEFORE the key existed, so config('app.key') is still
                // empty. After the redirect is streamed, the kernel's
                // terminate phase instantiates every web-group middleware
                // (EncryptCookies takes the Encrypter in its constructor),
                // which would otherwise throw MissingAppKeyException — logged
                // as an ERROR on a perfectly healthy first request.
                config(['app.key' => $key]);

                return redirect('/install');
            }

            if (! $request->is('install*')) {
                return redirect('/install');
            }
        }

        if ($installed && $request->is('install*')) {
            return redirect('/');
        }

        return $next($request);
    }

    /**
     * Ensure a usable .env exists at $basePath: copy .env.example when .env
     * is missing, and generate an APP_KEY when it is empty.
     *
     * Returns the newly generated APP_KEY, or null when nothing was written
     * (env already present with a key, or nothing to copy from).
     *
     * MUST only be called before installation — callers guard on the absence
     * of storage/app/installed.lock.
     */
    public static function bootstrapEnv(string $basePath): ?string
    {
        $envPath = rtrim($basePath, '/').'/.env';
        $examplePath = rtrim($basePath, '/').'/.env.example';

        if (! file_exists($envPath)) {
            if (! file_exists($examplePath)) {
                return null; // Nothing we can do — let the request proceed.
            }

            copy($examplePath, $envPath);
        }

        $content = (string) file_get_contents($envPath);

        // Already keyed? (APP_KEY= followed by a real value, not "" or '')
        if (preg_match('/^APP_KEY=(?!\s*$)(?!""\s*$)(?!\'\'\s*$).+$/m', $content)) {
            return null;
        }

        // Same format as `php artisan key:generate` (AES-256-CBC, 32 bytes).
        $key = 'base64:'.base64_encode(random_bytes(32));

        $content = preg_match('/^APP_KEY=.*$/m', $content)
            ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $content, 1)
            : rtrim($content)."\nAPP_KEY={$key}\n";

        file_put_contents($envPath, $content);

        return $key;
    }
}
