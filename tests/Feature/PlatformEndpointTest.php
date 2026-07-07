<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contrato del endpoint público de branding GET /api/platform y del health.
 * Verifica que la marca se sirve desde config/platform.php (single source of
 * truth) y que el endpoint es público (sin autenticación).
 */
class PlatformEndpointTest extends TestCase
{
    public function test_platform_endpoint_is_public_and_returns_branding(): void
    {
        $this->getJson('/api/platform')
            ->assertOk()
            ->assertJsonStructure([
                'name', 'short_name', 'tagline', 'description', 'version',
                'logo', 'logo_light', 'logo_dark', 'favicon', 'website',
                'primary_color', 'secondary_color', 'copyright',
            ])
            ->assertJsonPath('name', config('platform.name'))
            ->assertJsonPath('short_name', config('platform.short_name'))
            ->assertJsonPath('tagline', config('platform.tagline'));
    }

    public function test_health_service_uses_platform_name(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('service', config('platform.name').' API');
    }
}
