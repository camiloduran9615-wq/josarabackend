<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DaneMunicipioSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Importa el catálogo completo DIVIPOLA desde un archivo JSON oficial.
 *
 * El JSON debe estar en storage/app/municipios_dane.json con formato:
 *   [
 *     { "codigo": "11001", "nombre": "Bogotá D.C.", "departamento_codigo": "11",
 *       "departamento_nombre": "Bogotá D.C.", "region": "Andina", "es_capital": true },
 *     ...
 *   ]
 *
 * Fuente del JSON oficial DANE:
 *   https://www.dane.gov.co/files/sen/nomenclatura/divipola/
 *
 * Uso:
 *   php artisan municipios:sync
 *   php artisan municipios:sync --truncate   (borra todo y recarga)
 */
class SyncMunicipiosDaneCommand extends Command
{
    protected $signature = 'municipios:sync {--truncate : Borra todos los municipios antes de cargar} {--source= : Sincroniza desde una URL oficial configurada o explícita}';
    protected $description = 'Importa o sincroniza el catálogo DIVIPOLA DANE';

    public function handle(DaneMunicipioSyncService $syncService): int
    {
        if ($this->option('truncate')) {
            if (! $this->confirm('Esto borrará TODOS los municipios. ¿Continuar?')) {
                return Command::SUCCESS;
            }
            \App\Models\MunicipioDane::query()->delete();
            $this->info('Tabla municipios_dane vaciada.');
        }

        if ($this->option('source') || config('services.dane.divipola_url')) {
            try {
                if ($this->option('source')) {
                    config(['services.dane.divipola_url' => (string) $this->option('source')]);
                }
                $result = $syncService->syncFromConfiguredSource();
                $this->info("✓ Procesados: {$result['processed']}");
                $this->info("✓ Insertados: {$result['inserted']}");
                $this->info("✓ Actualizados: {$result['updated']}");
                $this->info("✓ Omitidos: {$result['skipped']}");
                $this->info("✓ Total municipios: {$result['total']}");
                return Command::SUCCESS;
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $path = storage_path('app/municipios_dane.json');

        if (!File::exists($path)) {
            $this->error("Archivo no encontrado: {$path}");
            $this->line('Descarga el JSON oficial desde:');
            $this->line('  https://www.dane.gov.co/files/sen/nomenclatura/divipola/');
            $this->line('Y guárdalo en storage/app/municipios_dane.json');
            return Command::FAILURE;
        }

        $json = json_decode(File::get($path), true);
        if (!is_array($json)) {
            $this->error('El JSON no tiene un formato válido (debe ser un array).');
            return Command::FAILURE;
        }

        $result = $syncService->syncRows($json, $path);
        $this->info("✓ Procesados: {$result['processed']}");
        $this->info("✓ Insertados: {$result['inserted']}");
        $this->info("✓ Actualizados: {$result['updated']}");
        $this->info("✓ Omitidos: {$result['skipped']}");
        $this->info("✓ Total municipios: {$result['total']}");

        return Command::SUCCESS;
    }
}
