<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Tests\TestCase;

/**
 * Tests de seguridad: aislamiento de tenant en AuditLogService.
 *
 * Estos tests verifican los hallazgos C-2 y M-2 del reporte de auditoría EPIC-002.
 */
class AuditLogTenantIsolationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // M-2: GLOBAL_BLACKLIST case-insensitive
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function sanitize_removes_password_case_insensitive(): void
    {
        $svc = app(AuditLogService::class);

        $reflection = new \ReflectionMethod($svc, 'sanitize');
        $reflection->setAccessible(true);

        $payload = [
            'nombre'   => 'Juan',
            'password' => 'secreto123',
            'Password' => 'secreto456',   // variante capitalizada
            'PASSWORD' => 'SECRETO789',   // variante mayúsculas
            'Api_Token' => 'tok-abc',     // variante mixta
        ];

        $result = $reflection->invoke($svc, $payload, null);

        $this->assertSame(['nombre' => 'Juan'], $result,
            'El sanitize debe eliminar todas las variantes de mayúsculas/minúsculas de la blacklist.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function sanitize_preserves_non_blacklisted_fields(): void
    {
        $svc = app(AuditLogService::class);

        $reflection = new \ReflectionMethod($svc, 'sanitize');
        $reflection->setAccessible(true);

        $payload = [
            'user_id'    => 42,
            'accion'     => 'login',
            'remember_token' => 'secret', // blacklisted
        ];

        $result = $reflection->invoke($svc, $payload, null);

        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('accion', $result);
        $this->assertArrayNotHasKey('remember_token', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function sanitize_returns_null_when_all_fields_are_blacklisted(): void
    {
        $svc = app(AuditLogService::class);

        $reflection = new \ReflectionMethod($svc, 'sanitize');
        $reflection->setAccessible(true);

        $payload = [
            'password'           => 'a',
            'password_hash'      => 'b',
            'password_confirmation' => 'c',
        ];

        $result = $reflection->invoke($svc, $payload, null);

        $this->assertNull($result, 'Cuando todos los campos son blacklisted, debe retornar null.');
    }

    // -------------------------------------------------------------------------
    // Hash chain — resistencia a tampering (C hallazgo 4 del diseño)
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function compute_hash_changes_when_payload_field_is_tampered(): void
    {
        $svc = app(AuditLogService::class);

        $payload = [
            'tenant_id'           => 'tenant-abc',
            'user_id'             => '123',
            'user_email_snapshot' => 'user@test.com',
            'user_role_snapshot'  => 'contador',
            'action'              => 'asiento.aprobado',
            'criticidad'          => 'warning',
            'auditable_type'      => 'Asiento',
            'auditable_id'        => 'uuid-1',
            'old_values'          => null,
            'new_values'          => ['estado' => 'aprobado'],
            'motivo'              => null,
            'metadata'            => null,
            'ip_address'          => '127.0.0.1',
            'user_agent'          => 'test',
            'request_id'          => null,
            'sucursal_id'         => null,
            'hash_anterior'       => null,
            'created_at'          => '2026-05-08T00:00:00+00:00',
        ];

        $hashOriginal = $svc->computeHash($payload, null);

        // Simular tampering: cambiar el user_id
        $payloadTampered             = $payload;
        $payloadTampered['user_id']  = '999';
        $hashTampered = $svc->computeHash($payloadTampered, null);

        $this->assertNotEquals($hashOriginal, $hashTampered,
            'El hash debe cambiar si se altera cualquier campo del payload.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function compute_hash_changes_when_new_values_are_tampered(): void
    {
        $svc = app(AuditLogService::class);

        $base = [
            'tenant_id'     => 'tenant-xyz',
            'user_id'       => '1',
            'action'        => 'asiento.anulado',
            'criticidad'    => 'critical',
            'new_values'    => ['estado' => 'anulado'],
            'old_values'    => null,
            'created_at'    => '2026-05-08T12:00:00+00:00',
            'hash_anterior' => null,
            'user_email_snapshot' => null,
            'user_role_snapshot'  => null,
            'auditable_type' => 'Asiento',
            'auditable_id'   => 'uuid-2',
            'motivo'        => 'Factura duplicada emitida por error',
            'metadata'      => null,
            'ip_address'    => '10.0.0.1',
            'user_agent'    => 'system',
            'request_id'    => null,
            'sucursal_id'   => null,
        ];

        $hashOrig = $svc->computeHash($base, null);

        $tampered               = $base;
        $tampered['new_values'] = ['estado' => 'aprobado']; // cambio silencioso
        $hashTamp = $svc->computeHash($tampered, null);

        $this->assertNotEquals($hashOrig, $hashTamp,
            'Alterar new_values debe invalidar el hash.');
    }

    // -------------------------------------------------------------------------
    // AuditLog model — append-only ya cubierto en AuditLogIsAppendOnlyTest.php
    // Este test verifica que el modelo rechaza write con mensaje claro.
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function audit_log_model_throws_logic_exception_on_update(): void
    {
        $log = new AuditLog();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/append-only/i');

        $log->update(['action' => 'hacked']);
    }
}
