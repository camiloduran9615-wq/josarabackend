<?php

declare(strict_types=1);

namespace App\Services\Contabilizacion;

use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\ParametrizacionContable;

/**
 * Repositorio que resuelve claves canónicas → cuenta contable.
 * Usado por ContabilizadorService para construir asientos derivados
 * sin acoplarse al PUC concreto del tenant.
 */
class ParametrizacionRepository
{
    /** @var array<string, CuentaContable> Cache en memoria por request. */
    private array $cache = [];

    /**
     * Devuelve la cuenta para una clave canónica.
     * Lanza ParametrizacionFaltanteException si no está configurada.
     */
    public function cuenta(string $clave): CuentaContable
    {
        if (isset($this->cache[$clave])) {
            return $this->cache[$clave];
        }

        $param = ParametrizacionContable::query()
            ->where('clave', $clave)
            ->where('activo', true)
            ->first();

        if ($param === null) {
            throw new ParametrizacionFaltanteException(
                "Falta configurar la cuenta contable para la clave '{$clave}'. "
                ."Configúrala en /api/v1/{tenant}/parametrizacion-contable."
            );
        }

        $cuenta = CuentaContable::query()->find($param->cuenta_contable_id);
        if ($cuenta === null) {
            throw new ParametrizacionFaltanteException(
                "La parametrización '{$clave}' apunta a una cuenta inexistente."
            );
        }

        return $this->cache[$clave] = $cuenta;
    }

    /** Limpia el cache (útil en tests). */
    public function flush(): void
    {
        $this->cache = [];
    }
}
