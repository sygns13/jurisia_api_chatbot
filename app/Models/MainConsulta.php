<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MainConsulta extends Model
{
    use HasFactory;

    /**
     * El nombre de la tabla asociada con el modelo.
     *
     * @var string
     */
    protected $table = 'MainConsulta';

    /**
     * El nombre de la conexión a la base de datos para el modelo.
     *
     * @var string|null
     */
    protected $connection = 'mysql'; // O el nombre de tu conexión si es diferente

    /**
     * Indica si el modelo debe tener timestamps (created_at, updated_at).
     * Se deshabilita porque la tabla usa campos de fecha personalizados.
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
        'status',
        'step',
        'service',
        'chatId',
        'message',
        'regDate',
        'regDatetime',
        'regTimestamp',
        'updDate',
        'updDatetime',
        'updTimestamp',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'integer',
        'step' => 'integer',
        'regDate' => 'date',
        'regDatetime' => 'datetime',
        'regTimestamp' => 'integer',
        'updDate' => 'date',
        'updDatetime' => 'datetime',
        'updTimestamp' => 'integer',
    ];
}
