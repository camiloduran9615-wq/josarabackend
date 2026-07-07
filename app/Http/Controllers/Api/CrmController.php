<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRM Básico — Prospectos, Oportunidades y Actividades.
 *
 * GET    /prospectos
 * POST   /prospectos
 * PUT    /prospectos/{id}
 * DELETE /prospectos/{id}
 *
 * GET    /oportunidades                    ← pipeline Kanban
 * POST   /oportunidades
 * PUT    /oportunidades/{id}
 * PUT    /oportunidades/{id}/etapa         ← drag-drop Kanban
 *
 * GET    /actividades-crm?oportunidad_id=
 * POST   /actividades-crm
 */
class CrmController extends Controller
{
    // ── Prospectos ─────────────────────────────────────────────────────────

    public function prospectosIndex(Request $request): JsonResponse
    {
        $rows = DB::table('prospectos')
            ->whereNull('deleted_at')
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('razon_social', 'ilike', "%{$request->q}%")
                    ->orWhere('contacto_email', 'ilike', "%{$request->q}%");
            }))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function prospectosStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'razon_social'      => ['required', 'string', 'max:200'],
            'contacto_nombre'   => ['nullable', 'string', 'max:100'],
            'contacto_email'    => ['nullable', 'email'],
            'contacto_telefono' => ['nullable', 'string', 'max:20'],
            'ciudad'            => ['nullable', 'string', 'max:80'],
            'sector'            => ['nullable', 'string', 'max:80'],
            'fuente'            => ['nullable', 'string', 'max:50'],
            'notas'             => ['nullable', 'string'],
        ]);

        $id = DB::table('prospectos')->insertGetId(array_merge($validated, [
            'id'         => (string) \Illuminate\Support\Str::uuid(),
            'estado'     => 'activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $row = DB::table('prospectos')->where('id', $validated['id'] ?? $id)->first()
            ?? DB::table('prospectos')->latest('created_at')->first();

        return response()->json(['success' => true, 'data' => $row], Response::HTTP_CREATED);
    }

    public function prospectosUpdate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'razon_social'      => ['sometimes', 'string', 'max:200'],
            'contacto_nombre'   => ['nullable', 'string', 'max:100'],
            'contacto_email'    => ['nullable', 'email'],
            'contacto_telefono' => ['nullable', 'string', 'max:20'],
            'ciudad'            => ['nullable', 'string', 'max:80'],
            'sector'            => ['nullable', 'string', 'max:80'],
            'fuente'            => ['nullable', 'string', 'max:50'],
            'estado'            => ['sometimes', 'string', 'in:activo,convertido,descartado'],
            'notas'             => ['nullable', 'string'],
        ]);

        DB::table('prospectos')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['success' => true, 'data' => DB::table('prospectos')->where('id', $id)->first()]);
    }

    public function prospectosDestroy(string $id): JsonResponse
    {
        DB::table('prospectos')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ── Oportunidades ──────────────────────────────────────────────────────

    public function oportunidadesIndex(Request $request): JsonResponse
    {
        $rows = DB::table('oportunidades')
            ->whereNull('deleted_at')
            ->when($request->filled('etapa'), fn ($q) => $q->where('etapa', $request->etapa))
            ->orderByDesc('fecha_cierre_esperada')
            ->get();

        // Agrupar por etapa para el Kanban
        $pipeline = $rows->groupBy('etapa');

        return response()->json(['success' => true, 'data' => $rows, 'pipeline' => $pipeline]);
    }

    public function oportunidadesStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'               => ['required', 'string', 'max:200'],
            'prospecto_id'         => ['nullable', 'string'],
            'tercero_id'           => ['nullable', 'string'],
            'etapa'                => ['sometimes', 'string'],
            'valor_estimado'       => ['sometimes', 'numeric', 'min:0'],
            'probabilidad'         => ['sometimes', 'integer', 'min:0', 'max:100'],
            'fecha_cierre_esperada'=> ['nullable', 'date'],
            'notas'                => ['nullable', 'string'],
        ]);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        DB::table('oportunidades')->insert(array_merge($validated, [
            'id'         => $uuid,
            'etapa'      => $validated['etapa'] ?? 'prospecto',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['success' => true, 'data' => DB::table('oportunidades')->where('id', $uuid)->first()], Response::HTTP_CREATED);
    }

    public function oportunidadesUpdate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'nombre'               => ['sometimes', 'string', 'max:200'],
            'etapa'                => ['sometimes', 'string'],
            'valor_estimado'       => ['sometimes', 'numeric', 'min:0'],
            'probabilidad'         => ['sometimes', 'integer', 'min:0', 'max:100'],
            'fecha_cierre_esperada'=> ['nullable', 'date'],
            'notas'                => ['nullable', 'string'],
            'motivo_perdida'       => ['nullable', 'string', 'max:100'],
        ]);

        DB::table('oportunidades')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['success' => true, 'data' => DB::table('oportunidades')->where('id', $id)->first()]);
    }

    public function cambiarEtapa(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'etapa' => ['required', 'string', 'in:prospecto,calificado,propuesta,negociacion,cerrado_ganado,cerrado_perdido'],
            'motivo_perdida' => ['nullable', 'string', 'max:100'],
        ]);

        DB::table('oportunidades')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['success' => true, 'data' => DB::table('oportunidades')->where('id', $id)->first()]);
    }

    // ── Actividades ────────────────────────────────────────────────────────

    public function actividadesIndex(Request $request): JsonResponse
    {
        $rows = DB::table('actividades_crm')
            ->when($request->filled('oportunidad_id'), fn ($q) => $q->where('oportunidad_id', $request->oportunidad_id))
            ->when($request->filled('prospecto_id'),   fn ($q) => $q->where('prospecto_id', $request->prospecto_id))
            ->orderByDesc('fecha_actividad')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function actividadesStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo'            => ['required', 'string', 'in:llamada,correo,reunion,demo,propuesta'],
            'asunto'          => ['required', 'string', 'max:200'],
            'descripcion'     => ['nullable', 'string'],
            'fecha_actividad' => ['required', 'date'],
            'resultado'       => ['nullable', 'string'],
            'oportunidad_id'  => ['nullable', 'string'],
            'prospecto_id'    => ['nullable', 'string'],
        ]);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        DB::table('actividades_crm')->insert(array_merge($validated, [
            'id'              => $uuid,
            'creado_por_id'   => $request->user()?->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]));

        return response()->json(['success' => true, 'data' => DB::table('actividades_crm')->where('id', $uuid)->first()], Response::HTTP_CREATED);
    }
}
