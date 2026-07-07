<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ActivoFijo;
use App\Models\Tenant\CuentaContable;
use App\Services\ActivosFijos\DepreciacionService;
use App\Services\Contabilizacion\ContabilizadorService;
use App\Services\Contabilizacion\ParametrizacionFaltanteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestión de Activos Fijos (Propiedad, Planta y Equipo — NIC 16).
 *
 * Endpoints:
 *   GET    /activos-fijos                    listar
 *   POST   /activos-fijos                    crear
 *   GET    /activos-fijos/{id}               ver detalle
 *   PUT    /activos-fijos/{id}               actualizar
 *   DELETE /activos-fijos/{id}               soft delete
 *   POST   /activos-fijos/depreciar/{año}/{mes}   genera depreciación mensual
 */
class ActivoFijoController extends Controller
{
    public function __construct(
        private readonly DepreciacionService $depreciacion,
        private readonly ContabilizadorService $contabilizador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ActivoFijo::with(['cuentaActivo', 'cuentaDepreciacionAcumulada', 'cuentaGastoDepreciacion'])
            ->orderBy('fecha_adquisicion', 'desc');

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->categoria);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $activos = $query->get()->map(fn (ActivoFijo $a) => [
            'id'                     => $a->id,
            'codigo'                 => $a->codigo,
            'descripcion'            => $a->descripcion,
            'categoria'              => $a->categoria,
            'costo_adquisicion'      => $a->costo_adquisicion,
            'fecha_adquisicion'      => $a->fecha_adquisicion?->toDateString(),
            'vida_util_meses'        => $a->vida_util_meses,
            'valor_residual'         => $a->valor_residual,
            'depreciacion_acumulada' => $a->depreciacion_acumulada,
            'valor_neto'             => $a->valorNeto(),
            'depreciacion_mensual'   => $a->depreciacionMensual(),
            'estado'                 => $a->estado,
            'ultima_depreciacion'    => $a->ultima_depreciacion?->toDateString(),
            'cuenta_activo'          => $a->cuentaActivo?->codigo,
            'cuenta_depreciacion'    => $a->cuentaDepreciacionAcumulada?->codigo,
            'cuenta_gasto'           => $a->cuentaGastoDepreciacion?->codigo,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $activos,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $activo = ActivoFijo::with(['cuentaActivo', 'cuentaDepreciacionAcumulada', 'cuentaGastoDepreciacion', 'depreciacionesMensuales'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $activo,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validarPayload($request);
        $extras    = $this->validarExtrasContables($request);

        // Default explícito para que el response no quede con estado=null
        // (la columna DB tiene default 'activo' pero solo se aplica en INSERT,
        //  no en la representación en memoria antes del refresh).
        $validated['estado'] ??= ActivoFijo::ESTADO_ACTIVO;

        $result = DB::transaction(function () use ($validated, $extras, $request) {
            $activo = ActivoFijo::create($validated);

            $asientoNumero = null;
            $asientoId     = null;

            if ($extras['generar_asiento']) {
                $asiento = $this->generarAsientoAdquisicion(
                    $activo, $extras, (string) ($request->user()?->id ?? '00000000-0000-0000-0000-000000000000'),
                );
                $asientoNumero = $asiento->numero;
                $asientoId     = $asiento->id;
            }

            return [$activo, $asientoNumero, $asientoId];
        });

        [$activo, $asientoNumero, $asientoId] = $result;

        $mensaje = "Activo fijo '{$activo->codigo}' registrado correctamente.";
        if ($asientoNumero !== null) {
            $mensaje .= " Asiento de adquisición {$asientoNumero} generado.";
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge(
                $activo->toArray(),
                ['asiento_adquisicion_id' => $asientoId, 'asiento_adquisicion_numero' => $asientoNumero],
            ),
            'message' => $mensaje,
        ], 201);
    }

    /**
     * Genera el asiento contable de adquisición del activo fijo:
     *
     *  forma_pago=contado_banco | contado_caja:
     *    D  cuenta_activo         (costo)
     *    D  cuenta_iva_descontable (si aplica IVA descontable)
     *    C  cuenta_banco / caja    (total = costo + iva)
     *
     *  forma_pago=credito:
     *    D  cuenta_activo
     *    D  cuenta_iva_descontable
     *    C  cuenta_proveedor       (con tercero_id)
     */
    private function generarAsientoAdquisicion(ActivoFijo $activo, array $extras, string $userId): \App\Models\Tenant\Asiento
    {
        $costo = (float) $activo->costo_adquisicion;
        $iva   = $extras['aplicar_iva']
            ? round($costo * ((float) $extras['tarifa_iva'] / 100), 2)
            : 0.0;
        $total = $costo + $iva;

        // Resolver cuenta contraparte (caja/banco/proveedor) por parametrización
        $claveContraparte = match ($extras['forma_pago']) {
            'contado_caja'  => 'compra.cuenta_caja',
            'credito'       => 'compra.cuenta_proveedor',
            default         => 'compra.cuenta_banco',
        };
        try {
            $cuentaContraparte = $this->contabilizador->cuenta($claveContraparte);
        } catch (ParametrizacionFaltanteException $e) {
            // Fallback: si la clave principal no existe, intentar la genérica
            $cuentaContraparte = $this->contabilizador->cuenta('compra.cuenta_banco');
        }

        $lineas = [];

        // D — Activo fijo (costo sin IVA)
        $lineas[] = [
            'cuenta_contable_id' => $activo->cuenta_activo_id,
            'tercero_id'         => $extras['tercero_id'] ?? null,
            'debito'             => $costo,
            'credito'            => 0,
            'descripcion'        => "Adquisición {$activo->codigo} — {$activo->descripcion}",
        ];

        // D — IVA descontable (si aplica)
        if ($iva > 0.01) {
            try {
                // Parametrización: compra.cuenta_iva_descontable o fallback genérico
                $cuentaIva = $this->contabilizador->cuenta('compra.cuenta_iva_descontable');
            } catch (ParametrizacionFaltanteException $e) {
                // Buscar 240810 manualmente como fallback
                $cuentaIva = CuentaContable::query()
                    ->where('codigo', 'like', '240810%')
                    ->where('acepta_movimientos', true)
                    ->first();
                if ($cuentaIva === null) {
                    throw new \RuntimeException(
                        'No se encontró cuenta IVA descontable. '
                        .'Configura compra.cuenta_iva_descontable o crea la cuenta 240810.'
                    );
                }
            }

            $lineas[] = [
                'cuenta_contable_id' => $cuentaIva->id,
                'tercero_id'         => $extras['tercero_id'] ?? null,
                'debito'             => $iva,
                'credito'            => 0,
                'descripcion'        => sprintf('IVA %s%% adquisición activo fijo', $extras['tarifa_iva']),
            ];
        }

        // C — Caja/Banco/Proveedor (total)
        $lineas[] = [
            'cuenta_contable_id' => $cuentaContraparte->id,
            'tercero_id'         => $extras['tercero_id'] ?? null,
            'debito'             => 0,
            'credito'            => $total,
            'descripcion'        => sprintf(
                'Pago adquisición activo fijo (%s)',
                str_replace('_', ' ', $extras['forma_pago']),
            ),
        ];

        return $this->contabilizador->contabilizar([
            'fecha'             => $activo->fecha_adquisicion->toDateString(),
            'tipo_comprobante'  => 'CM',  // Compra de Mercancías / Activos
            'numero_documento'  => $activo->codigo,
            'descripcion'       => "Adquisición Activo Fijo {$activo->codigo}: {$activo->descripcion}",
            'origen'            => $activo,
            'sucursal_id'       => $activo->sucursal_id,
            'created_by_id'     => $userId,
            'lineas'            => $lineas,
        ]);
    }

    /**
     * Valida los campos adicionales para generar el asiento de adquisición.
     * Si generar_asiento_compra=false, el resto son opcionales.
     *
     * @return array{
     *     generar_asiento: bool,
     *     forma_pago: string,
     *     aplicar_iva: bool,
     *     tarifa_iva: float,
     *     tercero_id: ?string,
     * }
     */
    private function validarExtrasContables(Request $request): array
    {
        $data = $request->validate([
            'generar_asiento_compra' => ['nullable', 'boolean'],
            'forma_pago'             => ['nullable', Rule::in(['contado_banco', 'contado_caja', 'credito'])],
            'aplicar_iva'            => ['nullable', 'boolean'],
            'tarifa_iva'             => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $generar = (bool) ($data['generar_asiento_compra'] ?? false);
        $formaPago = $data['forma_pago'] ?? 'contado_banco';

        // Si crédito, tercero_id (proveedor) es obligatorio
        if ($generar && $formaPago === 'credito') {
            $request->validate(['tercero_id' => ['required', 'uuid', 'exists:terceros,id']]);
        }

        return [
            'generar_asiento' => $generar,
            'forma_pago'      => $formaPago,
            'aplicar_iva'     => (bool) ($data['aplicar_iva'] ?? true),
            'tarifa_iva'      => (float) ($data['tarifa_iva'] ?? 19.0),
            'tercero_id'      => $request->input('tercero_id'),
        ];
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $activo = ActivoFijo::findOrFail($id);
        $validated = $this->validarPayload($request, ignorarUnique: $id);

        $activo->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $activo,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $activo = ActivoFijo::findOrFail($id);
        $activo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Activo dado de baja (soft delete).',
        ]);
    }

    /**
     * Genera la depreciación mensual consolidada para el periodo dado.
     */
    public function depreciar(Request $request, int $anio, int $mes): JsonResponse
    {
        if ($mes < 1 || $mes > 12) {
            return response()->json([
                'success' => false,
                'message' => "Mes inválido: {$mes}. Debe estar entre 1 y 12.",
            ], 422);
        }

        $createdById = (string) ($request->user()?->id ?? '00000000-0000-0000-0000-000000000000');

        $resultado = $this->depreciacion->depreciarMes($anio, $mes, $createdById);

        return response()->json([
            'success' => true,
            'data'    => $resultado,
            'message' => sprintf(
                'Depreciación %04d-%02d ejecutada. %d activos procesados, %d saltados, total $%s.',
                $anio, $mes,
                $resultado['activos_procesados'],
                $resultado['activos_saltados'],
                number_format($resultado['total_depreciado'], 2),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarPayload(Request $request, ?string $ignorarUnique = null): array
    {
        $codigoRule = Rule::unique('activos_fijos', 'codigo');
        if ($ignorarUnique !== null) {
            $codigoRule = $codigoRule->ignore($ignorarUnique);
        }

        return $request->validate([
            'codigo'                            => ['required', 'string', 'max:30', $codigoRule],
            'descripcion'                       => ['required', 'string', 'max:255'],
            'categoria'                         => ['required', 'string', Rule::in(ActivoFijo::CATEGORIAS)],
            'costo_adquisicion'                 => ['required', 'numeric', 'min:0'],
            'fecha_adquisicion'                 => ['required', 'date'],
            'vida_util_meses'                   => ['required', 'integer', 'min:1', 'max:1200'],
            'valor_residual'                    => ['nullable', 'numeric', 'min:0'],
            'fecha_inicio_depreciacion'         => ['nullable', 'date'],
            'tercero_id'                        => ['nullable', 'uuid', 'exists:terceros,id'],
            'sucursal_id'                       => ['nullable', 'uuid', 'exists:sucursales,id'],
            'centro_costo_id'                   => ['nullable', 'uuid', 'exists:centros_costo,id'],
            'cuenta_activo_id'                  => ['required', 'uuid', 'exists:cuentas_contables,id'],
            'cuenta_depreciacion_acumulada_id'  => ['required', 'uuid', 'exists:cuentas_contables,id'],
            'cuenta_gasto_depreciacion_id'      => ['required', 'uuid', 'exists:cuentas_contables,id'],
            'estado'                            => ['nullable', Rule::in([
                ActivoFijo::ESTADO_ACTIVO,
                ActivoFijo::ESTADO_VENDIDO,
                ActivoFijo::ESTADO_DADO_DE_BAJA,
            ])],
            'fecha_baja'                        => ['nullable', 'date'],
            'notas'                             => ['nullable', 'string'],
        ]);
    }
}
