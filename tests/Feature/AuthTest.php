<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_verified_user_can_view_dashboard(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_inactive_user_is_logged_out(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/login')
            ->assertOk()
            ->assertSee('location.replace', false)
            ->assertSee('/dashboard', false);
    }

    public function test_panel_visits_are_remembered_and_login_returns_to_last_page(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/invoices')->assertOk();

        $this->assertStringContainsString('/invoices', (string) session('url.intended'));
        $this->assertStringContainsString('/invoices', (string) session('panel.last_url'));

        $this->actingAs($user)
            ->get('/login')
            ->assertOk()
            ->assertSee('location.replace', false)
            ->assertSee('/invoices', false);
    }

    public function test_authenticated_user_visiting_root_returns_to_last_panel_page(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/invoices')->assertOk();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('location.replace', false)
            ->assertSee('/invoices', false)
            ->assertDontSee('/login', false);
    }

    public function test_session_status_reports_authentication_without_redirect(): void
    {
        $this->getJson('/session/status')
            ->assertOk()
            ->assertJson(['authenticated' => false, 'last' => null]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/session/status')
            ->assertOk()
            ->assertJson(['authenticated' => true]);
    }

    public function test_panel_pages_are_not_browser_cached(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_csrf_mismatch_keeps_authenticated_user_on_previous_page(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($user)->from('/dashboard');

        $response = app(\App\Exceptions\Handler::class)->render(
            request(),
            new \Symfony\Component\HttpKernel\Exception\HttpException(419)
        );

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('/dashboard', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);
    }
}
