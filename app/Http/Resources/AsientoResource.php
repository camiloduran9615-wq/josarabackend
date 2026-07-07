<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsientoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        $totalDebito = (float) $this->lineas?->sum(fn ($l) => (float) $l->debito) ?? 0;
        $totalCredito = (float) $this->lineas?->sum(fn ($l) => (float) $l->credito) ?? 0;

        return [
            'id'                => $this->id,
            'numero'            => $this->numero,
            'fecha'             => optional($this->fecha)->toDateString() ?? $this->fecha,
            'tipo_comprobante'  => $this->tipo_comprobante,
            'estado'            => $this->estado,
            'tipo_movimiento'   => $this->tipo_movimiento,
            'descripcion'       => $this->descripcion ?? $this->comprobante,
            'numero_documento'  => $this->numero_documento,
            'periodo_id'        => $this->periodo_id,
            'sucursal_id'       => $this->sucursal_id,
            'soportes_urls'     => $this->soportes_urls,
            'origen' => $this->when(
                $this->origen_type !== null,
                fn () => [
                    'type' => class_basename((string) $this->origen_type),
                    'id'   => $this->origen_id,
                ]
            ),
            'totales' => [
                'debito'      => number_format($totalDebito, 2, '.', ''),
                'credito'     => number_format($totalCredito, 2, '.', ''),
                'balanceado'  => abs($totalDebito - $totalCredito) <= 0.01,
            ],
            'lineas' => AsientoLineaResource::collection($this->whenLoaded('lineas')),
            'created_by_id'        => $this->created_by_id,
            'last_modified_by_id'  => $this->last_modified_by_id,
            'approved_by_id'       => $this->approved_by_id,
            'approved_at'          => optional($this->approved_at)->toIso8601String(),
            'voided_by_id'         => $this->voided_by_id,
            'voided_at'            => optional($this->voided_at)->toIso8601String(),
            'motivo_anulacion'     => $this->motivo_anulacion,
            'motivo_reverso'       => $this->motivo_reverso,
            'reversado_por_id'     => $this->reversado_por_id,
            'origen_reverso_id'    => $this->origen_reverso_id,
            'created_at'           => optional($this->created_at)->toIso8601String(),
            'updated_at'           => optional($this->updated_at)->toIso8601String(),
            'permissions' => $user ? [
                'can_update'  => $user->can('update', $this->resource),
                'can_approve' => $user->can('approve', $this->resource),
                'can_void'    => $user->can('void', $this->resource),
                'can_reverse' => $user->can('reverse', $this->resource),
                'can_discard' => $user->can('discard', $this->resource),
            ] : [],
        ];
    }
}
