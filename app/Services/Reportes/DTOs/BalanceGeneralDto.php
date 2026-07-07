<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Resultado completo de Balance General (Estado de Situación Financiera — NIC 1).
 *
 * Estructura jerárquica:
 *   activo:     Corriente + No Corriente
 *   pasivo:     Corriente + No Corriente
 *   patrimonio: Grupos (capital, reservas, resultado del ejercicio, etc.)
 *
 * Ecuación: activo.total = pasivo.total + patrimonio.total
 * Si no balancea (Δ > 0.01 COP), `ecuacion.balanceado = false` — alerta al contador.
 */
final readonly class BalanceGeneralDto
{
    public function __construct(
        public string $fechaCorte,
        public ?string $fechaComparativo,           // 'YYYY-MM-DD' o null si no se solicitó comparativo
        public string $moneda,                      // 'COP'
        public string $tenantRazonSocial,
        public string $tenantNit,

        public SeccionTotalDto $activo,
        public SeccionTotalDto $pasivo,
        public SeccionTotalDto $patrimonio,

        public EcuacionBalanceDto $ecuacion,

        public string $generadoAt,                  // ISO8601
        public int $tiempoMs,
        public bool $cached,
    ) {}
}
