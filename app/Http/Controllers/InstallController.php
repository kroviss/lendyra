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
        return view('install.requirements', ['checks' => $this->checks()]);
    }

    public function database()
    {
        if (in_array(false, $this->checks(), true)) {
            return redirect()->route('install.requirements');
        }

        return view('install.database');
    }

    public function saveDatabase(Request $request)
    {
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
        ]);

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
            Artisan::call('storage:link', ['--force' => true]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['database' => __('Migration failed: ').$e->getMessage()]);
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

        return redirect('/login')->with('status', __('Installation complete — log in with your admin account.'));
    }

    private function checks(): array
    {
        return [
            'PHP >= 8.2 ('.PHP_VERSION.')' => PHP_VERSION_ID >= 80200,
            'pdo_mysql extension' => extension_loaded('pdo_mysql'),
            'mbstring extension' => extension_loaded('mbstring'),
            'openssl extension' => extension_loaded('openssl'),
            'ctype extension' => extension_loaded('ctype'),
            'curl extension' => extension_loaded('curl'),
            'storage/ writable' => is_writable(storage_path()),
            'bootstrap/cache writable' => is_writable(base_path('bootstrap/cache')),
            '.env writable' => is_writable(base_path('.env')) || is_writable(base_path()),
        ];
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
