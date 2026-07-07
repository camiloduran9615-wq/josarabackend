<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Tenant\Asiento;
use App\Models\User;
use App\Policies\AsientoPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios del Policy: lógica pura sin BD.
 * Cubre la matriz de roles + segregación de funciones.
 */
class AsientoPolicyTest extends TestCase
{
    private AsientoPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AsientoPolicy();
    }

    private function user(string $role, string $id = 'u-1'): User
    {
        $u = new User();
        $u->id = $id;
        $u->role = $role;
        return $u;
    }

    private function asiento(string $estado, string $createdBy = 'u-1', ?string $modifiedBy = null): Asiento
    {
        $a = new Asiento();
        $a->id = 'a-1';
        $a->estado = $estado;
        $a->created_by_id = $createdBy;
        $a->last_modified_by_id = $modifiedBy ?? $createdBy;
        return $a;
    }

    public function test_admin_can_view(): void
    {
        $this->assertTrue($this->policy->viewAny($this->user(User::ROLE_ADMIN)));
    }

    public function test_readonly_can_view_but_not_create(): void
    {
        $u = $this->user(User::ROLE_READONLY);
        $this->assertTrue($this->policy->viewAny($u));
        $this->assertFalse($this->policy->create($u));
    }

    public function test_auxiliar_can_create(): void
    {
        $this->assertTrue($this->policy->create($this->user(User::ROLE_AUXILIAR)));
    }

    public function test_auditor_cannot_create(): void
    {
        $this->assertFalse($this->policy->create($this->user(User::ROLE_AUDITOR)));
    }

    public function test_auxiliar_cannot_approve(): void
    {
        $u = $this->user(User::ROLE_AUXILIAR, 'u-aux');
        $a = $this->asiento(Asiento::ESTADO_BORRADOR, 'u-otro');
        $this->assertFalse($this->policy->approve($u, $a));
    }

    public function test_segregation_creator_cannot_approve_own_asiento(): void
    {
        $u = $this->user(User::ROLE_CONTADOR, 'u-creator');
        $a = $this->asiento(Asiento::ESTADO_BORRADOR, 'u-creator');
        $this->assertFalse($this->policy->approve($u, $a));
    }

    public function test_segregation_last_modifier_cannot_approve(): void
    {
        $u = $this->user(User::ROLE_CONTADOR, 'u-modifier');
        $a = $this->asiento(Asiento::ESTADO_BORRADOR, 'u-creator', 'u-modifier');
        $this->assertFalse($this->policy->approve($u, $a));
    }

    public function test_contador_distinto_creator_can_approve(): void
    {
        $u = $this->user(User::ROLE_CONTADOR, 'u-contador');
        $a = $this->asiento(Asiento::ESTADO_BORRADOR, 'u-creator', 'u-creator');
        $this->assertTrue($this->policy->approve($u, $a));
    }

    public function test_cannot_approve_already_approved(): void
    {
        $u = $this->user(User::ROLE_CONTADOR, 'u-contador');
        $a = $this->asiento(Asiento::ESTADO_APROBADO, 'u-creator');
        $this->assertFalse($this->policy->approve($u, $a));
    }

    public function test_only_contador_can_void(): void
    {
        $a = $this->asiento(Asiento::ESTADO_APROBADO);
        // Sin periodo cargado → false (defensivo)
        $this->assertFalse($this->policy->void($this->user(User::ROLE_ADMIN), $a));
        $this->assertFalse($this->policy->void($this->user(User::ROLE_AUXILIAR), $a));
    }
}
