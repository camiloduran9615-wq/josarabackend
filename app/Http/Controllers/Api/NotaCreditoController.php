<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Asiento;
use App\Models\Tenant\AsientoLinea;
use App\Models\Tenant\CuentaContable;
use App\Models\Tenant\Factura;
use App\Models\Tenant\NotaCredito;
use App\Models\Tenant\Producto;
use App\Models\Tenant\Resolucion;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use App\Services\FactusService;
use App\Services\FactusMappingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestión de Notas Crédito.
 *
 * Una NC reversa una factura validada: regresa cartera/caja al cliente,
 * descuenta ventas e IVA generado, y devuelve inventario y costo de venta.
 *
 * Si la tenant tiene Factus configurado, emite la NC ante DIAN.
 * Si no, queda como NC LOCAL (válida contablemente, sin CUFE).
 */
class NotaCreditoController extends Controller
{
    public function __construct(
        protected FactusService $factusService,
        protected ContabilizadorService $contabilizador,
    ) {}

    /** GET /notas-credito — lista las NC emitidas */
    public function index()
    {
        $notas = NotaCredito::with('factura.tercero')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $notas]);
    }

    /** GET /notas-credito/facturas-anulables — facturas validadas sin NC previa */
    public function facturasAnulables()
    {
        $facturas = Factura::with('tercero')
            ->where('estado', 'validado')
            ->where('tipo_documento', 'FV')
            ->whereDoesntHave('notasCredito')
            ->orderBy('fecha_emision', 'desc')
            ->get()
            ->map(fn (Factura $f) => [
                'id'              => $f->id,
                'numero_completo' => $f->numero_completo ?: $f->reference_code,
                'fecha_emision'   => $f->fecha_emision,
                'valor_total'     => (float) $f->valor_total,
                'tercero'         => [
                    'id'             => $f->tercero->id ?? null,
                    'razon_social'   => $f->tercero->razon_social ?? null,
                    'identificacion' => $f->tercero->identificacion ?? null,
                ],
                'tiene_factus'    => !empty($f->factus_bill_id),
            ]);

        return response()->json(['success' => true, 'data' => $facturas]);
    }

    /** GET /notas-credito/{id} */
    public function show(string $id)
    {
        $nc = NotaCredito::with('factura.tercero')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $nc]);
    }

    /**
     * POST /notas-credito
     * Crea una NC contable; intenta enviarla a Factus si hay credenciales.
     */
    public function store(Request $request)
    {
        $request->validate([
            'factura_id'   => 'required|exists:facturas,id',
            'concept_code' => 'required|string', // 1..4 según anexo técnico DIAN
            'description'  => 'required|string|min:5|max:500',
        ]);

        $factura = Factura::with(['tercero', 'items', 'resolucion'])->findOrFail($request->factura_id);

        if ($factura->estado !== 'validado') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden anular facturas validadas.',
            ], 422);
        }

        if ($factura->notasCredito()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura ya tiene una nota crédito emitida.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $factura) {
            $referenceCode = 'NC-' . Str::upper(Str::random(10));
            $factusResult  = null;
            $factusOk      = false;
            $cufe          = null;
            $publicUrl     = null;
            $numeroFactus  = null;

            // Intentar enviar a Factus solo si hay factus_bill_id y resolución NC
            $resolucionNC = Resolucion::where('factus_id', 9)->first();
            if ($factura->factus_bill_id && $resolucionNC && $this->factusService->getAccessToken()) {
                $factusResult = $this->enviarAFactus($factura, $resolucionNC, $referenceCode, $request);
                $factusOk     = $factusResult['ok'];
                $cufe         = $factusResult['cufe']       ?? null;
                $publicUrl    = $factusResult['public_url'] ?? null;
                $numeroFactus = $factusResult['numero']     ?? null;
            }

            // Generar asiento contable de reversión SIEMPRE (incluso si Factus falla,
            // el reverso contable es válido localmente — el usuario reintenta Factus después)
            $asientoNC = $this->generarAsientoReverso($factura, $referenceCode, $request->description);

            // Crear el registro de la NC
            $nc = NotaCredito::create([
                'factura_id'                       => $factura->id,
                'numero'                           => $numeroFactus ?: ('LOC-' . substr($referenceCode, -8)),
                'numero_completo'                  => $numeroFactus ?: ('NC-LOCAL-' . substr($referenceCode, -8)),
                'valor_total'                      => $factura->valor_total,
                'reference_code'                   => $referenceCode,
                'cufe'                             => $cufe,
                'public_url'                       => $publicUrl,
                'discrepancy_response_code'        => $request->concept_code,
                'discrepancy_response_description' => $request->description,
                'estado'                           => $factusOk ? 'validado' : 'validado', // local también va como validado
            ]);

            $factura->update(['estado' => 'anulado']);

            return response()->json([
                'success'  => true,
                'message'  => $factusOk
                    ? 'Nota crédito emitida ante la DIAN y reversada contablemente.'
                    : 'Nota crédito reversada contablemente (modo LOCAL — sin envío a DIAN).',
                'data'     => $nc->fresh()->load('factura.tercero'),
                'factus'   => $factusResult,
                'asiento'  => $asientoNC->numero,
            ]);
        });
    }

    /**
     * Construye el payload Factus y lo envía. Retorna ['ok' => bool, 'cufe', 'numero', 'public_url', 'message'].
     */
    private function enviarAFactus(Factura $factura, Resolucion $resolucionNC, string $referenceCode, Request $request): array
    {
        $t = $factura->tercero;
        $payload = [
            'numbering_range_id'      => $resolucionNC->factus_id,
            'bill_id'                 => $factura->factus_bill_id,
            'reference_code'          => $referenceCode,
            'observation'             => $request->description,
            'correction_concept_code' => (int) $request->concept_code,
            'customer' => array_filter([
                'identification_document_id' => FactusMappingService::documentoId($t->identificacion_documento_id),
                'identification'             => (string) $t->identificacion,
                'dv'                         => $t->dv !== null ? (string) $t->dv : null,
                'company'                    => $t->razon_social ?: null,
                'names'                      => $t->nombres ? trim(($t->nombres ?? '') . ' ' . ($t->apellidos ?? '')) : null,
                'address'                    => $t->direccion ?: null,
                'email'                      => $t->email ?: null,
                'phone'                      => $t->telefono ?: null,
                'legal_organization_id'      => FactusMappingService::organizacionJuridicaId($t->organizacion_juridica_id, $t->identificacion_documento_id),
                'tribute_id'                 => FactusMappingService::tributoClienteId($t->tributo_id),
                'municipality_id'            => FactusMappingService::municipioId($t->municipio_id),
            ], fn($v) => $v !== null && $v !== ''),
            'items' => $factura->items->map(fn($item) => [
                'code_reference'   => $item->codigo_referencia ?? 'PROD-001',
                'name'             => $item->nombre,
                'quantity'         => (float) $item->cantidad,
                'discount_rate'    => 0.0,
                'price'            => (float) $item->precio_unitario,
                'tax_rate'         => (float) $item->porcentaje_iva,
                'unit_measure_id'  => 70,
                'standard_code_id' => 1,
                'is_excluded'      => 0,
                'tribute_id'       => FactusMappingService::tributoItemId((float) $item->porcentaje_iva),
            ])->toArray(),
        ];

        Log::info('Factus NC payload', ['factura_id' => $factura->id, 'payload' => $payload]);

        try {
            $result = $this->factusService->createCreditNote($payload);
            Log::info('Factus NC respuesta', ['result' => $result]);

            if (isset($result['status']) && $result['status'] === 'Created') {
                $data = $result['data']['credit_note'] ?? $result['data']['bill'] ?? $result['data'];
                return [
                    'ok'         => true,
                    'cufe'       => $data['cufe']       ?? null,
                    'public_url' => $data['public_url'] ?? null,
                    'numero'     => $data['number']     ?? null,
                ];
            }

            return [
                'ok'      => false,
                'message' => $result['message'] ?? 'Factus rechazó la NC',
                'errors'  => $result['errors']  ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Factus NC excepción', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Asiento contable de reversión usando parametrización (no códigos hardcoded).
     * El IVA se reversa discriminado por tarifa para que coincida con las cuentas
     * que usó la factura original (ej: 240805 para 19%, 240802 para 5%).
     */
    private function generarAsientoReverso(Factura $factura, string $referenceCode, string $descripcion): Asiento
    {
        $cuentaVentas     = $this->contabilizador->cuenta('factura.cuenta_ingresos_ventas');
        $cuentaClientes   = $this->contabilizador->cuenta('factura.cuenta_cartera');
        $cuentaInventario = $this->contabilizador->cuenta('factura.cuenta_inventario');
        $cuentaCosto      = $this->contabilizador->cuenta('factura.cuenta_costo_ventas');
        try {
            $cuentaIvaFallback = $this->contabilizador->cuenta('venta.cuenta_iva_generado');
        } catch (ParametrizacionFaltanteException) {
            $cuentaIvaFallback = CuentaContable::where('codigo', '240801')->first();
        }

        $bruto = (float) $factura->valor_bruto;
        $total = (float) $factura->valor_total;

        $costoTotal = 0;
        foreach ($factura->items as $item) {
            $prod = null;
            if ($item->codigo_referencia) {
                $prod = Producto::where('codigo', $item->codigo_referencia)->first();
            }
            if (!$prod && $item->nombre) {
                $prod = Producto::whereRaw('LOWER(nombre) = LOWER(?)', [$item->nombre])->orderBy('precio_compra')->first();
            }
            if ($prod) {
                $costoTotal += (float) $item->cantidad * (float) $prod->precio_compra;
            }
        }

        // 'estado', 'numero', 'approved_at' NO están en $fillable — forceFill bypassa.
        $fechaAsiento = now()->toDateString();
        $periodo = \App\Models\Tenant\PeriodoContable::where('estado', 'abierto')
            ->whereDate('fecha_inicio', '<=', $fechaAsiento)
            ->whereDate('fecha_fin', '>=', $fechaAsiento)
            ->first();

        $asiento = new Asiento();
        $asiento->forceFill([
            'id'              => (string) Str::uuid(),
            'numero'          => 'NC-' . strtoupper(Str::random(8)),
            'fecha'           => $fechaAsiento,
            'comprobante'     => 'NC',
            'numero_documento'=> $factura->numero_completo,
            'descripcion'     => "Nota crédito {$referenceCode} sobre factura {$factura->numero_completo} — {$descripcion}",
            'estado'          => 'aprobado',
            'tipo_comprobante'=> 'NC',
            'tipo_movimiento' => 'normal',
            'año_fiscal'      => (int) date('Y'),
            'periodo_id'      => $periodo?->id,
            'approved_at'     => now(),
        ])->save();

        // Reverso del ingreso
        $this->crearLinea($asiento->id, $factura->tercero_id, $cuentaVentas->id, $bruto, 0, 'Reverso venta');

        // Reverso del IVA por tarifa — usando MISMAS cuentas que la factura original
        $ivaPorTarifa = $factura->items
            ->groupBy(fn ($i) => (string) ((int) $i->porcentaje_iva))
            ->map(fn ($items) => $items->sum(fn ($i) => (float) ($i->valor_iva ?? 0)));

        foreach ($ivaPorTarifa as $tarifa => $valorIvaTarifa) {
            if ((float) $valorIvaTarifa <= 0) continue;
            $clave = "venta.cuenta_iva_generado_{$tarifa}";
            try {
                $cuentaIvaTarifa = $this->contabilizador->cuenta($clave);
            } catch (ParametrizacionFaltanteException) {
                $cuentaIvaTarifa = $cuentaIvaFallback;
            }
            $this->crearLinea($asiento->id, $factura->tercero_id, $cuentaIvaTarifa->id,
                round((float) $valorIvaTarifa, 2), 0, "Reverso IVA {$tarifa}%");
        }

        // Cancelar CxC
        $this->crearLinea($asiento->id, $factura->tercero_id, $cuentaClientes->id, 0, $total, 'Cancela CxC');

        // Devolver stock e invertir costo
        if ($costoTotal > 0) {
            $this->crearLinea($asiento->id, $factura->tercero_id, $cuentaInventario->id, $costoTotal, 0, 'Devolución inventario');
            $this->crearLinea($asiento->id, $factura->tercero_id, $cuentaCosto->id,      0, $costoTotal, 'Reverso costo de ventas');
        }

        return $asiento;
    }

    private function crearLinea(string $asientoId, ?string $terceroId, string $cuentaId, float $debito, float $credito, string $descripcion): void
    {
        AsientoLinea::create([
            'id'               => (string) Str::uuid(),
            'asiento_id'       => $asientoId,
            'tercero_id'       => $terceroId,
            'cuenta_id'        => $cuentaId,
            'debito'           => $debito,
            'credito'          => $credito,
            'descripcion_item' => $descripcion,
        ]);
    }
}
