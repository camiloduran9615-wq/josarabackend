<?php

namespace App\Console\Commands;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\CuentaContable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixHistoricalEntriesCommand extends Command
{
    protected $signature = 'contable:fix-historico {--dry : solo muestra cambios sin aplicar}';
    protected $description = 'Reemplaza el saldo de 110505 Caja por 111005 Bancos en asientos de compra contado y recibos no-efectivo';

    public function handle(): int
    {
        $caja   = CuentaContable::where('codigo', '110505')->firstOrFail();
        $bancos = CuentaContable::where('codigo', '111005')->firstOrFail();
        $dry    = $this->option('dry');

        $this->info("Caja: {$caja->id} | Bancos: {$bancos->id}");
        $this->info('Modo: ' . ($dry ? 'DRY RUN (sin cambios)' : 'APLICAR'));
        $this->newLine();

        $cambios = 0;

        // 1. Asientos de compra (CO-*) con CR a Caja: cambiar a Bancos
        $itemsCompra = AsientoLinea::whereHas('asiento', function ($q) {
                $q->where('estado', 'aprobado')->where('descripcion', 'LIKE', 'Factura de compra%');
            })
            ->where('cuenta_id', $caja->id)
            ->where('credito', '>', 0)
            ->with('asiento')
            ->get();

        foreach ($itemsCompra as $item) {
            $this->line("  [Compra] {$item->asiento->numero}: CR Caja \${$item->credito} → CR Bancos");
            if (!$dry) {
                $item->update(['cuenta_id' => $bancos->id]);
            }
            $cambios++;
        }

        // 2. Recibos de caja con forma_pago != 'efectivo' y DR a Caja: cambiar a Bancos
        // Detectamos por descripción ya que el item no tiene la forma_pago.
        // Mejor: cruzar con recibos_caja por número.
        $recibosNoEfectivo = DB::table('recibos_caja')
            ->where('forma_pago', '!=', 'efectivo')
            ->where('estado', 'registrado')
            ->select('id', 'numero', 'forma_pago')
            ->get();

        foreach ($recibosNoEfectivo as $rc) {
            // Buscar el asiento del recibo
            $items = AsientoLinea::whereHas('asiento', function ($q) use ($rc) {
                    $q->where('descripcion', 'LIKE', "%{$rc->numero}%")->where('estado', 'aprobado');
                })
                ->where('cuenta_id', $caja->id)
                ->where('debito', '>', 0)
                ->with('asiento')
                ->get();

            foreach ($items as $item) {
                $this->line("  [Recibo {$rc->forma_pago}] {$item->asiento->numero}: DR Caja \${$item->debito} → DR Bancos");
                if (!$dry) {
                    $item->update(['cuenta_id' => $bancos->id]);
                }
                $cambios++;
            }
        }

        $this->newLine();
        $this->info("Total cambios: $cambios");
        return self::SUCCESS;
    }
}
