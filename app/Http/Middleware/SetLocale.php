<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Locales the UI can switch to: every lang/*.json that ships,
     * labeled in its own language so it's findable when the current
     * locale is foreign to the reader.
     */
    public static function available(): array
    {
        $labels = [
            'en' => 'English',
            'fr' => 'Français',
            'es' => 'Español',
            'pt' => 'Português',
        ];

        $locales = [];
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $code = basename($file, '.json');
            $locales[$code] = $labels[$code] ?? strtoupper($code);
        }

        ksort($locales);

        return $locales;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && array_key_exists($locale, self::available())) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
