<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Throwable;

class InstallController extends Controller
{
    public function requirements()
    {
        return view('install.requirements', [
            'checks' => $this->checks(),
            'warnings' => $this->warnings(),
        ]);
    }

    public function database()
    {
        if ($redirect = $this->guardAgainstReinstall()) {
            return $redirect;
        }

        if (in_array(false, $this->checks(), true)) {
            return redirect()->route('install.requirements');
        }

        return view('install.database');
    }

    public function saveDatabase(Request $request)
    {
        if ($redirect = $this->guardAgainstReinstall()) {
            return $redirect;
        }

        $data = $request->validate([
            'host' => 'required',
            'port' => 'required|integer',
            'database' => 'required',
            'username' => 'required',
            'password' => 'nullable',
            'prefix' => 'nullable|alpha_dash|max:16',
        ]);

        $data['password'] = $data['password'] ?? '';
        $data['prefix'] = $data['prefix'] ?? '';

        try {
            new PDO(
                "mysql:host={$data['host']};port={$data['port']};dbname={$data['database']}",
                $data['username'],
                $data['password'],
                [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['database' => __('Connection failed: ').$e->getMessage()]);
        }

        $this->writeEnv([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $request->getSchemeAndHttpHost(),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => $data['password'],
            'DB_PREFIX' => $data['prefix'],
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
        ] + ($request->isSecure() ? ['SESSION_SECURE_COOKIE' => 'true'] : []));

        // Migrate over the submitted credentials NOW — the freshly written
        // .env only takes effect on the next request.
        config([
            'database.connections.mysql.host' => $data['host'],
            'database.connections.mysql.port' => $data['port'],
            'database.connections.mysql.database' => $data['database'],
            'database.connections.mysql.username' => $data['username'],
            'database.connections.mysql.password' => $data['password'],
            'database.connections.mysql.prefix' => $data['prefix'],
        ]);
        DB::purge('mysql');

        try {
            if (empty(config('app.key'))) {
                Artisan::call('key:generate', ['--force' => true]);
            }
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['database' => __('Migration failed: ').$e->getMessage()]);
        }

        // storage:link fails on hosts where symlink() is disabled. That must
        // NOT abort the install — carry a warning to the admin step instead.
        try {
            Artisan::call('storage:link', ['--force' => true]);
            session()->forget('install.storage_link_warning');
        } catch (Throwable $e) {
            session()->put('install.storage_link_warning', __(
                'The public storage link could not be created (:reason). Uploaded photos will not display until it exists — run "php artisan storage:link", or create a symlink from public/storage to storage/app/public in your hosting control panel.',
                ['reason' => $e->getMessage()]
            ));
        }

        return redirect()->route('install.admin');
    }

    public function admin()
    {
        try {
            if (User::query()->exists()) {
                file_put_contents(storage_path('app/installed.lock'), now()->toIso8601String());

                return redirect('/login');
            }
        } catch (Throwable) {
            return redirect()->route('install.database');
        }

        return view('install.admin');
    }

    public function saveAdmin(Request $request)
    {
        // The admin step must be a one-shot: once ANY user exists, an
        // anonymous visitor must never be able to mint another admin
        // (e.g. when installed.lock was lost during an upgrade).
        try {
            if (User::query()->exists()) {
                file_put_contents(storage_path('app/installed.lock'), now()->toIso8601String());

                return redirect('/login');
            }
        } catch (Throwable) {
            return redirect()->route('install.database');
        }

        $data = $request->validate([
            'name' => 'required|min:2|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|min:8|confirmed',
        ]);

        $branch = Branch::firstOrCreate(['code' => 'HQ'], ['name' => 'Head Office']);

        User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => Hash::make($data['password']),
                'role' => 'admin',
                'branch_id' => $branch->id,
            ]
        );

        file_put_contents(storage_path('app/installed.lock'), now()->toIso8601String());

        $status = __('Installation complete — log in with your admin account.');

        if ($warning = session()->pull('install.storage_link_warning')) {
            $status .= ' '.$warning;
        }

        return redirect('/login')->with('status', $status);
    }

    /**
     * The database step must never act as a reinstaller: when installed.lock
     * was lost but the currently configured database already has users, an
     * anonymous visitor could otherwise rewrite .env to point at their own
     * database and mint a fresh admin. Heal the lock and bail out, exactly
     * like the admin step does. An unreachable/unconfigured database means a
     * genuine fresh install — proceed.
     */
    private function guardAgainstReinstall()
    {
        try {
            if (User::query()->exists()) {
                file_put_contents(storage_path('app/installed.lock'), now()->toIso8601String());

                return redirect('/login');
            }
        } catch (Throwable) {
            // No reachable database yet — fresh install, carry on.
        }

        return null;
    }

    private function checks(): array
    {
        return [
            __('PHP >= :required (:current)', ['required' => '8.3', 'current' => PHP_VERSION]) => PHP_VERSION_ID >= 80300,
            __(':name extension', ['name' => 'pdo_mysql']) => extension_loaded('pdo_mysql'),
            __(':name extension', ['name' => 'mbstring']) => extension_loaded('mbstring'),
            __(':name extension', ['name' => 'openssl']) => extension_loaded('openssl'),
            __(':name extension', ['name' => 'ctype']) => extension_loaded('ctype'),
            __(':name extension', ['name' => 'curl']) => extension_loaded('curl'),
            __(':name extension', ['name' => 'fileinfo']) => extension_loaded('fileinfo'),
            __(':name extension', ['name' => 'dom']) => extension_loaded('dom'),
            __(':name extension', ['name' => 'xml']) => extension_loaded('xml'),
            __(':name extension', ['name' => 'tokenizer']) => extension_loaded('tokenizer'),
            __(':name extension', ['name' => 'filter']) => extension_loaded('filter'),
            __(':name extension', ['name' => 'session']) => extension_loaded('session'),
            __(':path writable', ['path' => 'storage/']) => is_writable(storage_path()),
            __(':path writable', ['path' => 'bootstrap/cache']) => is_writable(base_path('bootstrap/cache')),
            __(':path writable', ['path' => '.env']) => is_writable(base_path('.env')) || is_writable(base_path()),
        ];
    }

    /**
     * Non-blocking notices: the install may proceed, but the buyer should
     * know about these.
     */
    private function warnings(): array
    {
        $warnings = [];

        if (! function_exists('symlink')) {
            $warnings[] = __('The symlink() function is disabled on this host. Installation will work, but uploaded photos need a manual link from public/storage to storage/app/public.');
        }

        return $warnings;
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            copy(base_path('.env.example'), $path);
        }

        $content = file_get_contents($path);

        foreach ($values as $key => $value) {
            // Newlines in a submitted value must never become extra .env lines.
            $value = str_replace(["\r", "\n"], '', $value);
            $escaped = '"'.addcslashes($value, '"\\').'"';
            $line = "{$key}={$escaped}";

            // Escape backslashes and $ so a password like "pa$1ss" can't
            // act as a backreference in the replacement string.
            $replacement = str_replace(['\\', '$'], ['\\\\', '\\$'], $line);

            $content = preg_match("/^{$key}=.*$/m", $content)
                ? preg_replace("/^{$key}=.*$/m", $replacement, $content)
                : rtrim($content)."\n{$line}\n";
        }

        file_put_contents($path, $content);
    }
}
