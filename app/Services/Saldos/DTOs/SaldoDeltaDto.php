<?php

declare(strict_types=1);

namespace App\Services\Saldos\DTOs;

use App\Support\Bc;

/**
 * DTO inmutable que representa el delta a aplicar sobre una fila de `cuenta_saldos`.
 *
 * Emitido por `ActualizarSaldosListener` al procesar las líneas de un asiento aprobado.
 * Consumido por el UPSERT atómico (ON CONFLICT) en `BackfillSaldosService` y
 * `ReversarSaldosListener`.
 *
 * Las cantidades son strings (bcmath-friendly) para preservar precisión DECIMAL(18,4).
 */
final readonly class SaldoDeltaDto
{
    public function __construct(
        public string $cuentaContableId,
        public string $periodoId,
        public ?string $terceroId,
        public ?string $centroCostoId,
        public ?string $sucursalId,
        public string $deltaDebito,     // siempre >= 0 string DECIMAL(18,4)
        public string $deltaCredito,    // siempre >= 0 string DECIMAL(18,4)
    ) {
        if (! Bc::gte0($this->deltaDebito)) {
            throw new \InvalidArgumentException('deltaDebito no puede ser negativo.');
        }
        if (! Bc::gte0($this->deltaCredito)) {
            throw new \InvalidArgumentException('deltaCredito no puede ser negativo.');
        }
    }

    /** Crea el delta inverso (para anulación/reverso). */
    public function inverso(): self
    {
        return new self(
            cuentaContableId: $this->cuentaContableId,
            periodoId:        $this->periodoId,
            terceroId:        $this->terceroId,
            centroCostoId:    $this->centroCostoId,
            sucursalId:       $this->sucursalId,
            deltaDebito:      $this->deltaCredito,   // intercambiar D ↔ C
            deltaCredito:     $this->deltaDebito,
        );
    }

    /**
     * Clave de agrupación canónica (para batchear deltas de líneas que apuntan a la misma fila).
     */
    public function claveGrupo(): string
    {
        return implode('|', [
            $this->cuentaContableId,
            $this->periodoId,
            $this->terceroId     ?? '_',
            $this->centroCostoId ?? '_',
            $this->sucursalId    ?? '_',
        ]);
    }
}
