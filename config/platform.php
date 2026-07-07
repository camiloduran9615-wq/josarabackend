<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Branding de Plataforma — Single Source of Truth
|--------------------------------------------------------------------------
| Esta es la ÚNICA fuente de verdad del branding en el backend. Ningún otro
| archivo debe escribir el nombre de la plataforma de forma literal: todo se
| consume vía config('platform.*') o el endpoint público GET /api/platform.
|
| Todos los valores se resuelven desde variables de entorno (.env) con un
| fallback por defecto a la marca oficial JOSARA CLOUD. Para renombrar la
| plataforma basta con cambiar el .env — NO se toca el código.
|
| Ver BRANDING_GUIDE.md para el detalle de cada campo y el flujo White Label.
*/

return [
    // Identidad principal
    'name'        => env('APP_NAME', 'JOSARA CLOUD'),
    'short_name'  => env('APP_SHORT_NAME', 'JOSARA'),
    'tagline'     => env('APP_TAGLINE', 'Confianza y precisión en cada número.'),
    'description' => env('APP_DESCRIPTION', 'ERP Contable SaaS Multiempresa'),
    'company'     => env('APP_COMPANY', env('APP_NAME', 'JOSARA CLOUD')),

    // Metadatos
    'version'       => env('APP_VERSION', '1.0.0'),
    'website'       => env('APP_WEBSITE'),
    'frontend_url'  => env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost')),
    'support_email' => env('APP_SUPPORT_EMAIL'),

    // Activos visuales oficiales (rutas públicas desde backend/public).
    // White Label futuro: estos paths pueden sobrescribirse por env o por tenant.
    'logo_light' => env('APP_LOGO_LIGHT', '/logo_claro.png'),
    'logo_dark'  => env('APP_LOGO_DARK', '/logo_oscuro.png'),
    'favicon'    => env('APP_FAVICON', '/favicon.ico'),

    // Paleta de marca (reflejan las variables CSS actuales del frontend).
    // White Label futuro: estos valores alimentarán las CSS custom properties.
    'primary_color'   => env('APP_PRIMARY_COLOR', '#0D1B2A'),
    'secondary_color' => env('APP_SECONDARY_COLOR', '#D4AF37'),

    // Legal
    'copyright' => env('APP_COPYRIGHT', '© '.env('APP_NAME', 'JOSARA CLOUD')),
];
