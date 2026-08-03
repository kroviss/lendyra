<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = storage_path('app/installed.lock');
    }

    /** Temporarily hide the lock file, run $callback, always restore. */
    private function whileNotInstalled(callable $callback): void
    {
        $backup = $this->lockPath.'.bak';
        rename($this->lockPath, $backup);

        try {
            $callback();
        } finally {
            rename($backup, $this->lockPath);
        }
    }

    /**
     * Point the default connection at a database that does not exist, so the
     * installer's reinstall guard sees "no reachable database" and treats the
     * request as a genuine fresh install.
     */
    private function breakDatabaseConnection(): void
    {
        config(['database.connections.mysql.database' => 'lendyra_missing_'.uniqid()]);
        DB::purge('mysql');
    }

    public function test_uninstalled_app_redirects_everything_to_installer(): void
    {
        $this->whileNotInstalled(function () {
            $this->get('/login')->assertRedirect('/install');
            $this->get('/loans')->assertRedirect('/install');
        });
    }

    public function test_installer_pages_render_when_not_installed(): void
    {
        $this->whileNotInstalled(function () {
            $this->get('/install')->assertOk()->assertSee('Server requirements');

            // The database step only renders on a fresh install (no reachable
            // database with users) — simulate that regardless of this
            // machine's data.
            $this->breakDatabaseConnection();
            $this->get('/install/database')->assertOk()->assertSee('Database connection');
        });
    }

    public function test_installer_is_blocked_once_installed(): void
    {
        $this->get('/install')->assertRedirect('/');
        $this->get('/install/database')->assertRedirect('/');
    }

    public function test_installed_app_serves_normal_routes(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_database_step_rejects_bad_credentials(): void
    {
        $this->whileNotInstalled(function () {
            $this->breakDatabaseConnection();

            $this->from('/install/database')
                ->post('/install/database', [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'no_such_db_'.uniqid(),
                    'username' => 'no_such_user',
                    'password' => 'wrong',
                    'timezone' => 'UTC',
                ])
                ->assertRedirect('/install/database')
                ->assertSessionHasErrors('database');
        });
    }

    public function test_database_step_refuses_reinstall_when_users_exist(): void
    {
        // A lost installed.lock must not let an anonymous visitor point the
        // app at their own database: with users present, the database step
        // heals the lock and bails out to /login.
        DB::beginTransaction();

        try {
            User::create([
                'name' => 'Existing Admin',
                'email' => 'guard-'.uniqid().'@example.com',
                'password' => bcrypt('secret123'),
                'role' => 'admin',
            ]);

            $this->whileNotInstalled(function () {
                $this->get('/install/database')->assertRedirect('/login');
                $this->assertFileExists($this->lockPath, 'guard must heal installed.lock');

                unlink($this->lockPath); // hide it again for the POST

                $this->post('/install/database', [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'attacker_db',
                    'username' => 'attacker',
                    'password' => 'attacker',
                ])->assertRedirect('/login');
                $this->assertFileExists($this->lockPath, 'guard must heal installed.lock');
            });
        } finally {
            DB::rollBack();
        }
    }

    public function test_env_bootstrap_creates_env_and_key_from_example(): void
    {
        $dir = $this->tempBase();
        file_put_contents($dir.'/.env.example', "APP_NAME=Lendyra\nAPP_KEY=\nAPP_DEBUG=false\n");

        $key = EnsureInstalled::bootstrapEnv($dir);

        $this->assertNotNull($key);
        $this->assertStringStartsWith('base64:', $key);
        $this->assertFileExists($dir.'/.env');

        $env = file_get_contents($dir.'/.env');
        $this->assertStringContainsString('APP_KEY='.$key, $env);
        $this->assertStringContainsString('APP_NAME=Lendyra', $env);

        $this->cleanTempBase($dir);
    }

    public function test_env_bootstrap_fills_empty_key_in_existing_env(): void
    {
        $dir = $this->tempBase();
        file_put_contents($dir.'/.env', "APP_NAME=Lendyra\nAPP_KEY=\nDB_HOST=db.example.com\n");

        $key = EnsureInstalled::bootstrapEnv($dir);

        $this->assertNotNull($key);

        $env = file_get_contents($dir.'/.env');
        $this->assertStringContainsString('APP_KEY='.$key, $env);
        $this->assertStringContainsString('DB_HOST=db.example.com', $env); // untouched

        $this->cleanTempBase($dir);
    }

    public function test_env_bootstrap_never_touches_a_keyed_env(): void
    {
        $dir = $this->tempBase();
        $original = "APP_NAME=Lendyra\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n";
        file_put_contents($dir.'/.env', $original);

        $this->assertNull(EnsureInstalled::bootstrapEnv($dir));
        $this->assertSame($original, file_get_contents($dir.'/.env'));

        $this->cleanTempBase($dir);
    }

    public function test_env_bootstrap_is_a_noop_without_example_file(): void
    {
        $dir = $this->tempBase();

        $this->assertNull(EnsureInstalled::bootstrapEnv($dir));
        $this->assertFileDoesNotExist($dir.'/.env');

        $this->cleanTempBase($dir);
    }

    /** Isolated temp dir so these tests can NEVER touch the app's real .env. */
    private function tempBase(): string
    {
        $dir = sys_get_temp_dir().'/lendyra-installer-test-'.uniqid();
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function cleanTempBase(string $dir): void
    {
        foreach (['/.env', '/.env.example'] as $file) {
            if (file_exists($dir.$file)) {
                unlink($dir.$file);
            }
        }

        rmdir($dir);
    }
}
