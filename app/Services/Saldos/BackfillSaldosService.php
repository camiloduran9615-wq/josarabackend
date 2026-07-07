<?php

declare(strict_types=1);

namespace App\Services\Saldos;

use App\Models\Tenant as TenantCentralModel;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\CuentaSaldo;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stancl\Tenancy\Facades\Tenancy;
use Throwable;

/**
 * Reconstruye `cuenta_saldos` desde la verdad histórica: los asientos APROBADOS
 * (y NO los anulados ni los borradores) de un tenant.
 *
 * Casos de uso:
 *  - **Migración inicial** EPIC-LMB-001: el tenant tiene asientos legacy aprobados pero
 *    cuenta_saldos vacío. Se ejecuta una sola vez (idempotente).
 *  - **Recovery** post-incidente: tras detectar corrupción (ReconciliarSaldosService),
 *    el admin ejecuta `--fresh` para reconstruir desde cero.
 *
 * Reanudable: persiste checkpoint en `tenants.data->backfill_state` con:
 *   {
 *     last_asiento_id:    UUID del último asiento procesado (ORDER BY id),
 *     asientos_procesados: int,
 *     started_at:         ISO8601,
 *     completed:          bool,
 *     completed_at:       ISO8601|null,
 *     error:              string|null,
 *   }
 *
 * IMPORTANTE: NO dispara `AsientoAprobado` durante el backfill — los listeners de
 * Audit y N8n YA registraron el evento original cuando el asiento se aprobó la
 * primera vez. Re-emitir generaría duplicados en AuditLog y webhooks falsos.
 * Por eso llama directamente a `SaldoUpserter`.
 */
final class BackfillSaldosService
{
    public const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly SaldoUpserter $upserter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ejecuta el backfill para un tenant.
     *
     * @param  string  $tenantId  UUID del tenant
     * @param  bool    $fresh     true → trunca cuenta_saldos y reinicia checkpoint
     *
     * @return array{processed: int, skipped: int, completed: bool}
     *
     * @throws RuntimeException si el tenant no existe
     */
    public function ejecutar(string $tenantId, bool $fresh = false): array
    {
        $tenant = TenantCentralModel::query()->find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$tenantId} no existe.");
        }

        // Marcar inicio del backfill en BD central (antes de switch a tenant DB)
        $this->marcarInicio($tenant, $fresh);
        $checkpointInicial = $fresh ? null : $this->leerCheckpoint($tenant);

        Tenancy::initialize($tenant);
        $procesados = 0;
        $omitidos   = 0;

        try {
            if ($fresh) {
                $this->resetSaldos();
            }

            $ultimoIdProcesado = $checkpointInicial;

            while (true) {
                $chunk = $this->siguienteChunk($ultimoIdProcesado);
                if ($chunk->isEmpty()) {
                    break;
                }

                foreach ($chunk as $asiento) {
                    try {
                        $this->procesarAsiento($asiento);
                        $procesados++;
                    } catch (Throwable $e) {
                        $this->logger->warning('Backfill saldos: asiento fallido', [
                            'tenant_id'  => $tenantId,
                            'asiento_id' => $asiento->id,
                            'error'      => $e->getMessage(),
                        ]);
                        $omitidos++;
                    }
                    $ultimoIdProcesado = (string) $asiento->id;
                }

                // Checkpoint tras cada chunk completo. Si la corrida muere aquí,
                // un re-run reanuda desde este punto. Trade-off: si un asiento
                // dentro del chunk falla a mitad, la próxima iteración lo re-procesa
                // (idempotente porque SaldoUpserter usa UPSERT por delta).
                if ($ultimoIdProcesado !== null) {
                    Tenancy::end();
                    $this->actualizarCheckpoint($tenant, $ultimoIdProcesado, $procesados);
                    Tenancy::initialize($tenant);
                }
            }

            Tenancy::end();
            $this->marcarCompletado($tenant, $procesados);

            return ['processed' => $procesados, 'skipped' => $omitidos, 'completed' => true];
        } catch (Throwable $e) {
            try {
                Tenancy::end();
            } catch (Throwable) {
                // ignore — el throw original es más importante
            }
            $this->marcarError($tenant, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Asiento>
     */
    private function siguienteChunk(?string $ultimoId): \Illuminate\Database\Eloquent\Collection
    {
        $query = Asiento::query()
            ->where('estado', 'aprobado')
            ->whereNotNull('periodo_id')
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE);

        if ($ultimoId !== null) {
            $query->where('id', '>', $ultimoId);
        }

        return $query->get();
    }

    /**
     * Procesa un asiento: carga sus líneas, agrupa por dimensiones y aplica deltas.
     * Se envuelve en transacción para que un fallo parcial no deje saldos a medias.
     */
    private function procesarAsiento(Asiento $asiento): void
    {
        if ($asiento->periodo_id === null) {
            throw new RuntimeException("Asiento {$asiento->id} aprobado sin periodo_id.");
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Tenant\AsientoLinea> $lineas */
        $lineas = $asiento->lineas()->get();

        if ($lineas->isEmpty()) {
            return;
        }

        $deltas = $this->upserter->agruparLineas(
            $lineas,
            (string) $asiento->periodo_id,
            $asiento->sucursal_id !== null ? (string) $asiento->sucursal_id : null,
        );

        DB::transaction(function () use ($deltas): void {
            foreach ($deltas as $delta) {
                $this->upserter->aplicar($delta, invertir: false);
            }
        });
    }

    /**
     * Trunca `cuenta_saldos` del tenant actualmente inicializado.
     * Usado solo con --fresh.
     */
    private function resetSaldos(): void
    {
        // Hard delete, no soft delete: la tabla no tiene deleted_at y queremos ahorrar
        // espacio para tenants grandes. La fuente de verdad sigue siendo asientos.
        CuentaSaldo::query()->delete();
    }

    // ── Checkpoint en BD CENTRAL (tenant.data->backfill_state) ───────────────

    private function marcarInicio(TenantCentralModel $tenant, bool $fresh): void
    {
        $estado = [
            'started_at'          => now()->toIso8601String(),
            'last_asiento_id'     => $fresh ? null : ($this->leerCheckpoint($tenant)),
            'asientos_procesados' => $fresh ? 0 : (int) ($this->leerEstado($tenant)['asientos_procesados'] ?? 0),
            'completed'           => false,
            'completed_at'        => null,
            'error'               => null,
            'fresh'               => $fresh,
        ];
        $tenant->setAttribute('backfill_state', $estado);
        $tenant->save();
    }

    private function actualizarCheckpoint(TenantCentralModel $tenant, string $ultimoId, int $procesados): void
    {
        $estado = $this->leerEstado($tenant);
        $estado['last_asiento_id']     = $ultimoId;
        $estado['asientos_procesados'] = $procesados;
        $tenant->setAttribute('backfill_state', $estado);
        $tenant->save();
    }

    private function marcarCompletado(TenantCentralModel $tenant, int $procesados): void
    {
        $estado = $this->leerEstado($tenant);
        $estado['completed']           = true;
        $estado['completed_at']        = now()->toIso8601String();
        $estado['asientos_procesados'] = $procesados;
        $tenant->setAttribute('backfill_state', $estado);
        $tenant->save();
    }

    private function marcarError(TenantCentralModel $tenant, string $mensaje): void
    {
        $estado = $this->leerEstado($tenant);
        $estado['error']     = $mensaje;
        $estado['completed'] = false;
        $tenant->setAttribute('backfill_state', $estado);
        $tenant->save();
    }

    private function leerCheckpoint(TenantCentralModel $tenant): ?string
    {
        $estado = $this->leerEstado($tenant);
        $lastId = $estado['last_asiento_id'] ?? null;
        return is_string($lastId) ? $lastId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function leerEstado(TenantCentralModel $tenant): array
    {
        $estado = $tenant->getAttribute('backfill_state');
        return is_array($estado) ? $estado : [];
    }
}
