<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AudienciasExp extends Model
{
    use HasFactory;

    /**
     * El nombre de la tabla asociada con el modelo.
     *
     * @var string
     */
    protected $table = 'AudienciasExp';

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
     * Audiencia ya realizada.
     */
    const TIPO_REALIZADA = 'REAL';

    /**
     * Audiencia programada a futuro.
     */
    const TIPO_PROGRAMADA = 'PROG';

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nUnico',
        'xFormato',
        'nIncidente',
        'xNomInstancia',
        'especialista',
        'indTipoAudiencia',
        'nProgramacion',
        'nSala',
        'lEstado',
        'xDescAudiencia',
        'fAudiencia',
        'xArchivoActa',
        'xArchivoAudio',
        'xEnlace',
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
        'nProgramacion' => 'integer',
        'nSala' => 'integer',
        'fAudiencia' => 'datetime',
        'regDate' => 'date',
        'regDatetime' => 'datetime',
        'regTimestamp' => 'integer',
    ];
}
