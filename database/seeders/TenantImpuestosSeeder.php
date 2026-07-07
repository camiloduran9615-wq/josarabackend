<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Impuesto;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Catálogo de impuestos del sistema para Colombia 2026.
 *
 * Ejecutar DESPUÉS de TenantPucSeeder (depende de subcuentas 236505, 236605,
 * 240805, 240810). Todas las subcuentas — las cuentas padre (2365/2366/2408)
 * no aceptan movimientos, ver invariante ImpuestosCuentasIntegridadTest.
 * Idempotente: updateOrCreate sobre (codigo, vigencia_desde).
 *
 * Todos los registros son sistema=true — solo el super-admin puede modificarlos.
 *
 * Fuentes:
 *   IVA:        Estatuto Tributario Arts. 468, 468-1, 477, 481
 *   ReteFuente: ET Arts. 383, 392, 395-401 + Decreto 2418/2013
 *   ReteIVA:    ET Art. 437-1
 */
final class TenantImpuestosSeeder extends Seeder
{
    private const VIGENCIA = '2026-01-01';

    public function run(): void
    {
        $cuentas = $this->resolverCuentas();

        $registros = [
            ...$this->iva($cuentas),
            ...$this->retefuente($cuentas),
            ...$this->reteiva($cuentas),
        ];

        $sembrados = 0;
        foreach ($registros as $data) {
            Impuesto::query()->updateOrCreate(
                [
                    'codigo'         => $data['codigo'],
                    'vigencia_desde' => $data['vigencia_desde'],
                ],
                $data,
            );
            $sembrados++;
        }

        $this->command->info(sprintf('Impuestos sembrados: %d registros (sistema=true).', $sembrados));
    }

    // ── IVA ──────────────────────────────────────────────────────────────────

    /**
     * Convención de cuentas para IVA:
     *   - cuenta_contable_id       = 240805 "IVA Generado en Ventas" (lado ventas)
     *   - cuenta_contrapartida_id  = 240810 "IVA Descontable en Compras" (lado compras)
     *
     * Quien postee la factura/compra elige la cuenta según la dirección del
     * documento. Ambas son subcuentas (acepta_movimientos=true). Antes apuntaban
     * a 2408 (cuenta padre, no acepta movimientos) — bug latente corregido.
     *
     * @param  array{236505: string, 236605: string, 240805: string, 240810: string}  $cuentas
     * @return list<array<string,mixed>>
     */
    private function iva(array $cuentas): array
    {
        return [
            [
                'tipo'                    => 'iva',
                'codigo'                  => 'IVA-19',
                'codigo_dian_ubl'         => '01',
                'concepto_dian'           => '01',
                'nombre'                  => 'IVA General 19%',
                'tarifa_porcentaje'       => '19.0000',
                'base_minima_uvt'         => null,
                'aplica_compras'          => true,
                'aplica_ventas'           => true,
                'cuenta_contable_id'      => $cuentas['240805'],
                'cuenta_contrapartida_id' => $cuentas['240810'],
                'vigencia_desde'          => '2017-01-01',
                'vigencia_hasta'          => null,
                'activa'                  => true,
                'sistema'                 => true,
                'descripcion'             => 'IVA general 19%. ET Art. 468. Ley 1819/2016.',
            ],
            [
                'tipo'                    => 'iva',
                'codigo'                  => 'IVA-5',
                'codigo_dian_ubl'         => '02',
                'concepto_dian'           => '02',
                'nombre'                  => 'IVA Diferencial 5%',
                'tarifa_porcentaje'       => '5.0000',
                'base_minima_uvt'         => null,
                'aplica_compras'          => true,
                'aplica_ventas'           => true,
                'cuenta_contable_id'      => $cuentas['240805'],
                'cuenta_contrapartida_id' => $cuentas['240810'],
                'vigencia_desde'          => '2017-01-01',
                'vigencia_hasta'          => null,
                'activa'                  => true,
                'sistema'                 => true,
                'descripcion'             => 'IVA diferencial 5%. ET Art. 468-1. Aplica a bienes de primera necesidad y ciertos servicios.',
            ],
            [
                'tipo'                    => 'iva',
                'codigo'                  => 'IVA-0',
                'codigo_dian_ubl'         => '04',
                'concepto_dian'           => '04',
                'nombre'                  => 'IVA Exento 0% (Canasta Familiar)',
                'tarifa_porcentaje'       => '0.0000',
                'base_minima_uvt'         => null,
                'aplica_compras'          => false,
                'aplica_ventas'           => true,
                'cuenta_contable_id'      => $cuentas['240805'],
                'cuenta_contrapartida_id' => null,
                'vigencia_desde'          => '2017-01-01',
                'vigencia_hasta'          => null,
                'activa'                  => true,
                'sistema'                 => true,
                'descripcion'             => 'IVA exento 0%. ET Art. 477 y 481. Genera saldo a favor de IVA descontable.',
            ],
            [
                'tipo'                    => 'iva',
                'codigo'                  => 'IVA-EXCLUIDO',
                'codigo_dian_ubl'         => null,
                'concepto_dian'           => null,
                'nombre'                  => 'IVA Excluido (sin gravamen)',
                'tarifa_porcentaje'       => '0.0000',
                'base_minima_uvt'         => null,
                'aplica_compras'          => true,
                'aplica_ventas'           => true,
                'cuenta_contable_id'      => $cuentas['240805'],
                'cuenta_contrapartida_id' => null,
                'vigencia_desde'          => '2017-01-01',
                'vigencia_hasta'          => null,
                'activa'                  => true,
                'sistema'                 => true,
                'descripcion'             => 'Bienes y servicios excluidos del IVA (ET Art. 424 y concordantes). '
                                            . 'Distinto a exento: el excluido NO genera derecho a IVA descontable. '
                                            . 'Tarifa 0% — no produce movimiento contable; la cuenta queda referenciada '
                                            . 'solo para uniformidad de catálogo y trazabilidad en líneas.',
            ],
        ];
    }

    // ── RETEFUENTE ────────────────────────────────────────────────────────────

    /**
     * 10 actividades de ReteFuente más frecuentes en PyMEs colombianas.
     *
     * @param  array{236505: string, 236605: string, 240805: string, 240810: string}  $cuentas
     * @return list<array<string,mixed>>
     */
    private function retefuente(array $cuentas): array
    {
        $c2365 = $cuentas['236505'];
        $v     = self::VIGENCIA;

        return [
            // 1. Honorarios — personas no declarantes de renta
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-HONORARIOS-10',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '027',
                'nombre'             => 'ReteFuente Honorarios y Comisiones 10% (no declarante)',
                'tarifa_porcentaje'  => '10.0000',
                'base_minima_uvt'    => null,
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Honorarios y comisiones a personas naturales no declarantes. ET Art. 392. Tarifa 10%.',
            ],
            // 2. Honorarios — personas declarantes de renta
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-HONORARIOS-11',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '027',
                'nombre'             => 'ReteFuente Honorarios y Comisiones 11% (declarante)',
                'tarifa_porcentaje'  => '11.0000',
                'base_minima_uvt'    => null,
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Honorarios a personas naturales declarantes de renta. ET Art. 392. Tarifa 11%.',
            ],
            // 3. Servicios generales — no declarantes
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-SERVICIOS-4',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '012',
                'nombre'             => 'ReteFuente Servicios Generales 4% (no declarante)',
                'tarifa_porcentaje'  => '4.0000',
                'base_minima_uvt'    => '4.00',
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Servicios en general a personas naturales no declarantes. ET Art. 392. Base mínima 4 UVT/mes.',
            ],
            // 4. Servicios — contratistas y subcontratistas (declarantes)
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-SERVICIOS-6',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '012',
                'nombre'             => 'ReteFuente Servicios Contratistas 6% (declarante)',
                'tarifa_porcentaje'  => '6.0000',
                'base_minima_uvt'    => '4.00',
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Contratos de servicios a personas naturales declarantes de renta. ET Art. 392. Base mínima 4 UVT/mes.',
            ],
            // 5. Compras generales — no declarantes
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-COMPRAS-35',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '001',
                'nombre'             => 'ReteFuente Compras 3.5% (no declarante)',
                'tarifa_porcentaje'  => '3.5000',
                'base_minima_uvt'    => '27.00',
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Compras a personas naturales no declarantes. ET Art. 401. Base mínima 27 UVT acumulados en el mes.',
            ],
            // 6. Compras generales — declarantes
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-COMPRAS-25',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '001',
                'nombre'             => 'ReteFuente Compras 2.5% (declarante)',
                'tarifa_porcentaje'  => '2.5000',
                'base_minima_uvt'    => '27.00',
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Compras a personas jurídicas y naturales declarantes de renta. ET Art. 401. Base mínima 27 UVT.',
            ],
            // 7. Arrendamientos de bienes inmuebles
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-ARRENDAMIENTOS-35',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '003',
                'nombre'             => 'ReteFuente Arrendamientos Inmuebles 3.5%',
                'tarifa_porcentaje'  => '3.5000',
                'base_minima_uvt'    => null,
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Arrendamiento de bienes raíces a personas naturales. ET Art. 401. Tarifa 3.5%.',
            ],
            // 8. Rendimientos financieros e intereses
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-INTERESES-7',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '007',
                'nombre'             => 'ReteFuente Rendimientos Financieros 7%',
                'tarifa_porcentaje'  => '7.0000',
                'base_minima_uvt'    => null,
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Rendimientos financieros e intereses a personas naturales. ET Art. 395. Tarifa 7%.',
            ],
            // 9. Servicios de aseo y vigilancia
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-ASEO-VIGILANCIA-2',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '012',
                'nombre'             => 'ReteFuente Aseo y Vigilancia 2%',
                'tarifa_porcentaje'  => '2.0000',
                'base_minima_uvt'    => null,
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Servicios de aseo y vigilancia. ET Art. 462-1. Tarifa 2% sobre el AIU (Administración, Imprevistos, Utilidad).',
            ],
            // 10. Transporte de carga nacional
            [
                'tipo'               => 'retefuente',
                'codigo'             => 'RF-TRANSPORTE-CARGA-35',
                'codigo_dian_ubl'    => '06',
                'concepto_dian'      => '015',
                'nombre'             => 'ReteFuente Transporte de Carga Nacional 3.5%',
                'tarifa_porcentaje'  => '3.5000',
                'base_minima_uvt'    => null,
                'aplica_compras'     => true,
                'aplica_ventas'      => false,
                'cuenta_contable_id' => $c2365,
                'vigencia_desde'     => $v,
                'vigencia_hasta'     => null,
                'activa'             => true,
                'sistema'            => true,
                'descripcion'        => 'Transporte nacional de carga. Decreto 2418/2013 Art. 5. Tarifa 3.5%.',
            ],
        ];
    }

    // ── RETEIVA ───────────────────────────────────────────────────────────────

    /**
     * @param  array{236505: string, 236605: string, 240805: string, 240810: string}  $cuentas
     * @return list<array<string,mixed>>
     */
    private function reteiva(array $cuentas): array
    {
        return [
            [
                'tipo'                    => 'reteiva',
                'codigo'                  => 'RETEIVA-15',
                'codigo_dian_ubl'         => '05',
                'concepto_dian'           => '042',
                'nombre'                  => 'ReteIVA 15% del IVA',
                'tarifa_porcentaje'       => '15.0000',
                'base_minima_uvt'         => null,
                'aplica_compras'          => true,
                'aplica_ventas'           => false,
                'cuenta_contable_id'      => $cuentas['236605'],
                'cuenta_contrapartida_id' => $cuentas['240810'],
                'vigencia_desde'          => self::VIGENCIA,
                'vigencia_hasta'          => null,
                'activa'                  => true,
                'sistema'                 => true,
                'descripcion'             => 'Retención de IVA: 15% del valor del IVA facturado. ET Art. 437-1. Aplica cuando el vendedor es persona natural no responsable del régimen ordinario y el comprador es agente retenedor.',
            ],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resuelve UUIDs de las cuentas PUC requeridas por los impuestos.
     *
     * IVA usa subcuentas 240805 (ventas) y 240810 (compras), no la cuenta padre
     * 2408 — la cuenta padre no acepta movimientos. Mismo principio para
     * ReteFuente (236505) y ReteIVA (236605): se apunta a la subcuenta hoja.
     *
     * @return array{236505: string, 236605: string, 240805: string, 240810: string}
     * @throws RuntimeException si alguna cuenta no existe (PucSeeder no ejecutado)
     */
    private function resolverCuentas(): array
    {
        /**
         * @var array{236505: string, 236605: string, 240805: string, 240810: string} $mapa
         */
        $mapa = ['236505' => '', '236605' => '', '240805' => '', '240810' => ''];

        foreach (array_keys($mapa) as $codigo) {
            $id = CuentaContable::query()->where('codigo', $codigo)->value('id');
            if ($id === null) {
                throw new RuntimeException(
                    "Cuenta PUC {$codigo} no encontrada. Ejecuta TenantPucSeeder primero.",
                );
            }
            $mapa[$codigo] = (string) $id;
        }

        return $mapa;
    }
}
