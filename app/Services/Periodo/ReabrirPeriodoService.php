<?php

declare(strict_types=1);

namespace App\Services\Periodo;

use App\Events\Periodo\PeriodoReabierto;
use App\Models\DualApproval;
use App\Models\Tenant\PeriodoContable;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de reapertura de periodo cerrado en dos pasos:
 *  1) request — el contador solicita la reapertura con motivo.
 *  2) approve — un admin (≠ contador) aprueba antes de 30 minutos.
 *
 * No se permite reabrir periodos en estado bloqueado_fiscal.
 */
class ReabrirPeriodoService
{
    private const TTL_MINUTOS = 30;

    public function __construct(private readonly AuditLogService $logger) {}

    public function solicitar(PeriodoContable $periodo, User $contador, string $motivo): DualApproval
    {
        if ($periodo->estaBloqueadoFiscalmente()) {
            throw new PeriodoOperacionInvalidaException(
                'El periodo está bloqueado fiscalmente y no puede reabrirse vía sistema.'
            );
        }
        if ($periodo->estado !== PeriodoContable::ESTADO_CERRADO) {
            throw new PeriodoOperacionInvalidaException(
                'Solo se pueden solicitar reaperturas de periodos cerrados.'
            );
        }

        return DB::transaction(function () use ($periodo, $contador, $motivo): DualApproval {
            /** @var DualApproval $req */
            $req = DualApproval::query()->create([
                'tenant_id'       => function_exists('tenant') ? (string) tenant('id') : '00000000-0000-0000-0000-000000000000',
                'action'          => DualApproval::ACTION_PERIODO_REOPEN,
                'subject_type'    => PeriodoContable::class,
                'subject_id'      => (string) $periodo->id,
                'requested_by_id' => (string) $contador->id,
                'payload'         => [
                    'codigo' => $periodo->codigo,
                ],
                'motivo'          => $motivo,
                'expires_at'      => CarbonImmutable::now()->addMinutes(self::TTL_MINUTOS),
            ]);

            $this->logger->record(
                action: 'periodo.reopen_requested',
                criticidad: AuditLogService::CRITICIDAD_WARNING,
                auditable: $periodo,
                motivo: $motivo,
                metadata: [
                    'request_id'   => $req->id,
                    'requested_by' => $contador->id,
                ],
            );

            return $req;
        });
    }

    public function aprobar(string $requestId, User $admin): PeriodoContable
    {
        /** @var DualApproval|null $req */
        $req = DualApproval::query()->find($requestId);
        if ($req === null) {
            throw new PeriodoOperacionInvalidaException('Solicitud de reapertura no encontrada.');
        }
        if ($req->action !== DualApproval::ACTION_PERIODO_REOPEN) {
            throw new PeriodoOperacionInvalidaException('La solicitud no corresponde a una reapertura.');
        }
        if ($req->isApproved()) {
            throw new PeriodoOperacionInvalidaException('La solicitud ya fue aprobada.');
        }
        if ($req->isExpired()) {
            throw new PeriodoOperacionInvalidaException('La solicitud expiró. Inicie una nueva.');
        }
        if ((string) $req->requested_by_id === (string) $admin->id) {
            throw new PeriodoOperacionInvalidaException(
                'El admin aprobador no puede ser el mismo usuario que solicitó la reapertura.'
            );
        }

        return DB::transaction(function () use ($req, $admin): PeriodoContable {
            /** @var PeriodoContable|null $periodo */
            $periodo = PeriodoContable::query()->find($req->subject_id);
            if ($periodo === null) {
                throw new PeriodoOperacionInvalidaException('El periodo ya no existe.');
            }
            if ($periodo->estaBloqueadoFiscalmente()) {
                throw new PeriodoOperacionInvalidaException(
                    'El periodo está bloqueado fiscalmente.'
                );
            }

            $periodo->update([
                'estado'             => PeriodoContable::ESTADO_ABIERTO,
                'reabierto_por_id'   => $admin->id,
                'reabierto_at'       => now(),
                'motivo_reapertura'  => $req->motivo,
            ]);

            $req->update([
                'approved_at'    => now(),
                'approved_by_id' => $admin->id,
            ]);

            // El contador del request se necesita para el evento.
            /** @var User|null $contador */
            $contador = User::query()->find($req->requested_by_id);
            if ($contador !== null) {
                event(new PeriodoReabierto($periodo, $contador, $admin, $req->motivo));
            }

            return $periodo->fresh();
        });
    }
}
