<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            $this->from('/install/database')
                ->post('/install/database', [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'no_such_db_'.uniqid(),
                    'username' => 'no_such_user',
                    'password' => 'wrong',
                ])
                ->assertRedirect('/install/database')
                ->assertSessionHasErrors('database');
        });
    }
}
