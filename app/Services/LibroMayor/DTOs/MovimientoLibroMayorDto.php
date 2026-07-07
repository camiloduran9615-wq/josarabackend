<?php

declare(strict_types=1);

namespace App\Services\LibroMayor\DTOs;

/**
 * Una línea de movimiento en el reporte de Libro Mayor.
 *
 * Lleva el saldo acumulado HASTA esta línea (running balance) calculado en orden
 * cronológico (fecha asiento, número asiento).
 */
final readonly class MovimientoLibroMayorDto
{
    public function __construct(
        public string $asientoId,
        public ?string $asientoNumero,            // 'CG-2026-001847', null si borrador (no debería verse en libro mayor)
        public string $fecha,                      // 'YYYY-MM-DD'
        public string $tipoComprobante,
        public ?string $descripcionLinea,
        public ?string $documentoReferencia,
        public ?string $terceroId,
        public ?string $terceroNombre,
        public ?string $terceroIdentificacion,
        public ?string $centroCostoId,
        public ?string $centroCostoCodigo,
        public string $debito,
        public string $credito,
        public string $saldoAcumulado,            // saldo corrido firmado según naturaleza de la cuenta
    ) {}
}
