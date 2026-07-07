<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminAuditLog;
use Illuminate\Http\Request;

class PlatformAdminAuditService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function log(
        Request $request,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
    ): void {
        /** @var mixed $user */
        $user = $request->user();

        PlatformAdminAuditLog::create([
            'platform_admin_id' => $user instanceof PlatformAdmin ? $user->id : null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
