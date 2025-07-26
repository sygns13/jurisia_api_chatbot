<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailsExpediente extends Model
{
    use HasFactory;

     /**
     * El nombre de la tabla asociada con el modelo.
     *
     * @var string
     */
    protected $table = 'DetailsExpediente';

    /**
     * El nombre de la conexión a la base de datos para el modelo.
     *
     * @var string|null
     */
    protected $connection = 'mysql';

    /**
     * Indica si el modelo debe tener timestamps (created_at, updated_at).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nUnico',
        'xFormato',
        'xNomInstancia',
        'codEspecialidad',
        'xDescMateria',
        'fInicio',
        'xDescEstado',
        'codUbicacion',
        'xDescUbicacion',
        'usuarioJuez',
        'juez',
        'usuarioSecretario',
        'secretario',
        'tipoExpediente',
        'parte',
        'indTipoParte',
        'xDescParte',
        'regDate',
        'regDatetime',
        'regTimestamp',
        'chatId',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'nUnico' => 'integer',
        'fInicio' => 'datetime',
        'regDate' => 'date',
        'regDatetime' => 'datetime',
        'regTimestamp' => 'integer',
    ];
}
