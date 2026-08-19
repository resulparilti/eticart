<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\UiTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_defaults_to_light_theme(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-bs-theme="light"', false)
            ->assertSee('data-eticart-theme-toggle', false)
            ->assertSee('eticart-theme', false);
    }

    public function test_dashboard_uses_theme_cookie_without_waiting_for_js(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->withUnencryptedCookie(UiTheme::COOKIE, 'dark')
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-bs-theme="dark"', false)
            ->assertSee('background-color: #0b1420', false);
    }

    public function test_login_page_includes_blocking_theme_boot(): void
    {
        $this->withUnencryptedCookie(UiTheme::COOKIE, 'dark')
            ->get('/login')
            ->assertOk()
            ->assertSee('data-bs-theme="dark"', false)
            ->assertSee('data-eticart-theme-toggle', false);
    }
}
