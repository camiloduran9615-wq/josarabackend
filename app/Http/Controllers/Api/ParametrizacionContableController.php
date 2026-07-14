<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de la tabla parametrizacion_contable.
 *
 * Permite que el contador configure qué cuenta afecta cada tipo de operación:
 *  - compra.cuenta_proveedor           220505 - Proveedores nacionales
 *  - compra.cuenta_caja                110505 - Caja general
 *  - compra.cuenta_iva_descontable     240810 - IVA compras descontable
 *  - compra.cuenta_retefuente          236540 - Retención en la fuente compras
 *  - compra.cuenta_retefuente_honorarios  236540 - Reten. honorarios
 *  - compra.cuenta_reteica             236801 - Reteica
 *  - compra.cuenta_inventario_merc     143505 - Inventario mercancías
 *  - compra.cuenta_inventario_mp       145505 - Inventario materia prima
 *  - compra.cuenta_inventario_pt       146505 - Inventario producto terminado
 *  - compra.cuenta_inventario_pp       146005 - Inventario en proceso
 *  - factura.cuenta_cartera            130505 - Clientes
 *  - factura.cuenta_ingresos_ventas    413505 - Ingresos por ventas
 *  - factura.cuenta_iva_generado_19    240801 - IVA generado 19%
 *  - factura.cuenta_iva_generado_5     240801 - IVA generado 5%
 *  - factura.cuenta_inventario         143505 - Inventario (salida por venta)
 *  - factura.cuenta_costo_ventas       613505 - Costo de ventas
 *  - factura.cuenta_retefuente_ventas  135515 - Anticipo retención fuente
 *  - factura.cuenta_reteica_ventas     135518 - Anticipo reteica
 */
class ParametrizacionContableController extends Controller
{
    /**
     * Claves CRÍTICAS por módulo — sin estas no se puede generar el asiento contable.
     * Si una de estas está sin configurar, el documento falla al registrar.
     */
    private const CLAVES_CRITICAS = [
        'compra'    => [
            'compra.cuenta_proveedor',
            'compra.cuenta_iva_descontable',
            'compra.cuenta_inventario_merc',
            'compra.cuenta_retefuente',
        ],
        'factura'   => [
            'factura.cuenta_cartera',
            'factura.cuenta_ingresos_ventas',
            'factura.cuenta_iva_generado_19',
            'factura.cuenta_inventario',
            'factura.cuenta_costo_ventas',
        ],
        'cierre'    => [
            'cierre.cuenta_utilidad_ejercicio',
        ],
    ];

    /**
     * GET /parametrizacion-contable/validar/{modulo}
     * Devuelve las claves CRÍTICAS faltantes (sin cuenta asignada) del módulo.
     *
     * Respuesta:
     *   { valido: true,  faltantes: [], modulo: "compra" }    → todo OK
     *   { valido: false, faltantes: [{ clave, label }], ... } → faltan claves
     *
     * El frontend usa esto antes de abrir formularios para alertar al usuario
     * y redirigir a Configuración → Cuentas Maestras si la parametrización
     * está incompleta. Previene errores 500 al guardar documentos.
     */
    public function validar(string $tenant, string $modulo): JsonResponse
    {
        $clavesRequeridas = self::CLAVES_CRITICAS[$modulo] ?? null;
        if ($clavesRequeridas === null) {
            return response()->json([
                'success' => false,
                'message' => "Módulo '{$modulo}' no reconocido. Use: " . implode(', ', array_keys(self::CLAVES_CRITICAS)),
            ], 422);
        }

        $configuradas = ParametrizacionContable::query()
            ->whereIn('clave', $clavesRequeridas)
            ->where('activo', true)
            ->whereNotNull('cuenta_contable_id')
            ->pluck('clave')
            ->toArray();

        $faltantes = array_values(array_diff($clavesRequeridas, $configuradas));

        return response()->json([
            'success' => true,
            'data' => [
                'modulo'    => $modulo,
                'valido'    => count($faltantes) === 0,
                'total'     => count($clavesRequeridas),
                'configuradas' => count($configuradas),
                'faltantes' => array_map(fn ($c) => [
                    'clave' => $c,
                    'label' => $this->labelForClave($c),
                ], $faltantes),
            ],
        ]);
    }

    /** Etiqueta legible de una clave (para mostrar al usuario). */
    private function labelForClave(string $clave): string
    {
        $labels = [
            'compra.cuenta_proveedor'         => 'Proveedores (Crédito)',
            'compra.cuenta_iva_descontable'   => 'IVA Descontable',
            'compra.cuenta_inventario_merc'   => 'Inventario — Mercancías',
            'compra.cuenta_retefuente'        => 'Retención en la Fuente — Compras',
            'factura.cuenta_cartera'          => 'Cartera / Clientes',
            'factura.cuenta_ingresos_ventas'  => 'Ingresos por Ventas',
            'factura.cuenta_iva_generado_19'  => 'IVA Generado 19%',
            'factura.cuenta_inventario'       => 'Salida de Inventario',
            'factura.cuenta_costo_ventas'     => 'Costo de Ventas',
            'cierre.cuenta_utilidad_ejercicio'=> 'Utilidad del Ejercicio',
        ];
        return $labels[$clave] ?? $clave;
    }

    /**
     * GET /parametrizacion-contable
     * Devuelve todas las claves con su cuenta asignada, agrupadas por módulo.
     */
    public function index(string $tenant): JsonResponse
    {
        $params = ParametrizacionContable::with('cuenta:id,codigo,nombre')
            ->orderBy('clave')
            ->get();

        // Agrupar por prefijo (compra, factura, nomina, etc.)
        $grouped = $params->groupBy(function ($p) {
            return explode('.', $p->clave)[0];
        })->map(function ($items) {
            return $items->values();
        });

        return response()->json([
            'success' => true,
            'data'    => $grouped,
            'meta'    => ['total' => $params->count()],
        ]);
    }

    /**
     * PUT /parametrizacion-contable/{clave}
     * Actualiza la cuenta de una clave existente.
     * La clave es un string (ej: "compra.cuenta_proveedor"), URL-encoded si tiene punto.
     */
    public function update(Request $request, string $tenant, string $clave): JsonResponse
    {
        $validated = $request->validate([
            'cuenta_contable_id' => ['required', 'uuid', 'exists:cuentas_contables,id'],
            'descripcion'        => ['nullable', 'string', 'max:200'],
        ]);

        $param = ParametrizacionContable::firstOrNew(['clave' => $clave]);

        $cuenta = CuentaContable::findOrFail($validated['cuenta_contable_id']);

        $param->fill([
            'cuenta_contable_id' => $validated['cuenta_contable_id'],
            'descripcion'        => $validated['descripcion']
                ?? "Cuenta {$cuenta->codigo} — {$cuenta->nombre}",
            'activo'             => true,
        ])->save();

        $param->load('cuenta:id,codigo,nombre');

        return response()->json(['success' => true, 'data' => $param]);
    }

    /**
     * POST /parametrizacion-contable/bulk
     * Actualiza múltiples claves en una sola petición.
     * Body: { updates: [{ clave, cuenta_contable_id }] }
     */
    public function bulk(Request $request, string $tenant): JsonResponse
    {
        $validated = $request->validate([
            'updates'                        => ['required', 'array', 'min:1', 'max:100'],
            'updates.*.clave'                => ['required', 'string', 'max:100'],
            'updates.*.cuenta_contable_id'   => ['required', 'uuid', 'exists:cuentas_contables,id'],
        ]);

        $updated = [];
        foreach ($validated['updates'] as $item) {
            $param = ParametrizacionContable::firstOrNew(['clave' => $item['clave']]);
            $cuenta = CuentaContable::find($item['cuenta_contable_id']);

            $param->fill([
                'cuenta_contable_id' => $item['cuenta_contable_id'],
                'descripcion'        => $cuenta
                    ? "Cuenta {$cuenta->codigo} — {$cuenta->nombre}"
                    : $param->descripcion,
                'activo' => true,
            ])->save();

            $updated[] = $param->clave;
        }

        return response()->json([
            'success' => true,
            'message' => count($updated) . ' parámetros actualizados.',
            'data'    => ['updated_claves' => $updated],
        ]);
    }
}
