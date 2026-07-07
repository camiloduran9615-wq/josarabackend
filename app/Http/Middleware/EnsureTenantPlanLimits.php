<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Plans\PlanLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantPlanLimits
{
    public function __construct(private readonly PlanLimitService $limits)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->limits->evaluateCreate($request);

        if ($result['allowed']) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => sprintf(
                'Límite del plan alcanzado para %s.',
                (string) ($result['resource'] ?? 'este recurso')
            ),
            'errors' => [
                'plan_limit' => [
                    'feature_key' => $result['feature_key'] ?? null,
                    'limit' => $result['limit'] ?? null,
                    'used' => $result['used'] ?? null,
                ],
            ],
        ], Response::HTTP_FORBIDDEN);
    }
}
