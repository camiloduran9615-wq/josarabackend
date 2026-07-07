<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PlatformAdminAuditLog::with('platformAdmin');
        $action = $request->query('action');
        $targetType = $request->query('target_type');

        if (is_string($action) && $action !== '') {
            $query->where('action', $action);
        }

        if (is_string($targetType) && $targetType !== '') {
            $query->where('target_type', $targetType);
        }

        $logs = $query->latest('created_at')->paginate((int) $request->integer('per_page', 25));

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
