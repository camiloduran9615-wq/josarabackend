<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use App\Models\Tenant\Config;
use App\Models\Tenant\Resolucion;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\TenantTestCase;

class FactusIntegrationTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(InitializeTenancyByTenantIdentifier::class);
        config(['cache.default' => 'array']);
    }

    private function tenantUrl(string $path): string
    {
        return '/api/v1/'.$this->tenant->id.$path;
    }

    private function adminToken(): string
    {
        return $this->adminUser->createToken('factus-test', ['*'])->plainTextToken;
    }

    private function configureFactus(): void
    {
        Config::set('factus_base_url', 'https://api-sandbox.factus.test');
        Config::set('factus_client_id', 'client-id');
        Config::set('factus_client_secret', Crypt::encryptString('client-secret'));
        Config::set('factus_username', 'empresa@example.com');
        Config::set('factus_password', Crypt::encryptString('secret-password'));
        Config::set('factus_mode', 'sandbox');
    }

    public function test_save_factus_config_successfully(): void
    {
        $this->withToken($this->adminToken())
            ->postJson($this->tenantUrl('/configs/factus'), [
                'factus_base_url' => 'https://api-sandbox.factus.test',
                'factus_client_id' => 'client-id',
                'factus_client_secret' => 'client-secret',
                'factus_username' => 'empresa@example.com',
                'factus_password' => 'secret-password',
                'factus_mode' => 'sandbox',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('client-id', Config::get('factus_client_id'));
        $this->assertNotSame('client-secret', Config::get('factus_client_secret'));
        $this->assertSame('client-secret', Crypt::decryptString(Config::get('factus_client_secret')));
    }

    public function test_save_factus_config_validation_errors_return_422(): void
    {
        $this->withToken($this->adminToken())
            ->postJson($this->tenantUrl('/configs/factus'), [
                'factus_base_url' => 'not-a-url',
                'factus_mode' => 'invalid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_readonly_user_cannot_save_factus_config(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['role' => User::ROLE_READONLY]);
        $token = $user->createToken('readonly', ['read'])->plainTextToken;

        $this->withToken($token)
            ->postJson($this->tenantUrl('/configs/factus'), [
                'factus_mode' => 'sandbox',
            ])
            ->assertStatus(403);
    }

    public function test_factus_auth_error_returns_422(): void
    {
        $this->configureFactus();

        Http::fake([
            'api-sandbox.factus.test/oauth/token' => Http::response(['message' => 'invalid'], 401),
        ]);

        $this->withToken($this->adminToken())
            ->postJson($this->tenantUrl('/configs/factus/test'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_sync_resoluciones_successfully(): void
    {
        $this->configureFactus();

        Http::fake([
            'api-sandbox.factus.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'api-sandbox.factus.test/v1/numbering-ranges' => Http::response([
                'data' => [[
                    'id' => 123,
                    'prefix' => 'FE',
                    'from' => 1,
                    'to' => 1000,
                    'resolution_number' => '18760000001',
                    'start_date' => '2026-01-01',
                    'end_date' => '2027-01-01',
                    'document' => 'Factura electronica',
                    'is_active' => true,
                ]],
            ], 200),
        ]);

        $this->withToken($this->adminToken())
            ->postJson($this->tenantUrl('/resoluciones/sync'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Resolucion::query()->where('factus_id', 123)->exists());
    }

    public function test_sync_resoluciones_external_error_returns_502(): void
    {
        $this->configureFactus();

        Http::fake([
            'api-sandbox.factus.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'api-sandbox.factus.test/v1/numbering-ranges' => Http::response(['message' => 'down'], 500),
            'api-sandbox.factus.test/v2/numbering-ranges' => Http::response(['message' => 'down'], 500),
        ]);

        $this->withToken($this->adminToken())
            ->postJson($this->tenantUrl('/resoluciones/sync'))
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }
}

class FactusTenantResolutionTest extends TestCase
{
    public function test_unknown_tenant_returns_404_before_factus_config_update(): void
    {
        $this->postJson('/api/v1/tenant-that-does-not-exist/configs/factus', [
            'factus_mode' => 'sandbox',
        ])->assertStatus(404);
    }
}
