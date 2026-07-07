<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'                => $this->id,
            'tipo'              => $this->tipo,
            'codigo'            => $this->codigo,
            'fecha_inicio'      => optional($this->fecha_inicio)->toDateString(),
            'fecha_fin'         => optional($this->fecha_fin)->toDateString(),
            'año_fiscal'        => $this->año_fiscal,
            'mes'               => $this->mes,
            'estado'            => $this->estado,
            'cerrado_por_id'    => $this->cerrado_por_id,
            'cerrado_at'        => optional($this->cerrado_at)->toIso8601String(),
            'motivo_cierre'     => $this->motivo_cierre,
            'reabierto_por_id'  => $this->reabierto_por_id,
            'reabierto_at'      => optional($this->reabierto_at)->toIso8601String(),
            'motivo_reapertura' => $this->motivo_reapertura,
            'permissions' => $user ? [
                'can_close'          => $user->can('close', $this->resource),
                'can_request_reopen' => $user->can('requestReopen', $this->resource),
                'can_approve_reopen' => $user->can('approveReopen', $this->resource),
                'can_lock_fiscal'    => $user->can('lockFiscal', $this->resource),
            ] : [],
        ];
    }
}
