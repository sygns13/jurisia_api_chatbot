# Content Templates de WhatsApp (listas interactivas)

Guía para crear en Twilio las plantillas `twilio/list-picker` que usa el
`WhatsAppController` y registrar sus SID en el archivo `.env`.

Mientras los SID no estén configurados, **el bot sigue funcionando igual que
antes**, respondiendo con el listado en texto plano. La migración puede hacerse
de forma gradual.

---

## 1. Por qué hay varias plantillas de partes procesales

La cantidad de ítems de un `list-picker` **se fija al crear la plantilla**; solo
el texto de cada ítem admite variables. Como el número de partes procesales
varía según el expediente, se registra una plantilla por cada cantidad posible
(2, 3, 4 y 5 partes).

Si un expediente devuelve una cantidad de partes sin plantilla registrada
(por ejemplo 6), el controlador usa automáticamente el respaldo en texto plano.

El menú de tipos de consulta, en cambio, tiene 5 opciones fijas y no necesita
variables: una sola plantilla.

---

## 2. Importante: no enviar a aprobación

Estas plantillas se envían **dentro de la ventana de 24 horas** (el ciudadano
siempre escribe primero), por lo que **no requieren aprobación de WhatsApp**:

- En la consola de Twilio: usar **"Save"**, no "Save and submit to WhatsApp".
- Vía API: **no** llamar al endpoint `ApprovalRequests`.

Además, las plantillas `list-picker` no admiten aprobación ni pueden iniciar una
conversación, únicamente responder dentro de una sesión activa.

**Verificado el 11/08/2026:** el sandbox de WhatsApp **sí** admite plantillas
`list-picker` enviadas dentro de la ventana de 24 horas, pese a que la
documentación de Twilio indica que el sandbox no soporta plantillas
personalizadas (esa restricción aplica a las plantillas aprobadas que inician
conversación, no a los mensajes interactivos en sesión).

---

## 3. Límites de WhatsApp a respetar

| Campo | Límite |
|---|---|
| Título del ítem (`item`) | 24 caracteres |
| Descripción del ítem (`description`) | 72 caracteres |
| Texto del botón (`button`) | 20 caracteres |
| Cantidad de ítems | 1 a 10 |

El controlador ya trunca los valores dinámicos a estos límites.

---

## 4. Plantilla del menú de consultas

Registrar el SID resultante en `TWILIO_CONTENT_SID_CONSULTAS`.

```bash
curl -X POST https://content.twilio.com/v1/Content \
  -H "Content-Type: application/json" \
  -u "$TWILIO_SID:$TWILIO_AUTH_TOKEN" \
  -d '{
    "friendly_name": "csjan_menu_consultas",
    "language": "es",
    "types": {
      "twilio/list-picker": {
        "body": "¡Validación exitosa! ¿Qué deseas consultar?",
        "button": "Ver opciones",
        "items": [
          { "id": "consulta_info_general",        "item": "Información General", "description": "Información general del expediente" },
          { "id": "consulta_detalle_escritos",    "item": "Detalle de Escritos", "description": "Escritos presentados en el expediente" },
          { "id": "consulta_proximas_audiencias", "item": "Próximas Audiencias", "description": "Audiencias programadas" },
          { "id": "consulta_ubicacion",           "item": "Ubicación",           "description": "Ubicación actual del expediente" },
          { "id": "consulta_estadoexp",           "item": "Estado",              "description": "Estado actual del expediente" }
        ]
      }
    }
  }'
```

Los `id` deben conservar el prefijo **`consulta_`** y coincidir exactamente con
los casos del `switch` en `handleStep4_ProvideDetails()`. El controlador lee ese
identificador desde el campo `ButtonPayload` del webhook entrante.

---

## 5. Plantillas de partes procesales

Cada parte ocupa **dos variables consecutivas**: el código (título del ítem) y
su descripción. El controlador las arma en ese orden en
`buildPartesVariables()`.

### 5.1 Dos partes — `TWILIO_CONTENT_SID_PARTES_2`

```bash
curl -X POST https://content.twilio.com/v1/Content \
  -H "Content-Type: application/json" \
  -u "$TWILIO_SID:$TWILIO_AUTH_TOKEN" \
  -d '{
    "friendly_name": "csjan_partes_2",
    "language": "es",
    "variables": { "1": "DTE", "2": "DEMANDANTE", "3": "DDO", "4": "DEMANDADO" },
    "types": {
      "twilio/list-picker": {
        "body": "¡Expediente encontrado! Selecciona qué parte eres en el proceso:",
        "button": "Ver partes",
        "items": [
          { "id": "parte_1", "item": "{{1}}", "description": "{{2}}" },
          { "id": "parte_2", "item": "{{3}}", "description": "{{4}}" }
        ]
      }
    }
  }'
```

### 5.2 Tres partes — `TWILIO_CONTENT_SID_PARTES_3`

Igual que la anterior, con `friendly_name` **csjan_partes_3**, variables `1` a
`6` y estos ítems:

```json
"items": [
  { "id": "parte_1", "item": "{{1}}", "description": "{{2}}" },
  { "id": "parte_2", "item": "{{3}}", "description": "{{4}}" },
  { "id": "parte_3", "item": "{{5}}", "description": "{{6}}" }
]
```

### 5.3 Cuatro partes — `TWILIO_CONTENT_SID_PARTES_4`

`friendly_name` **csjan_partes_4**, variables `1` a `8`:

```json
"items": [
  { "id": "parte_1", "item": "{{1}}", "description": "{{2}}" },
  { "id": "parte_2", "item": "{{3}}", "description": "{{4}}" },
  { "id": "parte_3", "item": "{{5}}", "description": "{{6}}" },
  { "id": "parte_4", "item": "{{7}}", "description": "{{8}}" }
]
```

### 5.4 Cinco partes — `TWILIO_CONTENT_SID_PARTES_5`

`friendly_name` **csjan_partes_5**, variables `1` a `10`:

```json
"items": [
  { "id": "parte_1", "item": "{{1}}",  "description": "{{2}}" },
  { "id": "parte_2", "item": "{{3}}",  "description": "{{4}}" },
  { "id": "parte_3", "item": "{{5}}",  "description": "{{6}}" },
  { "id": "parte_4", "item": "{{7}}",  "description": "{{8}}" },
  { "id": "parte_5", "item": "{{9}}",  "description": "{{10}}" }
]
```

**El `id` es lo que determina la selección.** Verificado el 11/08/2026 con
tráfico real: al tocar un ítem de un `list-picker`, Twilio devuelve en el campo
`Body` el **ID del ítem** (`parte_2`), no su título. Para los botones de
respuesta rápida la documentación indica lo contrario —devuelven el título—,
pero el selector de lista se comporta así y no está documentado.

Como el `id` no admite variables, es necesariamente posicional. Por eso
`handleStep2_ReceivePartSelection()` traduce `parte_N` a la parte que ocupa esa
posición, consultando el mismo listado ordenado que se usó al enviar la lista
(`partesDeExpediente()`, con `ORDER BY indTipoParte`).

Consecuencia práctica: **los ID deben numerarse correlativamente desde
`parte_1`**, en el mismo orden en que aparecen los ítems. Si se altera esa
correspondencia, la selección resolverá a la parte equivocada.

### 5.5 Creación desde la consola

Alternativa al `curl`, con los mismos resultados. En **Messaging → Content
Template Builder → Create new content template**, tipo **List Picker**:

- *Body*: `¡Expediente encontrado! Selecciona qué parte eres en el proceso:`
- *Button text*: `Ver partes`

Los ítems se completan con variables en lugar de texto fijo. Por cada parte se
usan dos variables consecutivas: título (código) y descripción.

| Plantilla | Friendly name | Ítems (ID / Título / Descripción) |
|---|---|---|
| 2 partes | csjan_partes_2 | parte_1 / `{{1}}` / `{{2}}` — parte_2 / `{{3}}` / `{{4}}` |
| 3 partes | csjan_partes_3 | ... más parte_3 / `{{5}}` / `{{6}}` |
| 4 partes | csjan_partes_4 | ... más parte_4 / `{{7}}` / `{{8}}` |
| 5 partes | csjan_partes_5 | ... más parte_5 / `{{9}}` / `{{10}}` |

Valores de ejemplo sugeridos para las variables (la consola los exige, pero se
reemplazan en cada envío):

| Variable | Ejemplo | Variable | Ejemplo |
|---|---|---|---|
| `{{1}}` | DTE | `{{2}}` | DEMANDANTE |
| `{{3}}` | DDO | `{{4}}` | DEMANDADO |
| `{{5}}` | TER | `{{6}}` | TERCERO |
| `{{7}}` | LIT | `{{8}}` | LITISCONSORTE |
| `{{9}}` | AGR | `{{10}}` | AGRAVIADO |

---

## 6. Registro de los SID

Agregar al `.env` de producción, con los SID `HX...` devueltos por cada llamada:

```
TWILIO_CONTENT_SID_CONSULTAS=HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_CONTENT_SID_PARTES_2=HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_CONTENT_SID_PARTES_3=HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_CONTENT_SID_PARTES_4=HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_CONTENT_SID_PARTES_5=HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Luego, en el servidor:

```
php artisan config:clear
```

---

## 7. Verificación

1. Escribir al bot desde WhatsApp e ingresar un número de expediente válido.
2. Confirmar que las partes procesales llegan como lista desplegable.
3. Revisar `storage/logs/laravel.log`: cada mensaje entrante deja registrados
   los campos `ButtonText`, `ButtonPayload` y `ListId`.
4. Comportamiento confirmado con tráfico real: la selección de un ítem de lista
   llega como el **ID del ítem dentro de `Body`**. El controlador contempla
   además `ButtonPayload` y `ListId` por si Twilio los completa en otros
   escenarios.

---

## 8. Comportamiento ante fallos

| Situación | Comportamiento |
|---|---|
| SID no configurado | Se envía el listado en texto plano. |
| Cantidad de partes sin plantilla registrada | Se envía el listado en texto plano. |
| Error en la llamada a Twilio | Se registra en el log y se envía el listado en texto plano. |

El respaldo en texto plano conserva el formato anterior, por lo que las
respuestas numéricas (`1` a `5`) y por código (`DDO`) siguen siendo válidas.
