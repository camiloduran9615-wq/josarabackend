<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed inicial: crea bodegas "Bodega Principal" por cada sucursal
 * y migra el stock actual de cada producto a la bodega de la sucursal principal.
 *
 * Idempotente: no recrea si ya existen.
 *
 * Lógica:
 *  1. Para cada sucursal → crear una "Bodega Principal" de tipo 'mercancia'
 *  2. Para cada producto con stock_actual > 0 → insertar en producto_stock_bodega
 *     usando la bodega de la sucursal principal (es_principal=true)
 *  3. Si no hay sucursal principal, usar la primera sucursal disponible
 *  4. Si no hay sucursales en absoluto, crear una "Casa Matriz" y su bodega
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Crear bodega principal por sucursal ───────────────────────────
        $sucursales = DB::table('sucursales')->get();

        if ($sucursales->isEmpty()) {
            // Empresa sin sucursales aún: crear casa matriz + bodega
            $sucursalId = (string) \Illuminate\Support\Str::uuid();
            DB::table('sucursales')->insert([
                'id'           => $sucursalId,
                'nombre'       => 'Casa Matriz',
                'es_principal' => true,
                'activa'       => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $sucursales = DB::table('sucursales')->get();
        }

        $bodegasPorSucursal = [];

        foreach ($sucursales as $sucursal) {
            // Idempotencia: no crear si ya existe bodega para esta sucursal
            $existing = DB::table('bodegas')
                ->where('sucursal_id', $sucursal->id)
                ->where('es_principal', true)
                ->first();

            if ($existing) {
                $bodegasPorSucursal[$sucursal->id] = $existing->id;
                continue;
            }

            $bodegaId = (string) \Illuminate\Support\Str::uuid();
            $sufijo   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $sucursal->nombre) ?: 'BOD', 0, 4));

            DB::table('bodegas')->insert([
                'id'          => $bodegaId,
                'sucursal_id' => $sucursal->id,
                'codigo'      => 'BOD-' . $sufijo . '-01',
                'nombre'      => 'Bodega Principal',
                'tipo'        => 'mercancia',
                'es_principal'=> true,
                'activa'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $bodegasPorSucursal[$sucursal->id] = $bodegaId;
        }

        // ── 2. Bodega para backfill de stock ────────────────────────────────
        // Usar la bodega de la sucursal principal; si no hay, la primera
        $sucursalPrincipal = DB::table('sucursales')
            ->where('es_principal', true)
            ->first()
            ?? DB::table('sucursales')->first();

        $bodegaBackfillId = $bodegasPorSucursal[$sucursalPrincipal->id] ?? null;

        if (! $bodegaBackfillId) {
            return; // Sin bodega disponible — no hacer nada
        }

        // ── 3. Migrar stock_actual de productos → producto_stock_bodega ──────
        $productos = DB::table('productos')
            ->where('stock_actual', '>', 0)
            ->whereNull('deleted_at')
            ->get();

        foreach ($productos as $producto) {
            $yaExiste = DB::table('producto_stock_bodega')
                ->where('producto_id', $producto->id)
                ->where('bodega_id', $bodegaBackfillId)
                ->exists();

            if ($yaExiste) {
                continue;
            }

            $costoUnitario = (float) ($producto->precio_compra ?? 0);
            $stockActual   = (float) ($producto->stock_actual ?? 0);

            DB::table('producto_stock_bodega')->insert([
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'producto_id'     => $producto->id,
                'bodega_id'       => $bodegaBackfillId,
                'saldo_unidades'  => $stockActual,
                'saldo_valor'     => $stockActual * $costoUnitario,
                'costo_promedio'  => $costoUnitario,
                'version'         => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // ── 4. Seed categorías por defecto con tipos ────────────────────────
        $categoriasSeed = [
            ['nombre' => 'Mercancía',          'tipo' => 'mercancia'],
            ['nombre' => 'Materia Prima',       'tipo' => 'materia_prima'],
            ['nombre' => 'Producto en Proceso', 'tipo' => 'producto_proceso'],
            ['nombre' => 'Producto Terminado',  'tipo' => 'producto_terminado'],
            ['nombre' => 'Servicio',            'tipo' => 'servicio'],
            ['nombre' => 'Activo Fijo',         'tipo' => 'activo_fijo'],
        ];

        foreach ($categoriasSeed as $cat) {
            $existe = DB::table('categorias')->where('nombre', $cat['nombre'])->first();

            if ($existe) {
                // Actualizar el tipo si la categoría ya existía sin él
                DB::table('categorias')
                    ->where('id', $existe->id)
                    ->update(['tipo' => $cat['tipo'], 'updated_at' => now()]);
            } else {
                DB::table('categorias')->insert([
                    'id'         => (string) \Illuminate\Support\Str::uuid(),
                    'nombre'     => $cat['nombre'],
                    'tipo'       => $cat['tipo'],
                    'activa'     => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No eliminamos datos en el down; solo estructura en las migraciones anteriores
    }
};
