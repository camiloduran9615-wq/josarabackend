<?php

declare(strict_types=1);

namespace App\Services\Saldos\DTOs;

/**
 * Una anomalía individual detectada por `ReconciliarSaldosService`:
 * una fila de `cuenta_saldos` cuyos movimientos materializados NO coinciden
 * con la suma real de `asiento_lineas` aprobadas.
 */
final readonly class AnomaliaSaldoDto
{
    public function __construct(
        public string $cuentaSaldoId,
        public string $cuentaCodigo,
        public string $periodoId,
        public ?string $terceroId,
        public string $movimientoDebitoMaterializado,
        public string $movimientoCreditoMaterializado,
        public string $movimientoDebitoReal,
        public string $movimientoCreditoReal,
        public string $deltaDebito,    // ABS(real - materializado)
        public string $deltaCredito,
    ) {}
}
