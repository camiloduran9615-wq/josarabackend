<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CuentaContable;
use Illuminate\Http\Request;

class CuentaContableController extends Controller
{
    /**
     * Devuelve el PUC en forma plana, opcionalmente estructurado en árbol en el frontend
     * o devuelve el árbol anidado. Aquí devolveremos el árbol anidado para mayor facilidad.
     */
    public function index()
    {
        // Traer todas las cuentas
        $cuentas = CuentaContable::orderBy('codigo')->get();

        // Construir el árbol
        $tree = $this->buildTree($cuentas);

        return response()->json([
            'success' => true,
            'data' => $tree
        ]);
    }

    /**
     * Construye una estructura de árbol a partir de una lista plana de cuentas.
     */
    private function buildTree($elements, $parentId = null)
    {
        $branch = [];

        foreach ($elements as $element) {
            if ($element->parent_id == $parentId) {
                $children = $this->buildTree($elements, $element->id);
                if ($children) {
                    $element->children = $children;
                } else {
                    $element->children = [];
                }
                $branch[] = $element;
            }
        }

        return $branch;
    }

    /**
     * Crea una nueva cuenta contable en cualquier nivel de la jerarquía
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'uuid', 'exists:cuentas_contables,id'],
            'codigo' => ['required', 'string', 'max:20', 'unique:cuentas_contables,codigo'],
            'nombre' => ['required', 'string', 'max:255'],
            'naturaleza' => ['required_if:parent_id,null', 'string', 'in:debito,credito'],
            'acepta_movimientos' => ['boolean'],
            'exige_tercero' => ['boolean'],
            'exige_centro_costo' => ['boolean'],
            'exige_base_impuesto' => ['boolean'],
        ]);

        if ($request->filled('parent_id')) {
            $parent = CuentaContable::findOrFail($validated['parent_id']);
            
            // Determinar el siguiente nivel
            $levels = ['clase', 'grupo', 'cuenta', 'subcuenta', 'auxiliar'];
            $currentIndex = array_search($parent->nivel, $levels);
            
            if ($currentIndex === false || $currentIndex >= count($levels) - 1) {
                $validated['nivel'] = 'auxiliar'; // Si ya es auxiliar o no se encuentra, sigue siendo auxiliar
            } else {
                $validated['nivel'] = $levels[$currentIndex + 1];
            }

            // Heredar naturaleza del padre
            $validated['naturaleza'] = $parent->naturaleza;
        } else {
            // Es una cuenta de nivel 1 (Clase)
            $validated['nivel'] = 'clase';
            $validated['parent_id'] = null;
        }

        $validated['acepta_movimientos'] = $request->input('acepta_movimientos', $validated['nivel'] === 'auxiliar');

        $cuenta = CuentaContable::create($validated);
        
        return response()->json([
            'success' => true,
            'message' => 'Cuenta auxiliar creada correctamente.',
            'data' => $cuenta
        ], 201);
    }

    /**
     * Actualiza una cuenta contable
     */
    public function update(Request $request, $id)
    {
        \Illuminate\Support\Facades\Log::info("Solicitud de actualización para ID: " . $id);
        $cuenta = CuentaContable::findOrFail($id);
        
        $validated = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'acepta_movimientos' => ['sometimes', 'boolean'],
            'exige_tercero' => ['sometimes', 'boolean'],
            'exige_centro_costo' => ['sometimes', 'boolean'],
            'exige_base_impuesto' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $cuenta->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cuenta actualizada correctamente.',
            'data' => $cuenta
        ]);
    }

    /**
     * Inactiva una cuenta — con validaciones de integridad contable estrictas.
     *
     * Una cuenta NO puede inactivarse si:
     *   1. Tiene subcuentas hijas (rompe la jerarquía PUC)
     *   2. Tiene movimientos en asiento_items (rompe históricos y reportes)
     *   3. Está asignada en parametrizacion_contable (rompe el ERP)
     *   4. La usa algún impuesto activo (rompe cálculo de IVA/Retenciones)
     *   5. La referencia algún producto (rompe inventario)
     *   6. La referencia alguna bodega (rompe Kardex)
     */
    public function destroy(Request $request, $id)
    {
        $cuenta = CuentaContable::find($id);
        if (!$cuenta) {
            return response()->json(['success' => false, 'message' => 'Cuenta no encontrada.'], 404);
        }

        $bloqueos = $this->detectarBloqueos($cuenta);
        if (!empty($bloqueos)) {
            return response()->json([
                'success'   => false,
                'message'   => 'No se puede inactivar la cuenta porque tiene referencias activas.',
                'bloqueos'  => $bloqueos,
                'sugerencia'=> 'Reemplaza la cuenta en los módulos que la usan antes de inactivarla. Los movimientos históricos no se pueden eliminar (rompería auditoría y balances).',
            ], 422);
        }

        try {
            $cuenta->update(['activo' => false]);
            return response()->json([
                'success' => true,
                'message' => "Cuenta '{$cuenta->codigo} — {$cuenta->nombre}' marcada como INACTIVA. Ya no aparecerá en nuevas operaciones (los movimientos históricos se conservan intactos).",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Inspecciona todas las referencias activas a una cuenta y devuelve un detalle
     * estructurado para que el frontend muestre exactamente dónde está siendo usada.
     */
    private function detectarBloqueos(CuentaContable $cuenta): array
    {
        $bloqueos = [];

        // 1) Sub-cuentas hijas
        $hijas = $cuenta->children()->count();
        if ($hijas > 0) {
            $bloqueos[] = [
                'modulo'   => 'Plan de Cuentas',
                'detalle'  => "Tiene {$hijas} subcuenta(s) hija(s). Inactívalas primero o re-asígnalas a otro padre.",
                'critico'  => true,
            ];
        }

        // 2) Movimientos contables (asiento_items) — el más crítico
        $movs = \DB::table('asiento_items')->where('cuenta_id', $cuenta->id)->count();
        if ($movs > 0) {
            $bloqueos[] = [
                'modulo'   => 'Asientos Contables',
                'detalle'  => "Tiene {$movs} movimiento(s) registrado(s) en asientos. No se permite inactivar — rompería balances históricos, libro mayor y reportes tributarios.",
                'critico'  => true,
            ];
        }

        // 3) Parametrización Contable
        if (\Schema::hasTable('parametrizacion_contable')) {
            $params = \DB::table('parametrizacion_contable')
                ->where('cuenta_contable_id', $cuenta->id)
                ->pluck('clave')->toArray();
            if (!empty($params)) {
                $bloqueos[] = [
                    'modulo'   => 'Parametrización Contable',
                    'detalle'  => 'Está asignada a la(s) clave(s): ' . implode(', ', $params) . '. Cámbiala primero en "Cuentas Maestras".',
                    'critico'  => false,
                ];
            }
        }

        // 4) Impuestos
        if (\Schema::hasTable('impuestos')) {
            $imps = \DB::table('impuestos')
                ->where(function ($q) use ($cuenta) {
                    $q->where('cuenta_contable_id', $cuenta->id)
                      ->orWhere('cuenta_contrapartida_id', $cuenta->id);
                })
                ->where('activa', true)
                ->pluck('codigo')->toArray();
            if (!empty($imps)) {
                $bloqueos[] = [
                    'modulo'   => 'Impuestos',
                    'detalle'  => 'Usada por impuesto(s) activo(s): ' . implode(', ', $imps) . '. Reasigna otra cuenta o inactiva el impuesto.',
                    'critico'  => false,
                ];
            }
        }

        // 5) Productos (campos cuenta_*_id si existen)
        if (\Schema::hasTable('productos')) {
            $cols = \Schema::getColumnListing('productos');
            $cuentaCols = array_values(array_filter($cols, fn ($c) => str_starts_with($c, 'cuenta_') && str_ends_with($c, '_id')));
            if (!empty($cuentaCols)) {
                $totalProductos = \DB::table('productos')->where(function ($q) use ($cuentaCols, $cuenta) {
                    foreach ($cuentaCols as $col) $q->orWhere($col, $cuenta->id);
                })->count();
                if ($totalProductos > 0) {
                    $bloqueos[] = [
                        'modulo'   => 'Productos',
                        'detalle'  => "{$totalProductos} producto(s) usan esta cuenta. Re-asígnalos en el módulo Inventario.",
                        'critico'  => false,
                    ];
                }
            }
        }

        // 6) Bodegas
        if (\Schema::hasTable('bodegas')) {
            $cols = \Schema::getColumnListing('bodegas');
            $cuentaCols = array_values(array_filter($cols, fn ($c) => str_starts_with($c, 'cuenta_') && str_ends_with($c, '_id')));
            if (!empty($cuentaCols)) {
                $totalBodegas = \DB::table('bodegas')->where(function ($q) use ($cuentaCols, $cuenta) {
                    foreach ($cuentaCols as $col) $q->orWhere($col, $cuenta->id);
                })->count();
                if ($totalBodegas > 0) {
                    $bloqueos[] = [
                        'modulo'   => 'Bodegas',
                        'detalle'  => "{$totalBodegas} bodega(s) usan esta cuenta.",
                        'critico'  => false,
                    ];
                }
            }
        }

        return $bloqueos;
    }
}
