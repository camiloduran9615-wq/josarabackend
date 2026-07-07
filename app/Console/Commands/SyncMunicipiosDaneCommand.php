<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MunicipioDane;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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
    protected $signature = 'municipios:sync {--truncate : Borra todos los municipios antes de cargar}';
    protected $description = 'Importa el catálogo DIVIPOLA desde storage/app/municipios_dane.json';

    public function handle(): int
    {
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

        if ($this->option('truncate')) {
            if (!$this->confirm('Esto borrará TODOS los municipios. ¿Continuar?')) {
                return Command::SUCCESS;
            }
            MunicipioDane::query()->delete();
            $this->info('Tabla municipios_dane vaciada.');
        }

        $bar = $this->output->createProgressBar(count($json));
        $bar->start();

        $insertados = 0;
        $actualizados = 0;
        foreach ($json as $row) {
            if (!isset($row['codigo'], $row['nombre'], $row['departamento_codigo'])) {
                continue;
            }
            $municipio = MunicipioDane::query()->updateOrCreate(
                ['codigo' => $row['codigo']],
                [
                    'nombre'              => $row['nombre'],
                    'departamento_codigo' => $row['departamento_codigo'],
                    'departamento_nombre' => $row['departamento_nombre'] ?? '',
                    'region'              => $row['region'] ?? null,
                    'es_capital'          => $row['es_capital'] ?? false,
                ],
            );
            $municipio->wasRecentlyCreated ? $insertados++ : $actualizados++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Insertados: {$insertados}");
        $this->info("✓ Actualizados: {$actualizados}");
        $this->info("✓ Total municipios: " . MunicipioDane::query()->count());

        return Command::SUCCESS;
    }
}
