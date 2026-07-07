<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Resultado completo de Estado de Resultados (P&G) — NIC 1 párr. 82, clasificación por FUNCIÓN.
 *
 * Estructura (por función, NIC 1 párr. 103):
 *   ingresos           → clase 4
 *   costoVentas        → clase 6 + 7
 *   utilidadBruta      = ingresos - costoVentas
 *   gastosOperacionales→ clase 5 (sin financieros y sin impuestos)
 *   utilidadOperacional= utilidadBruta - gastosOperacionales
 *   otrosIngresosEgresos→ ingresos/gastos financieros y otros
 *   utilidadAntesImpuesto= utilidadOperacional ± otrosIngresosEgresos
 *   impuestoRenta      → cuenta 5405xx (gasto impuesto de renta)
 *   utilidadNeta       = utilidadAntesImpuesto - impuestoRenta
 */
final readonly class EstadoResultadosDto
{
    public function __construct(
        public string $desde,
        public string $hasta,
        public ?string $desdeComparativo,
        public ?string $hastaComparativo,
        public string $moneda,
        public string $tenantRazonSocial,
        public string $tenantNit,

        public BloqueEstadoResultadosDto $ingresos,
        public BloqueEstadoResultadosDto $costoVentas,
        public string $utilidadBruta,
        public ?string $utilidadBrutaComparativa,

        public BloqueEstadoResultadosDto $gastosOperacionales,
        public string $utilidadOperacional,
        public ?string $utilidadOperacionalComparativa,

        public BloqueEstadoResultadosDto $otrosIngresosEgresos,
        public string $utilidadAntesImpuesto,
        public ?string $utilidadAntesImpuestoComparativa,

        public BloqueEstadoResultadosDto $impuestoRenta,
        public string $utilidadNeta,
        public ?string $utilidadNetaComparativa,

        public string $generadoAt,
        public int $tiempoMs,
        public bool $cached,
    ) {}
}
