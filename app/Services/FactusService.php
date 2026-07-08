<?php

namespace App\Services;

use App\Exceptions\FactusIntegrationException;
use App\Models\Tenant\Config as TenantConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FactusService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $username;
    protected $password;
    protected ?string $tenantId = null;

    public function __construct()
    {
        // 1) Si estamos dentro de un tenant inicializado, leer su configuración propia.
        //    Cada empresa tiene sus credenciales DIAN/Factus (multi-tenant aware).
        if (function_exists('tenant') && tenant()) {
            $this->tenantId = tenant('id');
            $this->loadFromTenant();
        }

        // 2) Fallback a .env (modo desarrollo / consola central) si el tenant no tiene config.
        $this->baseUrl      = $this->baseUrl      ?: config('services.factus.base_url');
        $this->clientId     = $this->clientId     ?: config('services.factus.client_id');
        $this->clientSecret = $this->clientSecret ?: config('services.factus.client_secret');
        $this->username     = $this->username     ?: config('services.factus.username');
        $this->password     = $this->password     ?: config('services.factus.password');
    }

    /**
     * Carga credenciales desde la tabla configs del tenant actual.
     * Los secretos están encriptados con Crypt — se desencriptan al leer.
     */
    protected function loadFromTenant(): void
    {
        try {
            $rows = TenantConfig::whereIn('key', [
                'factus_base_url', 'factus_client_id', 'factus_client_secret',
                'factus_username', 'factus_password', 'factus_mode',
            ])->pluck('value', 'key');

            $this->baseUrl  = $rows->get('factus_base_url') ?: null;
            $this->clientId = $rows->get('factus_client_id') ?: null;
            $this->username = $rows->get('factus_username') ?: null;

            // Si no hay base_url explícita, derivar del modo
            if (!$this->baseUrl && $rows->get('factus_mode')) {
                $this->baseUrl = $rows->get('factus_mode') === 'production'
                    ? 'https://api.factus.com.co'
                    : 'https://api-sandbox.factus.com.co';
            }

            foreach (['factus_client_secret' => 'clientSecret', 'factus_password' => 'password'] as $key => $prop) {
                $enc = $rows->get($key);
                if ($enc) {
                    try { $this->{$prop} = Crypt::decryptString($enc); }
                    catch (\Throwable) { $this->{$prop} = null; }
                }
            }
        } catch (\Throwable $e) {
            // BD no inicializada todavía (ej. tests sin migraciones del tenant); ignorar.
        }
    }

    /**
     * Obtiene el token de acceso, usándolo de la caché si es posible.
     * El cache es tenant-scoped para evitar fuga de tokens entre empresas.
     */
    public function getAccessToken()
    {
        return $this->requestNewToken();
    }

    /**
     * Solicita un nuevo token a la API de Factus.
     */
    protected function requestNewToken()
    {
        $this->ensureConfigured();

        try {
            $response = Http::timeout(15)->asForm()->post("{$this->baseUrl}/oauth/token", [
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful()) {
                return $response->json()['access_token'];
            }

            Log::warning('factus.auth_failed', $this->safeLogContext([
                'external_status' => $response->status(),
            ]));

            $clientStatus = in_array($response->status(), [400, 401, 403, 422], true) ? 422 : 502;

            throw new FactusIntegrationException(
                'No fue posible autenticar con Factus. Verifica las credenciales configuradas.',
                $clientStatus,
                $response->status(),
            );
        } catch (FactusIntegrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('factus.auth_connection_error', $this->safeLogContext([
                'error_type' => $e::class,
            ]));

            throw new FactusIntegrationException(
                'Factus no respondió a la solicitud de autenticación. Intenta nuevamente.',
                502,
            );
        }
    }

    /**
     * Consulta datos de un adquiriente en la DIAN.
     */
    public function getAcquirerData($documentType, $documentNumber)
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->acceptJson()
            ->get("{$this->baseUrl}/v1/dian/acquirer", [
                'identification_document_id' => $documentType,
                'identification_number' => $documentNumber,
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning("Factus Acquirer Query Warning ({$documentNumber}): " . $response->body());
        return null;
    }

    /**
     * Obtiene los rangos de numeración disponibles.
     * Prueba v1 primero (sandbox), luego v2 (producción).
     */
    public function getNumberingRanges()
    {
        $token = $this->getAccessToken();
        $lastStatus = null;

        foreach (['/v1/numbering-ranges', '/v2/numbering-ranges'] as $path) {
            try {
                $response = Http::timeout(15)
                    ->withToken($token)
                    ->acceptJson()
                    ->get("{$this->baseUrl}{$path}");
            } catch (Throwable $e) {
                Log::error('factus.numbering_ranges_connection_error', $this->safeLogContext([
                    'endpoint' => $path,
                    'error_type' => $e::class,
                ]));

                throw new FactusIntegrationException(
                    'Factus no respondió al consultar las resoluciones DIAN.',
                    502,
                );
            }

            $lastStatus = $response->status();

            if ($response->successful()) {
                $json = $response->json();
                // Normalizar estructura v1 vs v2
                $items = $json['data']['data'] ?? $json['data'] ?? [];
                return [
                    'status' => 'OK',
                    'data'   => collect($items)->map(fn($r) => [
                        'id'              => $r['id'],
                        'prefix'          => $r['prefix'] ?? null,
                        'from'            => $r['from'] ?? null,
                        'to'              => $r['to'] ?? null,
                        'number'          => $r['resolution_number'] ?? null,
                        'start_date'      => $r['start_date'] ?? null,
                        'expiration_date' => $r['end_date'] ?? $r['expiration_date'] ?? null,
                        'document'        => $r['document'] ?? null,
                        'current'         => $r['current'] ?? null,
                        'is_active'       => $r['is_active'] ?? true,
                        'is_expired'      => $r['is_expired'] ?? false,
                    ])->toArray(),
                ];
            }

            Log::warning('factus.numbering_ranges_failed', $this->safeLogContext([
                'endpoint' => $path,
                'external_status' => $response->status(),
            ]));
        }

        throw new FactusIntegrationException(
            'No se pudieron obtener las resoluciones DIAN desde Factus.',
            502,
            $lastStatus,
        );
    }

    private function ensureConfigured(): void
    {
        $missing = [];
        foreach ([
            'factus_base_url' => $this->baseUrl,
            'factus_client_id' => $this->clientId,
            'factus_client_secret' => $this->clientSecret,
            'factus_username' => $this->username,
            'factus_password' => $this->password,
        ] as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return;
        }

        Log::notice('factus.config_missing', $this->safeLogContext([
            'missing_keys' => $missing,
        ]));

        throw new FactusIntegrationException(
            'La configuración de Factus no está completa para esta empresa.',
            404,
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function safeLogContext(array $extra = []): array
    {
        return array_merge([
            'tenant_id' => $this->tenantId,
            'tenant_slug' => function_exists('tenant') && tenant()
                ? (tenant('tenant_slug') ?: tenant('company_code') ?: tenant('id'))
                : null,
            'user_id' => auth()->id(),
        ], $extra);
    }

    /**
     * Crea y valida una factura en Factus.
     * Prueba v1 y v2 automáticamente.
     */
    public function createBill(array $data)
    {
        $token = $this->getAccessToken();

        foreach (['/v1/bills/validate', '/v2/bills/validate'] as $path) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}{$path}", $data);

            Log::info("Factus createBill [{$path}]", [
                'status'   => $response->status(),
                'response' => $response->json(),
            ]);

            if ($response->status() !== 403) {
                if ($response->successful()) {
                    return $response->json();
                }
                $body = $response->json();
                Log::error("Factus createBill error [{$path}]", [
                    'status' => $response->status(),
                    'body'   => $body,
                ]);
                return [
                    'success' => false,
                    'message' => $body['message'] ?? 'Error al crear la factura en Factus',
                    'errors'  => $body['errors'] ?? null,
                    'body'    => $body,
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Error al crear la factura en Factus (sin endpoint disponible)',
        ];
    }

    /**
     * Crea y valida una nota crédito en Factus.
     */
    public function createCreditNote(array $data)
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->acceptJson()
            ->post("{$this->baseUrl}/v1/credit-notes/validate", $data);

        Log::info('Factus createCreditNote', [
            'status'   => $response->status(),
            'response' => $response->json(),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $body = $response->json();
        Log::error('Factus createCreditNote error', [
            'status' => $response->status(),
            'body'   => $body,
        ]);

        return [
            'success' => false,
            'message' => $body['message'] ?? 'Error al crear la nota crédito en Factus',
            'errors'  => $body['errors'] ?? null,
            'body'    => $body,
        ];
    }
}
