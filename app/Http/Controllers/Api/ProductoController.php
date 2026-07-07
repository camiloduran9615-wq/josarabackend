<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Producto;
use App\Models\Tenant\InventarioMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Producto::with('categoria')->orderBy('nombre')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo' => 'required|unique:productos,codigo',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'unidad_medida' => 'required|string',
            'precio_venta' => 'required|numeric|min:0',
            'precio_compra' => 'required|numeric|min:0',
            'stock_minimo' => 'required|numeric|min:0',
            'categoria_id' => 'nullable|exists:categorias,id',
            'porcentaje_iva' => 'required|numeric|min:0',
            'inicial_stock' => 'nullable|numeric|min:0',
            'inventario_cuenta_id' => 'nullable|exists:cuentas_contables,id',
            'ventas_cuenta_id' => 'nullable|exists:cuentas_contables,id',
            'costos_cuenta_id' => 'nullable|exists:cuentas_contables,id',
        ]);

        return DB::transaction(function () use ($validated) {
            $inicialStock = $validated['inicial_stock'] ?? 0;
            unset($validated['inicial_stock']);
            
            $producto = Producto::create($validated + ['stock_actual' => $inicialStock]);

            if ($inicialStock > 0) {
                InventarioMovimiento::create([
                    'producto_id'     => $producto->id,
                    'sucursal_id'     => request()->user()?->sucursal_id,
                    'tipo'            => 'entrada',
                    'cantidad'        => $inicialStock,
                    'precio_unitario' => $producto->precio_compra,
                    'concepto'        => 'Carga inicial de inventario',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Producto creado correctamente.',
                'data' => $producto
            ], 201);
        });
    }

    public function update(Request $request, $id)
    {
        $producto = Producto::findOrFail($id);

        $validated = $request->validate([
            'codigo' => 'sometimes|required|unique:productos,codigo,' . $id,
            'nombre' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string',
            'unidad_medida' => 'sometimes|required|string',
            'precio_venta' => 'sometimes|required|numeric|min:0',
            'precio_compra' => 'sometimes|required|numeric|min:0',
            'stock_minimo' => 'sometimes|required|numeric|min:0',
            'categoria_id' => 'nullable|exists:categorias,id',
            'porcentaje_iva' => 'sometimes|required|numeric|min:0',
            'inventario_cuenta_id' => 'nullable|exists:cuentas_contables,id',
            'ventas_cuenta_id' => 'nullable|exists:cuentas_contables,id',
            'costos_cuenta_id' => 'nullable|exists:cuentas_contables,id',
            'activo' => 'boolean'
        ]);

        $producto->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado correctamente.',
            'data' => $producto
        ]);
    }

    public function destroy($id)
    {
        $producto = Producto::findOrFail($id);
        $producto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado correctamente.'
        ]);
    }

    /**
     * Registra un movimiento manual de inventario.
     */
    public function registrarMovimiento(Request $request)
    {
        $validated = $request->validate([
            'producto_id'    => 'required|exists:productos,id',
            'tipo'           => 'required|in:entrada,salida,ajuste',
            'cantidad'       => 'required|numeric|gt:0',
            'precio_unitario'=> 'required|numeric|min:0',
            'concepto'       => 'required|string|max:255',
            'sucursal_id'    => 'nullable|exists:sucursales,id',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $producto = Producto::findOrFail($validated['producto_id']);

            // Usar la sucursal del usuario si no se especifica explícitamente
            if (empty($validated['sucursal_id'])) {
                $validated['sucursal_id'] = $request->user()?->sucursal_id;
            }

            $movimiento = InventarioMovimiento::create($validated);

            if ($validated['tipo'] === 'entrada') {
                $producto->increment('stock_actual', $validated['cantidad']);
            } elseif ($validated['tipo'] === 'salida') {
                $producto->decrement('stock_actual', $validated['cantidad']);
            } else { // ajuste
                // En ajustes, la cantidad puede ser positiva (suma) o negativa (resta) en el concepto real,
                // pero aquí asumimos que el usuario dice "ajuste de X" y especifica si suma o resta.
                // Para simplificar, permitiremos al usuario elegir.
                // Pero por ahora, hagamos que sume al stock.
                $producto->increment('stock_actual', $validated['cantidad']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Movimiento registrado correctamente.',
                'stock_nuevo' => $producto->stock_actual
            ]);
        });
    }
}
