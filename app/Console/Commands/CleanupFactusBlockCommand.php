<?php

namespace App\Console\Commands;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\Factura;
use App\Models\Tenant\FacturaItem;
use App\Models\Tenant\FacturaRetencion;
use App\Models\Tenant\Resolucion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupFactusBlockCommand extends Command
{
    protected $signature = 'factus:cleanup-block';
    protected $description = 'Borra facturas pendientes, desactiva resoluciones Factus y crea resolución LOCAL';

    public function handle(): int
    {
        $this->info('=== ANTES ===');
        $pendientes = Factura::whereIn('estado', ['borrador', 'error'])->get();
        $this->info('Facturas pendientes: ' . $pendientes->count());
        foreach ($pendientes as $f) {
            $this->line("  - {$f->id} | " . ($f->numero ?: '-') . " | {$f->estado} | {$f->total}");
        }

        $this->newLine();
        $this->info('--- Resoluciones ---');
        foreach (Resolucion::all() as $r) {
            $this->line("  - {$r->id} | factus_id=" . ($r->factus_id ?? 'NULL') . " | {$r->nombre} | activa=" . ($r->activa ? '1' : '0'));
        }

        $this->newLine();
        $this->info('=== LIMPIEZA ===');
        DB::transaction(function () use ($pendientes) {
            foreach ($pendientes as $f) {
                FacturaItem::where('factura_id', $f->id)->delete();
                FacturaRetencion::where('factura_id', $f->id)->delete();
                if ($f->descripcion) {
                    $borrados = Asiento::where('descripcion', 'LIKE', '%' . substr($f->descripcion, 0, 30) . '%')->delete();
                    $this->line("  asientos borrados: $borrados");
                }
                $f->delete();
                $this->line("  factura borrada: {$f->id}");
            }
        });

        $desactivadas = Resolucion::whereNotNull('factus_id')->update(['activa' => false]);
        $this->info("Resoluciones Factus desactivadas: $desactivadas");

        $local = Resolucion::firstOrCreate(
            ['nombre' => 'Resolución LOCAL — Sin DIAN'],
            [
                'prefijo'           => 'LOC',
                'desde'             => 1,
                'hasta'             => 999999,
                'numero_resolucion' => 'LOCAL-001',
                'fecha_inicio'      => now()->toDateString(),
                'fecha_fin'         => now()->addYears(5)->toDateString(),
                'activa'            => true,
                'factus_id'         => null,
            ]
        );
        if (!$local->activa) {
            $local->update(['activa' => true]);
        }
        $this->info("Resolución LOCAL lista: {$local->id}");

        $this->newLine();
        $this->info('=== DESPUÉS ===');
        $this->line('Facturas pendientes: ' . Factura::whereIn('estado', ['borrador', 'error'])->count());
        $this->line('Resoluciones activas:');
        foreach (Resolucion::where('activa', true)->get() as $r) {
            $this->line("  - {$r->nombre} | factus_id=" . ($r->factus_id ?? 'NULL'));
        }

        return self::SUCCESS;
    }
}
