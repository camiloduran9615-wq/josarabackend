<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UserStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly UserStatusService $userStatus,
    ) {}

    /**
     * Lista todos los usuarios del tenant activo.
     *
     * GET /api/v1/{tenant}/users
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRole($request, [User::ROLE_ADMIN]);

        $users = User::orderBy('nombre')
            ->get(['id', 'nombre', 'apellido', 'email', 'role', 'activo', 'last_login', 'created_at']);

        return response()->json([
            'success' => true,
            'total'   => $users->count(),
            'data'    => UserResource::collection($users),
        ]);
    }

    /**
     * Crea un nuevo usuario dentro del tenant.
     *
     * POST /api/v1/{tenant}/users
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeRole($request, [User::ROLE_ADMIN]);

        $validated = $request->validate([
            'nombre'   => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role'     => ['required', Rule::in(User::ROLES)],
        ]);

        $user = User::create($validated);

        $this->auditLog->record(
            action:     'user.created',
            criticidad: AuditLogService::CRITICIDAD_INFO,
            auditable:  $user,
            newValues:  ['email' => $user->email, 'role' => $user->role],
        );

        return response()->json([
            'success' => true,
            'message' => "Usuario '{$user->nombre_completo}' creado exitosamente.",
            'data'    => new UserResource($user),
        ], 201);
    }

    /**
     * Muestra un usuario específico.
     *
     * GET /api/v1/{tenant}/users/{id}
     */
    public function show(Request $request, string $tenant, string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Un usuario puede verse a sí mismo; el admin puede ver a todos
        if (! $request->user()->isAdmin() && $request->user()->id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user),
        ]);
    }

    /**
     * Actualiza un usuario (solo Admin).
     *
     * PUT /api/v1/{tenant}/users/{id}
     */
    public function update(Request $request, string $tenant, string $id): JsonResponse
    {
        $this->authorizeRole($request, [User::ROLE_ADMIN]);

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'nombre'   => ['sometimes', 'string', 'max:100'],
            'apellido' => ['sometimes', 'string', 'max:100'],
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role'     => ['sometimes', Rule::in(User::ROLES)],
        ]);

        $oldValues = $user->only(array_keys($validated));
        $user->update($validated);

        $this->auditLog->record(
            action:     'user.updated',
            criticidad: AuditLogService::CRITICIDAD_INFO,
            auditable:  $user,
            oldValues:  $oldValues,
            newValues:  $validated,
        );

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado.',
            'data'    => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Desactiva un usuario (borrado lógico — nunca físico por auditoría DIAN).
     *
     * DELETE /api/v1/{tenant}/users/{id}
     */
    public function destroy(Request $request, string $tenant, string $id): JsonResponse
    {
        $this->authorizeRole($request, [User::ROLE_ADMIN]);

        $user = User::findOrFail($id);

        // Proteger: no se puede desactivar a uno mismo
        if ($request->user()->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes desactivar tu propia cuenta.',
            ], 422);
        }

        $user = $this->userStatus->setStatus($request->user(), $user, false);

        return response()->json([
            'success' => true,
            'message' => "Usuario '{$user->nombre_completo}' desactivado.",
        ]);
    }

    /**
     * Activa o inactiva un usuario de forma explícita e idempotente.
     *
     * PATCH /api/v1/{tenant}/users/{id}/status
     */
    public function changeStatus(Request $request, string $tenant, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $this->authorize('changeStatus', $user);

        $validated = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $user = $this->userStatus->setStatus(
            $request->user(),
            $user,
            (bool) $validated['activo'],
        );

        return response()->json([
            'success' => true,
            'message' => $user->activo
                ? "Usuario '{$user->nombre_completo}' activado."
                : "Usuario '{$user->nombre_completo}' inactivado.",
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Permite a un usuario cambiar su propia contraseña.
     *
     * PUT /api/v1/{tenant}/users/{id}/password
     */
    public function changePassword(Request $request, string $tenant, string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Solo el propio usuario puede cambiar su contraseña
        if ($request->user()->id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 403);
        }

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => $request->password]);

        // Nota: 'password' está en GLOBAL_BLACKLIST → no se loggea el valor
        $this->auditLog->record(
            action:     'user.password_changed',
            criticidad: AuditLogService::CRITICIDAD_WARNING,
            auditable:  $user,
        );

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    private function authorizeRole(Request $request, array $allowedRoles): void
    {
        if (! in_array($request->user()->role, $allowedRoles, true)) {
            abort(403, 'No tienes permisos para realizar esta acción.');
        }
    }
}
