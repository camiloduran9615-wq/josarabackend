<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EPIC-002 — Verificación nocturna del hash chain de auditoría.
// Detecta tampering de audit_logs y emite alerta crítica al log.
Schedule::command('audit:verify-chain')
    ->dailyAt('03:00')
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::critical(
            'audit:verify-chain falló — posible manipulación detectada.'
        );
    });

// Limpieza de solicitudes de aprobación dual expiradas (BD central).
Schedule::call(function (): void {
    \App\Models\DualApproval::query()
        ->whereNull('approved_at')
        ->where('expires_at', '<', now()->subDay())
        ->delete();
})->daily()->name('purge-expired-dual-approvals')->withoutOverlapping();

// EPIC-LMB-001 — Reconciliación nocturna de cuenta_saldos vs asiento_lineas
// para todos los tenants activos. Detecta drift y emite SaldosInconsistenciaDetectada,
// que el listener N8n propaga al webhook del tenant.
// 02:00 hora Colombia (UTC-5). Antes del audit:verify-chain (03:00) para que las
// inconsistencias detectadas queden auditadas en la corrida de chain del mismo día.
Schedule::command('saldos:reconciliar --all')
    ->dailyAt('02:00')
    ->timezone('America/Bogota')
    ->name('saldos-reconciliacion-nightly')
    ->withoutOverlapping(30) // 30 min lock — evita solapamiento si tarda mucho
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::critical(
            'saldos:reconciliar nocturno falló — revisar logs y workers de cola saldos-reconciliacion.'
        );
    });
