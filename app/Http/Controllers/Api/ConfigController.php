<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FactusIntegrationException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Config;
use App\Services\FactusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ConfigController extends Controller
{
    /** Claves de texto plano (nunca incluir company_logo aquí — se maneja aparte). */
    private const TEXT_KEYS = [
        'company_name',
        'company_nit',
        'company_address',
        'company_city',
        'company_phone',
    ];

    /** Factus — claves visibles (no sensibles). */
    private const FACTUS_PUBLIC_KEYS = [
        'factus_base_url',
        'factus_client_id',
        'factus_username',
        'factus_mode',
    ];

    /** Factus — claves sensibles (encriptadas en BD, enmascaradas al leer). */
    private const FACTUS_SECRET_KEYS = [
        'factus_client_secret',
        'factus_password',
    ];

    /** Enmascara un secreto: muestra los últimos 4 caracteres, el resto •. */
    private function maskSecret(?string $value): ?string
    {
        if (! $value || strlen($value) < 5) {
            return $value ? '••••' : null;
        }

        return str_repeat('•', max(4, strlen($value) - 4)).substr($value, -4);
    }

    /**
     * Construye la URL pública del logo del tenant actual.
     * Usa una ruta dedicada que sirve el archivo desde el disco tenant-aware,
     * en lugar de Storage::url() que apunta al symlink global y falla bajo
     * FilesystemTenancyBootstrapper.
     */
    private function logoUrl(string $tenantId): string
    {
        return rtrim(config('app.url'), '/')."/api/v1/{$tenantId}/logo";
    }

    /**
     * GET /{tenant}/configs
     * Devuelve configuraciones de texto + URL del logo (si existe).
     */
    public function index(): JsonResponse
    {
        $allKeys = [...self::TEXT_KEYS, 'company_logo'];
        $configs = Config::whereIn('key', $allKeys)->get()->keyBy('key');

        $data = [];
        foreach (self::TEXT_KEYS as $key) {
            $data[$key] = $configs->get($key)?->value ?? '';
        }
        // El logo puede ser null (sin logo) o una URL absoluta
        $data['company_logo'] = $configs->get('company_logo')?->value ?: null;

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /{tenant}/logo  (ruta PÚBLICA — sin sanctum)
     * Sirve el archivo del logo desde el disco 'public' que ya está
     * tenant-isolated por FilesystemTenancyBootstrapper. Stream directo,
     * sin cache (cachebusting se hace en el frontend con ?v=).
     */
    public function viewLogo(): StreamedResponse|Response
    {
        $tenantId = tenant('id');

        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
            $path = "logos/{$tenantId}/logo.{$ext}";
            if (Storage::disk('public')->exists($path)) {
                $mime = $ext === 'svg' ? 'image/svg+xml'
                      : ($ext === 'jpg' ? 'image/jpeg' : "image/{$ext}");

                return Storage::disk('public')->response($path, "logo.{$ext}", [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'no-cache, must-revalidate',
                ]);
            }
        }

        return response('Logo no encontrado', 404);
    }

    /**
     * POST /{tenant}/configs
     * Actualiza solo los campos de texto (no el logo).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->only(self::TEXT_KEYS);

        foreach ($data as $key => $value) {
            Config::set($key, $value);
        }

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada correctamente.',
        ]);
    }

    /**
     * Patrones que indican contenido activo/ejecutable dentro de un SVG
     * (FIX C-3, HANDOFF.md / QA_TEST_REPORT.md — confirmado explotable en
     * vivo: se subió un SVG con <script> y quedó servido públicamente sin
     * sanear, con el script intacto). No es un parser XML completo, pero
     * cubre los 3 vectores reales de XSS en SVG: <script>, atributos de
     * evento (onload, onclick, ...) y esquemas javascript:.
     */
    private const SVG_DANGEROUS_PATTERNS = [
        '/<\s*script\b/i',
        '/\bon[a-z]+\s*=/i',
        '/javascript\s*:/i',
        '/<\s*foreignObject\b/i',
    ];

    /**
     * POST /{tenant}/configs/logo
     * Sube o reemplaza el logo de la empresa.
     * Acepta: PNG, JPG/JPEG, WebP, SVG · Máx. 2 MB.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $tenantId = tenant('id');
        $file = $request->file('logo');

        // FIX C-3: si el archivo es SVG, rechazar contenido activo/ejecutable
        // antes de guardarlo. Se mantiene el soporte de SVG (no se elimina la
        // funcionalidad) porque el riesgo real está en el contenido, no en el
        // formato en sí.
        if ($file->getMimeType() === 'image/svg+xml') {
            $contenido = (string) file_get_contents($file->getRealPath());
            foreach (self::SVG_DANGEROUS_PATTERNS as $patron) {
                if (preg_match($patron, $contenido) === 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo SVG contiene elementos no permitidos (scripts o manejadores de eventos). Sube una imagen sin contenido activo.',
                    ], 422);
                }
            }
        }

        // Determinar extensión a partir del MIME real (evita spoofing de extensión)
        $mimeMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        $ext = $mimeMap[$file->getMimeType()]
            ?? strtolower($file->getClientOriginalExtension())
            ?? 'png';

        // Borrar cualquier logo anterior (todas las extensiones posibles)
        foreach (array_values($mimeMap) as $oldExt) {
            Storage::disk('public')->delete("logos/{$tenantId}/logo.{$oldExt}");
        }

        // Guardar el nuevo archivo
        $file->storeAs("logos/{$tenantId}", "logo.{$ext}", 'public');
        $url = $this->logoUrl($tenantId);

        Config::set('company_logo', $url);

        return response()->json([
            'success' => true,
            'data' => ['company_logo' => $url],
        ]);
    }

    /**
     * DELETE /{tenant}/configs/logo
     * Elimina el logo del almacenamiento y borra la configuración.
     */
    public function deleteLogo(): JsonResponse
    {
        $tenantId = tenant('id');

        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
            Storage::disk('public')->delete("logos/{$tenantId}/logo.{$ext}");
        }

        Config::set('company_logo', null);

        return response()->json([
            'success' => true,
            'data' => ['company_logo' => null],
        ]);
    }

    /**
     * GET /{tenant}/configs/factus
     * Devuelve las claves de Factus del tenant. Los secretos van enmascarados.
     */
    public function showFactus(): JsonResponse
    {
        $allKeys = [...self::FACTUS_PUBLIC_KEYS, ...self::FACTUS_SECRET_KEYS];
        $configs = Config::whereIn('key', $allKeys)->get()->keyBy('key');

        $data = [];
        foreach (self::FACTUS_PUBLIC_KEYS as $key) {
            $data[$key] = $configs->get($key)?->value ?? '';
        }
        foreach (self::FACTUS_SECRET_KEYS as $key) {
            $val = $configs->get($key)?->value;
            try {
                $decrypted = $val ? Crypt::decryptString($val) : null;
            } catch (\Throwable) {
                $decrypted = null;
            }
            $data[$key] = '';
            $data["{$key}_preview"] = $this->maskSecret($decrypted);
            $data["{$key}_has"] = (bool) $decrypted;
        }

        // Modo por defecto: sandbox
        if (empty($data['factus_mode'])) {
            $data['factus_mode'] = 'sandbox';
        }

        // URL por defecto según modo (si está vacía)
        if (empty($data['factus_base_url'])) {
            $data['factus_base_url'] = $data['factus_mode'] === 'production'
                ? 'https://api.factus.com.co'
                : 'https://api-sandbox.factus.com.co';
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /{tenant}/configs/factus
     * Actualiza la configuración de Factus. Los secretos solo se actualizan si vienen no vacíos
     * (para soportar el patrón "no rellenar el campo si no quiero cambiar la clave").
     */
    public function updateFactus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'factus_base_url' => 'nullable|url|max:255',
            'factus_client_id' => 'nullable|string|max:255',
            'factus_client_secret' => 'nullable|string|max:500',
            'factus_username' => 'nullable|string|max:255',
            'factus_password' => 'nullable|string|max:500',
            'factus_mode' => 'nullable|in:sandbox,production',
        ]);

        try {
            foreach (self::FACTUS_PUBLIC_KEYS as $key) {
                if (array_key_exists($key, $validated)) {
                    Config::set($key, $validated[$key]);
                }
            }
            foreach (self::FACTUS_SECRET_KEYS as $key) {
                $val = $validated[$key] ?? null;
                if ($val !== null && $val !== '') {
                    Config::set($key, Crypt::encryptString($val));
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Configuración de Factus actualizada.',
            ]);
        } catch (Throwable $e) {
            Log::error('factus.config_update_failed', $this->factusLogContext('configs/factus', [
                'error_type' => $e::class,
            ]));

            return response()->json([
                'success' => false,
                'message' => 'No fue posible guardar la configuración de Factus. Intenta nuevamente.',
            ], 500);
        }
    }

    /**
     * POST /{tenant}/configs/factus/test
     * Prueba la conexión con Factus usando las credenciales guardadas del tenant.
     */
    public function testFactus(FactusService $factus): JsonResponse
    {
        try {
            $token = $factus->getAccessToken();
            if ($token) {
                return response()->json([
                    'success' => true,
                    'message' => 'Conexión exitosa con Factus. Token obtenido correctamente.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se obtuvo token. Revisa las credenciales.',
            ], 422);
        } catch (FactusIntegrationException $e) {
            Log::warning('factus.connection_test_failed', $this->factusLogContext('configs/factus/test', [
                'error_type' => $e::class,
                'external_status' => $e->externalStatus(),
            ]));

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->clientStatus());
        } catch (Throwable $e) {
            Log::error('factus.connection_test_unexpected_error', $this->factusLogContext('configs/factus/test', [
                'error_type' => $e::class,
            ]));

            return response()->json([
                'success' => false,
                'message' => 'No fue posible probar la conexión con Factus. Intenta nuevamente.',
            ], 500);
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function factusLogContext(string $endpoint, array $extra = []): array
    {
        return array_merge([
            'tenant_id' => tenant('id'),
            'tenant_slug' => tenant('tenant_slug') ?: tenant('company_code') ?: tenant('id'),
            'user_id' => auth()->id(),
            'endpoint' => $endpoint,
        ], $extra);
    }
}
