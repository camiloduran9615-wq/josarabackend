<?php

namespace App\Console\Commands;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\DocumentoIngreso;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Genera asiento contable para un Documento de Ingreso (compra) que quedó
 * sin asiento. Útil cuando el documento se creó antes del fix BUG-040.
 */
class GenerarAsientoIngresoCommand extends Command
{
    protected $signature = 'contable:generar-asiento-ingreso {numero}';
    protected $description = 'Genera asiento contable para un documento de ingreso sin asiento';

    public function handle(): int
    {
        $numero = $this->argument('numero');
        $doc = DocumentoIngreso::with('items')->where('numero', $numero)->first();
        if (!$doc) {
            $this->error("Documento $numero no encontrado.");
            return self::FAILURE;
        }
        if ($doc->asiento_id) {
            $this->warn("El documento ya tiene asiento ID {$doc->asiento_id}.");
            return self::FAILURE;
        }

        // Cuentas según contexto
        $cuentaProveedor   = CuentaContable::where('codigo', '220505')->firstOrFail(); // CxP
        $cuentaIvaDescto   = CuentaContable::where('codigo', '240810')->firstOrFail();
        $cuentaRetefuente  = CuentaContable::where('codigo', '236540')->firstOrFail(); // o 236525 según concepto
        $cuentaBancos      = CuentaContable::where('codigo', '111005')->firstOrFail();
        $cuentaCaja        = CuentaContable::where('codigo', '110505')->firstOrFail();

        DB::transaction(function () use ($doc, $cuentaProveedor, $cuentaIvaDescto, $cuentaRetefuente, $cuentaBancos, $cuentaCaja) {
            // Cuenta de contrapartida (DR) — usa la cuenta de cada ítem
            $asiento = new Asiento();
            $asiento->forceFill([
                'id'                => (string) Str::uuid(),
                'numero'            => 'CO-' . strtoupper(Str::random(8)),
                'fecha'             => $doc->fecha,
                'comprobante'       => 'CO',
                'numero_documento'  => $doc->numero,
                'descripcion'       => "Factura de compra {$doc->numero} — {$doc->concepto}",
                'estado'            => 'aprobado',
                'tipo_comprobante'  => 'CO',
                'tipo_movimiento'   => 'normal',
                'año_fiscal'        => (int) date('Y', strtotime($doc->fecha)),
                'approved_at'       => now(),
                'origen_type'       => DocumentoIngreso::class,
                'origen_id'         => $doc->id,
            ])->save();

            // Líneas DR: cuenta de cada ítem por su valor bruto (sin IVA)
            foreach ($doc->items as $item) {
                if (!$item->cuenta_id) {
                    $this->warn("  Item '{$item->descripcion}' sin cuenta_id, omitido");
                    continue;
                }
                $subtotal = (float) $item->cantidad * (float) $item->precio_unitario;
                $this->crearLinea($asiento->id, $doc->tercero_id, $item->cuenta_id, $subtotal, 0, $item->descripcion ?? 'Item');
            }

            // DR IVA descontable
            if ((float) $doc->valor_iva > 0) {
                $this->crearLinea($asiento->id, $doc->tercero_id, $cuentaIvaDescto->id, (float) $doc->valor_iva, 0, 'IVA descontable');
            }

            // CR Retefuente (si hay)
            if ((float) $doc->valor_retefuente > 0) {
                $this->crearLinea($asiento->id, $doc->tercero_id, $cuentaRetefuente->id, 0, (float) $doc->valor_retefuente, 'Retefuente practicada');
            }

            // CR contrapartida según forma_pago
            $total = (float) $doc->valor_total;
            $cuentaCR = match ($doc->forma_pago) {
                'credito'           => $cuentaProveedor,
                'contado_efectivo'  => $cuentaCaja,
                default             => $cuentaBancos,   // contado_banco o legacy 'contado'
            };
            $this->crearLinea($asiento->id, $doc->tercero_id, $cuentaCR->id, 0, $total, "Pago/CxP {$doc->numero}");

            $doc->update(['asiento_id' => $asiento->id]);
            $this->info("Asiento {$asiento->numero} creado y vinculado a {$doc->numero}.");
            $this->line("  DR cuentas gasto/inventario + DR IVA = {$doc->valor_bruto} + {$doc->valor_iva}");
            $this->line("  CR Retefuente = {$doc->valor_retefuente}");
            $this->line("  CR {$cuentaCR->codigo} {$cuentaCR->nombre} = $total");
        });

        return self::SUCCESS;
    }

    private function crearLinea(string $asientoId, ?string $terceroId, string $cuentaId, float $debito, float $credito, string $descripcion): void
    {
        AsientoLinea::create([
            'id'               => (string) Str::uuid(),
            'asiento_id'       => $asientoId,
            'tercero_id'       => $terceroId,
            'cuenta_id'        => $cuentaId,
            'debito'           => $debito,
            'credito'          => $credito,
            'descripcion_item' => $descripcion,
        ]);
    }
}
