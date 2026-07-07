<?php

declare(strict_types=1);

namespace App\Services\Inventario\Exceptions;

use RuntimeException;

class StockInsuficienteException extends RuntimeException
{
    public function __construct(
        public readonly string $productoId,
        public readonly string $bodegaId,
        public readonly float  $disponible,
        public readonly float  $solicitado,
    ) {
        parent::__construct(
            "Stock insuficiente en bodega {$bodegaId} para producto {$productoId}. " .
            "Disponible: {$disponible} — Solicitado: {$solicitado}."
        );
    }
}
