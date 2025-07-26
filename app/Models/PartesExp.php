<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartesExp extends Model
{
    use HasFactory;

    use HasFactory;

    /**
     * El nombre de la tabla asociada con el modelo.
     *
     * @var string
     */
    protected $table = 'PartesExp';

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
        'cTipoPersona',
        'xDescTipoPersona',
        'indTipoParte',
        'xDescParte',
        'xApePaterno',
        'xApeMaterno',
        'xNombres',
        'xDocId',
        'cTipo',
        'xTipoDoc',
        'xAbrevi',
        'indActivo',
        'nUnico',
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
        'regDate' => 'date',
        'regDatetime' => 'datetime',
        'regTimestamp' => 'integer',
    ];
}
