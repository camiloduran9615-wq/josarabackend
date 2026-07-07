<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Tenant\Sucursal;
use Illuminate\Database\QueryException;
use Tests\TenantTestCase;

/**
 * Defensa en profundidad: aunque el controller respeta el invariante
 * (Opción A), validamos que la BASE DE DATOS misma rechace cualquier
 * intento de tener dos sucursales con es_principal=true (Opción C).
 *
 * Esto protege contra:
 *  - Bugs futuros en controllers/services que olviden el invariante.
 *  - Manipulación directa por scripts/seeders/comandos artisan.
 *  - Race conditions entre dos requests concurrentes que actualicen
 *    sucursales distintas marcándolas como principal.
 *
 * El índice UNIQUE parcial está definido en la migración tenant
 * 2026_05_19_140000_enforce_one_principal_sucursal_per_tenant.
 */
class SucursalPrincipalUniqueConstraintTest extends TenantTestCase
{
    public function test_no_se_puede_insertar_segunda_sucursal_principal_directa(): void
    {
        // Ya existe la principal "Casa Matriz" creada por la migración
        // 2026_05_10_000008. Verificamos invariante de partida.
        $this->assertSame(
            1,
            Sucursal::where('es_principal', true)->count(),
            'Estado de partida inválido: debería haber exactamente 1 principal.',
        );

        // Intentar insertar una SEGUNDA sucursal con es_principal=true
        // debe fallar con violación de UNIQUE a nivel PostgreSQL.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sucursales_one_principal_unique|unique constraint|23505/i');

        Sucursal::create([
            'nombre'       => 'Sede Pirata',
            'es_principal' => true,
            'activa'       => true,
        ]);
    }

    public function test_no_se_puede_marcar_segunda_sucursal_existente_como_principal(): void
    {
        // Crear una sucursal secundaria (no principal) — esto es válido.
        $secundaria = Sucursal::create([
            'nombre'       => 'Sucursal Norte',
            'es_principal' => false,
            'activa'       => true,
        ]);

        $this->assertSame(1, Sucursal::where('es_principal', true)->count());

        // Intentar promoverla a principal debe fallar:
        // ya existe otra fila con es_principal=true → viola el índice UNIQUE.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sucursales_one_principal_unique|unique constraint|23505/i');

        $secundaria->update(['es_principal' => true]);
    }

    public function test_se_pueden_crear_multiples_sucursales_no_principales(): void
    {
        // Múltiples sucursales con es_principal=false son legítimas
        // (red de sucursales de la empresa). El índice parcial NO las restringe.
        // Usamos delta sobre el conteo inicial para ser robustos al estado
        // previo del tenant (TenantTestCase no garantiza tenant prístino).
        $noPrincipalesInicio = Sucursal::where('es_principal', false)->count();
        $principalesInicio   = Sucursal::where('es_principal', true)->count();

        Sucursal::create([
            'nombre'       => 'Sucursal Norte',
            'es_principal' => false,
            'activa'       => true,
        ]);
        Sucursal::create([
            'nombre'       => 'Sucursal Sur',
            'es_principal' => false,
            'activa'       => true,
        ]);
        Sucursal::create([
            'nombre'       => 'Sucursal Centro',
            'es_principal' => false,
            'activa'       => true,
        ]);

        $this->assertSame(
            $noPrincipalesInicio + 3,
            Sucursal::where('es_principal', false)->count(),
            'Las 3 sucursales no-principales deberían insertarse sin restricción.',
        );
        $this->assertSame(
            $principalesInicio,
            Sucursal::where('es_principal', true)->count(),
            'El conteo de principales no debe cambiar.',
        );
    }
}
