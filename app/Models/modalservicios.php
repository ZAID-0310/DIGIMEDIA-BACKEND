<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Subservicio;

class modalservicios extends Model
{
    use HasFactory;
    protected $table = 'modalservicios';
    protected $primaryKey = 'id_modalservicio';

    protected $fillable = [
        'nombre',
        'telefono',
        'correo',
        'id_servicio',    // FK
        'id_subservicio', // FK nullable
        'fecha',
        'estado'
    ];

    public $timestamps = false;

    public function servicio()
    {
        return $this->belongsTo(servicios::class, 'id_servicio', 'id_servicio');
    }

    public function subservicio()
    {
        return $this->belongsTo(Subservicio::class, 'id_subservicio', 'id_subservicio');
    }

    public function watModal()
    {
        return $this->hasMany(WatModal::class, 'id_modalservicio', 'id_modalservicio');
    }

    public function campaniasWhatsApp(): BelongsToMany
    {
        return $this->belongsToMany(
            CampaniaWhatsApp::class,
            'campania_destinatarios',
            'id_modalservicio',
            'campania_id',
            'id_modalservicio',
            'id_campania'
        );
    }

    public function emailModal()
    {
        return $this->hasMany(EmailModal::class, 'id_modalservicio', 'id_modalservicio');
    }

}