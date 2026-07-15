<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// CentroCosto y Sucursal resueltos por el namespace de tenant

class DocumentoIngreso extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'documentos_ingreso';

    protected $fillable = [
        'tercero_id', 'sucursal_id', 'centro_costo_id',
        'tipo_documento_ingreso_id',
        'numero', 'tipo', 'fecha', 'fecha_vencimiento',
        'concepto', 'forma_pago', 'payment_term_id', 'payment_method_id',
        'valor_bruto', 'valor_iva', 'valor_retefuente',
        'valor_reteica', 'valor_reteiva', 'valor_total',
        'estado', 'observaciones', 'numero_documento_proveedor',
        'asiento_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_vencimiento' => 'date',
        'valor_bruto' => 'decimal:2',
        'valor_iva' => 'decimal:2',
        'valor_retefuente' => 'decimal:2',
        'valor_reteica' => 'decimal:2',
        'valor_reteiva' => 'decimal:2',
        'valor_total' => 'decimal:2',
    ];

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function tercero()
    {
        return $this->belongsTo(Tercero::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function items()
    {
        return $this->hasMany(DocumentoIngresoItem::class);
    }

    public function asiento()
    {
        return $this->belongsTo(Asiento::class);
    }

    public function tipoDocumento()
    {
        return $this->belongsTo(TipoDocumentoIngreso::class, 'tipo_documento_ingreso_id');
    }

    public function movimientosInventario()
    {
        return $this->hasMany(InventarioMovimiento::class);
    }
}
