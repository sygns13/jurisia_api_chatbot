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

class TelegramController extends Controller
{
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
        } else {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = $message->getText();
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
        

        Telegram::sendMessage(['chat_id' => $consulta->chatId, 'text' => 'Buscando expediente, por favor espere...']);
        if($consulta->status == 1 && $consulta->step == 1){
            sleep(2); // Bloqueo solicitado de 2 segundos
        }
        

        $expediente = CabExpediente::where('xFormato', $expedienteNum)->where('chatId', $consulta->chatId)->first();

        if (!$expediente) {
            Telegram::sendMessage([
                'chat_id' => $consulta->chatId,
                'text' => "No se encontró el expediente. Por favor, verifica el número e ingrésalo de nuevo.",
            ]);
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
            ->row(Keyboard::inlineButton(['text' => 'Ubicación del Expediente', 'callback_data' => 'consulta_ubicacion']))
            ->row(Keyboard::inlineButton(['text' => 'Estado del Expediente', 'callback_data' => 'consulta_estadoexp']))
            ->row(Keyboard::inlineButton(['text' => 'Depósitos Judiciales', 'callback_data' => 'consulta_depositos']))
            ->row(Keyboard::inlineButton(['text' => 'Calificación de la Demanda', 'callback_data' => 'consulta_calificacion']))
            ->row(Keyboard::inlineButton(['text' => 'Estado de la Demanda', 'callback_data' => 'consulta_estadodemanda']))
            ->row(Keyboard::inlineButton(['text' => 'Liquidaciones', 'callback_data' => 'consulta_liquidacion']))
            ->row(Keyboard::inlineButton(['text' => 'Informe Multidisciplinario', 'callback_data' => 'consulta_informe']));

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
        $detalle = DetailsExpediente::where('nUnico', $expediente->nUnico)->where('chatId', $consulta->chatId)->first();
        $responseText = "No se encontró información para esa consulta.";

        $tipoConsulta = str_replace('consulta_', '', $callbackData);

        $respuesta_ini = "El expediente " . $expediente->xFormato . " de la Instancia " . $expediente->instancia . " de la especiadidad " . $expediente->codEspecialidad ;
        $respuesta_ini .= " de la materia" . ($detalle->xDescMateria ?? 'No disponible') ." Que tiene como Juez a " . ($detalle->juez ?? 'No disponible');
        $respuesta_ini .= " y Secretario " . ($detalle->secretario ?? 'No disponible') . " Tiene la Siguiente Información.\n\n";

        if ($detalle) {
            switch ($tipoConsulta) {
                case 'ubicacion':
                    $responseText = $respuesta_ini." *Ubicación del Expediente:*\n" . ($detalle->xDescUbicacion ?? 'No disponible');
                    break;
                case 'estadoexp':
                    $responseText = $respuesta_ini." *Estado del Expediente:*\n" . ($detalle->xDescEstado ?? 'No disponible');
                    break;
                case 'depositos':
                    // Aquí iría la lógica para buscar en una tabla de depósitos, si existiera.
                    $responseText = $respuesta_ini." *Depósitos Judiciales:*\nActualmente no hay información de depósitos disponible a través de este canal.";
                    break;
                case 'calificacion':
                    $responseText = $respuesta_ini." *Calificación de la Demanda:*\nActualmente no hay información de calificación de demanda disponible a través de este canal.";
                    break;
                case 'estadodemanda':
                    $responseText = $respuesta_ini." *Estado de la Demanda:*\nActualmente no hay información de estado de demanda disponible a través de este canal.";
                    break;
                case 'liquidacion':
                    $responseText = $respuesta_ini." *Liquidaciones:*\nActualmente no hay información de liquidaciones disponible a través de este canal.";
                    break;
                case 'informe':
                    $responseText = $respuesta_ini." *Informe Multidisciplinario:*\nActualmente no hay información de informes multidisciplinarios disponible a través de este canal.";
                    break;
                default:
                    $responseText = "Consulta no reconocida. Por favor, inténtalo de nuevo.";
                    break;
            }
        }

        $consulta->consultaEspecifica = $tipoConsulta;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        Telegram::sendMessage([
            'chat_id' => $consulta->chatId,
            'text' => $responseText,
            'parse_mode' => 'Markdown',
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
