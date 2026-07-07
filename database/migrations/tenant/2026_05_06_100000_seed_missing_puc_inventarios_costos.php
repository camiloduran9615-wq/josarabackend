<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Usa DB raw para evitar que el observer de CuentaContable intente
        // escribir columnas que aún no existen en esta etapa de la migración.
        $this->insertIfMissing([
            [
                'codigo'            => '61',
                'nombre'            => 'Costo de Ventas y de Prestación de Servicios',
                'naturaleza'        => 'debito',
                'nivel'             => 'grupo',
                'parent_codigo'     => '6',
                'acepta_movimientos'=> false,
            ],
            [
                'codigo'            => '1430',
                'nombre'            => 'Mercancías no Fabricadas por la Empresa',
                'naturaleza'        => 'debito',
                'nivel'             => 'cuenta',
                'parent_codigo'     => '14',
                'acepta_movimientos'=> false,
            ],
            [
                'codigo'            => '6135',
                'nombre'            => 'Comercio al por Mayor y al por Menor',
                'naturaleza'        => 'debito',
                'nivel'             => 'cuenta',
                'parent_codigo'     => '61',
                'acepta_movimientos'=> false,
            ],
            [
                'codigo'            => '143005',
                'nombre'            => 'Inventario de Mercancías',
                'naturaleza'        => 'debito',
                'nivel'             => 'subcuenta',
                'parent_codigo'     => '1430',
                'acepta_movimientos'=> true,
            ],
            [
                'codigo'            => '613505',
                'nombre'            => 'Costo de Ventas Comercio',
                'naturaleza'        => 'debito',
                'nivel'             => 'subcuenta',
                'parent_codigo'     => '6135',
                'acepta_movimientos'=> true,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('cuentas_contables')
            ->whereIn('codigo', ['613505', '143005', '6135', '1430', '61'])
            ->delete();
    }

    private function insertIfMissing(array $rows): void
    {
        $now = now()->toDateTimeString();

        foreach ($rows as $row) {
            $exists = DB::table('cuentas_contables')
                ->where('codigo', $row['codigo'])
                ->exists();

            if ($exists) {
                continue;
            }

            $parentId = null;
            if (!empty($row['parent_codigo'])) {
                $parentId = DB::table('cuentas_contables')
                    ->where('codigo', $row['parent_codigo'])
                    ->value('id');
            }

            DB::table('cuentas_contables')->insert([
                'id'                  => Str::uuid()->toString(),
                'codigo'              => $row['codigo'],
                'nombre'              => $row['nombre'],
                'naturaleza'          => $row['naturaleza'],
                'nivel'               => $row['nivel'],
                'parent_id'           => $parentId,
                'acepta_movimientos'  => $row['acepta_movimientos'],
                'exige_tercero'       => false,
                'exige_centro_costo'  => false,
                'exige_base_impuesto' => false,
                'activo'              => true,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }
};
