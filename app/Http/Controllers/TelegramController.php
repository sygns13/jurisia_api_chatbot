<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Keyboard\Keyboard;
use App\Models\MainConsulta;
use App\Models\CabExpediente;
use App\Models\PartesExp;
use App\Models\DetailsExpediente;
use App\Http\Controllers\Concerns\FormateaRespuestasExpediente;

class TelegramController extends Controller
{
    use FormateaRespuestasExpediente;

    /**
     * Límite de caracteres de un mensaje de Telegram. Por encima, la API responde
     * 400 Bad Request y el usuario no recibe nada.
     */
    const LIMITE_MENSAJE = 4096;

    /**
     * Telegram valida el Markdown en el servidor y rechaza el mensaje completo si el
     * texto trae asteriscos o guiones bajos desbalanceados. Los datos del SIJ los traen
     * (sumillas como "DEPOSITA S /*600") y las URLs de las grabaciones tienen guiones
     * bajos que no se pueden escapar sin romper el enlace, así que estas respuestas se
     * envían en texto plano, sin parse_mode.
     */
    protected function usaNegrita()
    {
        return false;
    }

    public function handle(Request $request)
    {
        $update = Telegram::getWebhookUpdate();
        Log::info('Update Recibido:', $update->toArray());

        // Determinar si es un mensaje de texto o una acción de un botón (callback query)
        if ($update->isType('callback_query')) {
            $callbackQuery = $update->getCallbackQuery();
            $chatId = $callbackQuery->getMessage()->getChat()->getId();
            $data = $callbackQuery->getData();
            $this->answerCallbackQuery($callbackQuery->getId()); // Confirma la recepción al usuario
        
        } elseif ($update->getMessage()) {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();

            // Validación de tipo de mensaje
            if (!$message->has('text')) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Por favor, envía solo mensajes de texto. No puedo procesar archivos, imágenes o stickers.'
                ]);
                return response()->json(['status' => 'unsupported_type']);
            }
            $text = $message->getText();

        } else {
            // Ignorar updates que no son ni mensajes ni callbacks
            return response()->json(['status' => 'ignored']);
        }

        // Obtener o crear el estado de la conversación para este usuario
        $consulta = MainConsulta::firstOrCreate(
            ['chatId' => $chatId],
            [
                'service' => 'telegram',
                'status' => 0, // Pre - Iniciado
                'step' => 0,   // Paso inicial
                'regDate' => now()->toDateString(),
                'regDatetime' => now(),
                'regTimestamp' => now()->timestamp,
                'updDate' => now()->toDateString(),
                'updDatetime' => now(),
                'updTimestamp' => now()->timestamp,
            ]
        );

        // Dirigir al paso correspondiente según el estado de la conversación
        switch ($consulta->step) {
            case 0:
                $this->handleStep0_Start($consulta);
                break;
            case 1:
                $this->handleStep1_ReceiveExpediente($consulta, $text);
                break;
            case 2:
                // El paso 2 se activa con un botón, por lo que usamos $data
                if ($data) $this->handleStep2_ReceivePartSelection($consulta, $data);
                break;
            case 3:
                $this->handleStep3_ReceiveDni($consulta, $text);
                break;
            case 4:
                // El paso 4 también se activa con un botón
                if ($data) $this->handleStep4_ProvideDetails($consulta, $data);
                break;
        }

        return response()->json(['status' => 'success']);

        /*
        // Aquí procesas la lógica de tu bot
        // Por ejemplo, puedes obtener el mensaje y el chat_id
        $message = $update->getMessage();
        $chat_id = $message->getChat()->getId();
        $text = $message->getText();

        // Aquí es donde te comunicarías con tu propia API de Laravel
        // para obtener una respuesta y enviarla de vuelta a Telegram.
        // Por ejemplo:
        // $responseFromApi = $this->callMyApi($text);

        Telegram::sendMessage([
            'chat_id' => $chat_id,
            'text' => 'He recibido tu mensaje: ' . $text. ' Con el chat_id: ' . $chat_id,
        ]);

        return response()->json(['status' => 'success']);*/
    }

     /**
     * PASO 0: Inicia la conversación.
     */
    private function handleStep0_Start(MainConsulta $consulta)
    {
        $welcomeText = "¡Hola! Soy el asistente virtual de la Corte Superior de Justicia de Ancash.\n\n" .
                       "Por favor, ingresa el número de expediente que deseas consultar.";
        
        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => $welcomeText,
        ]);

        // Avanzar al siguiente paso
        $consulta->step = 1;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * PASO 1: Recibe y valida el número de expediente.
     */
    private function handleStep1_ReceiveExpediente(MainConsulta $consulta, string $expedienteNum)
    {
        // Validación del formato: 00012-2025-0-0201-JP-FC-02
        $validator = Validator::make(['expediente' => $expedienteNum], [
            'expediente' => ['required', 'regex:/^\d{5}-\d{4}-\d{1}-\d{4}-[A-Z]{2}-[A-Z]{2}-\d{2}$/']
        ]);

        if ($validator->fails()) {
            Telegram::sendMessage([
                'chat_id' => $consulta->chatId,
                'text' => "El formato del expediente no es válido. Por favor, ingrésalo nuevamente (ej: 00012-2025-0-0201-JP-FC-02).",
            ]);
            return;
        }

        $consulta->message = $expedienteNum;

        // Formato válido, guardar y buscar
        if($consulta->status == 0){
            $consulta->status = 1;
        }

        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
        

        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => $this->mensajeBuscandoExpediente(),
        ]);

        $expediente = $this->esperarExpediente($consulta, $expedienteNum);

        if (!$expediente) {
            // status = 3 lo pone el microservicio cuando confirmó que el expediente no
            // existe. Si no llegó a ponerlo, lo que se agotó fue la espera: son dos
            // situaciones distintas y conviene no decirle al ciudadano que su expediente
            // no existe cuando en realidad el sistema se demoró.
            $textoError = ($consulta->status == 3)
                ? "No se encontró el expediente. Por favor, verifica el número e ingrésalo de nuevo."
                : "El sistema está tardando más de lo habitual y no pudimos completar la consulta. "
                    . "Por favor, vuelve a enviar el número de expediente en unos minutos.";

            Telegram::sendMessage([
                'chat_id' => $consulta->chatId,
                'text' => $textoError,
            ]);
            $consulta->status = 0; // Resetear el estado
            $consulta->step = 0; // Volver al paso inicial
            $consulta->updDate = now()->toDateString();
            $consulta->updDatetime = now();
            $consulta->updTimestamp = now()->timestamp;
            $consulta->save();
            $this->resetConversation($consulta);
            return;
        }

        // Expediente encontrado, solicitar la parte procesal
        $partes = PartesExp::where('nUnico', $expediente->nUnico)->where('chatId', $consulta->chatId)->select('indTipoParte', 'xDescParte')->distinct()->get();
        
        if ($partes->isEmpty()) {
            Telegram::sendMessage(['chat_id' => $consulta->chatId, 'text' => 'Expediente encontrado, pero no se hallaron partes procesales asociadas.']);
            $this->resetConversation($consulta);
            return;
        }

        // Construir el teclado dinámicamente, un botón por fila
        $keyboard = Keyboard::make()->inline();
        foreach ($partes as $parte) {
            $keyboard->row(
                Keyboard::inlineButton(['text' => "{$parte->indTipoParte}: {$parte->xDescParte}", 'callback_data' => 'parte_' . $parte->indTipoParte])
            );
        }

        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => "¡Expediente encontrado! Por favor, selecciona qué parte eres en el proceso:",
            'reply_markup' => $keyboard,
        ]);

        $consulta->step = 2;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * Espera a que ms-jurisia-judicial resuelva el expediente y lo devuelve.
     *
     * Sustituye al sleep(2) fijo anterior. En lugar de dormir un tiempo fijo y mirar una
     * sola vez, sondea la base cada medio segundo hasta el tope de esperaMaximaSegundos()
     * y corta en cuanto tiene una respuesta:
     *
     *   - Si aparece el expediente, responde de inmediato (normalmente en 2-3 segundos).
     *   - Si el microservicio marca status = 3 (no encontrado), corta sin agotar la espera.
     *
     * Devuelve null si el expediente no existe o si se agotó el plazo.
     */
    private function esperarExpediente(MainConsulta $consulta, string $expedienteNum)
    {
        $limite = microtime(true) + $this->esperaMaximaSegundos();

        do {
            $expediente = CabExpediente::where('xFormato', $expedienteNum)
                ->where('chatId', $consulta->chatId)
                ->first();

            if ($expediente) {
                return $expediente;
            }

            // El microservicio ya respondió que no lo encontró: no tiene sentido seguir.
            $consulta->refresh();
            if ($consulta->status == 3) {
                return null;
            }

            $this->dormirIntervalo();
        } while (microtime(true) < $limite);

        return null;
    }

    /**
     * PASO 2: Recibe la selección de la parte y solicita el DNI.
     */
    private function handleStep2_ReceivePartSelection(MainConsulta $consulta, string $callbackData)
    {
        $tipoParte = str_replace('parte_', '', $callbackData);
        $consulta->tipoParteSeleccionada = $tipoParte;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => "Por favor, ingresa tu número de DNI (8 dígitos) para validar tu identidad.",
        ]);

        $consulta->step = 3;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * PASO 3: Recibe y valida el DNI, luego muestra las opciones de consulta.
     */
    private function handleStep3_ReceiveDni(MainConsulta $consulta, string $dni)
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            Telegram::sendMessage(['chat_id' => $consulta->chatId, 'text' => 'El DNI debe contener 8 dígitos numéricos. Por favor, ingrésalo de nuevo.']);
            return;
        }

        $consulta->dni = $dni;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        $expediente = CabExpediente::where('xFormato', $consulta->message)->where('chatId', $consulta->chatId)->first();
        $parteValida = PartesExp::where('nUnico', $expediente->nUnico)
            ->where('chatId', $consulta->chatId)
            ->where('indTipoParte', $consulta->tipoParteSeleccionada)
            ->where('xDocId', $dni)
            ->exists();

        if (!$parteValida) {
            Telegram::sendMessage(['chat_id' => $consulta->chatId, 'text' => 'Tu DNI no corresponde a la parte procesal seleccionada en este expediente. El proceso ha finalizado.']);
            $this->resetConversation($consulta);
            return;
        }

        // DNI validado
        $keyboard = Keyboard::make()->inline()
        ->row(Keyboard::inlineButton(['text' => 'Información General del Expediente', 'callback_data' => 'consulta_info_general']))
        ->row(Keyboard::inlineButton(['text' => 'Estado del Expediente', 'callback_data' => 'consulta_estadoexp']))
        ->row(Keyboard::inlineButton(['text' => 'Ubicación del Expediente', 'callback_data' => 'consulta_ubicacion']))
        ->row(Keyboard::inlineButton(['text' => 'Detalle de Escritos del Expediente', 'callback_data' => 'consulta_detalle_escritos']))
        ->row(Keyboard::inlineButton(['text' => 'Próximas Audiencias del Expediente', 'callback_data' => 'consulta_proximas_audiencias']))
        ->row(Keyboard::inlineButton(['text' => 'Audiencias del Expediente Realizadas', 'callback_data' => 'consulta_audiencias_realizadas']));
            //->row(Keyboard::inlineButton(['text' => 'Depósitos Judiciales', 'callback_data' => 'consulta_depositos']))
            //->row(Keyboard::inlineButton(['text' => 'Calificación de la Demanda', 'callback_data' => 'consulta_calificacion']))
            //->row(Keyboard::inlineButton(['text' => 'Estado de la Demanda', 'callback_data' => 'consulta_estadodemanda']))
            //->row(Keyboard::inlineButton(['text' => 'Liquidaciones', 'callback_data' => 'consulta_liquidacion']))
            //->row(Keyboard::inlineButton(['text' => 'Informe Multidisciplinario', 'callback_data' => 'consulta_informe']));

        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => "¡Validación exitosa! ¿Qué deseas consultar?",
            'reply_markup' => $keyboard,
        ]);

        $consulta->step = 4;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * PASO 4: Proporciona la información solicitada y cierra el flujo.
     */
    private function handleStep4_ProvideDetails(MainConsulta $consulta, string $callbackData)
    {
        $expediente = CabExpediente::where('xFormato', $consulta->message)->where('chatId', $consulta->chatId)->first();

        if (!$expediente) {
            Telegram::sendMessage([
                'chat_id' => $consulta->chatId,
                'text' => 'No fue posible recuperar el expediente. Por favor, inicia la consulta nuevamente.',
            ]);
            $this->resetConversation($consulta);
            return;
        }

        $detalle = DetailsExpediente::where('nUnico', $expediente->nUnico)->where('chatId', $consulta->chatId)->first();

        $tipoConsulta = str_replace('consulta_', '', $callbackData);

        switch ($tipoConsulta) {
            case 'info_general':
                $responseText = $this->respuestaInfoGeneral($expediente, $detalle, $consulta->chatId);
                break;
            case 'estadoexp':
                $responseText = $this->respuestaEstado($expediente, $detalle);
                break;
            case 'ubicacion':
                $responseText = $this->respuestaUbicacion($expediente, $detalle);
                break;
            case 'detalle_escritos':
                $responseText = $this->respuestaEscritos($expediente, $detalle, $consulta->chatId);
                break;
            case 'proximas_audiencias':
                $responseText = $this->respuestaProximasAudiencias($expediente, $detalle, $consulta->chatId);
                break;
            case 'audiencias_realizadas':
                $responseText = $this->respuestaAudienciasRealizadas($expediente, $detalle, $consulta->chatId);
                break;

                /*
            case 'depositos':
                // Aquí iría la lógica para buscar en una tabla de depósitos, si existiera.
                $responseText = "*Depósitos Judiciales:*\nActualmente no hay información de depósitos disponible a través de este canal.";
                break;
            case 'calificacion':
                $responseText = "*Calificación de la Demanda:*\nActualmente no hay información de calificación de demanda disponible a través de este canal.";
                break;
            case 'estadodemanda':
                $responseText = "*Estado de la Demanda:*\nActualmente no hay información de estado de demanda disponible a través de este canal.";
                break;
            case 'liquidacion':
                $responseText = "*Liquidaciones:*\nActualmente no hay información de liquidaciones disponible a través de este canal.";
                break;
            case 'informe':
                $responseText = "*Informe Multidisciplinario:*\nActualmente no hay información de informes multidisciplinarios disponible a través de este canal.";
                break;
                */
            default:
                $responseText = "Consulta no reconocida. Por favor, inténtalo de nuevo.";
                break;
        }

        $responseText = $this->limitarLongitud($responseText, self::LIMITE_MENSAJE);

        $consulta->consultaEspecifica = $tipoConsulta;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        // Sin parse_mode a propósito: ver la nota de $usaNegrita.
        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => $responseText,
        ]);

        Telegram::sendMessage(['chat_id' => $consulta->chatId, 'text' => 'Gracias por usar nuestro servicio. La consulta ha finalizado.']);
        $this->endConversation($consulta);
    }

    /**
     * Resetea la conversación para que el usuario pueda iniciar una nueva.
     */
    private function resetConversation(MainConsulta $consulta)
    {
        $consulta->step = 0;
        $consulta->message = null;
        $consulta->tipoParteSeleccionada = null;
        $consulta->dni = null;
        $consulta->consultaEspecifica = null;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

     /**
     * Finaliza la conversacion.
     */
    private function endConversation(MainConsulta $consulta)
    {
        $consulta->chatId = 'done-'.$consulta->chatId.'-done'; // Marcar como finalizado
        $consulta->step = 4;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * Responde a un callback query para que el botón deje de mostrar "cargando".
     */
    private function answerCallbackQuery(string $callbackQueryId)
    {
        Telegram::answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
    }
}
