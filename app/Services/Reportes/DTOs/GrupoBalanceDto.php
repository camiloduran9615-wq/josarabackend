<?php

declare(strict_types=1);

namespace App\Services\Reportes\DTOs;

/**
 * Grupo PUC (2 dígitos) con sus cuentas hoja y total agregado.
 *
 * Ejemplo:
 *   codigo: '11', nombre: 'Disponible',
 *   total: '30000000.00',
 *   cuentas: [
 *     { codigo: '11050501', nombre: 'Caja General', saldo: '500000.00' },
 *     { codigo: '11100501', nombre: 'Banco Bancolombia', saldo: '29500000.00' },
 *   ]
 */
final readonly class GrupoBalanceDto
{
    /**
     * @param  list<CuentaSaldoBalanceDto>  $cuentas
     */
    public function __construct(
        public string $codigo,
        public string $nombre,
        public string $total,
        public ?string $totalAnterior,
        public array $cuentas,
    ) {}
}
