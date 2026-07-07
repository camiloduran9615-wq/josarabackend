<?php

namespace App\Console\Commands;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Factura;
use App\Models\Tenant\FacturaItem;
use App\Models\Tenant\FacturaRetencion;
use App\Models\Tenant\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FixVentasYCostoCommand extends Command
{
    protected $signature = 'contable:fix-ventas-costo';
    protected $description = 'Borra facturas en error, genera costo de venta y reclasifica facturas contado';

    public function handle(): int
    {
        DB::transaction(function () {
            $this->info('═══ PASO 1: Eliminar facturas en estado error ═══');
            $facturasError = Factura::where('estado', 'error')->get();
            foreach ($facturasError as $f) {
                $ref = $f->reference_code;
                // Borrar asientos asociados (por descripción que contenga el reference_code o numero_completo)
                $asientos = Asiento::where(function ($q) use ($f, $ref) {
                    $q->where('descripcion', 'LIKE', "%{$ref}%");
                    if ($f->numero_completo) $q->orWhere('descripcion', 'LIKE', "%{$f->numero_completo}%");
                })->get();
                foreach ($asientos as $a) {
                    AsientoLinea::where('asiento_id', $a->id)->delete();
                    $a->forceDelete();
                }
                FacturaItem::where('factura_id', $f->id)->delete();
                FacturaRetencion::where('factura_id', $f->id)->delete();
                $f->forceDelete();
                $this->line("  ✗ Borrada {$ref} (".count($asientos)." asientos)");
            }

            $this->newLine();
            $this->info('═══ PASO 2: Generar Costo de Venta para validadas ═══');
            $cuentaCosto      = CuentaContable::where('codigo', '613505')->firstOrFail();
            $cuentaInventario = CuentaContable::where('codigo', '143005')->firstOrFail();

            $validadas = Factura::where('estado', 'validado')->where('tipo_documento', 'FV')->with('items')->get();
            foreach ($validadas as $f) {
                $costoTotal = 0;
                $detalle = [];
                foreach ($f->items as $item) {
                    // Match por código (preferido) o por nombre como fallback
                    $producto = null;
                    if ($item->codigo_referencia) {
                        $producto = Producto::where('codigo', $item->codigo_referencia)->first();
                    }
                    if (!$producto && $item->nombre) {
                        $producto = Producto::whereRaw('LOWER(nombre) = LOWER(?)', [$item->nombre])
                            ->orderBy('precio_compra', 'asc')
                            ->first();
                    }
                    if (!$producto) {
                        $this->warn("    Item sin producto match: {$item->nombre}");
                        continue;
                    }
                    $costoLinea = (float) $item->cantidad * (float) $producto->precio_compra;
                    $costoTotal += $costoLinea;
                    $detalle[] = "{$item->cantidad} × {$producto->nombre} @ \${$producto->precio_compra} = \${$costoLinea}";
                }

                if ($costoTotal <= 0) {
                    $this->warn("  ⚠ {$f->numero_completo}: sin costo calculado");
                    continue;
                }

                // Crear asiento de costo de venta. forceFill porque estado/numero/approved_at
                // están protegidos del $fillable del modelo Asiento.
                $asiento = new Asiento();
                $asiento->forceFill([
                    'id'              => (string) Str::uuid(),
                    'numero'          => 'CV-' . strtoupper(Str::random(8)),
                    'fecha'           => $f->fecha_emision,
                    'comprobante'     => 'CV',
                    'numero_documento'=> $f->numero_completo,
                    'descripcion'     => "Costo de venta factura {$f->numero_completo}",
                    'estado'          => 'aprobado',
                    'tipo_comprobante'=> 'CV',
                    'tipo_movimiento' => 'normal',
                    'año_fiscal'      => (int) date('Y', strtotime($f->fecha_emision)),
                    'approved_at'     => now(),
                ]);
                $asiento->save();

                AsientoLinea::create([
                    'id'           => (string) Str::uuid(),
                    'asiento_id'   => $asiento->id,
                    'cuenta_id'    => $cuentaCosto->id,
                    'tercero_id'   => $f->tercero_id,
                    'debito'       => $costoTotal,
                    'credito'      => 0,
                    'descripcion_item' => "Costo {$f->numero_completo}",
                ]);
                AsientoLinea::create([
                    'id'           => (string) Str::uuid(),
                    'asiento_id'   => $asiento->id,
                    'cuenta_id'    => $cuentaInventario->id,
                    'tercero_id'   => $f->tercero_id,
                    'debito'       => 0,
                    'credito'      => $costoTotal,
                    'descripcion_item' => "Salida inventario {$f->numero_completo}",
                ]);

                $this->info("  ✓ {$f->numero_completo}: costo \$" . number_format($costoTotal, 0));
                foreach ($detalle as $d) $this->line("      $d");
            }

            $this->newLine();
            $this->info('═══ PASO 3: Reclasificar facturas contado de Clientes a Caja/Bancos ═══');
            $cuentaClientes = CuentaContable::where('codigo', '130505')->firstOrFail();
            $cuentaCaja     = CuentaContable::where('codigo', '110505')->firstOrFail();
            $cuentaBancos   = CuentaContable::where('codigo', '111005')->firstOrFail();

            // Facturas validadas con payment_form='1' (contado)
            $contado = Factura::where('estado', 'validado')
                ->where('tipo_documento', 'FV')
                ->where('payment_form', '1')
                ->get();

            foreach ($contado as $f) {
                $cuentaDestino = $f->payment_method_code === '10' ? $cuentaCaja : $cuentaBancos;
                // Asiento de la factura tiene descripcion que incluye reference_code
                $items = AsientoLinea::whereHas('asiento', function ($q) use ($f) {
                        $q->where('descripcion', 'LIKE', "%{$f->reference_code}%")
                          ->orWhere('descripcion', 'LIKE', "%{$f->numero_completo}%");
                    })
                    ->where('cuenta_id', $cuentaClientes->id)
                    ->where('debito', '>', 0)
                    ->get();
                foreach ($items as $item) {
                    $item->update(['cuenta_id' => $cuentaDestino->id]);
                    $this->info("  ✓ {$f->numero_completo}: DR Clientes \${$item->debito} → DR {$cuentaDestino->codigo} {$cuentaDestino->nombre}");
                }
            }
        });

        $this->newLine();
        $this->info('═══ COMPLETADO ═══');
        return self::SUCCESS;
    }
}
