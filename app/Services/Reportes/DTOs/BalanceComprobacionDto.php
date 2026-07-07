<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Resultado completo del Balance de Comprobación para un periodo.
 *
 * `filas` puede filtrarse por nivel (nivel=1: solo clases, nivel=2: grupos,
 * nivel=4+: cuentas hoja — por defecto solo cuentas con movimiento).
 */
final readonly class BalanceComprobacionDto
{
    /**
     * @param  list<FilaBalanceComprobacionDto>  $filas
     */
    public function __construct(
        public string $periodoCodigo,
        public string $periodoNombre,
        public string $desde,
        public ?string $hasta,
        public string $moneda,
        public string $tenantRazonSocial,
        public string $tenantNit,
        public int $nivel,

        public array $filas,
        public ValidacionBalanceComprobacionDto $validacion,

        public string $generadoAt,
        public int $tiempoMs,
        public bool $cached,
    ) {}
}
