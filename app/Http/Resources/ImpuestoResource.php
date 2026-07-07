<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Tenant\Impuesto
 */
class ImpuestoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'tipo'                   => $this->tipo,
            'codigo'                 => $this->codigo,
            'codigo_dian_ubl'        => $this->codigo_dian_ubl,
            'concepto_dian'          => $this->concepto_dian,
            'nombre'                 => $this->nombre,
            'tarifa_porcentaje'      => (string) $this->tarifa_porcentaje,
            'base_minima_uvt'        => $this->base_minima_uvt !== null
                ? (string) $this->base_minima_uvt
                : null,
            'aplica_compras'         => $this->aplica_compras,
            'aplica_ventas'          => $this->aplica_ventas,
            'cuenta_contable_id'     => $this->cuenta_contable_id,
            'cuenta_contrapartida_id'=> $this->cuenta_contrapartida_id,
            'actividad_ciiu'         => $this->actividad_ciiu,
            'vigencia_desde'         => $this->vigencia_desde->toDateString(),
            'vigencia_hasta'         => $this->vigencia_hasta?->toDateString(),
            'activa'                 => $this->activa,
            'sistema'                => $this->sistema,
            'descripcion'            => $this->descripcion,
            'editable'               => ! $this->sistema,
            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
        ];
    }
}
