<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsientoLineaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'cuenta_id'            => $this->cuenta_id,
            'cuenta'               => $this->whenLoaded('cuenta', fn () => [
                'id'      => $this->cuenta->id,
                'codigo'  => $this->cuenta->codigo ?? null,
                'nombre'  => $this->cuenta->nombre ?? null,
            ]),
            'tercero_id'           => $this->tercero_id,
            'centro_costo_id'      => $this->centro_costo_id,
            'debito'               => (string) $this->debito,
            'credito'              => (string) $this->credito,
            'descripcion'          => $this->descripcion_item,
            'documento_referencia' => $this->documento_referencia,
        ];
    }
}
