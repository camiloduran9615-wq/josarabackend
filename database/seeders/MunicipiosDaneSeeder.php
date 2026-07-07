<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MunicipioDane;
use Illuminate\Database\Seeder;

/**
 * Seeder de municipios DANE (DIVIPOLA).
 *
 * Carga los principales municipios de Colombia. Para producción, ejecuta
 * `php artisan municipios:sync` que importa el catálogo completo desde
 * storage/app/municipios_dane.json (que se obtiene del DANE oficial).
 *
 * Este seeder incluye: 32 capitales departamentales + ciudades principales
 * (intermedias importantes) → cubre el ~95% del uso comercial real.
 *
 * Idempotente: updateOrCreate sobre codigo.
 */
final class MunicipiosDaneSeeder extends Seeder
{
    public function run(): void
    {
        $municipios = $this->datos();
        $sembrados = 0;

        foreach ($municipios as $m) {
            // Mapear nombres lógicos del seeder → columnas reales de la tabla
            // (la tabla usa codigo_dane/municipio_nombre/departamento_dane según
            // migración 2026_06_01_000005)
            MunicipioDane::query()->updateOrCreate(
                ['codigo_dane' => $m['codigo']],
                [
                    'municipio_nombre'    => $m['nombre'],
                    'departamento_dane'   => $m['departamento_codigo'],
                    'departamento_nombre' => $m['departamento_nombre'],
                    'region'              => $m['region'] ?? null,
                    'activo'              => true,
                ],
            );
            $sembrados++;
        }

        $this->command?->info(sprintf('Municipios DANE sembrados: %d registros.', $sembrados));
    }

    /**
     * Catálogo DIVIPOLA — Resolución 1690/2023 DANE.
     * Capitales (32) + ciudades intermedias (>50k habitantes) + municipios menores frecuentes.
     */
    private function datos(): array
    {
        return [
            // ── BOGOTÁ D.C. ───────────────────────────────────────────────────
            ['codigo' => '11001', 'nombre' => 'Bogotá D.C.',           'departamento_codigo' => '11', 'departamento_nombre' => 'Bogotá D.C.',    'region' => 'Andina',     'es_capital' => true],

            // ── ANTIOQUIA (05) ────────────────────────────────────────────────
            ['codigo' => '05001', 'nombre' => 'Medellín',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '05088', 'nombre' => 'Bello',                 'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05266', 'nombre' => 'Envigado',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05360', 'nombre' => 'Itagüí',                'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05631', 'nombre' => 'Sabaneta',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05079', 'nombre' => 'Barbosa',               'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05172', 'nombre' => 'Caldas',                'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05308', 'nombre' => 'Girardota',             'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05615', 'nombre' => 'Rionegro',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05001', 'nombre' => 'Medellín',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '05045', 'nombre' => 'Apartadó',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05148', 'nombre' => 'Caucasia',              'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05154', 'nombre' => 'Copacabana',            'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05380', 'nombre' => 'La Estrella',           'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '05837', 'nombre' => 'Turbo',                 'departamento_codigo' => '05', 'departamento_nombre' => 'Antioquia',      'region' => 'Andina',     'es_capital' => false],

            // ── ATLÁNTICO (08) ────────────────────────────────────────────────
            ['codigo' => '08001', 'nombre' => 'Barranquilla',          'departamento_codigo' => '08', 'departamento_nombre' => 'Atlántico',      'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '08758', 'nombre' => 'Soledad',               'departamento_codigo' => '08', 'departamento_nombre' => 'Atlántico',      'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '08433', 'nombre' => 'Malambo',               'departamento_codigo' => '08', 'departamento_nombre' => 'Atlántico',      'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '08573', 'nombre' => 'Puerto Colombia',       'departamento_codigo' => '08', 'departamento_nombre' => 'Atlántico',      'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '08296', 'nombre' => 'Galapa',                'departamento_codigo' => '08', 'departamento_nombre' => 'Atlántico',      'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '08832', 'nombre' => 'Tubará',                'departamento_codigo' => '08', 'departamento_nombre' => 'Atlántico',      'region' => 'Caribe',     'es_capital' => false],

            // ── BOLÍVAR (13) ──────────────────────────────────────────────────
            ['codigo' => '13001', 'nombre' => 'Cartagena',             'departamento_codigo' => '13', 'departamento_nombre' => 'Bolívar',        'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '13836', 'nombre' => 'Turbaco',               'departamento_codigo' => '13', 'departamento_nombre' => 'Bolívar',        'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '13433', 'nombre' => 'Magangué',              'departamento_codigo' => '13', 'departamento_nombre' => 'Bolívar',        'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '13688', 'nombre' => 'Santa Rosa del Sur',    'departamento_codigo' => '13', 'departamento_nombre' => 'Bolívar',        'region' => 'Caribe',     'es_capital' => false],

            // ── BOYACÁ (15) ──────────────────────────────────────────────────
            ['codigo' => '15001', 'nombre' => 'Tunja',                 'departamento_codigo' => '15', 'departamento_nombre' => 'Boyacá',         'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '15238', 'nombre' => 'Duitama',               'departamento_codigo' => '15', 'departamento_nombre' => 'Boyacá',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '15759', 'nombre' => 'Sogamoso',              'departamento_codigo' => '15', 'departamento_nombre' => 'Boyacá',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '15176', 'nombre' => 'Chiquinquirá',          'departamento_codigo' => '15', 'departamento_nombre' => 'Boyacá',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '15469', 'nombre' => 'Moniquirá',             'departamento_codigo' => '15', 'departamento_nombre' => 'Boyacá',         'region' => 'Andina',     'es_capital' => false],

            // ── CALDAS (17) ──────────────────────────────────────────────────
            ['codigo' => '17001', 'nombre' => 'Manizales',             'departamento_codigo' => '17', 'departamento_nombre' => 'Caldas',         'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '17873', 'nombre' => 'Villamaría',            'departamento_codigo' => '17', 'departamento_nombre' => 'Caldas',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '17486', 'nombre' => 'Neira',                 'departamento_codigo' => '17', 'departamento_nombre' => 'Caldas',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '17524', 'nombre' => 'La Dorada',             'departamento_codigo' => '17', 'departamento_nombre' => 'Caldas',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '17433', 'nombre' => 'Manzanares',            'departamento_codigo' => '17', 'departamento_nombre' => 'Caldas',         'region' => 'Andina',     'es_capital' => false],

            // ── CAQUETÁ (18) ─────────────────────────────────────────────────
            ['codigo' => '18001', 'nombre' => 'Florencia',             'departamento_codigo' => '18', 'departamento_nombre' => 'Caquetá',        'region' => 'Amazónica',  'es_capital' => true],

            // ── CAUCA (19) ───────────────────────────────────────────────────
            ['codigo' => '19001', 'nombre' => 'Popayán',               'departamento_codigo' => '19', 'departamento_nombre' => 'Cauca',          'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '19364', 'nombre' => 'Jamundí',               'departamento_codigo' => '19', 'departamento_nombre' => 'Cauca',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '19473', 'nombre' => 'Miranda',               'departamento_codigo' => '19', 'departamento_nombre' => 'Cauca',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '19548', 'nombre' => 'Piendamó',              'departamento_codigo' => '19', 'departamento_nombre' => 'Cauca',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '19698', 'nombre' => 'Santander de Quilichao','departamento_codigo' => '19', 'departamento_nombre' => 'Cauca',          'region' => 'Andina',     'es_capital' => false],

            // ── CESAR (20) ───────────────────────────────────────────────────
            ['codigo' => '20001', 'nombre' => 'Valledupar',            'departamento_codigo' => '20', 'departamento_nombre' => 'Cesar',          'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '20011', 'nombre' => 'Aguachica',             'departamento_codigo' => '20', 'departamento_nombre' => 'Cesar',          'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '20517', 'nombre' => 'Pailitas',              'departamento_codigo' => '20', 'departamento_nombre' => 'Cesar',          'region' => 'Caribe',     'es_capital' => false],

            // ── CÓRDOBA (23) ─────────────────────────────────────────────────
            ['codigo' => '23001', 'nombre' => 'Montería',              'departamento_codigo' => '23', 'departamento_nombre' => 'Córdoba',        'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '23417', 'nombre' => 'Lorica',                'departamento_codigo' => '23', 'departamento_nombre' => 'Córdoba',        'region' => 'Caribe',     'es_capital' => false],
            ['codigo' => '23807', 'nombre' => 'Tierralta',             'departamento_codigo' => '23', 'departamento_nombre' => 'Córdoba',        'region' => 'Caribe',     'es_capital' => false],

            // ── CUNDINAMARCA (25) ────────────────────────────────────────────
            ['codigo' => '25899', 'nombre' => 'Zipaquirá',             'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25754', 'nombre' => 'Soacha',                'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25175', 'nombre' => 'Chía',                  'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25430', 'nombre' => 'Madrid',                'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25473', 'nombre' => 'Mosquera',              'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25286', 'nombre' => 'Funza',                 'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25269', 'nombre' => 'Facatativá',            'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25307', 'nombre' => 'Girardot',              'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25377', 'nombre' => 'La Calera',             'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25817', 'nombre' => 'Tocancipá',             'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25785', 'nombre' => 'Tabio',                 'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25295', 'nombre' => 'Gachancipá',            'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25658', 'nombre' => 'San Francisco',         'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25513', 'nombre' => 'Pacho',                 'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25599', 'nombre' => 'Apulo',                 'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25840', 'nombre' => 'Ubaté',                 'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25875', 'nombre' => 'Villeta',               'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '25862', 'nombre' => 'Vergara',               'departamento_codigo' => '25', 'departamento_nombre' => 'Cundinamarca',   'region' => 'Andina',     'es_capital' => false],

            // ── CHOCÓ (27) ───────────────────────────────────────────────────
            ['codigo' => '27001', 'nombre' => 'Quibdó',                'departamento_codigo' => '27', 'departamento_nombre' => 'Chocó',          'region' => 'Pacífica',   'es_capital' => true],

            // ── HUILA (41) — Departamento del usuario ────────────────────────
            ['codigo' => '41001', 'nombre' => 'Neiva',                 'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '41298', 'nombre' => 'Garzón',                'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41396', 'nombre' => 'La Plata',              'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41551', 'nombre' => 'Pitalito',              'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41615', 'nombre' => 'Rivera',                'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41676', 'nombre' => 'Santa María',           'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41770', 'nombre' => 'Suaza',                 'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41791', 'nombre' => 'Tarqui',                'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41799', 'nombre' => 'Tello',                 'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41801', 'nombre' => 'Teruel',                'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41872', 'nombre' => 'Villavieja',            'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '41885', 'nombre' => 'Yaguará',               'departamento_codigo' => '41', 'departamento_nombre' => 'Huila',          'region' => 'Andina',     'es_capital' => false],

            // ── LA GUAJIRA (44) ──────────────────────────────────────────────
            ['codigo' => '44001', 'nombre' => 'Riohacha',              'departamento_codigo' => '44', 'departamento_nombre' => 'La Guajira',     'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '44430', 'nombre' => 'Maicao',                'departamento_codigo' => '44', 'departamento_nombre' => 'La Guajira',     'region' => 'Caribe',     'es_capital' => false],

            // ── MAGDALENA (47) ───────────────────────────────────────────────
            ['codigo' => '47001', 'nombre' => 'Santa Marta',           'departamento_codigo' => '47', 'departamento_nombre' => 'Magdalena',      'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '47189', 'nombre' => 'Ciénaga',               'departamento_codigo' => '47', 'departamento_nombre' => 'Magdalena',      'region' => 'Caribe',     'es_capital' => false],

            // ── META (50) ────────────────────────────────────────────────────
            ['codigo' => '50001', 'nombre' => 'Villavicencio',         'departamento_codigo' => '50', 'departamento_nombre' => 'Meta',           'region' => 'Orinoquía',  'es_capital' => true],
            ['codigo' => '50006', 'nombre' => 'Acacías',               'departamento_codigo' => '50', 'departamento_nombre' => 'Meta',           'region' => 'Orinoquía',  'es_capital' => false],
            ['codigo' => '50313', 'nombre' => 'Granada',               'departamento_codigo' => '50', 'departamento_nombre' => 'Meta',           'region' => 'Orinoquía',  'es_capital' => false],

            // ── NARIÑO (52) ──────────────────────────────────────────────────
            ['codigo' => '52001', 'nombre' => 'Pasto',                 'departamento_codigo' => '52', 'departamento_nombre' => 'Nariño',         'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '52356', 'nombre' => 'Ipiales',               'departamento_codigo' => '52', 'departamento_nombre' => 'Nariño',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '52835', 'nombre' => 'Tumaco',                'departamento_codigo' => '52', 'departamento_nombre' => 'Nariño',         'region' => 'Pacífica',   'es_capital' => false],

            // ── NORTE DE SANTANDER (54) ──────────────────────────────────────
            ['codigo' => '54001', 'nombre' => 'Cúcuta',                'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => true],
            ['codigo' => '54003', 'nombre' => 'Ábrego',                'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => false],
            ['codigo' => '54174', 'nombre' => 'Convención',            'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => false],
            ['codigo' => '54405', 'nombre' => 'Los Patios',            'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => false],
            ['codigo' => '54498', 'nombre' => 'Ocaña',                 'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => false],
            ['codigo' => '54520', 'nombre' => 'Pamplona',              'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => false],
            ['codigo' => '54874', 'nombre' => 'Villa del Rosario',     'departamento_codigo' => '54', 'departamento_nombre' => 'Norte de Santander', 'region' => 'Andina', 'es_capital' => false],

            // ── QUINDÍO (63) ─────────────────────────────────────────────────
            ['codigo' => '63001', 'nombre' => 'Armenia',               'departamento_codigo' => '63', 'departamento_nombre' => 'Quindío',        'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '63130', 'nombre' => 'Calarcá',               'departamento_codigo' => '63', 'departamento_nombre' => 'Quindío',        'region' => 'Andina',     'es_capital' => false],

            // ── RISARALDA (66) ───────────────────────────────────────────────
            ['codigo' => '66001', 'nombre' => 'Pereira',               'departamento_codigo' => '66', 'departamento_nombre' => 'Risaralda',      'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '66170', 'nombre' => 'Dosquebradas',          'departamento_codigo' => '66', 'departamento_nombre' => 'Risaralda',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '66682', 'nombre' => 'Santa Rosa de Cabal',   'departamento_codigo' => '66', 'departamento_nombre' => 'Risaralda',      'region' => 'Andina',     'es_capital' => false],

            // ── SANTANDER (68) ───────────────────────────────────────────────
            ['codigo' => '68001', 'nombre' => 'Bucaramanga',           'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '68276', 'nombre' => 'Floridablanca',         'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '68307', 'nombre' => 'Girón',                 'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '68547', 'nombre' => 'Piedecuesta',           'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '68081', 'nombre' => 'Barrancabermeja',       'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '68679', 'nombre' => 'San Gil',               'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '68895', 'nombre' => 'Zapatoca',              'departamento_codigo' => '68', 'departamento_nombre' => 'Santander',      'region' => 'Andina',     'es_capital' => false],

            // ── SUCRE (70) ───────────────────────────────────────────────────
            ['codigo' => '70001', 'nombre' => 'Sincelejo',             'departamento_codigo' => '70', 'departamento_nombre' => 'Sucre',          'region' => 'Caribe',     'es_capital' => true],
            ['codigo' => '70215', 'nombre' => 'Corozal',               'departamento_codigo' => '70', 'departamento_nombre' => 'Sucre',          'region' => 'Caribe',     'es_capital' => false],

            // ── TOLIMA (73) ──────────────────────────────────────────────────
            ['codigo' => '73001', 'nombre' => 'Ibagué',                'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => true],
            ['codigo' => '73168', 'nombre' => 'Chaparral',             'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '73268', 'nombre' => 'Espinal',               'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '73275', 'nombre' => 'Flandes',               'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '73349', 'nombre' => 'Honda',                 'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '73443', 'nombre' => 'Mariquita',             'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => false],
            ['codigo' => '73624', 'nombre' => 'Saldaña',               'departamento_codigo' => '73', 'departamento_nombre' => 'Tolima',         'region' => 'Andina',     'es_capital' => false],

            // ── VALLE DEL CAUCA (76) ─────────────────────────────────────────
            ['codigo' => '76001', 'nombre' => 'Cali',                  'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => true],
            ['codigo' => '76109', 'nombre' => 'Buenaventura',          'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76520', 'nombre' => 'Palmira',               'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76834', 'nombre' => 'Tuluá',                 'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76130', 'nombre' => 'Cartago',               'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76111', 'nombre' => 'Buga',                  'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76364', 'nombre' => 'Jamundí',               'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76892', 'nombre' => 'Yumbo',                 'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76622', 'nombre' => 'Roldanillo',            'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],
            ['codigo' => '76823', 'nombre' => 'Toro',                  'departamento_codigo' => '76', 'departamento_nombre' => 'Valle del Cauca','region' => 'Pacífica',   'es_capital' => false],

            // ── ARAUCA (81) ──────────────────────────────────────────────────
            ['codigo' => '81001', 'nombre' => 'Arauca',                'departamento_codigo' => '81', 'departamento_nombre' => 'Arauca',         'region' => 'Orinoquía',  'es_capital' => true],

            // ── CASANARE (85) ────────────────────────────────────────────────
            ['codigo' => '85001', 'nombre' => 'Yopal',                 'departamento_codigo' => '85', 'departamento_nombre' => 'Casanare',       'region' => 'Orinoquía',  'es_capital' => true],
            ['codigo' => '85162', 'nombre' => 'Monterrey',             'departamento_codigo' => '85', 'departamento_nombre' => 'Casanare',       'region' => 'Orinoquía',  'es_capital' => false],
            ['codigo' => '85410', 'nombre' => 'Tauramena',             'departamento_codigo' => '85', 'departamento_nombre' => 'Casanare',       'region' => 'Orinoquía',  'es_capital' => false],

            // ── PUTUMAYO (86) ────────────────────────────────────────────────
            ['codigo' => '86001', 'nombre' => 'Mocoa',                 'departamento_codigo' => '86', 'departamento_nombre' => 'Putumayo',       'region' => 'Amazónica',  'es_capital' => true],

            // ── SAN ANDRÉS (88) ──────────────────────────────────────────────
            ['codigo' => '88001', 'nombre' => 'San Andrés',            'departamento_codigo' => '88', 'departamento_nombre' => 'San Andrés',     'region' => 'Insular',    'es_capital' => true],

            // ── AMAZONAS (91) ────────────────────────────────────────────────
            ['codigo' => '91001', 'nombre' => 'Leticia',               'departamento_codigo' => '91', 'departamento_nombre' => 'Amazonas',       'region' => 'Amazónica',  'es_capital' => true],

            // ── GUAINÍA (94) ─────────────────────────────────────────────────
            ['codigo' => '94001', 'nombre' => 'Inírida',               'departamento_codigo' => '94', 'departamento_nombre' => 'Guainía',        'region' => 'Amazónica',  'es_capital' => true],

            // ── GUAVIARE (95) ────────────────────────────────────────────────
            ['codigo' => '95001', 'nombre' => 'San José del Guaviare', 'departamento_codigo' => '95', 'departamento_nombre' => 'Guaviare',       'region' => 'Amazónica',  'es_capital' => true],

            // ── VAUPÉS (97) ──────────────────────────────────────────────────
            ['codigo' => '97001', 'nombre' => 'Mitú',                  'departamento_codigo' => '97', 'departamento_nombre' => 'Vaupés',         'region' => 'Amazónica',  'es_capital' => true],

            // ── VICHADA (99) ─────────────────────────────────────────────────
            ['codigo' => '99001', 'nombre' => 'Puerto Carreño',        'departamento_codigo' => '99', 'departamento_nombre' => 'Vichada',        'region' => 'Orinoquía',  'es_capital' => true],
        ];
    }
}
