# Architecture

## Stack

- Laravel 13.19
- PHP 8.3
- Inertia + React 18
- Vite 8
- Tailwind 4
- MySQL 8
- `owlsolutions/custom-admin-kit` v0.4.0

Sessions, cache, and queues use the database driver.

## Runtime layout

```text
nginx (ai.youngfashionshow.com)
  → php8.3-fpm
    → /var/www/yfs-ai/public

cron (user deploy)
  → php artisan schedule:run   every minute
  → php artisan queue:work     flock-locked worker
```

Supervisor is **not** used for this project. The existing `jfs-photo-worker` supervisor program was left unchanged.

## Subsystems

YFS AI is one Laravel backend with separate product subsystems. Voice and Sales must not be folded into the Instagram bot.

```text
YFS AI Laravel backend
├── Instagram / Facebook Assistant   IMPLEMENTED / CONNECTED
├── Voice Assistant                  PHASE 2 / IN PROGRESS
└── Sales Agent                      PHASE 3 / PLANNED
```

| Subsystem | Transport | Status |
| --- | --- | --- |
| Instagram / Facebook Assistant | Meta messaging | Implemented / connected. Do not change this product to add voice. |
| Voice Assistant | Inbound telephony (Twilio → ElevenLabs voice layer → Custom LLM → Gemini) | Phase 2 in progress. Realtime path **Current** (live inbound call confirmed). Orchestrator / tools / post-call **Planned**. Canonical: `docs/VOICE_ARCHITECTURE.md`. |
| Sales Agent | Outbound telephony | Planned. Not specified here. |

Phase 2 production choice is ElevenLabs for realtime voice and Gemini for the business LLM behind YFS Custom LLM. OpenAI Realtime and Gemini Live are not the Voice path. Instagram messages and voice transcripts stay in separate modules.

## Application modules that exist today

| Area | Role |
| --- | --- |
| Auth / admin shell | custom-admin-kit login, dashboard, users, settings |
| Instagram assistant | OAuth, webhooks, conversations, YFS prompt routing, JFS read-only facts, bot replies |
| Bot management | Structured prompt editor, enable/disable, prompt analysis |
| AI providers | Stored API keys and role connections (`bot_runtime`, `prompt_analysis`) |
| Telegram bot | Bound channel in Settings. Closed Instagram cases are posted there |
| CRM screens | Customers. Orders and calendar are hidden. |

Laravel Voice Assistant tables and admin routes do **not** exist yet.

A separate Node process lives at `/var/www/yfs-ai/voice-runtime` and listens on `127.0.0.1:3101`. It is the Custom LLM gateway for the ElevenLabs Voice Agent. It is not part of php-fpm or the Laravel cron/queue workers.

LLM and ElevenLabs provider keys are stored in Laravel Settings (`ai_provider_settings`, encrypted). Node fetches them through `GET /api/internal/voice-runtime/config`. Settings → AI holds `bot_runtime` (production: Gemini). Settings → ElevenLabs holds the ElevenLabs API key. Speech Engine code is experimental / legacy and is not production routing.

## Request flow (Instagram)

1. Meta sends GET/POST to `/api/webhooks/meta/instagram` or `/api/webhooks/meta/facebook`.
2. Laravel verifies the webhook token (when configured) and queues/processes the event.
3. Incoming messages create or update `conversations` and `conversation_messages`.
4. If the conversation bot is enabled and an AI provider is connected, the bot may generate a reply. Extra prompt topics load only when needed. Event/client facts come from a read-only JFS connection.
5. A live-operator request (or a yes after the bot offered an operator) sets `pending_human`, disables the conversation bot, and notifies the Telegram channel.
6. Human/manual mode can disable the bot per conversation; a scheduler can re-enable it after inactivity.

This flow is present in code and is the live messaging product. Voice calls will not enter this path.

## Voice Assistant

Canonical architecture: `docs/VOICE_ARCHITECTURE.md`. Product/roadmap: `docs/VOICE_ASSISTANT.md`.

**Current** (confirmed by a real inbound phone call):

```text
Twilio
  ↓
ElevenLabs Voice Agent     STT / turn-taking / interruptions / TTS / audio
  ↓
Custom LLM                 POST /voice-engine/v1/chat/completions
  ↓
Gemini                     bot_runtime gemini-3.8-flash
  ↓
SSE → ElevenLabs TTS → phone
```

Public namespace: `/voice-engine/` → `127.0.0.1:3101`.  
Incoming model label: `yfs-bot-runtime`. Actual provider/model come from Laravel `bot_runtime`.  
Laravel `location /` is unchanged. Twilio routing is not owned by YFS.

**Planned:** Voice Orchestrator, YFS Core / Bitrix tools, post-call `voice_*` persistence. Not implemented.

Speech Engine WebSocket is experimental / legacy, not production routing.

## Frontend

Production assets are built with `npm run build` into `public/build`.  
`public/storage` is a symlink to `storage/app/public`.
