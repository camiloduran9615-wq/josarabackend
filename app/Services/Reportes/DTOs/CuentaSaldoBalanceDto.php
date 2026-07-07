<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Una cuenta hoja con su saldo en el reporte de Balance General o P&G.
 *
 * `saldo` ya viene firmado según naturaleza para presentación al usuario:
 *   - Activo / Gasto / Costo : positivo si excede crédito
 *   - Pasivo / Patrimonio / Ingreso : positivo si excede débito
 */
final readonly class CuentaSaldoBalanceDto
{
    public function __construct(
        public string $codigo,
        public string $nombre,
        public int $clase,
        public string $naturaleza,                 // 'debito' | 'credito'
        public string $saldo,                      // string DECIMAL(18,4) firmado para presentación
        public ?string $saldoAnterior = null,      // comparativo año/periodo anterior
    ) {}
}
