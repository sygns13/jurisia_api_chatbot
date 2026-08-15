<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AudienciasExp;
use App\Models\DetailsExpediente;
use App\Models\EscritosExp;

/**
 * Construye los textos de respuesta de las opciones de consulta del expediente.
 *
 * TelegramController y WhatsAppController son duplicados deliberados de la misma máquina
 * de estados, pero el contenido de estas respuestas es idéntico en ambos canales, así que
 * vive aquí para no escribirlo dos veces y para que un cambio no haya que replicarlo.
 *
 * Lo que sí difiere por canal:
 *   - El límite de longitud del mensaje, que cada controlador pasa a limitarLongitud():
 *     Telegram admite 4096 caracteres y WhatsApp trunca cerca de los 1600.
 *   - El marcado de negrita, según usaNegrita(): WhatsApp sí, Telegram no (ver la nota
 *     de ese método).
 */
trait FormateaRespuestasExpediente
{
    /**
     * Registros que se muestran en el chat. El microservicio envía hasta 50 por consulta;
     * mostrarlos todos haría ilegible el mensaje y excedería el límite de WhatsApp.
     */
    protected $registrosVisibles = 10;

    /**
     * Tope de espera, en segundos, mientras ms-jurisia-judicial resuelve el expediente.
     *
     * El bot no puede llamar al microservicio: este espera a que el scheduler recoja la
     * consulta pendiente (sondea cada 1500 ms), consulte el SIJ y devuelva los datos por
     * HTTP. El grueso del tiempo es el pickup del scheduler más los dos saltos HTTP; las
     * consultas al SIJ suman unos 56 ms.
     *
     * Ocho segundos dejan margen para un pickup lento y una red cargada, y a la vez
     * respetan el límite de 15 segundos que Twilio da a un webhook: enviar el aviso
     * (~1 s) + esperar (8 s) + responder (~1 s) deja unos 5 segundos de holgura.
     *
     * En la práctica casi nunca se agota: esperarExpediente() devuelve apenas aparecen
     * los datos, y corta de inmediato si el microservicio informa que no existe.
     */
    protected function esperaMaximaSegundos()
    {
        return 8;
    }

    /**
     * Cada cuánto se vuelve a mirar la base mientras se espera, en microsegundos.
     */
    protected function intervaloSondeoMicrosegundos()
    {
        return 500000; // 0,5 s
    }

    /**
     * Pausa entre sondeos.
     *
     * Algunos hostings compartidos incluyen usleep en disable_functions; si no está
     * disponible se cae a sleep(1), que es más grueso pero no rompe el flujo. Invocar
     * una función deshabilitada sería un error fatal y el ciudadano no recibiría nada.
     */
    protected function dormirIntervalo()
    {
        if (function_exists('usleep')) {
            usleep($this->intervaloSondeoMicrosegundos());
            return;
        }

        sleep(1);
    }

    /**
     * Aviso que se muestra antes de esperar. Incluye el tiempo máximo de forma explícita
     * para que el ciudadano sepa qué esperar; el número sale de esperaMaximaSegundos(),
     * así que texto y comportamiento no pueden quedar desalineados.
     */
    protected function mensajeBuscandoExpediente()
    {
        return "Buscando el expediente, por favor espera un momento.\n"
            . "La consulta puede tomar hasta " . $this->esperaMaximaSegundos() . " segundos.";
    }

    /**
     * Indica si el canal admite marcado de negrita con asteriscos.
     *
     * WhatsApp lo interpreta en el cliente y un asterisco suelto no rompe nada. Telegram,
     * en cambio, valida el Markdown en el servidor: si el texto trae un asterisco o guion
     * bajo desbalanceado, la API responde 400 y el usuario NO recibe el mensaje.
     *
     * Los datos del SIJ son texto libre y sí traen esos caracteres (hay sumillas reales
     * como "DEPOSITA S /*600" u "OFICIO N°447-2017-AC*CSJAN/PJ"), y los enlaces de las
     * grabaciones llevan guiones bajos que no se pueden escapar sin romper la URL.
     * Por eso TelegramController sobrescribe este método devolviendo false.
     *
     * Es un método y no una propiedad a propósito: en PHP, redeclarar una propiedad de
     * un trait con un valor por defecto distinto es un error fatal, mientras que los
     * métodos de la clase sí tienen precedencia sobre los del trait.
     */
    protected function usaNegrita()
    {
        return true;
    }

    /**
     * Aplica negrita solo si el canal la soporta.
     */
    private function negrita($texto)
    {
        return $this->usaNegrita() ? "*{$texto}*" : $texto;
    }

    /**
     * Encabezado común a todas las opciones.
     */
    protected function cabeceraExpediente($expediente, $detalle)
    {
        $instancia = $this->valorODefecto($detalle ? $detalle->xNomInstancia : null, $expediente->instancia);
        $especialidad = $this->valorODefecto($detalle ? $detalle->xDescEspecialidad : null, $expediente->codEspecialidad);

        $texto = $this->negrita("Expediente {$expediente->xFormato}") . "\n";
        $texto .= "Instancia: " . $this->valorODefecto($instancia) . "\n";
        $texto .= "Especialidad: " . $this->valorODefecto($especialidad) . "\n";

        if ($detalle && $detalle->xDescMateria) {
            $texto .= "Materia: " . trim($detalle->xDescMateria) . "\n";
        }

        return $texto . "\n";
    }

    /**
     * OPCIÓN 1: Información general del expediente.
     *
     * No requiere tabla propia: materia, estado, ubicación, juez, secretario y partes ya se
     * almacenan en DetailsExpediente, que ahora incluye además sede y especialidad.
     */
    protected function respuestaInfoGeneral($expediente, $detalle, $chatId)
    {
        if (!$detalle) {
            return $this->cabeceraExpediente($expediente, $detalle)
                . $this->negrita('Información General del Expediente:') . "\nNo disponible.";
        }

        $texto = $this->cabeceraExpediente($expediente, $detalle);
        $texto .= $this->negrita('Información General del Expediente:') . "\n";
        $texto .= "• Sede: " . $this->valorODefecto($detalle->xDescSede, $expediente->sede) . "\n";
        $texto .= "• Tipo de expediente: " . $this->valorODefecto($detalle->tipoExpediente) . "\n";
        $texto .= "• Fecha de inicio: " . $this->formatearFecha($detalle->fInicio, 'd/m/Y') . "\n";
        $texto .= "• Estado: " . $this->valorODefecto($detalle->xDescEstado) . "\n";
        $texto .= "• Ubicación: " . $this->valorODefecto($detalle->xDescUbicacion) . "\n";
        $texto .= "• Juez: " . $this->valorODefecto($detalle->juez) . "\n";
        $texto .= "• Secretario: " . $this->valorODefecto($detalle->secretario) . "\n";

        // Las partes salen de DetailsExpediente, que trae una fila por parte con el nombre
        // completo ya concatenado en origen.
        $partes = DetailsExpediente::where('nUnico', $expediente->nUnico)
            ->where('chatId', $chatId)
            ->whereNotNull('parte')
            ->select('indTipoParte', 'xDescParte', 'parte')
            ->distinct()
            ->orderBy('indTipoParte')
            ->get();

        if ($partes->isNotEmpty()) {
            $texto .= "\n" . $this->negrita('Partes procesales:') . "\n";
            foreach ($partes as $parte) {
                $rol = $this->valorODefecto($parte->xDescParte, $parte->indTipoParte);
                $texto .= "• {$rol}: " . trim($parte->parte) . "\n";
            }
        }

        return $texto;
    }

    /**
     * OPCIÓN 2: Estado del expediente.
     */
    protected function respuestaEstado($expediente, $detalle)
    {
        return $this->cabeceraExpediente($expediente, $detalle)
            . $this->negrita('Estado del Expediente:') . "\n"
            . $this->valorODefecto($detalle ? $detalle->xDescEstado : null);
    }

    /**
     * OPCIÓN 3: Ubicación del expediente.
     */
    protected function respuestaUbicacion($expediente, $detalle)
    {
        return $this->cabeceraExpediente($expediente, $detalle)
            . $this->negrita('Ubicación del Expediente:') . "\n"
            . $this->valorODefecto($detalle ? $detalle->xDescUbicacion : null);
    }

    /**
     * OPCIÓN 4: Detalle de escritos presentados.
     */
    protected function respuestaEscritos($expediente, $detalle, $chatId)
    {
        $consulta = EscritosExp::where('nUnico', $expediente->nUnico)
            ->where('chatId', $chatId)
            ->orderBy('fEscrito', 'desc');

        $total = $consulta->count();
        $escritos = $consulta->limit($this->registrosVisibles)->get();

        $texto = $this->cabeceraExpediente($expediente, $detalle);
        $texto .= $this->negrita('Detalle de Escritos del Expediente:') . "\n";

        if ($escritos->isEmpty()) {
            return $texto . "No se registran escritos presentados en este expediente.";
        }

        $texto .= $this->leyendaTotal($total, $escritos->count()) . "\n";

        foreach ($escritos as $escrito) {
            $texto .= "\n• " . $this->negrita("Escrito N° " . $this->valorODefecto($escrito->nroEscrito));
            $texto .= " (" . $this->formatearFecha($escrito->fEscrito, 'd/m/Y') . ")\n";

            if ($escrito->xSumilla) {
                $texto .= "  Sumilla: " . trim($escrito->xSumilla) . "\n";
            }

            // x_resolucion nulo en el SIJ significa que el escrito todavía no fue proveído.
            if ($escrito->xResolucion) {
                $texto .= "  Atendido con resolución: " . trim($escrito->xResolucion);
                $texto .= " (" . $this->formatearFecha($escrito->fAtencion, 'd/m/Y') . ")\n";
            } else {
                $texto .= "  Estado: Pendiente de atención\n";
            }
        }

        return $texto;
    }

    /**
     * OPCIÓN 5: Próximas audiencias programadas.
     */
    protected function respuestaProximasAudiencias($expediente, $detalle, $chatId)
    {
        $consulta = AudienciasExp::where('nUnico', $expediente->nUnico)
            ->where('chatId', $chatId)
            ->where('indTipoAudiencia', AudienciasExp::TIPO_PROGRAMADA)
            ->orderBy('fAudiencia', 'asc');

        $total = $consulta->count();
        $audiencias = $consulta->limit($this->registrosVisibles)->get();

        $texto = $this->cabeceraExpediente($expediente, $detalle);
        $texto .= $this->negrita('Próximas Audiencias del Expediente:') . "\n";

        if ($audiencias->isEmpty()) {
            return $texto . "No se tienen audiencias programadas próximamente.";
        }

        $texto .= $this->leyendaTotal($total, $audiencias->count()) . "\n";

        foreach ($audiencias as $audiencia) {
            $texto .= "\n• " . $this->negrita($this->formatearFecha($audiencia->fAudiencia, 'd/m/Y H:i')) . "\n";
            $texto .= "  " . $this->valorODefecto($audiencia->xDescAudiencia, 'Audiencia programada') . "\n";

            if ($audiencia->nSala) {
                $texto .= "  Sala: {$audiencia->nSala}\n";
            }
        }

        return $texto;
    }

    /**
     * OPCIÓN 6: Audiencias ya realizadas.
     */
    protected function respuestaAudienciasRealizadas($expediente, $detalle, $chatId)
    {
        $consulta = AudienciasExp::where('nUnico', $expediente->nUnico)
            ->where('chatId', $chatId)
            ->where('indTipoAudiencia', AudienciasExp::TIPO_REALIZADA)
            ->orderBy('fAudiencia', 'desc');

        $total = $consulta->count();
        $audiencias = $consulta->limit($this->registrosVisibles)->get();

        $texto = $this->cabeceraExpediente($expediente, $detalle);
        $texto .= $this->negrita('Audiencias del Expediente Realizadas:') . "\n";

        if ($audiencias->isEmpty()) {
            return $texto . "No se registran audiencias realizadas en este expediente.";
        }

        $texto .= $this->leyendaTotal($total, $audiencias->count()) . "\n";

        foreach ($audiencias as $audiencia) {
            $texto .= "\n• " . $this->negrita($this->formatearFecha($audiencia->fAudiencia, 'd/m/Y H:i')) . "\n";
            $texto .= "  " . $this->valorODefecto($audiencia->xDescAudiencia, 'Audiencia realizada') . "\n";

            if ($audiencia->xArchivoActa) {
                $texto .= "  Cuenta con acta registrada.\n";
            }

            // Solo se publica el enlace cuando el SIJ lo registró como URL pública. Las
            // rutas FTP internas no se almacenan ni se envían al ciudadano.
            if ($audiencia->xEnlace) {
                $texto .= "  Grabación: {$audiencia->xEnlace}\n";
            } elseif ($audiencia->xArchivoAudio) {
                $texto .= "  Cuenta con grabación de audio/video en el juzgado.\n";
            }
        }

        return $texto;
    }

    /**
     * Indica cuántos registros se están mostrando de un total mayor.
     */
    private function leyendaTotal($total, $mostrados)
    {
        if ($total > $mostrados) {
            return "(Mostrando los {$mostrados} más recientes de {$total})";
        }

        return "(Total: {$total})";
    }

    /**
     * Recorta el mensaje al límite del canal sin cortar una línea por la mitad.
     *
     * Telegram rechaza los mensajes de más de 4096 caracteres y WhatsApp los trunca en
     * silencio, así que el corte se hace aquí de forma explícita y avisando al usuario.
     */
    protected function limitarLongitud($texto, $limite)
    {
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        $aviso = "\n\n[...] Mensaje recortado. Consulta con un rango menor o acércate a Mesa de Partes.";
        $corte = mb_substr($texto, 0, $limite - mb_strlen($aviso));

        // Retrocede hasta el último salto de línea para no cortar a media palabra.
        $ultimoSalto = mb_strrpos($corte, "\n");
        if ($ultimoSalto !== false && $ultimoSalto > 0) {
            $corte = mb_substr($corte, 0, $ultimoSalto);
        }

        return $corte . $aviso;
    }

    /**
     * Devuelve el primer valor no vacío, o 'No disponible'.
     */
    private function valorODefecto($valor, $alternativo = null)
    {
        $valor = is_string($valor) ? trim($valor) : $valor;

        if (!empty($valor)) {
            return $valor;
        }

        $alternativo = is_string($alternativo) ? trim($alternativo) : $alternativo;

        return !empty($alternativo) ? $alternativo : 'No disponible';
    }

    /**
     * Formatea una fecha ya casteada a Carbon por el modelo.
     */
    private function formatearFecha($fecha, $formato)
    {
        if (empty($fecha)) {
            return 'No disponible';
        }

        return $fecha->format($formato);
    }
}
