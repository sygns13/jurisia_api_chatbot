<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabExpediente extends Model
{
    use HasFactory;

     /**
     * El nombre de la tabla asociada con el modelo.
     *
     * @var string
     */
    protected $table = 'CabExpediente';

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
        'nIncidente',
        'tipoExpediente',
        'codEspecialidad',
        'codInstancia',
        'instancia',
        'organoJurisd',
        'sede',
        'indAnulado',
        'indUltimo',
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
        'nIncidente' => 'integer',
        'regDate' => 'date',
        'regDatetime' => 'datetime',
        'regTimestamp' => 'integer',
    ];
}
