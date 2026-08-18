<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Root redirects to login in this app.
     */
    public function test_the_application_redirects_root_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
