<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Locale', 'email' => 'locale-'.uniqid().'@example.com',
            'password' => bcrypt('secret123'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_switching_locale_translates_the_ui(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->from('/')
            ->post('/locale', ['locale' => 'fr'])
            ->assertRedirect('/');

        $this->assertSame('fr', session('locale'));

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee(__('Dashboard', locale: 'fr'));

        // Back to English.
        $this->actingAs($user)->post('/locale', ['locale' => 'en']);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Dashboard');
    }

    public function test_guests_can_switch_locale_on_the_login_page(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'es'])->assertRedirect('/login');

        $this->get('/login')->assertOk()->assertSee(__('Log in', locale: 'es'));
    }

    public function test_unknown_locales_are_rejected(): void
    {
        // Too long → validation redirect-back with an error.
        $this->from('/login')->post('/locale', ['locale' => '../../etc.json'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('locale');

        // Well-formed but not shipped → hard 422 from the whitelist.
        $this->from('/login')->post('/locale', ['locale' => 'de'])->assertStatus(422);

        $this->assertNull(session('locale'));
    }
}
