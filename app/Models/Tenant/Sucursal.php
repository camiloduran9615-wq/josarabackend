<?php

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Sucursal extends Model
{
    use HasUuids;

    protected $table = 'sucursales';

    protected $fillable = [
        'nombre', 
        'direccion', 
        'telefono', 
        'ciudad', 
        'es_principal', 
        'activa'
    ];

    public function usuarios()
    {
        return $this->hasMany(User::class);
    }

    public function facturas()
    {
        return $this->hasMany(Factura::class);
    }

    public function movimientos()
    {
        return $this->hasMany(InventarioMovimiento::class);
    }
}
