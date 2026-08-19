<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsGeneralTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_settings_page_and_update(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Genel')
            ->assertSee('Sistem adı')
            ->assertSee('Raporlar');

        $this->actingAs($user)
            ->get(route('settings.general'))
            ->assertOk()
            ->assertSee('Sistem adı')
            ->assertSee('Firma adı')
            ->assertSee('İade kargo firması');

        $this->actingAs($user)
            ->put(route('settings.general.update'), [
                'general_app_name' => 'ShopiShare',
                'general_company_name' => 'Özşeyma Tekstil',
                'general_company_address' => 'Merkez Mah. No 10 İstanbul',
                'general_company_phone' => '0212 111 22 33',
                'general_return_cargo_name' => 'Yurtiçi Kargo',
                'general_return_cargo_code' => '216625941',
            ])
            ->assertRedirect();

        $this->assertSame('ShopiShare', Setting::getValue('general_app_name'));
        $this->assertSame('ShopiShare', Setting::appName());
        $this->assertSame('Özşeyma Tekstil', Setting::getValue('mail_brand_name'));
        $this->assertSame('Özşeyma Tekstil', Setting::getValue('general_company_name'));
        $this->assertSame('216625941', Setting::getValue('general_return_cargo_code'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ShopiShare');
    }
}
