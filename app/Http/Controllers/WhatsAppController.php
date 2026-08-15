<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Twilio\Rest\Client;
use App\Models\MainConsulta;
use App\Models\CabExpediente;
use App\Models\PartesExp;
use App\Models\DetailsExpediente;

class WhatsAppController extends Controller
{
    /**
     * Cliente REST de Twilio. Se instancia bajo demanda.
     *
     * @var \Twilio\Rest\Client|null
     */
    private $twilio = null;

    public function handle(Request $request)
    {
        $from = $request->input('From'); // Formato: whatsapp:+51999888777
        $text = trim((string) $request->input('Body'));
        $numMedia = (int) $request->input('NumMedia');
        $chatId = str_replace('whatsapp:', '', $from); // Usamos el número como Chat ID

        // Cuando el usuario toca una opción de una lista interactiva, Twilio
        // devuelve el título en "Body" y el identificador oculto del ítem.
        $payload = $request->input('ButtonPayload') ?: $request->input('ListId');

        Log::info("Mensaje de WhatsApp recibido de {$chatId}: {$text}", [
            // "To" es el sender que recibió el mensaje. Debe coincidir con
            // TWILIO_WHATSAPP_FROM: si difieren, la respuesta sale por un número
            // sin sesión abierta con el usuario y WhatsApp la descarta.
            'To' => $request->input('To'),
            'ButtonText' => $request->input('ButtonText'),
            'ButtonPayload' => $request->input('ButtonPayload'),
            'ListId' => $request->input('ListId'),
        ]);

        // --- VALIDACIÓN DE TIPO DE MENSAJE ---
        // Si NumMedia > 0, el usuario envió un archivo.
        if ($numMedia > 0) {
            $this->sendMessage($chatId, 'Por favor, envía solo mensajes de texto. No puedo procesar archivos, imágenes o stickers.');
            return $this->ack(); // Finaliza la ejecución
        }
        // --- FIN DE LA VALIDACIÓN ---

        // Obtener o crear el estado de la conversación
        $consulta = MainConsulta::firstOrCreate(
            ['chatId' => $chatId, 'service' => 'whatsapp'],
            [
                'status' => 0,
                'step' => 0,
                'regDate' => now()->toDateString(),
                'regDatetime' => now(),
                'regTimestamp' => now()->timestamp,
                'updDate' => now()->toDateString(),
                'updDatetime' => now(),
                'updTimestamp' => now()->timestamp,
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
                $this->handleStep4_ProvideDetails($consulta, $text, $payload);
                break;
        }

        // Los mensajes se envían por la API REST de Twilio, no por TwiML.
        // Aquí solo confirmamos la recepción del webhook.
        return $this->ack();
    }

    /**
     * Cliente REST de Twilio, instanciado una sola vez por request.
     */
    private function twilio()
    {
        if ($this->twilio === null) {
            $this->twilio = new Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );
        }

        return $this->twilio;
    }

    /**
     * Verifica que la configuración de Twilio esté completa.
     *
     * Sin esta comprobación, el SDK construye el cliente con credenciales nulas
     * y falla con un TypeError poco descriptivo dentro de vendor.
     */
    private function twilioConfigured()
    {
        return !empty(config('services.twilio.sid'))
            && !empty(config('services.twilio.token'))
            && !empty(config('services.twilio.whatsapp_from'));
    }

    /**
     * Registra qué parámetro de configuración falta, sin exponer su valor.
     */
    private function logConfiguracionIncompleta(string $chatId)
    {
        Log::error('Configuración de Twilio incompleta: no se envió el mensaje de WhatsApp.', [
            'chatId' => $chatId,
            'TWILIO_SID' => empty(config('services.twilio.sid')) ? 'VACIO' : 'OK',
            'TWILIO_AUTH_TOKEN' => empty(config('services.twilio.token')) ? 'VACIO' : 'OK',
            'TWILIO_WHATSAPP_FROM' => empty(config('services.twilio.whatsapp_from')) ? 'VACIO' : 'OK',

            // Diagnóstico: distingue entre config/services.php no desplegado,
            // configuración cacheada y .env sin valores.
            'bloque_services_twilio' => config('services.twilio') === null ? 'NO EXISTE' : 'EXISTE',
            'config_cacheada' => app()->configurationIsCached() ? 'SI' : 'NO',
            'env_directo_TWILIO_SID' => empty(env('TWILIO_SID')) ? 'VACIO' : 'OK',
            'archivo_config_services' => file_exists(config_path('services.php')) ? 'EXISTE' : 'NO EXISTE',
            'ruta_base' => base_path(),
        ]);
    }

    /**
     * Huella no reversible de las credenciales, para comparar el valor que usa
     * el servidor contra el del entorno local sin exponer los secretos.
     *
     * Se registra solo ante un fallo de envío.
     */
    private function credencialesFingerprint()
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');

        return [
            'sid_longitud' => strlen($sid),
            'sid_prefijo' => substr($sid, 0, 2),
            'sid_huella' => substr(hash('sha256', $sid), 0, 8),
            'token_longitud' => strlen($token),
            'token_huella' => substr(hash('sha256', $token), 0, 8),
            'from' => (string) config('services.twilio.whatsapp_from'),
            'config_cacheada' => app()->configurationIsCached() ? 'SI' : 'NO',
        ];
    }

    /**
     * Envía un mensaje de texto simple por WhatsApp.
     *
     * Devuelve false si no se pudo enviar, para que el flujo no avance de paso
     * dejando al usuario sin la indicación correspondiente.
     */
    private function sendMessage(string $chatId, string $message)
    {
        if (!$this->twilioConfigured()) {
            $this->logConfiguracionIncompleta($chatId);
            return false;
        }

        try {
            $inicio = microtime(true);

            $mensaje = $this->twilio()->messages->create('whatsapp:' . $chatId, [
                'from' => 'whatsapp:' . config('services.twilio.whatsapp_from'),
                'body' => $message,
            ]);

            $this->medirLatencia($inicio, 'mensaje de texto');
            $this->logEnvioAceptado($chatId, $mensaje);

            return true;
        } catch (\Throwable $e) {
            // \Throwable y no \Exception: el SDK puede lanzar Error/TypeError,
            // que no son capturados por \Exception.
            Log::error('Error al enviar mensaje de WhatsApp: ' . $e->getMessage(), array_merge(
                ['chatId' => $chatId],
                $this->credencialesFingerprint()
            ));

            return false;
        }
    }

    /**
     * Envía una lista interactiva (Content Template del tipo twilio/list-picker).
     *
     * Devuelve false si la plantilla no está configurada o si el envío falló,
     * para que el flujo pueda continuar con el listado en texto plano.
     */
    private function sendListPicker(string $chatId, $contentSid, array $variables = [])
    {
        if (empty($contentSid)) {
            // Deja constancia explícita: sin SID no se intenta siquiera la
            // llamada, y la respuesta sale en texto plano.
            Log::info('Content Template no configurada; se responde en texto plano.', [
                'chatId' => $chatId,
            ]);

            return false;
        }

        if (!$this->twilioConfigured()) {
            $this->logConfiguracionIncompleta($chatId);

            return false;
        }

        try {
            $params = [
                'from' => 'whatsapp:' . config('services.twilio.whatsapp_from'),
                'contentSid' => $contentSid,
            ];

            if (!empty($variables)) {
                $params['contentVariables'] = json_encode($variables, JSON_UNESCAPED_UNICODE);
            }

            $inicio = microtime(true);

            $mensaje = $this->twilio()->messages->create('whatsapp:' . $chatId, $params);

            $this->medirLatencia($inicio, 'lista interactiva');
            $this->logEnvioAceptado($chatId, $mensaje);

            return true;
        } catch (\Throwable $e) {
            Log::error('Error al enviar lista interactiva de WhatsApp: ' . $e->getMessage(), [
                'chatId' => $chatId,
                'contentSid' => $contentSid,
            ]);

            return false;
        }
    }

    /**
     * Deja constancia de que Twilio aceptó el envío.
     *
     * Aceptar no es entregar: el estado inicial suele ser "queued" y cualquier
     * fallo posterior ocurre de forma asíncrona, visible solo en los logs de la
     * consola de Twilio. El SID permite cruzar ambos registros.
     */
    private function logEnvioAceptado(string $chatId, $mensaje)
    {
        Log::info('Twilio aceptó el envío de WhatsApp.', [
            'chatId' => $chatId,
            'messageSid' => $mensaje->sid,
            'status' => $mensaje->status,
            'from' => (string) config('services.twilio.whatsapp_from'),
            'errorCode' => $mensaje->errorCode,
        ]);
    }

    /**
     * Registra la duración de la llamada saliente a Twilio cuando resulta lenta.
     *
     * Cada mensaje implica ahora una petición HTTPS hacia Twilio, a diferencia
     * del esquema TwiML anterior, que respondía dentro del mismo webhook.
     */
    private function medirLatencia($inicio, string $tipo)
    {
        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if ($ms > 1500) {
            Log::warning("Llamada lenta a Twilio ({$tipo}): {$ms} ms");
        }
    }

    /**
     * Respuesta vacía al webhook de Twilio.
     */
    private function ack()
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
            ->header('Content-Type', 'text/xml');
    }

    private function handleStep0_Start(MainConsulta $consulta)
    {
        $welcomeText = "¡Hola! Soy el asistente virtual de la Corte Superior de Justicia de Ancash.\n\n" .
                       "Por favor, ingresa el número de expediente que deseas consultar.";

        if (!$this->sendMessage($consulta->chatId, $welcomeText)) {
            return; // No avanzamos de paso si el saludo no llegó al usuario.
        }

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
        if ($consulta->status == 0) {
            $consulta->status = 1;
        }

        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        if (!$this->sendMessage($consulta->chatId, 'Buscando expediente, por favor espere...')) {
            return; // Sin canal de salida no tiene sentido continuar con la búsqueda.
        }

        if ($consulta->status == 1 && $consulta->step == 1) {
            sleep(2); // Bloqueo solicitado de 2 segundos
        }

        $expediente = CabExpediente::where('xFormato', $expedienteNum)->first();

        if (!$expediente) {
            $this->sendMessage($consulta->chatId, "No se encontró el expediente. Por favor, verifica el número e ingrésalo de nuevo.");
            // No cambiamos el step, para que el usuario pueda intentarlo de nuevo.
            return;
        }

        $partes = $this->partesDeExpediente($expediente);

        if ($partes->isEmpty()) {
            $this->sendMessage($consulta->chatId, 'Expediente encontrado, pero no se hallaron partes procesales asociadas.');
            $this->resetConversation($consulta);
            return;
        }

        // Intentamos presentar las partes como lista interactiva. La cantidad de
        // ítems de un list-picker se fija al crear la plantilla, por lo que se
        // usa el SID correspondiente a la cantidad de partes encontradas.
        $sidsPartes = config('services.twilio.content.partes', []);
        $totalPartes = $partes->count();
        $contentSid = isset($sidsPartes[$totalPartes]) ? $sidsPartes[$totalPartes] : null;

        $enviado = $this->sendListPicker(
            $consulta->chatId,
            $contentSid,
            $this->buildPartesVariables($partes)
        );

        if (!$enviado) {
            // Respaldo en texto plano cuando no hay plantilla configurada.
            $finalResponseText = "¡Expediente encontrado! Por favor, selecciona qué parte eres en el proceso respondiendo con el código (ej: DDO):\n\n";
            foreach ($partes as $parte) {
                $finalResponseText .= "• *{$parte->indTipoParte}*: {$parte->xDescParte}\n";
            }

            $enviado = $this->sendMessage($consulta->chatId, $finalResponseText);
        }

        if (!$enviado) {
            return; // El usuario no vio las partes: no avanzamos de paso.
        }

        // Actualizamos el estado de la conversación al final
        $consulta->message = $expedienteNum;
        $consulta->step = 2;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * Partes procesales de un expediente, en orden estable.
     *
     * El orden es crítico: los ID de los ítems de la plantilla son posicionales
     * (parte_1, parte_2, ...) porque no admiten variables. Si el orden cambiara
     * entre el envío de la lista y la respuesta del usuario, la selección se
     * resolvería a la parte equivocada. Por eso el ORDER BY es obligatorio y
     * ambos pasos usan este mismo método.
     */
    private function partesDeExpediente($expediente)
    {
        return PartesExp::where('nUnico', $expediente->nUnico)
            ->select('indTipoParte', 'xDescParte')
            ->distinct()
            ->orderBy('indTipoParte')
            ->get();
    }

    /**
     * Arma las variables de la plantilla de partes procesales.
     *
     * Por cada parte se envían dos variables consecutivas: el código de la parte
     * (título del ítem) y su descripción.
     */
    private function buildPartesVariables($partes)
    {
        $variables = [];
        $indice = 1;

        foreach ($partes as $parte) {
            // WhatsApp limita el título del ítem a 24 caracteres y la
            // descripción a 72. Además, no admite variables vacías.
            $variables[(string) $indice++] = Str::limit((string) $parte->indTipoParte, 24, '') ?: '-';
            $variables[(string) $indice++] = Str::limit((string) ($parte->xDescParte ?: 'Parte procesal'), 72, '');
        }

        return $variables;
    }

    /**
     * PASO 2: Recibe la selección de la parte y solicita el DNI.
     *
     * Tanto si el usuario toca un ítem de la lista como si escribe el código,
     * Twilio entrega el texto en "Body", por lo que el tratamiento es el mismo.
     */
    private function handleStep2_ReceivePartSelection(MainConsulta $consulta, string $tipoParte)
    {
        $processedInput = strtoupper(trim($tipoParte));

        // Primero, recuperamos el expediente basado en el mensaje guardado en el paso anterior
        $expediente = CabExpediente::where('xFormato', $consulta->message)->first();

        if (!$expediente) {
            $this->sendMessage($consulta->chatId, 'No fue posible recuperar el expediente. Por favor, inicia la consulta nuevamente.');
            $this->resetConversation($consulta);
            return;
        }

        // Si la respuesta vino de la lista interactiva, Twilio entrega en "Body"
        // el ID del ítem (parte_1, parte_2, ...) y no el código de la parte.
        // Resolvemos la posición contra el mismo listado ordenado que se envió.
        if (preg_match('/^PARTE_(\d+)$/', $processedInput, $coincidencias)) {
            $seleccionada = $this->partesDeExpediente($expediente)->get((int) $coincidencias[1] - 1);

            if (!$seleccionada) {
                $this->sendMessage($consulta->chatId, 'La opción seleccionada ya no está disponible. Por favor, elige una de las opciones mostradas anteriormente.');
                return;
            }

            $processedInput = strtoupper(trim((string) $seleccionada->indTipoParte));
        }

        // Verificamos si la parte seleccionada por el usuario es válida para ese expediente
        $parteExiste = PartesExp::where('nUnico', $expediente->nUnico)
            ->where('indTipoParte', $processedInput)
            ->exists();

        if (!$parteExiste) {
            $this->sendMessage($consulta->chatId, "La opción '{$processedInput}' no es válida. Por favor, elige una de las opciones mostradas anteriormente (ej: DDO).");
            return; // Mantenemos al usuario en el mismo paso
        }

        if (!$this->sendMessage($consulta->chatId, "Por favor, ingresa tu número de DNI (8 dígitos) para validar tu identidad.")) {
            return; // No avanzamos de paso si la indicación no llegó al usuario.
        }

        // Si la opción es válida, la guardamos y avanzamos
        $consulta->tipoParteSeleccionada = $processedInput;
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
            $this->sendMessage($consulta->chatId, 'El DNI debe contener 8 dígitos numéricos. Por favor, ingrésalo de nuevo.');
            return;
        }

        $consulta->dni = $dni;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();

        $expediente = CabExpediente::where('xFormato', $consulta->message)->first();

        if (!$expediente) {
            $this->sendMessage($consulta->chatId, 'No fue posible recuperar el expediente. Por favor, inicia la consulta nuevamente.');
            $this->resetConversation($consulta);
            return;
        }

        $parteValida = PartesExp::where('nUnico', $expediente->nUnico)
            ->where('indTipoParte', $consulta->tipoParteSeleccionada)
            ->where('xDocId', $dni)
            ->exists();

        if (!$parteValida) {
            $this->sendMessage($consulta->chatId, 'Tu DNI no corresponde a la parte procesal seleccionada en este expediente. El proceso ha finalizado.');
            $this->resetConversation($consulta);
            return;
        }

        // Menú de consultas como lista interactiva.
        $enviado = $this->sendListPicker(
            $consulta->chatId,
            config('services.twilio.content.consultas')
        );

        if (!$enviado) {
            // Respaldo en texto plano cuando no hay plantilla configurada.
            $responseText = "¡Validación exitosa! ¿Qué deseas consultar?\nResponde con el número de la opción:\n\n" .
                            "1. Información General del Expediente\n" .
                            "2. Detalle de Escritos del Expediente\n" .
                            "3. Próximas Audiencias del Expediente\n" .
                            "4. Ubicación del Expediente\n" .
                            "5. Estado del Expediente\n";

            $enviado = $this->sendMessage($consulta->chatId, $responseText);
        }

        if (!$enviado) {
            return; // El usuario no vio el menú: no avanzamos de paso.
        }

        $consulta->step = 4;
        $consulta->updDate = now()->toDateString();
        $consulta->updDatetime = now();
        $consulta->updTimestamp = now()->timestamp;
        $consulta->save();
    }

    /**
     * PASO 4: Proporciona la información solicitada y cierra el flujo.
     *
     * La opción puede llegar de dos maneras: como identificador del ítem tocado
     * en la lista interactiva (consulta_xxx) o como el número escrito por el
     * usuario cuando se usó el respaldo en texto plano.
     */
    private function handleStep4_ProvideDetails(MainConsulta $consulta, string $opcion, $payload = null)
    {
        $expediente = CabExpediente::where('xFormato', $consulta->message)->first();

        if (!$expediente) {
            $this->sendMessage($consulta->chatId, 'No fue posible recuperar el expediente. Por favor, inicia la consulta nuevamente.');
            $this->resetConversation($consulta);
            return;
        }

        $detalle = DetailsExpediente::where('nUnico', $expediente->nUnico)->first();
        $responseText = "No se encontró información para esa consulta.";

        $mapOpciones = [
            '1' => 'info_general',
            '2' => 'detalle_escritos',
            '3' => 'proximas_audiencias',
            '4' => 'ubicacion',
            '5' => 'estadoexp',
            '6' => 'depositos',
            '7' => 'calificacion',
            '8' => 'estadodemanda',
            '9' => 'liquidacion',
            '10' => 'informe',
        ];

        $entrada = strtolower(trim($opcion));

        if ($payload && Str::startsWith($payload, 'consulta_')) {
            // Identificador entregado en ButtonPayload.
            $tipoConsulta = substr($payload, strlen('consulta_'));
        } elseif (Str::startsWith($entrada, 'consulta_')) {
            // La lista interactiva entrega el ID del ítem en "Body".
            $tipoConsulta = substr($entrada, strlen('consulta_'));
        } else {
            // Respuesta numérica del respaldo en texto plano.
            $tipoConsulta = isset($mapOpciones[$entrada]) ? $mapOpciones[$entrada] : 'invalida';
        }

        $respuesta_ini = "El expediente " . $expediente->xFormato . " de la Instancia " . $expediente->instancia . " de la especiadidad " . $expediente->codEspecialidad ;
        $respuesta_ini .= " de la materia" . ($detalle->xDescMateria ?? 'No disponible') ." Que tiene como Juez a " . ($detalle->juez ?? 'No disponible');
        $respuesta_ini .= " y Secretario " . ($detalle->secretario ?? 'No disponible') . " Tiene la Siguiente Información.\n\n";

        if ($detalle && $tipoConsulta !== 'invalida') {
             switch ($tipoConsulta) {
                case 'info_general':
                    $responseText = $respuesta_ini." *Información General del Expediente:*\n" . ($detalle->xDescUbicacion ?? 'No disponible');
                    break;
                case 'detalle_escritos':
                    $responseText = $respuesta_ini." *Detalle de Escritos del Expediente:*\n" . ($detalle->xDescUbicacion ?? 'No disponible');
                    break;
                case 'proximas_audiencias':
                    $responseText = $respuesta_ini." *Próximas Audiencias del Expediente:*\n" . ($detalle->xDescUbicacion ?? 'No se tienen audiencias programadas próximamente.');
                    break;

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

        if (!$this->sendMessage($consulta->chatId, $responseText)) {
            return; // Mantenemos el paso para que el usuario pueda reintentar.
        }

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
