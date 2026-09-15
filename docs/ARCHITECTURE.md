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
| Voice Assistant | Inbound telephony (Twilio → ElevenLabs Native Agent → Laravel webhook tools) | Phase 2 in progress. Native webhook POC **SUCCESS**. Custom LLM remains experimental/fallback. Voice contacts/calls + language memory **Current**. Public show/brand YFS Core tools **Current**. Bitrix **Planned**. Canonical: `docs/VOICE_ARCHITECTURE.md`. |
| Sales Agent | Outbound telephony | Planned. Not specified here. |

Phase 2 selected architecture is ElevenLabs Native Agent plus Laravel webhook tools (POC SUCCESS). Node Custom LLM is experimental/fallback and is not deleted. OpenAI Realtime and Gemini Live are not the Voice path. Instagram messages and voice transcripts stay in separate modules.

## Application modules that exist today

| Area | Role |
| --- | --- |
| Auth / admin shell | custom-admin-kit login, dashboard, users, settings |
| Instagram assistant | OAuth, webhooks, conversations, YFS prompt routing, JFS read-only facts, bot replies |
| Bot management | Structured prompt editor, enable/disable, prompt analysis |
| Voice Assistant bot settings | Call Center → Bot settings. Editable behaviour sections from the client Customer Support policy. Assembled by `VoiceAssistantPromptBuilder` for `POST /api/voice/context` and the ElevenLabs initiation adapter. |
| Voice Assistant calls | Call Center → Voice Assistant. `voice_contacts` / `voice_calls` journal and transcript sheet. |
| AI providers | Stored API keys and role connections (`bot_runtime`, `prompt_analysis`) |
| Telegram bot | Bound channel in Settings. Closed Instagram cases are posted there |
| CRM screens | Customers. Orders and calendar are hidden. |

Laravel Voice Assistant post-call tables (`voice_contacts`, `voice_calls`) and the Call Center call journal **exist**. Follow-ups and YFS/Bitrix matching do not.

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

**Selected Phase 2 architecture (POC SUCCESS):**

```text
ElevenLabs Native Agent
  → authenticated webhook tool
  → YFS AI Laravel
  → structured JSON
  → agent voice response
```

Smoke-test: `POST /api/voice/tools/test-context` (Bearer `ELEVENLABS_TOOL_TOKEN`). Confirmed via ElevenLabs Test Tool and a real voice conversation.

Runtime prompt contract: `POST /api/voice/context` (same Bearer). Diagnostic JSON from Call Center Bot settings.

ElevenLabs Conversation Initiation adapter: `POST /api/voice/elevenlabs/conversation-initiation` (same Bearer). Optional `agent.language` for known contacts. Paste this URL into ElevenLabs. This repo does not change ElevenLabs settings.

Post-call: HMAC `POST /api/voice/elevenlabs/post-call`. Persist transcript / language / summary. Recording URL is not in the transcription webhook.

**Experimental / fallback** (existing Custom LLM production routing, unchanged):

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

**Current:** first real read-only YFS Core Voice tools (`get_public_shows`, `get_show_brands`) wrapping `JfsReadService`. Bitrix not connected.

Speech Engine WebSocket is experimental / legacy, not production routing.

## Frontend

Production assets are built with `npm run build` into `public/build`.  
`public/storage` is a symlink to `storage/app/public`.
