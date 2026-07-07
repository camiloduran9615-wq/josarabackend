<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Tenant\LiquidacionNomina;
use App\Models\Tenant\NominaDian;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Genera el XML de Nómina Electrónica según el Anexo Técnico 1.0 DIAN.
 *
 * El XML resultante es un IndividualPayroll UBL 2.1 con:
 *   - Datos del empleador (tenant)
 *   - Datos del trabajador (empleado)
 *   - Período de liquidación
 *   - Devengados y deducciones
 *   - CUNE (Código Único Nómina Electrónica) — SHA-384 de campos clave
 *
 * NOTA: Este XML debe firmarse digitalmente (certificado DIAN) antes de enviar.
 * La firma se delega a Factus/n8n en la capa de integración.
 */
class NominaDianXmlService
{
    private const XMLNS = 'dian:gov:co:facturaelectronica:NominaIndividual';
    private const XSD_VERSION = '1.0';

    /**
     * Genera el XML para una liquidación y persiste en nomina_dian.
     * Idempotente: si ya existe el registro, regenera y actualiza el XML.
     */
    public function generar(LiquidacionNomina $liquidacion): NominaDian
    {
        $liquidacion->load(['empleado', 'contrato', 'periodo', 'lineas.concepto']);

        $xml = $this->buildXml($liquidacion);

        return DB::transaction(function () use ($liquidacion, $xml): NominaDian {
            return NominaDian::updateOrCreate(
                ['liquidacion_id' => $liquidacion->id],
                [
                    'xml_generado'     => $xml,
                    'numero_documento' => $this->numeroDocumento($liquidacion),
                    'cune'             => $this->calcularCune($liquidacion),
                    'estado_dian'      => 'pendiente',
                ],
            );
        });
    }

    private function buildXml(LiquidacionNomina $liq): string
    {
        $empleado = $liq->empleado;
        $contrato = $liq->contrato;
        $periodo  = $liq->periodo;

        $devengados        = $liq->lineas->where('tipo', 'devengado');
        $deducciones       = $liq->lineas->where('tipo', 'deduccion');
        $aportesEmpleador  = $liq->lineas->where('tipo', 'aporte_empleador');

        $fechaGen = Carbon::now()->toAtomString();

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<NominaIndividual
  xmlns="dian:gov:co:facturaelectronica:NominaIndividual"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="dian:gov:co:facturaelectronica:NominaIndividual NominaIndividualElectronicaXSD.xsd"
  SchemaVersion="{$this->e(self::XSD_VERSION)}">

  <InformacionGeneral
    Version="V1.0"
    Ambiente="2"
    TipoXML="102"
    CUNE="{$this->e($this->calcularCune($liq))}"
    EncripCUNE="SHA-384"
    FechaGen="{$this->e($fechaGen)}"
    PeriodoNomina="{$this->e($this->mapPeriodoNomina($periodo->tipo))}"
    TipoMoneda="COP"
    FechaIngreso="{$this->e($contrato->fecha_inicio->toDateString())}"
    Sueldo="{$this->e(number_format((float)$contrato->salario_basico, 2, '.', ''))}"
    CodigoTrabajador="{$this->e($empleado->numero_documento)}"
    NumeroSecuenciaXML="{$this->e($this->numeroDocumento($liq))}"
  />

  <Empleador
    NIT="{$this->e(tenant()->data['nit'] ?? '000000000')}"
    RazonSocial="{$this->e(tenant()->data['razon_social'] ?? 'Empresa')}"
    Pais="CO"
    DepartamentoEstado="{$this->e($contrato->departamento ?? '11')}"
    MunicipioCiudad="11001"
    Direccion="{$this->e(tenant()->data['direccion'] ?? 'Sin dirección')}"
  />

  <Trabajador
    TipoTrabajador="{$this->e($this->mapTipoTrabajador($contrato->tipo_trabajador))}"
    SubTipoTrabajador="{$this->e($contrato->subtipo_trabajador ?? '00')}"
    AltoRiesgoPension="{$this->e($contrato->alto_riesgo ? 'true' : 'false')}"
    TipoDocumento="{$this->e($this->mapTipoDocumento($empleado->tipo_documento))}"
    NumeroDocumento="{$this->e($empleado->numero_documento)}"
    PrimerApellido="{$this->e($empleado->primer_apellido)}"
    SegundoApellido="{$this->e($empleado->segundo_apellido ?? '')}"
    PrimerNombre="{$this->e($empleado->primer_nombre)}"
    OtrosNombres="{$this->e($empleado->segundo_nombre ?? '')}"
    LugarTrabajoPais="CO"
    LugarTrabajoDepartamentoEstado="{$this->e($contrato->departamento ?? '11')}"
    LugarTrabajoMunicipioCiudad="11001"
    LugarTrabajoDireccion="{$this->e(tenant()->data['direccion'] ?? '')}"
    SalarioIntegral="false"
    TipoContrato="{$this->e($this->mapTipoContrato($contrato->tipo_contrato))}"
    Sueldo="{$this->e(number_format((float)$contrato->salario_basico, 2, '.', ''))}"
  />

  <Periodo
    FechaIngreso="{$this->e($contrato->fecha_inicio->toDateString())}"
    FechaLiquidacionInicio="{$this->e($periodo->fecha_inicio->toDateString())}"
    FechaLiquidacionFin="{$this->e($periodo->fecha_fin->toDateString())}"
    TiempoLaborado="{$this->e((string) $liq->dias_laborados)}"
    FechaGen="{$this->e(Carbon::now()->toDateString())}"
  />

  <Devengados>
    {$this->buildDevengados($devengados)}
  </Devengados>

  <Deducciones>
    {$this->buildDeducciones($deducciones)}
  </Deducciones>

  <AportesEmpleador>
    {$this->buildAportesEmpleador($aportesEmpleador)}
  </AportesEmpleador>

  <DevengadosTotal>{$this->e(number_format((float)$liq->total_devengado, 2, '.', ''))}</DevengadosTotal>
  <DeduccionesTotal>{$this->e(number_format((float)$liq->total_deduccion, 2, '.', ''))}</DeduccionesTotal>
  <AportesEmpleadorTotal>{$this->e(number_format($this->sumar($aportesEmpleador), 2, '.', ''))}</AportesEmpleadorTotal>
  <ComprobanteTotal>{$this->e(number_format((float)$liq->neto_pagar, 2, '.', ''))}</ComprobanteTotal>

</NominaIndividual>
XML;

        return $xml;
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant\LiquidacionLinea> $lineas */
    private function buildDevengados(mixed $lineas): string
    {
        $basico = $lineas->firstWhere(fn ($l) => $l->concepto?->subtipo === 'basico');
        if ($basico === null) {
            return '';
        }

        $nodo = '<Basico DiasTrabajados="' . $this->e((string)(int)$basico->cantidad) . '" '
            . 'SueldoTrabajado="' . $this->e(number_format((float)$basico->valor_total, 2, '.', '')) . '" />';

        // Auxilio de transporte (sólo si aplica — Ley 15/1959)
        $aux = $lineas->firstWhere(fn ($l) => ($l->concepto?->codigo ?? '') === 'AUX_TRANSPORTE');
        if ($aux !== null) {
            $nodo .= '<AuxilioTransporte Pago="' . $this->money($aux->valor_total) . '" />';
        }

        // Horas extras (agrupadas — diurnas y nocturnas por separado)
        $horasExtras = $lineas->where(fn ($l) => $l->concepto?->subtipo === 'hora_extra');
        if ($horasExtras->count() > 0) {
            $nodo .= '<HorasExtras>';
            foreach ($horasExtras as $he) {
                $codigo = $he->concepto?->codigo ?? '';
                $tag    = $codigo === 'H_EXTRA_NOCT' ? 'HoraExtraOrdinariaNocturna' : 'HoraExtraOrdinariaDiurna';
                $pct    = $codigo === 'H_EXTRA_NOCT' ? '75.00' : '25.00';
                $nodo  .= '<' . $tag . ' Cantidad="' . (int) $he->cantidad . '" '
                    . 'Porcentaje="' . $pct . '" '
                    . 'PagoTotal="' . $this->money($he->valor_total) . '" />';
            }
            $nodo .= '</HorasExtras>';
        }

        // Bonificaciones / comisiones
        foreach ($lineas->where(fn ($l) => $l->concepto?->subtipo === 'bonificacion') as $bonif) {
            $nodo .= '<Bonificaciones><Bonificacion BonificacionNS="' . $this->money($bonif->valor_total) . '" /></Bonificaciones>';
        }
        foreach ($lineas->where(fn ($l) => $l->concepto?->subtipo === 'comision') as $com) {
            $nodo .= '<Comisiones><Comision Comision="' . $this->money($com->valor_total) . '" /></Comisiones>';
        }

        // Provisiones laborales (prima, cesantías, intereses cesantías, vacaciones)
        foreach ($lineas as $linea) {
            $codigo = $linea->concepto?->codigo ?? '';
            $valor  = $this->money($linea->valor_total);
            $nodo .= match ($codigo) {
                'PRIMA'         => '<Primas Cantidad="30" Pago="' . $valor . '" />',
                'CESANTIAS'     => '<Cesantias Pago="' . $valor . '" />',
                'INT_CESANTIAS' => '<CesantiasIntereses Porcentaje="12.00" Pago="' . $valor . '" />',
                'VACACIONES'    => '<Vacaciones><VacacionesComunes Cantidad="0" Pago="' . $valor . '" /></Vacaciones>',
                default         => '',
            };
        }

        return $nodo;
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant\LiquidacionLinea> $lineas */
    private function buildDeducciones(mixed $lineas): string
    {
        $nodo = '';
        $salud   = $lineas->firstWhere(fn ($l) => ($l->concepto?->subtipo ?? '') === 'salud');
        $pension = $lineas->firstWhere(fn ($l) => ($l->concepto?->subtipo ?? '') === 'pension');

        if ($salud !== null) {
            $nodo .= '<Salud Porcentaje="4.00" '
                . 'DeduccionSalud="' . $this->money($salud->valor_total) . '" />';
        }
        if ($pension !== null) {
            $nodo .= '<FondoPension Porcentaje="4.00" '
                . 'DeduccionPension="' . $this->money($pension->valor_total) . '" />';
        }

        foreach ($lineas as $linea) {
            $subtipo = $linea->concepto?->subtipo ?? '';
            $valor   = $this->money($linea->valor_total);
            $nodo .= match ($subtipo) {
                'retefuente' => '<RetencionFuente Deduccion="' . $valor . '" />',
                'embargo'    => '<EmbargosFiscales><EmbargoFiscal Deduccion="' . $valor . '" /></EmbargosFiscales>',
                'libranza'   => '<Libranzas><Libranza Deduccion="' . $valor . '" /></Libranzas>',
                'sindicato'  => '<Sindicatos><Sindicato Porcentaje="1.00" Deduccion="' . $valor . '" /></Sindicatos>',
                default      => '',
            };
        }

        return $nodo;
    }

    /**
     * Sección APORTES EMPLEADOR (Ley 100, Ley 1607). El anexo técnico DIAN
     * Nómina Electrónica 1.0 no exige reportar estos valores como tales
     * porque NO afectan el neto del trabajador, pero los incluimos como
     * extensión informativa para auditoría/conciliación. Si en una versión
     * futura del XSD pasan a ser obligatorios, ya están serializados.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant\LiquidacionLinea> $lineas
     */
    private function buildAportesEmpleador(mixed $lineas): string
    {
        $nodo = '';
        foreach ($lineas as $linea) {
            $codigo = $linea->concepto?->codigo ?? '';
            $valor  = $this->money($linea->valor_total);
            $nodo .= match ($codigo) {
                'EMP_SALUD'   => '<SaludEmpleador Porcentaje="8.50" Aporte="' . $valor . '" />',
                'EMP_PENSION' => '<PensionEmpleador Porcentaje="12.00" Aporte="' . $valor . '" />',
                'EMP_ARL'     => '<ARLEmpleador Aporte="' . $valor . '" />',
                'EMP_CCF'     => '<CajaCompensacion Porcentaje="4.00" Aporte="' . $valor . '" />',
                'EMP_SENA'    => '<SENA Porcentaje="2.00" Aporte="' . $valor . '" />',
                'EMP_ICBF'    => '<ICBF Porcentaje="3.00" Aporte="' . $valor . '" />',
                default       => '',
            };
        }
        return $nodo;
    }

    private function sumar(mixed $lineas): float
    {
        return (float) $lineas->sum(fn ($l) => (float) $l->valor_total);
    }

    private function money(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    private function calcularCune(LiquidacionNomina $liq): string
    {
        // CUNE = SHA-384(NumeroDoc + FechaGen + DevTotal + DedTotal + NetoPagar + NIT_Empleador)
        $cadena = implode('', [
            $this->numeroDocumento($liq),
            Carbon::now()->toDateString(),
            number_format((float) $liq->total_devengado, 2, '.', ''),
            number_format((float) $liq->total_deduccion, 2, '.', ''),
            number_format((float) $liq->neto_pagar, 2, '.', ''),
            tenant()->data['nit'] ?? '000000000',
        ]);

        return hash('sha384', $cadena);
    }

    private function numeroDocumento(LiquidacionNomina $liq): string
    {
        return 'NIE' . ($liq->periodo?->año ?? date('Y'))
            . str_pad((string) ($liq->periodo?->mes ?? date('m')), 2, '0', STR_PAD_LEFT)
            . substr($liq->empleado?->numero_documento ?? '0', 0, 8)
            . substr($liq->id, 0, 4);
    }

    private function mapTipoDocumento(string $tipo): string
    {
        return match (strtoupper($tipo)) {
            'CC'  => '13',
            'CE'  => '22',
            'PA'  => '41',
            'NIT' => '31',
            default => '13',
        };
    }

    private function mapTipoTrabajador(string $tipo): string
    {
        return match ($tipo) {
            'pensionado' => '02',
            'aprendiz'   => '12',
            default      => '01',  // dependiente
        };
    }

    private function mapTipoContrato(string $tipo): string
    {
        return match ($tipo) {
            'fijo'        => '2',
            'obra_labor'  => '3',
            'aprendizaje' => '4',
            default       => '1',  // indefinido
        };
    }

    private function mapPeriodoNomina(string $tipo): string
    {
        return match ($tipo) {
            'quincenal' => '5',
            default     => '6',  // mensual
        };
    }

    private function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
