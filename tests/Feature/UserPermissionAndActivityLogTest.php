<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPermissionAndActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_open_users_page(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->get(route('users.index'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('orders.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Kullanıcılar')
            ->assertDontSee('İşlem kayıtları');
    }

    public function test_admin_can_delete_user_and_logs_remain_filterable_by_name(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'name' => 'Yönetici Ali',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        $target = User::factory()->create([
            'name' => 'Ayşe Kaya',
            'email' => 'ayse@example.com',
            'email_verified_at' => now(),
        ]);
        $target->assignRole('viewer');
        $target->givePermissionTo('alerts.update');

        $this->actingAs($target)
            ->post(route('alerts.read-all'))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $target->id,
            'user_name' => 'Ayşe Kaya',
        ]);

        $originalEmail = $target->email;

        $this->actingAs($admin)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $target->id,
            'user_name' => 'Ayşe Kaya',
            'user_email' => $originalEmail,
        ]);

        $this->actingAs($admin)
            ->get(route('activity-logs.index', ['q' => 'Ayşe Kaya']))
            ->assertOk()
            ->assertSee('Ayşe Kaya')
            ->assertSee('işlemini yaptı');

        $recreated = $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Ayşe Yeni',
                'email' => $originalEmail,
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
                'role' => 'viewer',
                'is_active' => '1',
            ]);

        $recreated->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'email' => $originalEmail,
            'name' => 'Ayşe Yeni',
        ]);
    }

    public function test_mutating_request_writes_named_activity_log(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'name' => 'Mehmet Demir',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('alerts.read-all'))
            ->assertRedirect();

        $log = ActivityLog::query()->where('user_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Mehmet Demir', (string) $log->description);
        $this->assertStringContainsString('işlemini yaptı', (string) $log->description);
        $this->assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/', (string) $log->description);
    }

    public function test_login_writes_activity_log(): void
    {
        $user = User::factory()->create([
            'name' => 'Giriş Yapan',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login',
            'user_name' => 'Giriş Yapan',
        ]);
    }

    public function test_cannot_delete_own_account_or_last_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
        $this->assertSame(1, Role::findByName('admin')->users()->count());
    }
}
