<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint público de branding — GET /api/platform.
 *
 * Devuelve ÚNICAMENTE información pública de identidad de la plataforma,
 * leída de config/platform.php (single source of truth). El frontend lo
 * consume al iniciar para renderizar nombre, tagline, logos, etc., y es la
 * base sobre la que se construirá el White Label (branding por tenant).
 *
 * No expone datos sensibles (credenciales, emails internos, rutas de servidor).
 */
class PlatformController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name'            => config('platform.name'),
            'short_name'      => config('platform.short_name'),
            'tagline'         => config('platform.tagline'),
            'description'     => config('platform.description'),
            'version'         => config('platform.version'),
            'logo'            => config('platform.logo_light'),
            'logo_light'      => config('platform.logo_light'),
            'logo_dark'       => config('platform.logo_dark'),
            'favicon'         => config('platform.favicon'),
            'website'         => config('platform.website'),
            'primary_color'   => config('platform.primary_color'),
            'secondary_color' => config('platform.secondary_color'),
            'copyright'       => config('platform.copyright'),
        ]);
    }
}
