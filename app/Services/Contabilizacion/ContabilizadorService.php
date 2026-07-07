<?php

declare(strict_types=1);

namespace App\Services\Contabilizacion;

use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\PeriodoContable;
use App\Services\Asiento\ConsecutivoAsientoService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Genera asientos derivados a partir de documentos fuente
 * (Factura, NotaCredito, NotaDebito, ReciboCaja, DocumentoIngreso).
 *
 * Idempotencia: el unique parcial unique_asiento_origen (PG) o un check
 * en este servicio (otros drivers) impiden duplicación.
 *
 * NOTA EPIC-002: este servicio queda con la infraestructura común
 * (lookup parametrización, índice idempotencia, asignación de consecutivo)
 * y un builder genérico. Los mapeos específicos por tipo de documento
 * se afinarán en iteraciones siguientes cuando se valide el schema actual
 * de Factura/NotaCredito (campos exactos de retenciones, impuestos, etc.).
 */
class ContabilizadorService
{
    public function __construct(
        private readonly ParametrizacionRepository $params,
        private readonly ConsecutivoAsientoService $consecutivos,
    ) {}

    /**
     * Devuelve el asiento existente derivado del documento fuente (si lo hay).
     */
    public function asientoExistenteDe(Model $origen): ?Asiento
    {
        return Asiento::query()
            ->where('origen_type', $origen::class)
            ->where('origen_id', (string) $origen->getKey())
            ->where('tipo_movimiento', '!=', Asiento::TIPO_REVERSO)
            ->first();
    }

    /**
     * Construye un asiento aprobado a partir de un payload pre-calculado.
     * El builder específico de cada tipo de documento (Factura, NotaCredito,
     * etc.) construye este payload y delega aquí.
     *
     * @param  array{
     *     fecha: string,
     *     tipo_comprobante: string,
     *     descripcion: string,
     *     sucursal_id?: ?string,
     *     origen: \Illuminate\Database\Eloquent\Model,
     *     created_by_id: string,
     *     lineas: array<int, array{
     *         cuenta_contable_id: string,
     *         tercero_id?: ?string,
     *         debito: float,
     *         credito: float,
     *         descripcion?: ?string,
     *         documento_referencia?: ?string,
     *     }>,
     * }  $payload
     */
    public function contabilizar(array $payload): Asiento
    {
        // Idempotencia
        $existente = $this->asientoExistenteDe($payload['origen']);
        if ($existente !== null) {
            return $existente;
        }

        return DB::transaction(function () use ($payload): Asiento {
            $fecha = CarbonImmutable::parse($payload['fecha']);
            $periodo = PeriodoContable::actual($fecha);

            // numero_documento: número del documento fuente (ej: ING-000001, FV-00001).
            // Si el caller no lo pasa explícitamente, se toma del campo 'numero' del
            // modelo origen, o en último caso se trunca la descripción.
            $numeroDoc = $payload['numero_documento']
                ?? $payload['origen']->numero
                ?? $payload['origen']->reference_code
                ?? substr((string) ($payload['descripcion'] ?? ''), 0, 50);

            // forceFill: estado/tipo_movimiento/approved_* son LIFECYCLE_FIELDS,
            // no están en $fillable — mass-assign con `create()` los descartaba
            // silenciosamente y el asiento quedaba en 'borrador' (default DB).
            /** @var Asiento $asiento */
            $asiento = (new Asiento())->forceFill([
                'fecha'             => $fecha->toDateString(),
                'periodo_id'        => $periodo->id,
                'tipo_comprobante'  => $payload['tipo_comprobante'],
                'numero_documento'  => $numeroDoc,
                'estado'            => Asiento::ESTADO_APROBADO,
                'tipo_movimiento'   => Asiento::TIPO_NORMAL,
                'descripcion'       => $payload['descripcion'],
                'comprobante'       => $payload['descripcion'],
                'sucursal_id'       => $payload['sucursal_id'] ?? null,
                'origen_type'       => $payload['origen']::class,
                'origen_id'         => (string) $payload['origen']->getKey(),
                'created_by_id'     => $payload['created_by_id'],
                'approved_by_id'    => $payload['created_by_id'],
                'approved_at'       => now(),
            ]);
            $asiento->save();

            foreach ($payload['lineas'] as $l) {
                AsientoLinea::query()->create([
                    'asiento_id'           => $asiento->id,
                    'cuenta_id'            => $l['cuenta_contable_id'],
                    'tercero_id'           => $l['tercero_id'] ?? null,
                    'debito'               => (float) ($l['debito'] ?? 0),
                    'credito'              => (float) ($l['credito'] ?? 0),
                    'descripcion_item'     => $l['descripcion'] ?? null,
                    'documento_referencia' => $l['documento_referencia'] ?? null,
                ]);
            }

            $this->consecutivos->asignar($asiento);

            return $asiento->fresh(['lineas']);
        });
    }

    /**
     * Resuelve la cuenta para una clave canónica de parametrización.
     * Atajo público para que los Builders no necesiten inyectar el repo.
     */
    public function cuenta(string $clave): \App\Models\Tenant\CuentaContable
    {
        return $this->params->cuenta($clave);
    }
}
