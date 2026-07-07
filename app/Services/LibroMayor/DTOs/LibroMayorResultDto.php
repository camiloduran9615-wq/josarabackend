<?php

declare(strict_types=1);

namespace App\Services\LibroMayor\DTOs;

/**
 * Resultado completo del Libro Mayor para una cuenta + filtros.
 *
 * Corresponde a la estructura de respuesta GET /libro-mayor/{cuenta} del §4.1
 * del Arquitecto. El Resource HTTP solo serializa este DTO a JSend.
 */
final readonly class LibroMayorResultDto
{
    /**
     * @param  array{
     *      id: string,
     *      codigo: string,
     *      nombre: string,
     *      naturaleza: string,
     *      clase: ?int,
     *  }  $cuenta
     * @param  array{
     *      periodo_id: ?string,
     *      tercero_id: ?string,
     *      centro_costo_id: ?string,
     *      sucursal_id: ?string,
     *      desde: ?string,
     *      hasta: ?string,
     *  }  $filtros
     * @param  array{
     *      saldo_inicial_debito: string,
     *      saldo_inicial_credito: string,
     *      movimiento_debito: string,
     *      movimiento_credito: string,
     *      saldo_final_debito: string,
     *      saldo_final_credito: string,
     *  }  $saldos
     * @param  list<MovimientoLibroMayorDto>  $movimientos
     * @param  array{total: int, page: int, per_page: int, last_page: int}  $paginacion
     */
    public function __construct(
        public array $cuenta,
        public array $filtros,
        public array $saldos,
        public array $movimientos,
        public array $paginacion,
    ) {}
}
