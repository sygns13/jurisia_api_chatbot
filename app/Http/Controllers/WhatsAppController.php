<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Twilio\TwiML\MessagingResponse;
use App\Models\MainConsulta;
use App\Models\CabExpediente;
use App\Models\PartesExp;
use App\Models\DetailsExpediente;

class WhatsAppController extends Controller
{
    public function handle(Request $request)
    {
        $from = $request->input('From'); // Formato: whatsapp:+51999888777
        $text = $request->input('Body');
        $chatId = str_replace('whatsapp:', '', $from); // Usamos el número como Chat ID

        Log::info("Mensaje de WhatsApp recibido de {$chatId}: {$text}");

        // --- VALIDACIÓN DE TIPO DE MENSAJE ---
        // Si NumMedia > 0, el usuario envió un archivo.
        if ($numMedia > 0) {
            $this->sendMessage($chatId, 'Por favor, envía solo mensajes de texto. No puedo procesar archivos, imágenes o stickers.');
            return response(''); // Finaliza la ejecución
        }
        // --- FIN DE LA VALIDACIÓN ---

        // Obtener o crear el estado de la conversación
        $consulta = MainConsulta::firstOrCreate(
            ['chatId' => $chatId, 'service' => 'whatsapp'],
            [
                'status' => 0,
                'step' => 0,
                'regDatetime' => now(),
            ]
        );

        // Dirigir al paso correspondiente
        switch ($consulta->step) {
            case 0:
                $this->handleStep0_Start($consulta);
                break;
            case 1:
                $this->handleStep1_ReceiveExpediente($consulta, $text);
                break;
            case 2:
                $this->handleStep2_ReceivePartSelection($consulta, $text);
                break;
            case 3:
                $this->handleStep3_ReceiveDni($consulta, $text);
                break;
            case 4:
                $this->handleStep4_ProvideDetails($consulta, $text);
                break;
        }

        // WhatsApp espera una respuesta vacía o con TwiML.
        // Respondemos directamente en cada método, por lo que aquí no hacemos nada.
        return response('');
    }

    private function sendMessage(string $chatId, string $message)
    {
        // Usamos TwiML para responder en la misma sesión del webhook
        $twiml = new MessagingResponse();
        $twiml->message($message);
        
        // Imprimimos el TwiML para que Twilio lo procese.
        // Esto solo funciona si respondes dentro de los 15 segundos.
        echo $twiml;
    }

    private function handleStep0_Start(MainConsulta $consulta)
    {
        $welcomeText = "¡Hola! Soy el asistente virtual de la Corte Superior de Justicia de Ancash.\n\n" .
                       "Por favor, ingresa el número de expediente que deseas consultar.";
        
        $this->sendMessage($consulta->chatId, $welcomeText);

        $consulta->step = 1;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    private function handleStep1_ReceiveExpediente(MainConsulta $consulta, string $expedienteNum)
    {
        $validator = Validator::make(['expediente' => $expedienteNum], [
            'expediente' => ['required', 'regex:/^\d{5}-\d{4}-\d{1}-\d{4}-[A-Z]{2}-[A-Z]{2}-\d{2}$/']
        ]);

        if ($validator->fails()) {
            $this->sendMessage($consulta->chatId, "El formato del expediente no es válido. Por favor, ingrésalo nuevamente (ej: 00012-2025-0-0201-JP-FC-02).");
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

        if($consulta->status == 1 && $consulta->step == 1){
            sleep(2);
        }

        // --- INICIO DE LA CORRECCIÓN ---
        $finalResponseText = "Buscando expediente, por favor espere...\n\n";

        $expediente = CabExpediente::where('xFormato', $expedienteNum)->first();

        if (!$expediente) {
            $finalResponseText .= "No se encontró el expediente. Por favor, verifica el número e ingrésalo de nuevo.";
            $this->sendMessage($consulta->chatId, $finalResponseText);
            // No cambiamos el step, para que el usuario pueda intentarlo de nuevo.
            return;
        }
        
        $partes = PartesExp::where('nUnico', $expediente->nUnico)->select('indTipoParte', 'xDescParte')->distinct()->get();

        if ($partes->isEmpty()) {
            $finalResponseText .= 'Expediente encontrado, pero no se hallaron partes procesales asociadas.';
            $this->sendMessage($consulta->chatId, $finalResponseText);
            $this->resetConversation($consulta);
            return;
        }

        $finalResponseText .= "¡Expediente encontrado! Por favor, selecciona qué parte eres en el proceso respondiendo con el código (ej: DDO):\n\n";
        foreach ($partes as $parte) {
            $finalResponseText .= "• *{$parte->indTipoParte}*: {$parte->xDescParte}\n";
        }

        // Enviamos todos los mensajes acumulados en una sola respuesta
        $this->sendMessage($consulta->chatId, $finalResponseText);

        // Actualizamos el estado de la conversación al final
        $consulta->message = $expedienteNum;
        $consulta->step = 2;
        $consulta->save();
        // --- FIN DE LA CORRECCIÓN ---
    }

    /**
     * PASO 2: Recibe la selección de la parte y solicita el DNI.
     */
    private function handleStep2_ReceivePartSelection(MainConsulta $consulta, string $tipoParte)
    {
        $processedInput = strtoupper(trim($tipoParte));

        // Primero, recuperamos el expediente basado en el mensaje guardado en el paso anterior
        $expediente = CabExpediente::where('xFormato', $consulta->message)->first();

        // Verificamos si la parte seleccionada por el usuario es válida para ese expediente
        $parteExiste = PartesExp::where('nUnico', $expediente->nUnico)
            ->where('indTipoParte', $processedInput)
            ->exists();

        if (!$parteExiste) {
            $this->sendMessage($consulta->chatId, "La opción '{$processedInput}' no es válida. Por favor, elige una de las opciones mostradas anteriormente (ej: DDO).");
            return; // Mantenemos al usuario en el mismo paso
        }
        
        // Si la opción es válida, la guardamos y avanzamos
        $consulta->tipoParteSeleccionada = $processedInput;
        $consulta->step = 3;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        $this->sendMessage($consulta->chatId, "Por favor, ingresa tu número de DNI (8 dígitos) para validar tu identidad.");
    }

    /**
     * PASO 3: Recibe y valida el DNI, luego muestra las opciones de consulta.
     */
    private function handleStep3_ReceiveDni(MainConsulta $consulta, string $dni)
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            $this->sendMessage($consulta->chatId, 'El DNI debe contener 8 dígitos numéricos. Por favor, ingrésalo de nuevo.');
            return;
        }

        $consulta->dni = $dni;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        $expediente = CabExpediente::where('xFormato', $consulta->message)->first();
        $parteValida = PartesExp::where('nUnico', $expediente->nUnico)
            ->where('indTipoParte', $consulta->tipoParteSeleccionada)
            ->where('xDocId', $dni)
            ->exists();

        if (!$parteValida) {
            $this->sendMessage($consulta->chatId, 'Tu DNI no corresponde a la parte procesal seleccionada en este expediente. El proceso ha finalizado.');
            $this->resetConversation($consulta);
            return;
        }

        $responseText = "¡Validación exitosa! ¿Qué deseas consultar?\nResponde con el número de la opción:\n\n" .
                        "1. Ubicación del Expediente\n" .
                        "2. Estado del Expediente\n" .
                        "3. Depósitos Judiciales\n" .
                        "4. Calificación de la Demanda\n" .
                        "5. Estado de la Demanda\n" .
                        "6. Liquidaciones\n" .
                        "7. Informe Multidisciplinario";

        $this->sendMessage($consulta->chatId, $responseText);

        $consulta->step = 4;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }


    /**
     * PASO 4: Proporciona la información solicitada y cierra el flujo.
     */
    private function handleStep4_ProvideDetails(MainConsulta $consulta, string $opcion)
    {
        $expediente = CabExpediente::where('xFormato', $consulta->message)->first();
        $detalle = DetailsExpediente::where('nUnico', $expediente->nUnico)->first();
        $responseText = "No se encontró información para esa consulta.";

        $mapOpciones = [
            '1' => 'ubicacion',
            '2' => 'estadoexp',
            '3' => 'depositos',
            '4' => 'calificacion',
            '5' => 'estadodemanda',
            '6' => 'liquidacion',
            '7' => 'informe',
        ];

        $tipoConsulta = $mapOpciones[trim($opcion)] ?? 'invalida';

        $respuesta_ini = "El expediente " . $expediente->xFormato . " de la Instancia " . $expediente->instancia . " de la especiadidad " . $expediente->codEspecialidad ;
        $respuesta_ini .= " de la materia" . ($detalle->xDescMateria ?? 'No disponible') ." Que tiene como Juez a " . ($detalle->juez ?? 'No disponible');
        $respuesta_ini .= " y Secretario " . ($detalle->secretario ?? 'No disponible') . " Tiene la Siguiente Información.\n\n";

        if ($detalle && $tipoConsulta !== 'invalida') {
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
        } else {
            $responseText = "La opción '{$opcion}' no es válida. La consulta ha finalizado.";
        }

        $consulta->consultaEspecifica = $tipoConsulta;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        $this->sendMessage($consulta->chatId, $responseText);
        $this->resetConversation($consulta);
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
}
