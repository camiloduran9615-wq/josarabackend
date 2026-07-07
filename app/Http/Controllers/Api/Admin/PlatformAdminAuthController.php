<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PlatformAdminAuthController extends Controller
{
    public function __construct(private readonly PlatformAdminAuditService $audit)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $admin = PlatformAdmin::where('email', mb_strtolower($validated['email']))->first();

        if (! $admin || ! $admin->active || ! Hash::check($validated['password'], $admin->password)) {
            $this->audit->log($request, 'platform_admin.login.failed', PlatformAdmin::class, null, [
                'email_hash' => hash('sha256', mb_strtolower($validated['email'])),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! in_array($admin->role, PlatformAdmin::ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], Response::HTTP_FORBIDDEN);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken('platform-admin', ['platform:admin'])->plainTextToken;

        $this->audit->log($request, 'platform_admin.login.succeeded', PlatformAdmin::class, $admin->id);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'admin' => $this->serializeAdmin($admin),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->serializeAdmin($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log($request, 'platform_admin.logout', PlatformAdmin::class, $request->user()?->id);
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['success' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $actor */
        $actor = $request->user();

        if (! $actor->canManagePlatform()) {
            abort(Response::HTTP_FORBIDDEN, 'No autorizado.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:platform_admins,email'],
            'password' => ['required', 'string', 'min:12'],
            'role' => ['required', Rule::in(PlatformAdmin::ROLES)],
            'active' => ['sometimes', 'boolean'],
        ]);

        $admin = PlatformAdmin::create([
            'name' => $validated['name'],
            'email' => mb_strtolower($validated['email']),
            'password' => $validated['password'],
            'role' => $validated['role'],
            'active' => $validated['active'] ?? true,
        ]);

        $this->audit->log($request, 'platform_admin.created', PlatformAdmin::class, $admin->id, [
            'role' => $admin->role,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeAdmin($admin),
        ], Response::HTTP_CREATED);
    }

    private function serializeAdmin(PlatformAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'active' => $admin->active,
            'last_login_at' => $admin->last_login_at,
        ];
    }
}
