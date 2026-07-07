<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Tenant\Bodega;
use App\Models\Tenant\InventarioMovimiento;
use App\Models\Tenant\ProductoStockBodega;
use App\Services\Inventario\Exceptions\BodegaInactivaException;
use App\Services\Inventario\Exceptions\StockInsuficienteException;
use Illuminate\Support\Facades\DB;

/**
 * Motor de Costo Promedio Ponderado (CPP) — NIIF 2 / NIC 2.
 *
 * Todas las operaciones se ejecutan dentro de una transacción con
 * SELECT FOR UPDATE para evitar race conditions en entornos concurrentes.
 *
 * Nunca llamar directamente a ProductoStockBodega::update() fuera de este servicio.
 */
final class CostoPromedioService
{
    /**
     * Registra una ENTRADA de inventario y recalcula el CPP.
     *
     * CPP nuevo = (saldo_valor_anterior + valor_entrada) / (saldo_unid_anterior + cantidad)
     *
     * @param  string  $productoId
     * @param  string  $bodegaId
     * @param  float   $cantidad       unidades que ingresan
     * @param  float   $costoUnitario  costo unitario de esta entrada (sin IVA)
     * @param  array{
     *     tipo:              string,
     *     concepto:          string,
     *     tercero_id?:       string|null,
     *     centro_costo_id?:  string|null,
     *     asiento_id?:       string|null,
     *     origen_documento?: string|null,
     * } $meta
     * @return InventarioMovimiento
     */
    public function registrarEntrada(
        string $productoId,
        string $bodegaId,
        float  $cantidad,
        float  $costoUnitario,
        array  $meta = [],
    ): InventarioMovimiento {
        return DB::transaction(function () use ($productoId, $bodegaId, $cantidad, $costoUnitario, $meta): InventarioMovimiento {
            $bodega = Bodega::findOrFail($bodegaId);
            if (! $bodega->activa) {
                throw new BodegaInactivaException($bodegaId);
            }

            // SELECT FOR UPDATE: bloquea la fila hasta que termine la transacción
            $stock = ProductoStockBodega::where('producto_id', $productoId)
                ->where('bodega_id', $bodegaId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = ProductoStockBodega::create([
                    'producto_id'    => $productoId,
                    'bodega_id'      => $bodegaId,
                    'saldo_unidades' => 0,
                    'saldo_valor'    => 0,
                    'costo_promedio' => 0,
                    'version'        => 0,
                ]);
            }

            // ── Calcular nuevo CPP ───────────────────────────────────────────
            $saldoAntUnid  = (float) $stock->saldo_unidades;
            $saldoAntValor = (float) $stock->saldo_valor;

            $nuevasUnid  = $saldoAntUnid + $cantidad;
            $nuevoValor  = $saldoAntValor + ($cantidad * $costoUnitario);
            $nuevoCpp    = $nuevasUnid > 0 ? round($nuevoValor / $nuevasUnid, 4) : $costoUnitario;

            // ── Actualizar stock ─────────────────────────────────────────────
            $stock->update([
                'saldo_unidades'   => $nuevasUnid,
                'saldo_valor'      => round($nuevoValor, 2),
                'costo_promedio'   => $nuevoCpp,
                'ultima_entrada_at'=> now(),
                'version'          => $stock->version + 1,
            ]);

            // Sincronizar campo de conveniencia en productos
            DB::table('productos')
                ->where('id', $productoId)
                ->update([
                    'stock_actual' => DB::raw(
                        "(SELECT COALESCE(SUM(saldo_unidades),0)
                          FROM producto_stock_bodega
                          WHERE producto_id = '{$productoId}')"
                    ),
                    'precio_compra' => $nuevoCpp,
                    'updated_at'   => now(),
                ]);

            // ── Registrar movimiento KARDEX ──────────────────────────────────
            return InventarioMovimiento::create([
                'producto_id'             => $productoId,
                'bodega_id'               => $bodegaId,
                'tipo'                    => $meta['tipo'] ?? 'entrada_compra',
                'cantidad'                => $cantidad,
                'precio_unitario'         => $costoUnitario,
                'costo_unitario'          => $costoUnitario,
                'concepto'                => $meta['concepto'] ?? 'Entrada de inventario',
                'saldo_unidades_despues'  => $nuevasUnid,
                'saldo_valor_despues'     => round($nuevoValor, 2),
                'costo_promedio_despues'  => $nuevoCpp,
                'tercero_id'              => $meta['tercero_id'] ?? null,
                'centro_costo_id'         => $meta['centro_costo_id'] ?? null,
                'asiento_id'              => $meta['asiento_id'] ?? null,
                'documento_ingreso_id'    => $meta['documento_ingreso_id'] ?? null,
                'factura_id'              => $meta['factura_id'] ?? null,
            ]);
        });
    }

    /**
     * Registra una SALIDA al CPP vigente.
     *
     * La salida siempre usa el CPP vigente del momento (no el de la entrada).
     * Si no hay stock suficiente → lanza StockInsuficienteException.
     *
     * @return array{0: InventarioMovimiento, 1: float} [movimiento, costo_cpp_usado]
     */
    public function registrarSalida(
        string $productoId,
        string $bodegaId,
        float  $cantidad,
        array  $meta = [],
    ): array {
        $permitirNegativo = (bool) (DB::table('configuraciones_tenant')
            ->where('clave', 'inventario.permitir_stock_negativo')
            ->value('valor') ?? false);

        return DB::transaction(function () use ($productoId, $bodegaId, $cantidad, $meta, $permitirNegativo): array {
            $bodega = Bodega::findOrFail($bodegaId);
            if (! $bodega->activa) {
                throw new BodegaInactivaException($bodegaId);
            }

            // SELECT FOR UPDATE
            $stock = ProductoStockBodega::where('producto_id', $productoId)
                ->where('bodega_id', $bodegaId)
                ->lockForUpdate()
                ->firstOrFail();

            $saldoAntUnid  = (float) $stock->saldo_unidades;
            $saldoAntValor = (float) $stock->saldo_valor;
            $cppActual     = (float) $stock->costo_promedio;

            // ── Verificar stock disponible ───────────────────────────────────
            if (! $permitirNegativo && $cantidad > $saldoAntUnid) {
                throw new StockInsuficienteException($productoId, $bodegaId, $saldoAntUnid, $cantidad);
            }

            // ── Calcular nuevos saldos ───────────────────────────────────────
            $valorSalida = round($cantidad * $cppActual, 2);
            $nuevasUnid  = $saldoAntUnid - $cantidad;
            $nuevoValor  = max(0, $saldoAntValor - $valorSalida);
            // El CPP no cambia en salidas (solo lo hace en entradas)
            $nuevoCpp    = $nuevasUnid > 0 ? round($nuevoValor / $nuevasUnid, 4) : $cppActual;

            // ── Actualizar stock ─────────────────────────────────────────────
            $stock->update([
                'saldo_unidades'  => $nuevasUnid,
                'saldo_valor'     => $nuevoValor,
                'costo_promedio'  => $nuevoCpp,
                'ultima_salida_at'=> now(),
                'version'         => $stock->version + 1,
            ]);

            DB::table('productos')
                ->where('id', $productoId)
                ->update([
                    'stock_actual' => DB::raw(
                        "(SELECT COALESCE(SUM(saldo_unidades),0)
                          FROM producto_stock_bodega
                          WHERE producto_id = '{$productoId}')"
                    ),
                    'updated_at' => now(),
                ]);

            // ── Registrar movimiento KARDEX ──────────────────────────────────
            $movimiento = InventarioMovimiento::create([
                'producto_id'            => $productoId,
                'bodega_id'              => $bodegaId,
                'tipo'                   => $meta['tipo'] ?? 'salida_venta',
                'cantidad'               => $cantidad,
                'precio_unitario'        => $meta['precio_venta'] ?? $cppActual,
                'costo_unitario'         => $cppActual,
                'concepto'               => $meta['concepto'] ?? 'Salida de inventario',
                'saldo_unidades_despues' => $nuevasUnid,
                'saldo_valor_despues'    => $nuevoValor,
                'costo_promedio_despues' => $nuevoCpp,
                'tercero_id'             => $meta['tercero_id'] ?? null,
                'centro_costo_id'        => $meta['centro_costo_id'] ?? null,
                'asiento_id'             => $meta['asiento_id'] ?? null,
                'factura_id'             => $meta['factura_id'] ?? null,
            ]);

            return [$movimiento, $cppActual];
        });
    }

    /**
     * Reversa un movimiento previo (para anulaciones de documentos).
     * Registra un movimiento inverso al tipo original.
     */
    public function reversarMovimiento(
        InventarioMovimiento $movimientoOriginal,
        string               $concepto = 'Reversión por anulación',
    ): InventarioMovimiento {
        $signo = str_starts_with($movimientoOriginal->tipo, 'entrada') ? '-' : '+';

        if ($signo === '-') {
            // Era una entrada → hacemos una salida
            [$movimiento] = $this->registrarSalida(
                productoId: $movimientoOriginal->producto_id,
                bodegaId:   $movimientoOriginal->bodega_id,
                cantidad:   (float) $movimientoOriginal->cantidad,
                meta: [
                    'tipo'     => 'devolucion_compra',
                    'concepto' => $concepto,
                ],
            );
        } else {
            // Era una salida → hacemos una entrada al CPP del movimiento original
            $movimiento = $this->registrarEntrada(
                productoId:    $movimientoOriginal->producto_id,
                bodegaId:      $movimientoOriginal->bodega_id,
                cantidad:      (float) $movimientoOriginal->cantidad,
                costoUnitario: (float) $movimientoOriginal->costo_unitario,
                meta: [
                    'tipo'     => 'devolucion_venta',
                    'concepto' => $concepto,
                ],
            );
        }

        return $movimiento;
    }
}
