# Factibilidad: enviar audio del expediente por Telegram y WhatsApp

**Fecha:** 2026-08-11
**Alcance:** `appJurisiaApiBot` (Laravel 8 / PHP 8.2) — canales Telegram (`irazasyed/telegram-bot-sdk` v3.9) y WhatsApp (`twilio/sdk` v8.7.0).
**Pregunta:** si el microservicio de la Corte (`ms-jurisia-judicial`) envía un archivo de audio del expediente y la API lo guarda en su `storage` local (no hay almacenamiento externo), ¿pueden Telegram y/o WhatsApp entregar ese adjunto junto con la respuesta de texto?

---

## 1. Respuesta corta

**Sí, ambos canales lo soportan.** Ninguno de los dos exige almacenamiento externo tipo S3. Pero **no funciona con la implementación actual**: hoy el código solo envía texto y listas interactivas, y hay que agregar el envío de media en ambos controladores más el punto de ingesta del archivo.

| Canal | ¿Soporta audio? | ¿Requiere URL pública? | Esfuerzo |
|---|---|---|---|
| **Telegram** | Sí | **No** — sube el binario por `multipart/form-data` desde el disco local | Bajo |
| **WhatsApp (Twilio)** | Sí | **Sí** — Twilio descarga el archivo desde una URL que su infraestructura debe poder resolver | Medio (hay que exponer el archivo) |

La diferencia esencial: Telegram acepta el **contenido del archivo**; Twilio acepta solo una **referencia (URL)** que va a buscar por su cuenta.

---

## 2. Estado actual del código (evidencia)

Lo verificado en este árbol:

- `WhatsAppController::sendMessage()` (`public_html/appJurisiaApiBot/app/Http/Controllers/WhatsAppController.php:175`) construye el mensaje con **solo** `from` y `body`. No hay ningún uso de `mediaUrl` en el proyecto.
- `WhatsAppController::sendListPicker()` (línea 231) usa `contentSid` + `contentVariables`, exclusivamente para `twilio/list-picker`.
- `TelegramController` usa únicamente `Telegram::sendMessage([...])`; no hay llamadas a `sendAudio`, `sendVoice` ni `sendDocument`.
- Ambos controladores **rechazan** media *entrante* (`WhatsAppController.php:43` con `NumMedia > 0`; `TelegramController.php:34` con `!$message->has('text')`). Esto es una restricción de entrada y **no** impide el envío de salida.
- `ApiController::updateConsulta()` valida y consume exclusivamente JSON (`id`, `chatId`, `expFound`, `cabExpedienteChat`, `listPartes`, `detailsExp`). **No existe hoy ninguna vía por la que el microservicio pueda entregar un binario.**
- `config/filesystems.php` tiene el disco `public` estándar (`storage/app/public` → `public_path('storage')`), pero **el symlink no existe**: `public_html/` contiene solo `appJurisiaApiBot/`, `index.php`, `favicon.ico` y `robots.txt`. Además `php artisan storage:link` apuntaría a `public_path()` = `.../appJurisiaApiBot/public`, directorio que en este despliegue **no existe** (ver `CLAUDE.md`, sección "Deployment layout").

Conclusión de la evidencia: el canal de salida de media hay que construirlo, y la ruta de ingesta del binario también.

---

## 3. Telegram — soportado de forma nativa y sin infraestructura adicional

El SDK ya instalado expone los métodos necesarios (verificado en `vendor/irazasyed/telegram-bot-sdk/src/Methods/Message.php`):

| Método | Línea en vendor | Uso |
|---|---|---|
| `sendAudio()` | `Message.php:176` | audio reproducible en el reproductor musical del cliente |
| `sendVoice()` | `Message.php:321` | nota de voz (burbuja con forma de onda) |
| `sendDocument()` | `Message.php:211` | cualquier archivo, como adjunto descargable |

Los tres se resuelven internamente con `uploadFile(...)`, es decir **envío multipart del binario local**. El parámetro acepta `InputFile::file($rutaLocal)` (clase presente en `vendor/irazasyed/telegram-bot-sdk/src/FileUpload/InputFile.php`).

### Restricciones de la Bot API

- **Formato:** `sendAudio` espera *"an audio file (MP3 or M4A) to be sent"*. `sendVoice` espera *"a voice note in OGG OPUS format"*. Cualquier otro formato debe ir por `sendDocument` (se entrega como adjunto, sin reproductor embebido).
- **Tamaño:** hasta **50 MB** subiendo el archivo por `multipart/form-data`; **20 MB** si en lugar del binario se pasa una URL HTTP. (El límite de 2000 MB solo aplica corriendo un Bot API server propio, que no es el caso.)
- **Texto + audio:** el `caption` del audio tiene un tope de caracteres muy inferior al de `sendMessage`. Dado que `$responseText` en el paso 4 ya concatena un preámbulo largo (`WhatsAppController.php:574-576` y su equivalente en Telegram), **lo correcto es enviar el texto con `sendMessage` y luego el audio con `sendAudio` en un segundo mensaje**, en vez de pelear con el límite del caption.
  > Nota: el docblock del SDK v3.9 dice "0-200 characters" para el caption; ese valor está desactualizado respecto de la Bot API vigente. Como la recomendación es no usar caption largo, el punto es irrelevante en la práctica.

**Veredicto Telegram: viable sin exponer nada al exterior.** El archivo puede vivir en `storage/app/` (privado) y enviarse leyéndolo desde disco.

---

## 4. WhatsApp / Twilio — soportado, pero exige exponer el archivo por HTTPS

El SDK instalado soporta el parámetro (verificado en `vendor/twilio/sdk/src/Twilio/Rest/Api/V2010/Account/MessageOptions.php:274`, `setMediaUrl(array $mediaUrl)`). En la práctica:

```php
$this->twilio()->messages->create('whatsapp:' . $chatId, [
    'from'     => 'whatsapp:' . config('services.twilio.whatsapp_from'),
    'body'     => $texto,               // opcional según tipo de audio, ver abajo
    'mediaUrl' => [$urlPublicaDelAudio],
]);
```

### Restricción central: Twilio no recibe el archivo, lo va a buscar

La documentación de `twilio/media` es explícita: *"Static media urls should resolve to **publicly hosted** media files."* Y la guía de media de WhatsApp añade que *"Twilio checks the `content-type` header at the provided `MediaUrl` to validate the content type of the media file"* — si la cabecera no coincide con el archivo real, **Twilio rechaza el request**.

Esto significa que "guardarlo en el storage local" **no alcanza para WhatsApp**: hay que publicar una URL HTTPS que los servidores de Twilio puedan descargar, devolviendo el `Content-Type` correcto.

### Restricciones de formato y tamaño

- **Tipos de audio aceptados para WhatsApp:** `audio/ogg`, `audio/mpeg`, `audio/mp3`, `audio/3gpp`, `audio/ac3`, `audio/amr`. Para OGG hay una condición dura: *"WhatsApp outbound OGG is only supported when the OGG file uses the opus audio codec."*
- **Tamaño:** la referencia de tipos MIME indica **20 MB** como máximo combinado para mensajes salientes de WhatsApp; la documentación de `twilio/media` indica *"less than 5MB (16MB for WhatsApp)"*. Las cifras no coinciden entre páginas de Twilio; **el criterio prudente es mantenerse por debajo de 16 MB**.
  > Ojo con un falso negativo: el PHPDoc de `MessageOptions.php:27` menciona "5 MB … y 500 KB para otros tipos". Ese texto describe **MMS/SMS**, no WhatsApp. No es el límite aplicable aquí.
- **Texto en el mismo mensaje:** *"Most WhatsApp audio types cannot be paired with text in the same message. Only `audio/ogg` and `audio/mpeg` allow text combination."* Es decir: con MP3 (`audio/mpeg`) sí se puede mandar `body` + audio juntos; con AMR/3GPP/AC3 hay que **separar en dos mensajes**. Como la arquitectura actual ya envía todo out-of-band por REST (no por TwiML), mandar dos mensajes seguidos no rompe nada.

### Ventana de 24 horas

Los mensajes con media libre (freeform) solo se pueden enviar dentro de la ventana de servicio al cliente: *"customer service windows are valid for 24 hours after the most recently received message, during which you can communicate with customers using free-form messages"*. Fuera de esa ventana hay que usar plantillas, y *"WhatsApp does not support media in 'template' messages that take place outside of a 24-hour 'session'"* (fuera de sesión se requiere una media template aprobada aparte).

**Esto no es un problema para el flujo actual**: el audio se envía como respuesta inmediata al paso 4, disparado por un mensaje del propio usuario, o sea siempre dentro de la ventana. Sí sería un problema si en el futuro se quisiera enviar el audio de forma proactiva (push) horas después.

**Veredicto WhatsApp: viable, condicionado a publicar el archivo por HTTPS.**

---

## 5. La brecha real: cómo publicar el audio sin abrir el storage

Este despliegue tiene una particularidad que conviene mirar antes de improvisar. La aplicación Laravel completa vive **dentro** del document root (`public_html/appJurisiaApiBot/`), y el `.htaccess` de `public_html/` solo redirige al front controller cuando el recurso **no existe** en disco:

```apache
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

Es decir, cualquier archivo real bajo `public_html/appJurisiaApiBot/...` es servido directamente por Apache, sin pasar por Laravel. Volcar los audios en `storage/app/public/` los haría alcanzables, sí — pero por una URL que expone la estructura interna y depende de que no exista ninguna regla de bloqueo del hosting. **No es el camino recomendable.**

### Opción recomendada: ruta firmada que hace *stream* del archivo

Mantener el audio en `storage/app/` (privado) y publicarlo mediante una ruta Laravel con URL firmada y caducidad:

```php
// routes/api.php
Route::get('/v1/audio/{consulta}', [AudioController::class, 'stream'])
    ->name('audio.stream')
    ->middleware('signed');
```

```php
// AudioController::stream()
return response()->file(storage_path('app/audios/' . $nombreArchivo), [
    'Content-Type' => 'audio/mpeg',   // debe coincidir con el archivo real (Twilio lo valida)
]);
```

y al enviar:

```php
$url = URL::temporarySignedRoute('audio.stream', now()->addHours(2), ['consulta' => $consulta->id]);
```

Esto cumple los tres requisitos de Twilio a la vez: URL alcanzable públicamente, sin autenticación por cabeceras (la firma va en el query string), y `Content-Type` bajo control. La caducidad limita la exposición de información judicial a una ventana corta.

> Advertencia de retención: Twilio, una vez descargado el archivo, lo **almacena en su lado** — *"Twilio retains the stored media until you delete the related Media subresource instance"*. Es decir, la URL firmada caduca pero la copia en Twilio persiste hasta que se borre explícitamente vía API. Para contenido de expedientes judiciales esto amerita una decisión formal (¿se purga el Media subresource tras la entrega?).

---

## 6. Cambios necesarios (checklist)

Ninguno de estos existe hoy:

1. **Ingesta del binario desde el microservicio.** `ApiController::updateConsulta` es JSON puro. Se necesita un endpoint nuevo (p. ej. `POST /api/v1/consulta-audio`) que reciba `multipart/form-data`, o bien aceptar el audio en base64 dentro del JSON existente (más simple, pero infla el payload ~33% y pega contra `post_max_size`).
2. **Persistencia del archivo.** `Storage::disk('local')->putFileAs('audios', $file, $nombre)`. Nombre no adivinable (evitar `nUnico` crudo en la ruta).
3. **Columna en base de datos** para la ruta y metadatos (`audioPath`, `audioMime`, `audioBytes`). Recordar que **las tablas del dominio no tienen migraciones** — se crean directamente en MySQL — y que hay que agregar el campo al `$fillable` del modelo y setear a mano el trío `regDate`/`regDatetime`/`regTimestamp`.
4. **Envío en `TelegramController`:** `Telegram::sendAudio(['chat_id' => ..., 'audio' => InputFile::file($ruta)])` después del `sendMessage` del paso 4.
5. **Envío en `WhatsAppController`:** método nuevo `sendMedia($chatId, $url, $body = null)` con la misma estructura defensiva que `sendMessage()` (chequeo de `twilioConfigured()`, `try/catch \Throwable`, retorno booleano, `medirLatencia`).
6. **Ruta firmada + `AudioController`** según §5.
7. **Verificar límites de PHP en el hosting compartido** (`upload_max_filesize`, `post_max_size`, `memory_limit`): un audio de 15 MB puede exceder el default de Hostinger y el fallo se manifestaría como un 413 o un request vacío, no como una excepción legible.
8. **Política de purga** del storage local y, si aplica, del Media subresource en Twilio.

> **Recordatorio de este proyecto:** `TelegramController` y `WhatsAppController` son casi-duplicados deliberados de la misma máquina de estados. Un cambio de flujo hay que aplicarlo **en los dos**. Y los despliegues son subidas manuales de archivos: un cambio que toca controlador + config + `.env` puede llegar a medias al servidor.

---

## 7. Riesgos y consideraciones

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Codec OGG no-opus | Twilio rechaza el envío a WhatsApp | Estandarizar el formato de entrega del microservicio en **MP3 (`audio/mpeg`)**: es el único que simultáneamente cumple `sendAudio` de Telegram, es aceptado por WhatsApp y admite texto en el mismo mensaje |
| `Content-Type` mal declarado | Twilio rechaza el request | Fijar la cabecera explícitamente en la ruta de stream, no confiar en la detección de Apache |
| Audio > 16 MB | Falla el envío por WhatsApp (Telegram aguanta hasta 50 MB) | Validar tamaño en la ingesta y degradar a un mensaje "audio no disponible por este canal" |
| URL firmada filtrada | Exposición de información de expediente | Caducidad corta (1-2 h) + nombre de archivo no adivinable + purga periódica |
| Copia persistente en Twilio | Información judicial fuera del control de la Corte | Decisión formal sobre borrado del Media subresource |
| Latencia | El webhook ya hace `sleep(2)` y llamadas HTTPS salientes; sumar la subida del audio puede acercarlo al timeout de Twilio/Telegram | Enviar el audio en un job en cola, o al menos medir con `medirLatencia()` |
| Envío proactivo fuera de 24 h | Bloqueado por política de WhatsApp | Mantener el audio como respuesta a mensaje del usuario |

---

## 8. Conclusión

**Afirmativa para ambos canales.** El almacenamiento local es suficiente:

- **Telegram** puede enviar el archivo directamente desde `storage/app/` sin exponerlo — el SDK v3.9 ya instalado tiene `sendAudio`/`sendVoice`/`sendDocument` con subida multipart, límite 50 MB.
- **WhatsApp** también puede, pero Twilio descarga la media desde una URL: hay que exponer el archivo por HTTPS con `Content-Type` correcto, preferentemente con una ruta firmada y caduca, respetando ~16 MB y la ventana de 24 h.

El trabajo no está en la capacidad de los canales sino en (a) crear la vía de ingesta del binario desde `ms-jurisia-judicial`, que hoy no existe, y (b) publicar el archivo de forma controlada para Twilio.

Formato recomendado de entrega desde el microservicio: **MP3 / `audio/mpeg`**, ≤ 16 MB.

---

## 9. Fuentes

**Telegram**
- [Telegram Bot API — `sendAudio`](https://core.telegram.org/bots/api#sendaudio) — formato MP3/M4A, `InputFile`.
- [Telegram Bot API — `sendVoice`](https://core.telegram.org/bots/api#sendvoice) — requisito OGG OPUS.
- [Telegram Bot API — `InputFile` / Sending files](https://core.telegram.org/bots/api#sending-files) — multipart hasta 50 MB, 20 MB vía URL HTTP.

**Twilio / WhatsApp**
- [Twilio — Accepted Content Types for Media](https://www.twilio.com/docs/messaging/guides/accepted-mime-types) — tipos de audio aceptados por WhatsApp; límite de 20 MB; regla de que solo `audio/ogg` y `audio/mpeg` admiten texto en el mismo mensaje.
- [Twilio — Guidance on WhatsApp Media Messages](https://www.twilio.com/docs/whatsapp/guidance-whatsapp-media-messages) — validación del `content-type` header; OGG solo con codec opus; límites de tamaño.
- [Twilio — `twilio/media` content type](https://www.twilio.com/docs/content/twilio-media) — *"Static media urls should resolve to publicly hosted media files"*; 16 MB para WhatsApp.
- [Twilio — Message Resource (`MediaUrl`)](https://www.twilio.com/docs/messaging/api/message-resource) — parámetro `MediaUrl`.
- [Twilio — Media subresource](https://www.twilio.com/docs/messaging/api/media-resource) — retención: Twilio conserva la media hasta que se borre el subrecurso.
- [Twilio — Overview of the WhatsApp Business Platform](https://www.twilio.com/docs/whatsapp/api) — ventana de 24 h para mensajes freeform con media.
- [Twilio — Error 63016 (fuera de la ventana de mensajería)](https://www.twilio.com/docs/api/errors/63016) — comportamiento al enviar freeform fuera de sesión.

**Código verificado en este árbol**
- `vendor/irazasyed/telegram-bot-sdk/src/Methods/Message.php:176,211,321` — `sendAudio`, `sendDocument`, `sendVoice`.
- `vendor/irazasyed/telegram-bot-sdk/src/FileUpload/InputFile.php`.
- `vendor/twilio/sdk/src/Twilio/Rest/Api/V2010/Account/MessageOptions.php:274` — `setMediaUrl()`.
- `app/Http/Controllers/WhatsAppController.php:175,231` — envío actual, solo texto y list-picker.
- `app/Http/Controllers/ApiController.php:55` — `updateConsulta`, solo JSON.
- `config/filesystems.php` — disco `public` sin symlink desplegado.
- `public_html/.htaccess` — sirve archivos existentes sin pasar por Laravel.
