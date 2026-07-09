<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivoFijoController;
use App\Http\Controllers\Api\AjusteCarteraController;
use App\Http\Controllers\Api\AsientoController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BalanceComprobacionController;
use App\Http\Controllers\Api\BalanceGeneralController;
use App\Http\Controllers\Api\BodegasController;
use App\Http\Controllers\Api\CentrosCostoController;
use App\Http\Controllers\Api\CierreAnualController;
use App\Http\Controllers\Api\ComprobanteEgresoController;
use App\Http\Controllers\Api\ConciliacionBancariaController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\CotizacionController;
use App\Http\Controllers\Api\CrmController;
use App\Http\Controllers\Api\CuentaContableController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardEjecutivoController;
use App\Http\Controllers\Api\DocumentoIngresoController;
use App\Http\Controllers\Api\EstadoCambiosPatrimonioController;
use App\Http\Controllers\Api\EstadoResultadosController;
use App\Http\Controllers\Api\EstadosFinancierosCsvController;
use App\Http\Controllers\Api\FacturaController;
use App\Http\Controllers\Api\FlujoEfectivoController;
use App\Http\Controllers\Api\FormularioRentaController;
use App\Http\Controllers\Api\ImpuestoController;
use App\Http\Controllers\Api\InformacionExogenaController;
use App\Http\Controllers\Api\KardexController;
use App\Http\Controllers\Api\LibroMayorController;
use App\Http\Controllers\Api\MunicipioDaneController;
use App\Http\Controllers\Api\NominaController;
use App\Http\Controllers\Api\NotaCreditoController;
use App\Http\Controllers\Api\NotaDebitoController;
use App\Http\Controllers\Api\NotasEstadosFinancierosController;
use App\Http\Controllers\Api\ParametrizacionContableController;
// Dashboard KPIs
use App\Http\Controllers\Api\PeriodoContableController;
use App\Http\Controllers\Api\ProductoController;
// Nómina Electrónica DIAN
use App\Http\Controllers\Api\ReciboCajaController;
// CRM Básico
use App\Http\Controllers\Api\RemisionController;
// Conciliación Bancaria
use App\Http\Controllers\Api\ReportController;
// EPIC-LMB-001 — Libro Mayor, Balances, Impuestos, Cierre Anual
use App\Http\Controllers\Api\ResolucionController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\TerceroController;
use App\Http\Controllers\Api\TipoComprobanteController;
use App\Http\Controllers\Api\TipoDocumentoIngresoController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\InitializeTenancyByTenantIdentifier;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes — Recursos contables por empresa
|--------------------------------------------------------------------------
| DESARROLLO LOCAL: Usamos identificación por PATH (/api/v1/{tenant}/...)
| PRODUCCIÓN: se puede resolver por subdominio usando el mismo identificador público
|
| Ejemplo local:  POST http://localhost/api/v1/{tenant-slug}/users
| Ejemplo prod:   POST https://empresa.saascontable.com/api/v1/users
*/

Route::prefix('api/v1/{tenant}')
    ->middleware([InitializeTenancyByTenantIdentifier::class])
    ->group(function () {

        // Salud del tenant — sin autenticación (útil para healthchecks de infra),
        // pero NO expone PII (razon_social) ni el UUID interno del tenant.
        // Para obtener info del tenant usar GET /api/v1/{tenant}/configs (autenticado).
        Route::get('/health', fn () => response()->json(['status' => 'active']));

        // Logo del tenant — público (aparece en facturas e iframes externos).
        // Sirve desde el disco 'public' que está tenant-isolated por FilesystemTenancyBootstrapper.
        Route::get('/logo', [ConfigController::class, 'viewLogo'])->name('tenant.logo');

        // ── Auth del tenant ────────────────────────────────────────────────
        // Login está en api.php (central) porque necesita identificar el tenant.
        // Logout y me están aquí porque el token Sanctum vive en la DB del tenant
        // (se creó después de tenancy()->initialize() en el login).
        Route::prefix('auth')->middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('tenant.auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('tenant.auth.me');
        });

        // ── Recursos protegidos (requieren token Sanctum) ─────────────────
        // FIX C-2 (HANDOFF.md / QA_TEST_REPORT.md): 'token.can-mutate' bloquea
        // POST/PUT/PATCH/DELETE de usuarios sin abilities de escritura en su
        // token (en la práctica, el rol readonly). Ver
        // App\Http\Middleware\EnsureTokenCanMutate para el detalle completo
        // de por qué esto no afecta a admin/contador/auxiliar/auditor.
        Route::middleware(['auth:sanctum', 'token.can-mutate', 'tenant.plan-limits'])->group(function () {

            // Dashboard KPIs — cacheable, lectura ligera
            Route::get('dashboard', DashboardController::class)->name('dashboard.kpis');
            // FEAT-AA: Dashboard Ejecutivo (KPIs gerenciales avanzados)
            Route::get('dashboard-ejecutivo', DashboardEjecutivoController::class)->name('dashboard.ejecutivo');

            // Gestión de Usuarios
            Route::apiResource('users', UserController::class);
            Route::put('users/{id}/password', [UserController::class, 'changePassword'])
                ->name('users.password');


            // Catálogo central DANE administrable desde Configuración.
            Route::get('municipios-dane', [MunicipioDaneController::class, 'adminIndex']);
            Route::post('municipios-dane/sync', [MunicipioDaneController::class, 'sync']);
            Route::post('municipios-dane', [MunicipioDaneController::class, 'store']);
            Route::put('municipios-dane/{codigo}', [MunicipioDaneController::class, 'update']);
            Route::delete('municipios-dane/{codigo}', [MunicipioDaneController::class, 'destroy']);

            // Gestión de Terceros
            Route::get('terceros/search-dian', [TerceroController::class, 'searchDian']);
            Route::apiResource('terceros', TerceroController::class)->except(['show']);

            // Facturación Electrónica
            Route::get('facturas', [FacturaController::class, 'index']);
            Route::post('facturas', [FacturaController::class, 'store']);
            Route::get('facturas/ranges', [FacturaController::class, 'ranges']);
            Route::get('facturas/{id}', [FacturaController::class, 'show']);
            Route::post('facturas/{id}/enviar', [FacturaController::class, 'enviar']);
            Route::get('notas-credito', [NotaCreditoController::class, 'index']);
            Route::get('notas-credito/facturas-anulables', [NotaCreditoController::class, 'facturasAnulables']);
            Route::post('notas-credito', [NotaCreditoController::class, 'store']);
            Route::get('notas-credito/{id}', [NotaCreditoController::class, 'show']);

            // Reportes
            Route::get('reports/withholdings', [ReportController::class, 'withholdings']);
            Route::get('reports/withholding-certificate/{tercero}', [ReportController::class, 'certificate']);
            // FEAT-B: Retenciones practicadas a proveedores (lo opuesto a withholdings).
            Route::get('reports/retefuente-practicada', [ReportController::class, 'retefuentePracticada']);
            Route::get('reports/retefuente-practicada-certificate/{tercero}', [ReportController::class, 'certificateRetefuentePracticada']);
            // FEAT-C: Formulario 300 IVA bimestral DIAN.
            Route::get('reports/iva-bimestral', [ReportController::class, 'ivaBimestral']);
            // FEAT-D: Formulario 350 retenciones mensual DIAN.
            Route::get('reports/retenciones-mensual', [ReportController::class, 'retencionesMensual']);

            // FEAT-E: Activos Fijos + Depreciación NIC 16
            Route::apiResource('activos-fijos', ActivoFijoController::class);
            Route::post('activos-fijos/depreciar/{anio}/{mes}', [ActivoFijoController::class, 'depreciar'])
                ->where('anio', '[0-9]{4}')
                ->where('mes', '[0-9]{1,2}');

            // FEAT-F: Estado de Cambios en el Patrimonio (NIC 1)
            Route::get('reports/estado-cambios-patrimonio', EstadoCambiosPatrimonioController::class);
            // FEAT-G: Estado de Flujo de Efectivo (NIC 7) método indirecto
            Route::get('reports/flujo-efectivo', FlujoEfectivoController::class);

            // FEAT-I/J/K/L: Información Exógena DIAN (medios magnéticos)
            Route::get('reports/exogena-1001', [InformacionExogenaController::class, 'formato1001']);
            Route::get('reports/exogena-1003', [InformacionExogenaController::class, 'formato1003']);
            Route::get('reports/exogena-1005', [InformacionExogenaController::class, 'formato1005']);
            Route::get('reports/exogena-1006', [InformacionExogenaController::class, 'formato1006']);
            Route::get('reports/exogena-1007', [InformacionExogenaController::class, 'formato1007']);
            Route::get('reports/exogena-1008', [InformacionExogenaController::class, 'formato1008']);
            Route::get('reports/exogena-1009', [InformacionExogenaController::class, 'formato1009']);
            // FEAT-N: descarga CSV directa (compatible con MUISCA DIAN)
            Route::get('reports/exogena-{formato}/csv', [InformacionExogenaController::class, 'csv'])
                ->where('formato', '[0-9]{4}');
            // FEAT-O: descarga CSV de Estados Financieros NIIF
            Route::get('reports/csv/balance-general', [EstadosFinancierosCsvController::class, 'balanceGeneral']);
            Route::get('reports/csv/estado-resultados', [EstadosFinancierosCsvController::class, 'estadoResultados']);
            Route::get('reports/csv/estado-cambios-patrimonio', [EstadosFinancierosCsvController::class, 'estadoCambiosPatrimonio']);
            Route::get('reports/csv/flujo-efectivo', [EstadosFinancierosCsvController::class, 'flujoEfectivo']);
            Route::get('reports/csv/balance-comprobacion', [EstadosFinancierosCsvController::class, 'balanceComprobacion']);

            // FEAT-P: Notas a los Estados Financieros (NIC 1.117)
            Route::get('reports/notas-estados-financieros', NotasEstadosFinancierosController::class);

            // FEAT-W: Formulario 110 DIAN — Declaración de Renta y Complementario
            Route::get('reports/formulario-110', FormularioRentaController::class);

            // Configuración
            Route::get('configs', [ConfigController::class, 'index']);
            Route::post('configs', [ConfigController::class, 'update']);
            // Logo de empresa — rutas específicas ANTES del wildcard configs/{id}
            Route::post('configs/logo', [ConfigController::class, 'uploadLogo']);
            Route::delete('configs/logo', [ConfigController::class, 'deleteLogo']);
            // Configuración Factus (multi-tenant — cada empresa sus credenciales DIAN)
            Route::get('configs/factus', [ConfigController::class, 'showFactus']);
            Route::post('configs/factus', [ConfigController::class, 'updateFactus']);
            Route::post('configs/factus/test', [ConfigController::class, 'testFactus']);

            // Gestión de Inventarios
            Route::apiResource('productos', ProductoController::class);
            Route::post('productos/movimiento', [ProductoController::class, 'registrarMovimiento']);

            // Resoluciones de Facturación
            Route::apiResource('resoluciones', ResolucionController::class);
            Route::post('resoluciones/sync', [ResolucionController::class, 'syncFromFactus']);

            // Tipos de Comprobante (FV-1, FV-2, DC-1…)
            Route::apiResource('tipo-comprobantes', TipoComprobanteController::class)->except(['show', 'edit', 'create']);

            // Sucursales Internas
            Route::apiResource('sucursales', SucursalController::class);

            // Facturas de Compra (antes "Documentos de Ingreso")
            Route::apiResource('facturas-compra', DocumentoIngresoController::class)
                ->except(['edit', 'create', 'update']);
            // Alias de compatibilidad
            Route::apiResource('documentos-ingreso', DocumentoIngresoController::class)
                ->except(['edit', 'create', 'update']);

            // Comprobantes de Egreso (pagos a proveedores)
            Route::apiResource('comprobantes-egreso', ComprobanteEgresoController::class)
                ->except(['edit', 'create', 'update']);

            // Recibos de Caja
            Route::get('recibos-caja/cartera/{terceroId}', [ReciboCajaController::class, 'cartera']);
            Route::apiResource('recibos-caja', ReciboCajaController::class)
                ->except(['edit', 'create', 'update']);

            // Notas Débito
            Route::apiResource('notas-debito', NotaDebitoController::class)
                ->except(['edit', 'create', 'update']);

            // Remisiones
            Route::apiResource('remisiones', RemisionController::class)
                ->except(['edit', 'create']);

            // Cotizaciones
            Route::apiResource('cotizaciones', CotizacionController::class)
                ->except(['edit', 'create']);

            // Ajustes de Cartera
            Route::apiResource('ajustes-cartera', AjusteCarteraController::class)
                ->except(['edit', 'create', 'update']);

            // ─── Inventario Multi-Bodega ──────────────────────────────────────
            // Bodegas
            Route::apiResource('bodegas', BodegasController::class)->except(['edit', 'create']);

            // Centros de Costo
            Route::apiResource('centros-costo', CentrosCostoController::class)->except(['edit', 'create']);

            // KARDEX y valorización (rutas fijas antes del wildcard de stock)
            Route::get('kardex/valorizacion', [KardexController::class, 'valorizacion']);
            Route::get('kardex/stock/{productoId}', [KardexController::class, 'stockTotal']);
            Route::get('kardex', [KardexController::class, 'index']);

            // Tipos de Documento de Ingreso (parametrización estilo SIIGO)
            Route::apiResource('tipos-documento-ingreso', TipoDocumentoIngresoController::class)
                ->except(['edit', 'create']);

            // Parametrización Contable (cuentas por módulo)
            Route::get('parametrizacion-contable', [ParametrizacionContableController::class, 'index']);
            // Validación de claves críticas faltantes por módulo (antes del wildcard)
            Route::get('parametrizacion-contable/validar/{modulo}', [ParametrizacionContableController::class, 'validar'])
                ->where('modulo', 'compra|factura|cierre');
            Route::put('parametrizacion-contable/{clave}', [ParametrizacionContableController::class, 'update'])
                ->where('clave', '.+');   // permite puntos en la clave
            Route::post('parametrizacion-contable/bulk', [ParametrizacionContableController::class, 'bulk']);

            // Gestión del PUC (Plan Único de Cuentas)
            Route::get('cuentas-contables', [CuentaContableController::class, 'index']);
            Route::post('cuentas-contables', [CuentaContableController::class, 'store']);
            Route::put('cuentas-contables/{id}', [CuentaContableController::class, 'update']);
            Route::delete('cuentas-contables/{id}', [CuentaContableController::class, 'destroy']);

            // ─── EPIC-002: Cerrar el Ciclo Contable ───────────────────────
            // Asientos contables
            Route::get('asientos', [AsientoController::class, 'index']);
            Route::post('asientos', [AsientoController::class, 'store']);
            Route::get('asientos/{id}', [AsientoController::class, 'show']);
            Route::put('asientos/{id}', [AsientoController::class, 'update']);
            Route::delete('asientos/{id}', [AsientoController::class, 'destroy']);

            // Acciones de dominio: rate-limited para evitar abuso
            Route::middleware('throttle:30,1')->group(function () {
                Route::post('asientos/{id}/aprobar', [AsientoController::class, 'aprobar']);
                Route::post('asientos/{id}/anular', [AsientoController::class, 'anular']);
                Route::post('asientos/{id}/reversar', [AsientoController::class, 'reversar']);
            });

            // Periodos contables
            Route::get('periodos', [PeriodoContableController::class, 'index']);
            Route::get('periodos/{id}', [PeriodoContableController::class, 'show']);
            Route::get('periodos/{id}/checklist-cierre', [PeriodoContableController::class, 'checklistCierre']);

            // Operaciones de cierre: muy restrictivas — 5 por minuto por usuario
            Route::middleware('throttle:5,1')->group(function () {
                Route::post('periodos/{id}/cerrar', [PeriodoContableController::class, 'cerrar']);
                Route::post('periodos/{id}/reabrir/solicitar', [PeriodoContableController::class, 'solicitarReapertura']);
                Route::post('periodos/{id}/reabrir/aprobar', [PeriodoContableController::class, 'aprobarReapertura']);
                Route::post('periodos/{id}/bloquear-fiscal', [PeriodoContableController::class, 'bloquearFiscal']);
            });

            // Audit logs (solo lectura)
            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('audit-logs/{id}', [AuditLogController::class, 'show']);

            // Export y verify-chain: operaciones costosas O(n) — muy limitadas.
            // NOMBRE explícito: FIX REG-1 usa estos nombres en EnsureTokenCanMutate
            // para permitir que el rol `auditor` (ability 'export') las ejecute
            // sin abrir la puerta a mutaciones de negocio en el resto de rutas.
            Route::middleware('throttle:3,1')->group(function () {
                Route::post('audit-logs/export', [AuditLogController::class, 'export'])
                    ->name('audit-logs.export');
                Route::post('audit-logs/verify-chain', [AuditLogController::class, 'verifyChain'])
                    ->name('audit-logs.verify-chain');
            });

            // ─── Conciliación Bancaria ────────────────────────────────────────
            Route::get('extractos-bancarios', [ConciliacionBancariaController::class, 'index']);
            Route::post('extractos-bancarios/importar', [ConciliacionBancariaController::class, 'importar']);
            Route::get('extractos-bancarios/{id}/lineas', [ConciliacionBancariaController::class, 'lineas']);
            Route::post('extractos-bancarios/{id}/conciliar-auto', [ConciliacionBancariaController::class, 'conciliarAuto']);
            Route::post('extractos-bancarios/{id}/conciliar-manual', [ConciliacionBancariaController::class, 'conciliarManual']);
            // FEAT-H: papel de trabajo de conciliación bancaria
            Route::get('extractos-bancarios/{id}/reporte-conciliacion', [ConciliacionBancariaController::class, 'reporteConciliacion']);

            // ─── CRM Básico ────────────────────────────────────────────────────
            Route::get('prospectos', [CrmController::class, 'prospectosIndex']);
            Route::post('prospectos', [CrmController::class, 'prospectosStore']);
            Route::put('prospectos/{id}', [CrmController::class, 'prospectosUpdate']);
            Route::delete('prospectos/{id}', [CrmController::class, 'prospectosDestroy']);
            Route::get('oportunidades', [CrmController::class, 'oportunidadesIndex']);
            Route::post('oportunidades', [CrmController::class, 'oportunidadesStore']);
            Route::put('oportunidades/{id}', [CrmController::class, 'oportunidadesUpdate']);
            Route::put('oportunidades/{id}/etapa', [CrmController::class, 'cambiarEtapa']);
            Route::get('actividades-crm', [CrmController::class, 'actividadesIndex']);
            Route::post('actividades-crm', [CrmController::class, 'actividadesStore']);

            // ─── EPIC-LMB-001: Libro Mayor, Balances e Impuestos ─────────────
            // Reportes — lectura intensiva; cache los protege de N+1
            Route::middleware('throttle:60,1')->group(function () {
                Route::get('libro-mayor/{cuentaId}', LibroMayorController::class)
                    ->name('libro-mayor.query');

                Route::get('balance-general', BalanceGeneralController::class)
                    ->name('balance-general.show');

                Route::get('estado-resultados', EstadoResultadosController::class)
                    ->name('estado-resultados.show');

                Route::get('balance-comprobacion', BalanceComprobacionController::class)
                    ->name('balance-comprobacion.show');
            });

            // Impuestos — CRUD + calculadora
            // La ruta fija /calcular debe ir ANTES del wildcard {impuesto} de apiResource
            Route::post('impuestos/calcular', [ImpuestoController::class, 'calcular'])
                ->name('impuestos.calcular');
            Route::apiResource('impuestos', ImpuestoController::class)
                ->except(['edit', 'create']);

            // ─── Nómina Electrónica DIAN ──────────────────────────────────
            // Empleados
            Route::get('empleados', [NominaController::class, 'empleadosIndex']);
            Route::post('empleados', [NominaController::class, 'empleadosStore']);
            Route::put('empleados/{id}', [NominaController::class, 'empleadosUpdate']);
            Route::delete('empleados/{id}', [NominaController::class, 'empleadosDestroy']);
            // Contratos laborales
            Route::get('contratos', [NominaController::class, 'contratosIndex']);
            Route::post('contratos', [NominaController::class, 'contratosStore']);
            // Periodos de nómina
            Route::get('periodos-nomina', [NominaController::class, 'periodosNominaIndex']);
            Route::post('periodos-nomina', [NominaController::class, 'periodosNominaStore']);
            // Liquidaciones
            Route::get('liquidaciones', [NominaController::class, 'liquidacionesIndex']);
            Route::post('liquidaciones/{id}/aprobar', [NominaController::class, 'aprobar']);
            Route::get('liquidaciones/{id}/xml', [NominaController::class, 'generarXml']);
            Route::post('liquidaciones/{empleadoId}/{periodoId}', [NominaController::class, 'liquidar'])
                ->where('empleadoId', '[0-9a-f-]{36}')
                ->where('periodoId', '[0-9a-f-]{36}');

            // Cierre anual — operación crítica e irreversible: 3 intentos/min
            Route::middleware('throttle:3,1')->group(function () {
                Route::post('cierre-anual/{anio}', CierreAnualController::class)
                    ->where('anio', '[0-9]{4}')
                    ->name('cierre-anual.ejecutar');
            });
        });
    });
