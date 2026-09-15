# External services

Documented as configured today. No live third-party secrets are stored except empty Meta placeholders in `.env`.

## Meta / Instagram

Default OAuth flow: `META_OAUTH_FLOW=instagram_login`.

### Meta Business Login URLs

- OAuth redirect: `https://ai.youngfashionshow.com/instagram/connect/meta/callback`
- Deauthorize callback: `https://ai.youngfashionshow.com/api/meta/instagram/deauthorize`
- Data deletion request: `https://ai.youngfashionshow.com/api/meta/instagram/data-deletion`

### Other Meta URLs

- Instagram webhook: `https://ai.youngfashionshow.com/api/webhooks/meta/instagram`
- Facebook OAuth (hidden leftover): `https://ai.youngfashionshow.com/facebook/connect/meta/callback`
- Facebook webhook (hidden leftover): `https://ai.youngfashionshow.com/api/webhooks/meta/facebook`

### `.env` Meta variables

**Required to connect (from Meta Developer, currently empty):**

- `META_INSTAGRAM_APP_ID` (or `META_APP_ID` as fallback)
- `META_INSTAGRAM_APP_SECRET` (or `META_APP_SECRET` as fallback)
- `META_WEBHOOK_VERIFY_TOKEN` — generate locally, paste the same value into Meta

**Optional UI override:** the same three can be entered on Instagram Settings instead of `.env`. UI wins if both exist.

**Generated / already set by us:**

- `META_OAUTH_REDIRECT_URI` = `${APP_URL}/instagram/connect/meta/callback`
- `META_OAUTH_FLOW=instagram_login`
- `META_OAUTH_AUTHORIZE_URL=https://www.instagram.com/oauth/authorize`
- `META_OAUTH_TOKEN_URL=https://api.instagram.com/oauth/access_token`
- `META_INSTAGRAM_GRAPH_BASE_URL=https://graph.instagram.com`
- `INSTAGRAM_TOKEN_EXCHANGE_URL=https://graph.instagram.com/access_token`
- `INSTAGRAM_TOKEN_EXCHANGE_METHOD=GET`
- `INSTAGRAM_TOKEN_REFRESH_URL=https://graph.instagram.com/refresh_access_token`
- `INSTAGRAM_TOKEN_REFRESH_DAYS_BEFORE_EXPIRY=14`
- `META_GRAPH_API_VERSION=v25.0`
- `META_GRAPH_BASE_URL=https://graph.facebook.com`
- `META_OAUTH_SCOPES=instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments`

**Optional / unused until needed:**

- `META_OAUTH_CONFIG_ID` — only for `facebook_business_login`
- `META_INSTAGRAM_MESSAGING_SEND_PATH` — override IG send path
- `META_PAGE_ACCESS_TOKEN` — manual page token; OAuth Page connect is preferred
- `META_INSTAGRAM_CUSTOMER_PROFILE_PATH` / `META_INSTAGRAM_CUSTOMER_PROFILE_FIELDS`

AI keys are **not** env vars. They are entered in Settings → AI.

## Telegram

Nutgram (`nutgram/nutgram`) is installed. Bot token and channel are entered in Settings → Telegram bot, not in `.env`.

Webhook: `https://ai.youngfashionshow.com/api/telegram/webhook`

The bot answers `/start` after the token is bound.

Closed Instagram cases (form sent, manager request, operator needed, JFS lookup) are posted to the bound channel, group, or forum topic. Product rules: `docs/INSTAGRAM_BOT.md`.

## JFS (read-only)

The main ops app at `/var/www/jfs` is never written from this project.

Laravel connection name: `jfs`. Env keys: `JFS_DB_HOST`, `JFS_DB_PORT`, `JFS_DB_DATABASE`, `JFS_DB_USERNAME`, `JFS_DB_PASSWORD`.

Used for public event fields and client lookup by email only.

## Mail

`MAIL_MAILER=log`. No SMTP. Password reset mail stays on the server.

## Object storage / Redis

AWS keys empty. Redis is not used. Filesystem is local. Cache/session/queue are MySQL.

## OpenAI (existing)

OpenAI is available in Settings → AI (`ai_provider_settings`). Project name: **YoungFashionShow AI**. Do not record the key here.

Voice Custom LLM gateway still supports `bot_runtime = openai`. Production Voice LLM is **Gemini**, not OpenAI.

OpenAI Realtime is **not selected** for Phase 2 voice. Post-call analysis model is **Planned** and is not the realtime path. Canonical: `docs/VOICE_ARCHITECTURE.md`.

## Gemini (Voice LLM — Current)

Production `bot_runtime` for Voice Custom LLM: provider `gemini`, model `gemini-3.8-flash`.

Gemini is the text/reasoning model behind ElevenLabs Custom LLM. Gemini Live API is not used.

## Voice runtime (Phase 2, Node)

YFS Node Voice Runtime: `/var/www/yfs-ai/voice-runtime`.  
Listen: `127.0.0.1:3101`. Isolated from Laravel php-fpm.

**Experimental / fallback** role: OpenAI Chat Completions-compatible **Custom LLM gateway** for the ElevenLabs Voice Agent. Existing production routing is unchanged. LLM routing follows Laravel `bot_runtime` (Gemini). The selected Phase 2 path is Native Agent webhook tools, not this gateway.

| Endpoint | URL |
| --- | --- |
| Local health | `http://127.0.0.1:3101/health` |
| Public health (nginx) | `https://ai.youngfashionshow.com/voice-engine/health` |
| Custom LLM | `https://ai.youngfashionshow.com/voice-engine/v1/chat/completions` |
| Laravel internal config | `GET /api/internal/voice-runtime/config` (Bearer token) |

Incoming ElevenLabs `model`: `yfs-bot-runtime` (label only).

Admin source of truth:

- LLM: Settings → AI (`bot_runtime` role) — production Gemini
- ElevenLabs: Settings → ElevenLabs (API key + connection status)

Node does not store provider keys on disk. It asks Laravel and keeps them in process memory. Custom LLM auth is a shared secret in `voice-runtime/.env`, not a Laravel business setting.

## Voice services

Details: `docs/VOICE_ARCHITECTURE.md`. Do not write account IDs, tokens, or API keys here.

| Service | Role | Status |
| --- | --- | --- |
| Twilio | Phone transport into ElevenLabs. Outbound is Phase 3. | **Current** on the ElevenLabs side. Not a first-party YFS Twilio stack. Do not change from this project. |
| ElevenLabs Voice Agent | Realtime voice: telephony, STT, turn-taking, interruptions, languages/voices, TTS, conversation + audio ownership. Native Agent hosted LLM plus Laravel webhook tools is the selected Phase 2 path. Languages (EN / RU / UK with voice overrides) stay on the ElevenLabs side. | **Current**. Native webhook POC SUCCESS. Custom LLM remains experimental/fallback. |
| Native Agent Laravel webhook tools | Authenticated `POST /api/voice/tools/test-context` smoke-test. Structured JSON back to the agent voice response. | **Current (POC SUCCESS)**. Confirmed via ElevenLabs Test Tool and a real voice conversation. Not YFS Core / Bitrix. |
| Native Agent conversation context | Authenticated `POST /api/voice/context`. Assembled prompt from `voice_assistant_settings`. | **Current (backend contract)**. Not yet wired to ElevenLabs conversation initiation. |
| YFS Custom LLM / Gemini | Experimental/fallback business brain (text) behind Custom LLM. | **Experimental / fallback**. Existing routing unchanged. Not deleted. |
| ElevenLabs Speech Engine | Experimental / legacy low-level realtime path. | Not production routing. Code kept, not activated. |
| Voice Orchestrator / YFS Core / Bitrix tools | Laravel tool layer. | Next: first real read-only YFS Core tool. Bitrix not connected. |
| Post-call audio / transcript / analysis | Persist `voice_*`, archive audio, Telegram. | **Planned**. Not implemented. |
| OpenAI Realtime / Gemini Live | Full realtime voice APIs. | **Rejected for Phase 2**. |

## Explicitly not used from Mousse Bakery

No Mousse Meta app, page tokens, Instagram accounts, or AI keys were copied.
