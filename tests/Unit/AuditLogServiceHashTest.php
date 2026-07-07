<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuditLogService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests del cálculo de hash chain. No tocan BD, solo el algoritmo.
 */
class AuditLogServiceHashTest extends TestCase
{
    private AuditLogService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AuditLogService();
    }

    public function test_compute_hash_is_deterministic(): void
    {
        $payload = [
            'tenant_id'  => 'tenant-uuid',
            'action'     => 'asiento.created',
            'criticidad' => 'info',
            'created_at' => '2026-05-07 10:00:00',
        ];

        $h1 = $this->svc->computeHash($payload, null);
        $h2 = $this->svc->computeHash($payload, null);

        $this->assertSame($h1, $h2);
        $this->assertSame(64, strlen($h1));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1);
    }

    public function test_compute_hash_changes_when_payload_changes(): void
    {
        $base = [
            'tenant_id'  => 't',
            'action'     => 'asiento.created',
            'criticidad' => 'info',
        ];
        $alt  = $base;
        $alt['action'] = 'asiento.updated';

        $this->assertNotSame(
            $this->svc->computeHash($base, null),
            $this->svc->computeHash($alt, null),
        );
    }

    public function test_compute_hash_chains_with_previous(): void
    {
        $payload = ['tenant_id' => 't', 'action' => 'a'];

        $h1 = $this->svc->computeHash($payload, null);
        $h2 = $this->svc->computeHash($payload, $h1);
        $h3 = $this->svc->computeHash($payload, $h2);

        $this->assertNotSame($h1, $h2);
        $this->assertNotSame($h2, $h3);
        // El mismo input con el mismo prev produce el mismo hash:
        $this->assertSame($h2, $this->svc->computeHash($payload, $h1));
    }

    public function test_key_order_does_not_affect_hash(): void
    {
        $a = ['action' => 'x', 'tenant_id' => 't', 'criticidad' => 'info'];
        $b = ['criticidad' => 'info', 'tenant_id' => 't', 'action' => 'x'];

        $this->assertSame(
            $this->svc->computeHash($a, null),
            $this->svc->computeHash($b, null),
        );
    }

    public function test_nested_array_order_does_not_affect_hash(): void
    {
        $a = ['action' => 'x', 'metadata' => ['z' => 1, 'a' => 2]];
        $b = ['action' => 'x', 'metadata' => ['a' => 2, 'z' => 1]];

        $this->assertSame(
            $this->svc->computeHash($a, null),
            $this->svc->computeHash($b, null),
        );
    }

    public function test_hash_excludes_id_and_hash_actual(): void
    {
        $base = ['tenant_id' => 't', 'action' => 'x'];
        $with = $base + ['id' => 'whatever-uuid', 'hash_actual' => 'previously'];

        $this->assertSame(
            $this->svc->computeHash($base, null),
            $this->svc->computeHash($with, null),
        );
    }

    public function test_known_vector(): void
    {
        // Vector congelado: si esto falla, alguien cambió el algoritmo.
        // Para regenerarlo legítimamente, reemplaza el valor esperado tras
        // confirmar el cambio con el equipo.
        $payload = [
            'action'     => 'asiento.created',
            'tenant_id'  => '00000000-0000-0000-0000-000000000001',
            'criticidad' => 'info',
        ];
        $expected = hash(
            'sha256',
            json_encode(
                ['action' => 'asiento.created', 'criticidad' => 'info', 'tenant_id' => '00000000-0000-0000-0000-000000000001'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . ''
        );
        $this->assertSame($expected, $this->svc->computeHash($payload, null));
    }
}
