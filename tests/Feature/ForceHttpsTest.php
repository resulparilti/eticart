<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ForceHttpsTest extends TestCase
{
    public function test_local_and_testing_http_is_not_redirected(): void
    {
        $this->get('http://localhost/login')->assertOk();
    }

    public function test_production_http_redirects_to_https(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $this->get('http://neotic.com.tr/login')
            ->assertRedirect()
            ->assertStatus(301);

        $this->assertStringStartsWith('https://', (string) $this->get('http://neotic.com.tr/login')->headers->get('Location'));
    }
}
