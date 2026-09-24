<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendTest extends TestCase
{
    public function test_guests_can_load_the_banking_interface(): void
    {
        $this->withoutVite();

        $response = $this->get(route('bank'));

        $response->assertViewIs('bank')
            ->assertSee('MVP Bank')
            ->assertSee('id="app"', false);
    }

    public function test_frontend_does_not_intercept_unknown_api_routes(): void
    {
        $response = $this->getJson('/api/v1/missing-endpoint');

        $response->assertNotFound()->assertJsonStructure(['message']);
    }
}
