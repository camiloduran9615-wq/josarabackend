<?php

declare(strict_types=1);

namespace App\Services\Saldos\DTOs;

use App\Support\Bc;

/**
 * Resultado de una corrida de `ReconciliarSaldosService`.
 *
 * Si `anomalias` está vacío y `delta_total <= 0.01` → reconciliación limpia.
 * En caso contrario, el job dispara `SaldosInconsistenciaDetectada` con este DTO.
 */
final readonly class ReconciliacionResultDto
{
    /**
     * @param  list<AnomaliaSaldoDto>  $anomalias
     */
    public function __construct(
        public string $tenantId,
        public ?string $periodoId,
        public int $filasComparadas,
        public int $anomaliasCount,
        public string $deltaDebitoTotal,
        public string $deltaCreditoTotal,
        public array $anomalias,
        public \DateTimeImmutable $iniciadoAt,
        public \DateTimeImmutable $finalizadoAt,
    ) {}

    public function estaLimpio(): bool
    {
        return $this->anomaliasCount === 0
            && Bc::lte($this->deltaDebitoTotal,  Bc::TOLERANCIA_COP)
            && Bc::lte($this->deltaCreditoTotal, Bc::TOLERANCIA_COP);
    }

    public function duracionSegundos(): int
    {
        return $this->finalizadoAt->getTimestamp() - $this->iniciadoAt->getTimestamp();
    }
}
