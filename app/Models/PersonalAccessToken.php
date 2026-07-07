<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Modelo de token extendido que asegura que Sanctum use la conexión
 * de DB activa del tenant (no la conexión central).
 *
 * Esto implementa el aislamiento cross-tenant a nivel de token:
 * Un token válido en Empresa A jamás funcionará en Empresa B
 * porque cada empresa tiene su propia tabla personal_access_tokens.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // Laravel usará la conexión activa establecida por stancl/tenancy,
    // que es la DB del tenant activo. No necesita override de $connection.
    // El aislamiento viene del bootstrapper DatabaseTenancyBootstrapper.
}
