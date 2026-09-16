# Latest Work Report

## Task

Audit whether YFS AI can programmatically accept incoming Instagram audio/video calls and hand them to the existing Voice Assistant. Research only. No implementation.

## Status

Done. Audit-only. Production code, Meta App, webhook subscriptions, Instagram configuration, ElevenLabs, Twilio, routes, migrations, and credentials were **not** changed.

## Commit hash

See the follow-up report commit on `main` for the implementation SHA (recorded immediately after this file is first committed).

## Verdict

| Question | Answer |
| --- | --- |
| Can Instagram Business/Professional **in-app Call** be received programmatically? | **NO** |
| Available to current YFS Meta setup? | **NO** |
| Can ElevenLabs Native Agent take Meta WebRTC directly? | **REQUIRES BRIDGE** (Agents WebSocket PCM or SIP). Not a direct SDP attach. |
| Must Twilio be involved? | **NOT POSSIBLE** for Instagram → PSTN → Twilio (Meta policy). Twilio stays the telephone path only. |
| Best architecture if Meta later ships Instagram Calling | Meta signalling + WebRTC → our media bridge → **same** ElevenLabs Native Agent WebSocket → **same** Laravel tools / prompt / YFS Core / Bitrix. Not a second bot. |

Full write-up: `docs/Voice/INSTAGRAM_CALLING_AUDIT.md`.

## What was checked

- YFS-AI Instagram/Meta code and docs (`docs/INSTAGRAM_ASSISTANT.md`, `docs/INSTAGRAM_BOT.md`, webhook service, OAuth scopes, subscribe fields)
- Live **non-secret** config flags (2026-09-16)
- Official Meta docs: Instagram Platform webhooks, Instagram Messaging webhooks, Messenger Calling, WhatsApp Cloud API Calling, Developer Policies §5.7
- Official ElevenLabs Agents WebSocket, SIP trunking, WebRTC/LiveKit client transport
- Official OpenAI Realtime WebRTC/SIP (comparison only)

## Current YFS Meta capabilities (no secrets)

| Key / surface | configured |
| --- | --- |
| `META_INSTAGRAM_APP_ID` / secret (or UI override) | true |
| `META_WEBHOOK_VERIFY_TOKEN` | true |
| `META_GRAPH_API_VERSION` | `v25.0` |
| `META_OAUTH_FLOW` | `instagram_login` |
| Scopes | `instagram_business_basic`, `instagram_business_manage_messages`, `instagram_business_manage_comments` |
| Primary Instagram account | connected; token present; `instagram_user_id` present |
| Stored webhook fields | `messages`, `messaging_postbacks`, `messaging_seen` |
| Facebook Page account | `not_configured` |
| Calling / WebRTC / SIP in repo | none |

Inbound Instagram path remains Direct messaging only (`object=instagram` → queued message job). Non-message webhook keys are ignored.

## Official docs (checked 2026-09-16)

- https://developers.facebook.com/docs/instagram-platform/webhooks/ — no `calls` field
- https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling — Messenger app → Page, WebRTC, country-limited
- https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling/consumer2biz/accept-c2b-call — `platform` Messenger only
- https://developers.facebook.com/docs/whatsapp/cloud-api/calling/ — WhatsApp only
- https://developers.facebook.com/devpolicy/ — PSTN forbidden on any calling leg
- https://elevenlabs.io/docs/eleven-agents/libraries/web-sockets — custom PCM transport
- https://developers.openai.com/api/docs/guides/realtime-webrtc — OpenAI WebRTC is a separate session, not Meta ICE

## Eligibility gaps

- No public **Instagram Calling API**
- YFS is on Instagram Login messaging, not Page `pages_messaging` calling
- Page not connected; Messenger Calling country list / `messenger_api_calling` not checked (would not answer Instagram Call anyway)
- A new Meta App would not unlock Instagram Call; the API is missing

## Manual dashboard checks (do not subscribe anything)

1. App Dashboard → Webhooks → Instagram object: confirm no `calls`
2. If a Page is ever connected: Calling country eligibility + `messenger_api_calling`
3. Ask Meta whether Instagram Calling exists as a private/beta program (not in public docs)

## Production confirmation

No routes, controllers, services, migrations, npm/composer packages, Meta subscriptions, Meta permissions, ElevenLabs config, Twilio config, WebRTC servers, or `.env` values were added or changed for this task.
