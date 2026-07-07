<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\TenantRegistered;
use App\Models\Tenant;
use App\Models\Tenant\Config;
use App\Models\Tenant\Sucursal;
use App\Models\User;
use App\Services\Registration\TenantRegistrationNotificationService;
use Database\Seeders\TenantConceptosNominaSeeder;
use Database\Seeders\TenantImpuestosSeeder;
use Database\Seeders\TenantParametrizacionSeeder;
use Database\Seeders\TenantPucSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantController extends Controller
{
    /**
     * Registra una nueva empresa (Tenant) en el sistema.
     * Provisiona el registro central + base de datos exclusiva del tenant
     * + sucursal principal única + usuario administrador inicial.
     *
     * POST /api/v1/tenants
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Datos de la Empresa
            'razon_social' => ['required', 'string', 'max:255'],
            'nit' => ['required', 'string', 'max:20', 'unique:tenants,nit', 'regex:/^\d{9,10}-\d{1}$/'],
            'tenant_slug' => ['nullable', 'string', 'max:80'],
            'email_contacto' => ['required', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:100'],

            // Datos del Administrador
            'admin_nombre' => ['required', 'string', 'max:255'],
            'admin_apellido' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
        ], [
            'nit.unique' => 'Este NIT ya está registrado en el sistema. Si ya tienes una cuenta, por favor inicia sesión.',
            'nit.regex' => 'El formato del NIT no es válido. Debe ser: 123456789-0',
            'nit.required' => 'El NIT de la empresa es obligatorio.',
            'razon_social.required' => 'La razón social es obligatoria.',
            'email_contacto.required' => 'El email de contacto es obligatorio.',
            'email_contacto.email' => 'El email de contacto no tiene un formato válido.',
            'admin_email.required' => 'El email del administrador es obligatorio.',
            'admin_email.email' => 'El email del administrador no tiene un formato válido.',
            'admin_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $tenant = null;

        try {
            // Paso 1: crear el Tenant. stancl/tenancy provisiona la BD física
            // y corre las migraciones tenant (incluida la que crea la sucursal
            // principal por defecto y el índice UNIQUE parcial).
            $tenant = Tenant::create([
                'tenant_slug' => Tenant::generateUniqueTenantSlug(
                    (string) ($validated['tenant_slug'] ?? $validated['razon_social']),
                ),
                'company_code' => Tenant::generateUniqueCompanyCode(
                    (string) ($validated['tenant_slug'] ?? $validated['razon_social']),
                ),
                'razon_social' => $validated['razon_social'],
                'nit' => $validated['nit'],
                'email_contacto' => $validated['email_contacto'],
                'telefono' => $validated['telefono'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'ciudad' => $validated['ciudad'] ?? null,
                'plan_id' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'activo' => true,
            ]);

            // Paso 2: provisionar el contenido del tenant (configs, sucursal,
            // admin, catálogos). Toda esta sección corre dentro de una
            // transacción en la conexión del tenant: si algo falla, se hace
            // rollback de los inserts del tenant; luego en el catch borramos
            // el registro central y la BD física para no dejar tenants huérfanos.
            tenancy()->initialize($tenant);

            DB::connection('tenant')->transaction(function () use ($validated): void {
                // 2.1 — Configuración Key-Value de la empresa
                $configs = [
                    'company_name' => $validated['razon_social'],
                    'company_nit' => $validated['nit'],
                    'company_address' => $validated['direccion'] ?? '',
                    'company_city' => $validated['ciudad'] ?? '',
                    'company_phone' => $validated['telefono'] ?? '',
                ];
                foreach ($configs as $key => $value) {
                    Config::set($key, $value);
                }

                // 2.2 — Sucursal Principal única.
                //
                // La migración tenant 2026_05_10_000008 ya creó una sucursal
                // principal por defecto ("Casa Matriz") y la 2026_05_19_140000
                // garantiza vía índice UNIQUE parcial que solo puede existir
                // una con es_principal=true. Aquí la actualizamos con los
                // datos del registro en lugar de crear una segunda.
                $sucursal = Sucursal::firstOrNew(
                    ['es_principal' => true],
                    ['activa' => true],
                );
                $sucursal->fill([
                    'nombre' => 'Sede Principal',
                    'direccion' => $validated['direccion'] ?? null,
                    'ciudad' => $validated['ciudad'] ?? null,
                    'telefono' => $validated['telefono'] ?? null,
                    'activa' => true,
                ]);
                $sucursal->es_principal = true;
                $sucursal->save();

                // 2.3 — Usuario administrador vinculado a la sucursal principal.
                //       email único por tenant (validado a nivel BD del tenant).
                if (User::where('email', $validated['admin_email'])->exists()) {
                    throw new \RuntimeException(
                        'El email del administrador ya está registrado en esta empresa.',
                    );
                }

                User::create([
                    'nombre' => $validated['admin_nombre'],
                    'apellido' => $validated['admin_apellido'],
                    'email' => $validated['admin_email'],
                    'password' => Hash::make($validated['admin_password']),
                    'role' => User::ROLE_ADMIN,
                    'sucursal_id' => $sucursal->id,
                    'activo' => true,
                ]);
            });

            // 2.4 — Catálogos base: PUC, impuestos, parametrización contable,
            //       conceptos de nómina. Se siembran fuera de la transacción
            //       porque los seeders manejan su propia atomicidad y son
            //       idempotentes; si algo falla aquí, el catch borra el tenant.
            $tenantSeeders = [
                TenantPucSeeder::class,
                TenantImpuestosSeeder::class,
                TenantParametrizacionSeeder::class,
                TenantConceptosNominaSeeder::class,
            ];
            foreach ($tenantSeeders as $seederClass) {
                Artisan::call('db:seed', [
                    '--class' => $seederClass,
                    '--force' => true,
                ]);
            }

            tenancy()->end();

            $registrationData = [
                'admin_name' => trim($validated['admin_nombre'].' '.$validated['admin_apellido']),
                'admin_email' => $validated['admin_email'],
            ];

            try {
                TenantRegistered::dispatch($tenant, $registrationData);
                app(TenantRegistrationNotificationService::class)->send($tenant, $registrationData);
            } catch (Throwable $notificationError) {
                Log::warning('tenant_registration.notification_failed', [
                    'tenant_slug' => $tenant->publicIdentifier(),
                    'admin_email_hash' => hash('sha256', mb_strtolower($validated['admin_email'])),
                    'exception' => $notificationError::class,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Empresa '{$tenant->razon_social}' registrada exitosamente. Tu periodo de prueba de 14 días ha iniciado.",
                'data' => [
                    'tenant_slug' => $tenant->publicIdentifier(),
                    'razon_social' => $tenant->razon_social,
                    'nit' => $tenant->nit,
                    'email_contacto' => $tenant->email_contacto,
                    'activo' => $tenant->activo,
                    'trial_ends_at' => $tenant->trial_ends_at,
                ],
            ], 201);

        } catch (Throwable $e) {
            // Rollback de provisionamiento: si el tenant central llegó a
            // crearse pero algo posterior falló, lo eliminamos junto con su BD
            // física para no dejar registros huérfanos ni bases de datos
            // colgantes (que consumirían recursos y bloquearían reintentos
            // por NIT duplicado).
            $tenantId = $tenant?->id;
            if ($tenant !== null) {
                try {
                    if (tenancy()->initialized) {
                        tenancy()->end();
                    }
                    // Tenant::delete() dispara TenantDeleted → drop de la BD
                    // física vía stancl/tenancy.
                    $tenant->delete();
                } catch (Throwable $cleanupError) {
                    Log::error('Fallo en cleanup tras error de provisión de tenant', [
                        'tenant_id' => $tenantId,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            // No exponer el mensaje interno al cliente: puede contener
            // detalles de schema, paths, credenciales, etc. Logueamos
            // completo para diagnóstico y devolvemos un mensaje genérico.
            Log::error('Error al provisionar tenant', [
                'nit' => $validated['nit'] ?? null,
                'tenant_id' => $tenantId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo registrar la empresa. Por favor intenta nuevamente o contacta soporte.',
            ], 500);
        }
    }

    /**
     * Lista todas las empresas registradas.
     *
     * GET /api/v1/tenants
     *
     * FIX C-1 (HANDOFF.md / QA_TEST_REPORT.md): antes no requería
     * autenticación y filtraba NIT/email/UUID de todos los tenants. Ahora
     * requiere token Sanctum central (routes/api.php) + Gate 'manage-tenants'
     * (solo role=admin).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('manage-tenants');

        $tenants = Tenant::select([
            'id', 'tenant_slug', 'razon_social', 'nit', 'email_contacto', 'ciudad', 'activo', 'created_at',
        ])->orderBy('razon_social')->get();

        return response()->json([
            'success' => true,
            'total' => $tenants->count(),
            'data' => $tenants,
        ]);
    }

    /**
     * Muestra una empresa específica.
     *
     * GET /api/v1/tenants/{id}
     *
     * FIX C-1: mismo Gate 'manage-tenants' que index().
     */
    public function show(string $id): JsonResponse
    {
        $this->authorize('manage-tenants');

        $tenant = Tenant::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $tenant,
        ]);
    }
}
