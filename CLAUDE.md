# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Hostinger shared-hosting deployment tree for the domain `tan-goose-839396.hostingersite.com`. The only application is **appJurisiaApiBot**, a Laravel 8 / PHP 8 chatbot API that lets litigants query judicial case files ("expedientes") of the Corte Superior de Justicia de Ancash (Peru) over Telegram and WhatsApp.

Only `public_html/` is uploaded to the host. The empty `DO_NOT_UPLOAD_HERE` marker at the tree root exists to keep files from being dropped one level above the web root.

## Deployment layout (important)

The Laravel app does **not** have a `public/` directory. It was split so that the framework lives outside the document root:

```
public_html/                 <- Apache document root
  index.php                  <- front controller; requires ../appJurisiaApiBot/{vendor,bootstrap}
  .htaccess                  <- standard Laravel rewrite, all requests -> index.php
  appJurisiaApiBot/          <- full Laravel app (app, config, routes, vendor, storage, .env)
```

Consequences to keep in mind:

- `php artisan serve` and `server.php` are **broken** — both look for `appJurisiaApiBot/public/index.php`, which does not exist. To run locally, point a web server's document root at `public_html/`.
- Anything that would normally live in `public/` (compiled Mix assets, storage symlink) has to be placed in `public_html/` manually. `webpack.mix.js` and `package.json` still assume the default `public/` path and are effectively unused — there is no frontend beyond the stock `welcome.blade.php`.
- Editing `public_html/index.php` requires keeping the `__DIR__.'/appJurisiaApiBot/...'` prefixes intact.

## Commands

Run all of these from `public_html/appJurisiaApiBot/`:

```bash
composer install                       # deps (vendor/ is committed in this tree)
php artisan config:clear && php artisan cache:clear   # after any .env or config change
php artisan route:list
php artisan tinker

vendor/bin/phpunit                     # all tests
vendor/bin/phpunit --testsuite=Feature # one suite (Unit | Feature)
vendor/bin/phpunit --filter=testBasicTest tests/Feature/ExampleTest.php   # single test
```

Note on tests: `phpunit.xml` has the sqlite/in-memory `DB_CONNECTION` overrides **commented out**, so tests hit the real MySQL connection from `.env`. Only the stock Laravel example tests exist.

Migrations exist only for Laravel's default tables (users, password_resets, failed_jobs, personal_access_tokens). The four domain tables (`MainConsulta`, `CabExpediente`, `PartesExp`, `DetailsExpediente`) have **no migrations** — they are created directly in the MySQL database. Model `$fillable` lists are the de-facto schema documentation.

## Architecture

### Two-process design

This app never scrapes the judicial system itself. It is the chat-facing half of a pair:

1. A user messages the bot; a `MainConsulta` row records the conversation state with `status = 1, step = 1` and the expediente number in `message`.
2. An external service, **ms-jurisia-judicial**, polls `GET /api/v1/consultas-pendientes` (`ApiController::getPendingConsultas` — rows where `status = 1 AND step = 1 AND message IS NOT NULL`), fetches the real case data, and posts it back to `POST /api/v1/update-consulta`.
3. `ApiController::updateConsulta` writes `CabExpediente` + `PartesExp[]` + `DetailsExpediente[]` inside a `DB::transaction`, stamping every row with the originating `chatId`, then sets `MainConsulta.status` to `2` (found) or `3` (not found).
4. The bot's next turn reads those tables to answer the user.

Because of this handoff, the bot's "search" (`handleStep1_ReceiveExpediente`) is a blocking `sleep(2)` followed by a `CabExpediente` lookup — it is racing the external service, not calling it. Both bot controllers depend on the external service having already populated the tables.

Note the inbound key mapping is not symmetric: `updateConsulta` reads lowercase keys from the external payload (`xformato`, `nunico`, `nincidente`) and writes camelCase columns (`xFormato`, `nUnico`, `nIncidente`). Preserve this when touching that method.

### Conversation state machine

`MainConsulta.step` is the sole conversation state, keyed by `chatId`, and drives an identical 5-step flow in both channels:

| step | stage |
|---|---|
| 0 | greet, ask for expediente number |
| 1 | validate format `/^\d{5}-\d{4}-\d{1}-\d{4}-[A-Z]{2}-[A-Z]{2}-\d{2}$/`, look up `CabExpediente`, list procedural parties |
| 2 | user picks their party (`indTipoParte`) |
| 3 | validate 8-digit DNI against `PartesExp.xDocId` for that party |
| 4 | answer the chosen query type from `DetailsExpediente`, then close |

`MainConsulta.status`: `0` pre-started, `1` awaiting external lookup, `2` found, `3` not found.

`endConversation()` "closes" a conversation by rewriting `chatId` to `'done-'.$chatId.'-done'`, so the next message from that user hits `firstOrCreate` and starts a fresh row. `resetConversation()` instead clears the fields and returns to step 0.

### TelegramController vs WhatsAppController

`app/Http/Controllers/TelegramController.php` and `WhatsAppController.php` are deliberate near-duplicates of the same state machine — **a change to the flow usually has to be made in both**. Differences:

- Telegram (`irazasyed/telegram-bot-sdk`) uses `Telegram::getWebhookUpdate()`, inline `Keyboard` buttons and `callback_query` data for steps 2 and 4; it sends messages out-of-band via the Bot API.
- WhatsApp (`twilio/sdk`) reads Twilio form fields (`From`, `Body`, `NumMedia`, `ButtonPayload`, `ListId`) and sends out-of-band via the Twilio REST API (`Twilio\Rest\Client`), returning an empty `<Response/>` to the webhook. Steps 2 and 4 present `twilio/list-picker` Content Templates when their SIDs are configured, and fall back to the original plain-text listing otherwise.
- Telegram queries scope every lookup by `chatId`; WhatsApp does not, so it can read another chat's rows for the same expediente.

WhatsApp interactive lists: TwiML cannot carry buttons (`<Message>` only supports text and `<Media>`), which is why that channel sends via REST with `contentSid`. **A tapped list-picker item arrives as the item's `id` in `Body`** — not its title, and not in `ButtonPayload` (confirmed against live traffic 2026-08-11; Twilio documents the title-in-`Body` behaviour for quick-reply only, and says nothing about list-picker). Since `id` accepts no variables it must be positional, so `partesDeExpediente()` applies `ORDER BY indTipoParte` and both the send and the resolve go through it — change one without the other and users get mapped to the wrong procedural party. A list-picker's **item count is fixed when the template is created** and only item text accepts variables, so `config/services.php` holds one SID per party count (`services.twilio.content.partes[2..5]`) plus one static SID for the 5-option query menu. Every SID is optional — an unset SID, an unsupported party count, or a Twilio error all degrade to the plain-text listing, so numbered (`1`–`5`) and code (`DDO`) replies must keep working. Setup and template JSON: `public_html/appJurisiaApiBot/CONTENT_TEMPLATES_WHATSAPP.md`.

The Hostinger server runs **PHP 8.2** (confirmed 2026-08-11 from a production stack trace), though `composer.json` still declares `^7.3|^8.0`. Existing code is written to the 7.3 baseline (e.g. `Str::startsWith` rather than `str_starts_with`); match that unless the constraint is bumped.

Production lives at `/home/u921203011/domains/csjan.org/public_html/chatbot/`, which is **not** the path this local tree mirrors — deploys are manual file uploads, so a change touching both a controller and a config file can arrive half-applied. When runtime behaviour contradicts the code you just read, verify the file actually reached the server before debugging the logic.

### Everything else

- `MainConsultaController`, `CabExpedienteController`, `DetailsExpedienteController`, `PartesExpController` are scaffolded resource CRUD controllers that are **not routed and not functional** — they reference Blade views (`main_consultas.index`, …) that do not exist, and `MainConsultaController` is missing its `use App\Models\MainConsulta` import. Treat them as dead scaffolding unless asked to wire them up.
- All four domain models: explicit `$table` (PascalCase, matching MySQL), `$connection = 'mysql'`, `$timestamps = false`. Instead of Eloquent timestamps every table carries a `regDate` / `regDatetime` / `regTimestamp` triple (plus `updDate` / `updDatetime` / `updTimestamp` on `MainConsulta`) that **must be set by hand on every write** — the codebase does this inline with `now()->toDateString()`, `now()`, `now()->timestamp`.
- There are no foreign keys or Eloquent relationships; joins are manual `where('nUnico', …)->where('chatId', …)` lookups.

## Configuration

`.env` (not committed, present on the host) supplies, beyond the Laravel defaults: `TELEGRAM_BOT_TOKEN` (consumed by `config/telegram.php` under the bot key `mybot`), and `TWILIO_SID` / `TWILIO_AUTH_TOKEN` / `TWILIO_WHATSAPP_FROM`.

Webhooks are unauthenticated: `config/cors.php` allows all origins on `api/*`, the `/api/*` routes carry no signature check, and `VerifyCsrfToken::$except` lists `/telegram/webhook` (a leftover — API routes are not in the `web` group, so it has no effect). Any change here should keep the endpoints reachable by Telegram and Twilio.

Language: code comments, log messages and all user-facing bot text are in Spanish. Match that when editing.
