<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza el invariante: por cada tenant existe a lo sumo UNA sucursal
 * con es_principal = true.
 *
 * Estrategia (idempotente, segura de re-ejecutar):
 *  1. Data fix: si existen múltiples sucursales con es_principal=true,
 *     deja solo la más antigua (created_at ASC) como principal y desmarca
 *     el resto. Es la elección menos destructiva: respeta el orden de creación.
 *  2. Crea un índice UNIQUE parcial: una sola fila puede tener
 *     es_principal=true. Inserts/updates que rompan el invariante fallarán
 *     a nivel de base de datos — defensa en profundidad por encima del
 *     controller.
 *
 * El índice parcial es muy eficiente en PostgreSQL: ocupa poco espacio
 * (solo indexa filas con es_principal=true) y acelera las queries
 * frecuentes que filtran por la sucursal principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Data fix: dejar solo UNA principal (la más antigua) ──────────
        $principales = DB::table('sucursales')
            ->where('es_principal', true)
            ->orderBy('created_at')
            ->orderBy('id') // tiebreaker determinista si timestamps coinciden
            ->pluck('id');

        if ($principales->count() > 1) {
            $idsADesmarcar = $principales->skip(1)->values()->all();

            DB::table('sucursales')
                ->whereIn('id', $idsADesmarcar)
                ->update([
                    'es_principal' => false,
                    'updated_at'   => now(),
                ]);
        }

        // ── 2. Índice UNIQUE parcial ────────────────────────────────────────
        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS sucursales_one_principal_unique
            ON sucursales (es_principal)
            WHERE es_principal = true
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sucursales_one_principal_unique');
    }
};
