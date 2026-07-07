<?php

declare(strict_types=1);

namespace App\Services\Asiento;

use App\Events\Asiento\AsientoAnulado;
use App\Events\Asiento\AsientoAprobado;
use App\Events\Asiento\AsientoReversado;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de Asientos: crear borrador, editar, aprobar,
 * anular, reversar, descartar. Toda operación sensible es atómica.
 */
class AsientoService
{
    public function __construct(
        private readonly ConsecutivoAsientoService $consecutivos,
    ) {}

    // -----------------------------------------------------------------------
    // Crear borrador
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function crearBorrador(array $data, array $lineas, User $autor): Asiento
    {
        return DB::transaction(function () use ($data, $lineas, $autor): Asiento {
            $fecha = CarbonImmutable::parse((string) $data['fecha']);
            $periodo = PeriodoContable::actual($fecha);

            $this->guardEnPeriodoEditable($periodo);

            /** @var Asiento $asiento */
            $asiento = Asiento::query()->create([
                'fecha'             => $fecha->toDateString(),
                'periodo_id'        => $periodo->id,
                'tipo_comprobante'  => $data['tipo_comprobante'],
                'estado'            => Asiento::ESTADO_BORRADOR,
                'tipo_movimiento'   => Asiento::TIPO_NORMAL,
                'descripcion'       => $data['descripcion'],
                'comprobante'       => $data['descripcion'], // legacy
                'numero_documento'  => $data['numero_documento'] ?? null,
                'sucursal_id'       => $data['sucursal_id'] ?? null,
                'soportes_urls'     => $data['soportes_urls'] ?? null,
                'created_by_id'     => $autor->id,
                'last_modified_by_id' => $autor->id,
            ]);

            $this->reemplazarLineas($asiento, $lineas);

            return $asiento->fresh(['lineas']);
        });
    }

    // -----------------------------------------------------------------------
    // Editar borrador
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $lineas
     */
    public function editarBorrador(Asiento $asiento, array $data, ?array $lineas, User $editor): Asiento
    {
        if (! $asiento->esBorrador()) {
            throw new AsientoOperacionInvalidaException(
                'Solo se pueden editar asientos en estado borrador.'
            );
        }

        return DB::transaction(function () use ($asiento, $data, $lineas, $editor): Asiento {
            $updates = [
                'descripcion'        => $data['descripcion'] ?? $asiento->descripcion,
                'tipo_comprobante'   => $data['tipo_comprobante'] ?? $asiento->tipo_comprobante,
                'sucursal_id'        => $data['sucursal_id'] ?? $asiento->sucursal_id,
                'soportes_urls'      => $data['soportes_urls'] ?? $asiento->soportes_urls,
                'last_modified_by_id' => $editor->id,
            ];

            if (isset($data['fecha'])) {
                $fecha = CarbonImmutable::parse((string) $data['fecha']);
                $periodo = PeriodoContable::actual($fecha);
                $this->guardEnPeriodoEditable($periodo);
                $updates['fecha'] = $fecha->toDateString();
                $updates['periodo_id'] = $periodo->id;
            }

            $asiento->update($updates);

            if ($lineas !== null) {
                $this->reemplazarLineas($asiento, $lineas);
            }

            return $asiento->fresh(['lineas']);
        });
    }

    // -----------------------------------------------------------------------
    // Aprobar
    // -----------------------------------------------------------------------

    public function aprobar(Asiento $asiento, User $aprobador): Asiento
    {
        return DB::transaction(function () use ($asiento, $aprobador): Asiento {
            // SELECT FOR UPDATE serializa aprobaciones concurrentes del mismo asiento.
            // Sin este lock, dos POSTs simultáneos pasaban el guard `esBorrador()` antes
            // de que cualquiera commiteara, ejecutando dos veces el listener de saldos
            // y duplicando los movimientos en `cuenta_saldos` (BUG: doble aprobación).
            /** @var Asiento $locked */
            $locked = Asiento::query()
                ->whereKey($asiento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->esBorrador()) {
                throw new AsientoOperacionInvalidaException(
                    'Solo se pueden aprobar asientos en estado borrador.'
                );
            }

            $locked->loadMissing(['lineas', 'periodo']);

            $periodo = $locked->periodo;
            if ($periodo === null || ! $periodo->estaAbierto()) {
                throw new AsientoOperacionInvalidaException(
                    'No se puede aprobar: el periodo del asiento no está abierto.'
                );
            }

            if (! $locked->balanceado()) {
                throw new AsientoOperacionInvalidaException(
                    'El asiento no está balanceado. ∑D='
                    . $locked->totalDebito() . ', ∑C=' . $locked->totalCredito()
                );
            }

            if ($locked->lineas->count() < 2) {
                throw new AsientoOperacionInvalidaException(
                    'Un asiento requiere al menos 2 líneas (partida doble).'
                );
            }

            $locked->forceFill([
                'estado'         => Asiento::ESTADO_APROBADO,
                'approved_by_id' => $aprobador->id,
                'approved_at'    => now(),
            ])->save();

            $this->consecutivos->asignar($locked);

            $locked->refresh();
            event(new AsientoAprobado($locked, $aprobador));

            return $locked;
        });
    }

    // -----------------------------------------------------------------------
    // Anular (periodo abierto)
    // -----------------------------------------------------------------------

    public function anular(Asiento $asiento, User $contador, string $motivo): Asiento
    {
        return DB::transaction(function () use ($asiento, $contador, $motivo): Asiento {
            /** @var Asiento $locked */
            $locked = Asiento::query()
                ->whereKey($asiento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->esAprobado()) {
                throw new AsientoOperacionInvalidaException(
                    'Solo se pueden anular asientos aprobados.'
                );
            }

            $locked->loadMissing('periodo');
            if ($locked->periodo === null || ! $locked->periodo->estaAbierto()) {
                throw new AsientoOperacionInvalidaException(
                    'Solo se pueden anular asientos cuyo periodo está abierto. '
                    .'Use reverso para periodos cerrados.'
                );
            }

            $locked->forceFill([
                'estado'           => Asiento::ESTADO_ANULADO,
                'voided_by_id'     => $contador->id,
                'voided_at'        => now(),
                'motivo_anulacion' => $motivo,
            ])->save();

            $locked->refresh();
            event(new AsientoAnulado($locked, $contador, $motivo));

            return $locked;
        });
    }

    // -----------------------------------------------------------------------
    // Reversar (cualquier periodo)
    // -----------------------------------------------------------------------

    public function reversar(
        Asiento $original,
        User $contador,
        string $motivo,
        \DateTimeInterface|string|null $fechaReverso = null,
    ): Asiento {
        $fecha = $fechaReverso !== null
            ? CarbonImmutable::parse((string) $fechaReverso)
            : CarbonImmutable::now();

        $periodoReverso = PeriodoContable::actual($fecha);
        if (! $periodoReverso->estaAbierto()) {
            throw new AsientoOperacionInvalidaException(
                'La fecha del reverso debe caer en un periodo abierto.'
            );
        }

        return DB::transaction(function () use ($original, $contador, $motivo, $fecha, $periodoReverso): Asiento {
            /** @var Asiento $original */
            $original = Asiento::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $original->esAprobado()) {
                throw new AsientoOperacionInvalidaException(
                    'Solo se pueden reversar asientos aprobados.'
                );
            }

            $original->loadMissing('lineas');

            // forceCreate: permite escribir campos lifecycle en la creación del reverso
            // (estado, tipo_movimiento, approved_by_id, etc. no están en $fillable).
            /** @var Asiento $reverso */
            $reverso = (new Asiento())->forceFill([
                'fecha'             => $fecha->toDateString(),
                'periodo_id'        => $periodoReverso->id,
                'tipo_comprobante'  => $original->tipo_comprobante,
                'estado'            => Asiento::ESTADO_APROBADO,
                'tipo_movimiento'   => Asiento::TIPO_REVERSO,
                'descripcion'       => 'Reverso de '.$original->numero.' — '.$motivo,
                'comprobante'       => 'Reverso de '.$original->numero,
                'numero_documento'  => $original->numero,
                'sucursal_id'       => $original->sucursal_id,
                'origen_type'       => Asiento::class,
                'origen_id'         => $original->id,
                'origen_reverso_id' => $original->id,
                'created_by_id'     => $contador->id,
                'approved_by_id'    => $contador->id,
                'approved_at'       => now(),
                'motivo_reverso'    => $motivo,
            ]);
            $reverso->save();

            // Líneas espejo: cada D pasa a C y viceversa
            foreach ($original->lineas as $linea) {
                AsientoLinea::query()->create([
                    'asiento_id'           => $reverso->id,
                    'cuenta_id'            => $linea->cuenta_id,
                    'tercero_id'           => $linea->tercero_id,
                    'centro_costo_id'      => $linea->centro_costo_id,
                    'debito'               => $linea->credito,
                    'credito'              => $linea->debito,
                    'descripcion_item'     => '[REVERSO] '.($linea->descripcion_item ?? ''),
                    'documento_referencia' => $linea->documento_referencia,
                ]);
            }

            $this->consecutivos->asignar($reverso);

            // Marcar el original como reversado
            $original->forceFill([
                'estado'           => Asiento::ESTADO_REVERSADO,
                'reversado_por_id' => $reverso->id,
            ])->save();

            $reverso->refresh();
            $original->refresh();
            event(new AsientoReversado($original, $reverso, $contador, $motivo));

            return $reverso;
        });
    }

    // -----------------------------------------------------------------------
    // Descartar (borrar borrador)
    // -----------------------------------------------------------------------

    public function descartar(Asiento $asiento): void
    {
        if (! $asiento->esBorrador()) {
            throw new AsientoOperacionInvalidaException(
                'Solo se pueden descartar asientos en estado borrador.'
            );
        }
        $asiento->delete(); // soft delete
    }

    // -----------------------------------------------------------------------
    // Helpers privados
    // -----------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function reemplazarLineas(Asiento $asiento, array $lineas): void
    {
        $asiento->lineas()->delete();
        foreach ($lineas as $l) {
            AsientoLinea::query()->create([
                'asiento_id'           => $asiento->id,
                'cuenta_id'            => $l['cuenta_contable_id'] ?? $l['cuenta_id'],
                'tercero_id'           => $l['tercero_id'] ?? null,
                'centro_costo_id'      => $l['centro_costo_id'] ?? null,
                'debito'               => (float) ($l['debito'] ?? 0),
                'credito'              => (float) ($l['credito'] ?? 0),
                'descripcion_item'     => $l['descripcion'] ?? $l['descripcion_item'] ?? null,
                'documento_referencia' => $l['documento_referencia'] ?? null,
            ]);
        }
    }

    private function guardEnPeriodoEditable(PeriodoContable $periodo): void
    {
        if (! $periodo->estaAbierto()) {
            throw new AsientoOperacionInvalidaException(
                "El periodo {$periodo->codigo} no acepta nuevos asientos (estado={$periodo->estado})."
            );
        }
    }
}
