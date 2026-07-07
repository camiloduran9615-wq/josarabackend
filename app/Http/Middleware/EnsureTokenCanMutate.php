<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FIX C-2 (HANDOFF.md / QA_TEST_REPORT.md) + FIX REG-1 (ACCEPTANCE_REPORT.md).
 *
 * Evidencia original (C-2): un usuario creado con role=readonly (abilities de
 * token = ['read'], ver AuthController::abilitiesForRole()) pudo crear y
 * eliminar un Tercero real vía API y vía el botón real del frontend, porque
 * casi ningún controlador de negocio valida autorización más allá de
 * `auth:sanctum`. Solo 4 Policies estaban conectadas (Asiento, Periodo,
 * AuditLog, Impuesto) de ~47 controladores.
 *
 * Esta corrección NO reescribe los ~42 controladores restantes (fuera de
 * alcance para un fix "pequeño y justificable"): en su lugar bloquea, a
 * nivel de middleware sobre el grupo de rutas del tenant, cualquier
 * petición mutante (POST/PUT/PATCH/DELETE) cuyo token no tenga una ability
 * de escritura genuina ('create', 'update' o '*').
 *
 * ── REG-1 (ACCEPTANCE_REPORT.md) ────────────────────────────────────────
 * La versión previa de este middleware también aceptaba la ability 'export'
 * como permiso de escritura. Eso convertía a 'export' en un COMODÍN GLOBAL
 * de mutación: el rol `auditor` (abilities ['read','export'], pensado como
 * solo-lectura + exportación de compliance) podía crear/editar/eliminar
 * terceros, productos y cualquier otro recurso. La ability 'export' existe
 * únicamente para dos operaciones POST de auditoría que NO mutan datos de
 * negocio (exportan/verifican la cadena de auditoría, usan POST por costo y
 * tamaño de payload). La corrección aplica el principio de mínimo privilegio
 * considerando la ACCIÓN (método HTTP), el PERMISO (ability) y la RUTA:
 * 'export' solo habilita un POST cuando la ruta es una de EXPORT_ONLY_ROUTES.
 *
 * Huella final por rol (sin regresión para los roles de escritura):
 *   - admin:    ['*']                                           → muta todo
 *   - contador: ['read','create','update','approve','void',...] → muta (create/update)
 *   - auxiliar: ['read','create','update']                      → muta (create/update)
 *   - auditor:  ['read','export']                               → SOLO POST audit-logs/{export,verify-chain};
 *                                                                 BLOQUEADO en toda otra mutación (FIX REG-1)
 *   - readonly: ['read']                                        → BLOQUEADO en toda mutación
 *
 * Las Policies existentes (Asiento, Periodo, AuditLog, Impuesto) se siguen
 * evaluando normalmente dentro de sus controladores — esta middleware es una
 * capa adicional, no un reemplazo.
 */
class EnsureTokenCanMutate
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Rutas donde la ability 'export' (rol `auditor`) SÍ habilita un POST,
     * pese a no ser una escritura de negocio: exportación y verificación de
     * la cadena de auditoría. Se identifican por NOMBRE de ruta (no por URI)
     * para que el matching sea robusto ante el prefijo /api/v1/{tenant}.
     */
    private const EXPORT_ONLY_ROUTES = [
        'audit-logs.export',
        'audit-logs.verify-chain',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $token = $request->user()?->currentAccessToken();

        // Sin token no aplicamos esta capa: `auth:sanctum` ya gobierna el acceso.
        if ($token === null) {
            return $next($request);
        }

        // Abilities de escritura genéricas: admin ('*'), contador y auxiliar.
        if ($token->can('*') || $token->can('create') || $token->can('update')) {
            return $next($request);
        }

        // FIX REG-1: 'export' NO es un permiso de escritura global. Solo habilita
        // las operaciones POST de exportación/verificación de auditoría, y
        // únicamente cuando la ruta coincide con una de sus nombres explícitos.
        if ($token->can('export') && $request->routeIs(...self::EXPORT_ONLY_ROUTES)) {
            return $next($request);
        }

        abort(403, 'Tu rol no tiene permisos para realizar esta operación.');
    }
}
