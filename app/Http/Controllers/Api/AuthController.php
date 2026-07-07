<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\Tenant\Sucursal;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Inicia sesión y devuelve un token Sanctum.
     *
     * POST /api/v1/auth/login
     * Body nuevo: { tenant_slug, email, password }
     * Body legado temporal: { company_code|tenant_id, email, password }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'tenant_slug' => ['nullable', 'string', 'max:80'],
            'company_code' => ['nullable', 'string', 'max:80'],
            'tenant_id' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $this->resolveLoginTenant($request);
        if ($tenant === null || ! $tenant->activo) {
            $this->recordFailedLogin($request, null, 'tenant_not_resolved');
            $this->throwInvalidCredentials();
        }

        // Inicializar el tenant correcto (cambia la conexión a su DB).
        tenancy()->initialize($tenant);

        /** @var User|null $user */
        $user = User::where('email', $request->email)->first();

        // Verificar credenciales y estado activo
        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->recordFailedLogin($request, $tenant, 'invalid_credentials');
            $this->throwInvalidCredentials();
        }

        if (! $user->activo) {
            $this->recordFailedLogin($request, $tenant, 'inactive_user');

            return response()->json([
                'success' => false,
                'message' => 'Las credenciales proporcionadas son incorrectas.',
            ], 403);
        }

        // Revocar tokens anteriores del mismo dispositivo
        $user->tokens()->where('name', 'api-token')->delete();

        // Crear token con habilidades basadas en el rol
        $token = $user->createToken('api-token', $this->abilitiesForRole($user->role));

        // Actualizar último login
        $user->update(['last_login' => now()]);

        // Registrar en auditoría central
        $this->auditLog->record(
            action: 'auth.login',
            criticidad: AuditLogService::CRITICIDAD_INFO,
            auditable: $user,
            metadata: ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        );

        // Obtener sucursales disponibles
        $sucursales = Sucursal::where('activa', true)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'tenant_slug' => $tenant->publicIdentifier(),
                'user' => new UserResource($user),
                'sucursales' => $sucursales,
            ],
        ]);
    }

    /**
     * Cierra sesión e invalida el token actual.
     *
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auditLog->record(
            action: 'auth.logout',
            criticidad: AuditLogService::CRITICIDAD_INFO,
            auditable: $user,
        );

        $user->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * Devuelve el perfil del usuario autenticado.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user()),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Define las habilidades del token según el rol del usuario.
     * Implementa el principio de mínimo privilegio.
     */
    private function abilitiesForRole(string $role): array
    {
        return match ($role) {
            User::ROLE_ADMIN => ['*'],
            User::ROLE_CONTADOR => ['read', 'create', 'update', 'approve', 'void', 'close-period'],
            User::ROLE_AUXILIAR => ['read', 'create', 'update'],
            User::ROLE_AUDITOR => ['read', 'export'],
            User::ROLE_READONLY => ['read'],
            default => ['read'],
        };
    }

    private function resolveLoginTenant(Request $request): ?Tenant
    {
        $tenantSlug = trim((string) $request->input('tenant_slug', ''));
        $companyCode = trim((string) $request->input('company_code', ''));
        $legacyTenantId = trim((string) $request->input('tenant_id', ''));

        if ($tenantSlug === '' && $companyCode === '' && $legacyTenantId === '') {
            return null;
        }

        if ($tenantSlug !== '') {
            return Tenant::resolveByPublicIdentifier($tenantSlug);
        }

        if ($companyCode !== '') {
            return Tenant::resolveByPublicIdentifier($companyCode);
        }

        return Tenant::resolveByPublicIdentifier($legacyTenantId);
    }

    private function throwInvalidCredentials(): never
    {
        throw ValidationException::withMessages([
            'email' => ['Las credenciales proporcionadas son incorrectas.'],
        ]);
    }

    private function recordFailedLogin(Request $request, ?Tenant $tenant, string $reason): void
    {
        $shouldEndTenancy = $tenant !== null && ! tenancy()->initialized;

        $metadata = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason' => $reason,
            'email_hash' => hash('sha256', mb_strtolower((string) $request->input('email'))),
            'tenant_slug_present' => $request->filled('tenant_slug'),
            'company_code_present' => $request->filled('company_code'),
            'legacy_tenant_id_present' => $request->filled('tenant_id'),
        ];

        try {
            if ($shouldEndTenancy) {
                tenancy()->initialize($tenant);
            }

            $this->auditLog->record(
                action: 'auth.login.failed',
                criticidad: AuditLogService::CRITICIDAD_WARNING,
                metadata: $metadata,
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar intento fallido de login en auditoría', [
                'reason' => $reason,
                'tenant_id' => $tenant?->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } finally {
            if ($shouldEndTenancy && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
