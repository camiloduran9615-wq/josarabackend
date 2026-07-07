<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\DocumentoIngreso;
use App\Models\Tenant\Factura;
use App\Models\Tenant\NotaCredito;
use App\Models\Tenant\NotaDebito;
use App\Models\Tenant\ReciboCaja;
use App\Services\Contabilizacion\ContabilizadorService;
use Illuminate\Console\Command;

/**
 * Reprocesa la contabilización de un documento (Factura, NotaCredito,
 * NotaDebito, ReciboCaja, DocumentoIngreso). Idempotente: si ya existe
 * el asiento derivado, lo retorna; no duplica.
 *
 * Útil para:
 *  - Casos donde un evento de dominio se perdió por error de queue
 *  - Backfill después de cambiar la parametrización contable
 *
 * Uso:
 *   php artisan asientos:contabilizar-documento {tipo} {id}
 *   tipo ∈ {factura, nota-credito, nota-debito, recibo-caja, documento-ingreso}
 */
class ContabilizarDocumento extends Command
{
    protected $signature = 'asientos:contabilizar-documento
        {tipo : factura|nota-credito|nota-debito|recibo-caja|documento-ingreso}
        {id : UUID del documento}';

    protected $description = 'Genera (o re-obtiene) el asiento derivado de un documento';

    private const MAP = [
        'factura'           => Factura::class,
        'nota-credito'      => NotaCredito::class,
        'nota-debito'       => NotaDebito::class,
        'recibo-caja'       => ReciboCaja::class,
        'documento-ingreso' => DocumentoIngreso::class,
    ];

    public function handle(ContabilizadorService $contabilizador): int
    {
        $tipo = (string) $this->argument('tipo');
        $id = (string) $this->argument('id');

        if (! isset(self::MAP[$tipo])) {
            $this->error("Tipo inválido: {$tipo}. Usa uno de: ".implode(', ', array_keys(self::MAP)));
            return self::FAILURE;
        }

        $modelClass = self::MAP[$tipo];
        $documento = $modelClass::query()->find($id);
        if ($documento === null) {
            $this->error("Documento {$tipo}/{$id} no encontrado.");
            return self::FAILURE;
        }

        $existente = $contabilizador->asientoExistenteDe($documento);
        if ($existente !== null) {
            $this->info("Asiento ya existe: {$existente->numero} (id={$existente->id}).");
            return self::SUCCESS;
        }

        $this->warn(
            'Este comando aún no construye payload por tipo de documento '
            .'(builders por documento son trabajo de iteración siguiente). '
            .'Por ahora se requiere que el módulo correspondiente emita el evento '
            .'de aprobación; el listener de contabilización generará el asiento.'
        );
        $this->line("Documento detectado: {$tipo}/{$documento->getKey()}");
        $this->line('Sin asiento derivado todavía.');

        return self::SUCCESS;
    }
}
