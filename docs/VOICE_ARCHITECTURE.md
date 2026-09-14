# Voice Architecture

Canonical source for YFS Voice AI Consultant architecture.

Product goals, roadmap, planned schema, and admin intent: `docs/VOICE_ASSISTANT.md`.  
Vendor URLs and credentials locations: `docs/EXTERNAL_SERVICES.md`.  
Subsystem map: `docs/ARCHITECTURE.md`.

Do not record API keys, tokens, passwords, or other secrets in this file.

Status labels used below:

- **Current** — implemented and confirmed on production
- **Planned** — next Voice Consultant work; not implemented
- **Experimental / legacy** — code may exist; not production routing
- **Rejected for Phase 2** — not the selected architecture

---

## 1. Current production realtime architecture

**Current.** Confirmed by a successful inbound telephone call through the production gateway.

Runtime evidence from `yfs-voice-runtime` logs (no secrets):

- `provider = gemini`
- `model = gemini-3.8-flash`
- `incomingModel = yfs-bot-runtime`
- Custom LLM streaming works
- cancellation / disconnect abort works
- the live phone conversation went through this gateway

```text
Caller
  ↓
Twilio                          phone transport
  ↓
ElevenLabs Voice Agent          realtime voice layer
  ├─ Twilio telephony integration
  ├─ STT
  ├─ turn detection
  ├─ interruptions
  ├─ conversational timing
  ├─ language / voice
  ├─ TTS
  ├─ conversation ownership
  └─ audio recording
        ↓
YFS Custom LLM gateway          OpenAI-compatible HTTP/SSE
POST /voice-engine/v1/chat/completions
        ↓
Provider routing (Laravel bot_runtime)
        ↓
Gemini                          reasoning / text generation
        ↓
SSE chunks back to ElevenLabs
        ↓
ElevenLabs TTS
        ↓
Twilio → telephone
```

Gemini Live API is **not** used.  
OpenAI Realtime is **not** used.  
YFS does not own Twilio webhooks or Media Streams. Telephony stays on the ElevenLabs native phone integration.

---

## 2. Responsibility boundaries

### Twilio — **Current**

- Phone transport only
- Inbound number / call legs as configured on the ElevenLabs side
- Not wired as a first-party YFS telephony stack

### ElevenLabs — **Current**

Realtime audio and conversation ownership:

- Twilio telephony integration
- STT
- turn-taking / turn detection
- interruptions
- conversational timing
- language and voices
- TTS
- conversation ownership
- audio recording (ElevenLabs is the source of telephone conversation audio)

ElevenLabs hosted/native LLM is **not** the business brain. The agent LLM is the YFS Custom LLM gateway.

### voice-runtime (`/var/www/yfs-ai/voice-runtime`) — **Current**

- ElevenLabs-compatible Custom LLM endpoint
- provider routing from Laravel config
- streaming SSE
- Gemini protocol conversion (OpenAI Chat Completions ↔ Gemini)
- cancellation / abort on client disconnect
- OpenAI LLM provider remains implemented for a future `bot_runtime` switch; it is not the current production LLM

Listen: `127.0.0.1:3101`  
systemd: `yfs-voice-runtime`  
nginx: `/voice-engine/` → that process (Laravel `location /` unchanged)

### Laravel — **Current** for config; **Planned** for Voice Orchestrator

**Current:**

- AI role connections (`bot_runtime`, `prompt_analysis`)
- encrypted provider credentials in `ai_provider_settings`
- ElevenLabs API key in Settings → ElevenLabs
- internal config: `GET /api/internal/voice-runtime/config`

**Planned:**

- Voice Orchestrator
- external data / tool layer
- persistence and post-call workflows
- Telegram notifications for voice calls

### Gemini — **Current** for text; **Planned** for tool decisions

**Current:** call-time reasoning and text generation behind Custom LLM.

**Planned:** function / tool decisions. Tool calling is not production-proven on a live call.

---

## 3. Current endpoint

Public Custom LLM URL:

`https://ai.youngfashionshow.com/voice-engine/v1/chat/completions`

Contract: OpenAI Chat Completions-compatible HTTP + SSE (`text/event-stream`, `data: {chunk}`, `data: [DONE]`).

Auth: `Authorization: Bearer` (Custom LLM shared secret in `voice-runtime/.env`). Alternative: `X-YFS-Voice-Token`. This secret is not a provider API key.

Incoming `model` from ElevenLabs:

`yfs-bot-runtime`

That value is a label only. The real provider and model are resolved from Laravel `bot_runtime` via `GET /api/internal/voice-runtime/config`.

Health (non-secret):

- local: `http://127.0.0.1:3101/health`
- public: `https://ai.youngfashionshow.com/voice-engine/health`

Health reports process status, latest Laravel config fetch, current provider/model names, and whether keys are configured — never key values.

---

## 4. Current active runtime

Laravel `bot_runtime` on production:

| Field | Value |
| --- | --- |
| provider | `gemini` |
| model | `gemini-3.8-flash` |

The Custom LLM gateway is provider-agnostic (`openai` and `gemini`). Switching `bot_runtime` in Laravel Settings does not require rewriting the public ElevenLabs contract.

Instagram/Facebook `prompt_analysis` is a separate role. It is not the voice LLM.

---

## 5. Next architectural part — Voice Orchestrator

**Planned. Not implemented.**

```text
ElevenLabs
  ↓
Custom LLM gateway          (Current)
  ↓
Voice Orchestrator          (Planned, Laravel)
  ↓
Gemini                      (Current as LLM; Planned for tool use)
  ↓
Laravel tools / services    (Planned)
```

Future tools/services (all **Planned**, not live on a production call):

- YFS Core customer lookup
- YFS participation / history
- past shows
- future shows
- show / event details
- Bitrix24 contact / company
- Bitrix deals
- stages
- CRM notes / history / communications
- other relevant CRM context

Rules for that layer:

- `voice-runtime` and the LLM never receive direct DB credentials or Bitrix credentials
- access goes through Laravel services / tools
- YFS Core and Bitrix24 are **read-only** for Voice Consultant until write actions are approved separately
- YFS Core / Bitrix Voice integration does **not** exist yet
- do not treat Instagram JFS read-only lookup as the Voice tool layer

---

## 6. Post-call pipeline

**Planned. Not implemented.**

ElevenLabs remains the owner/source of telephone conversation audio. YFS does not currently persist call audio, transcripts, or summaries.

Planned post-call path:

1. post-call webhook and/or audio retrieval from ElevenLabs
2. persist a Voice bounded context (not Instagram inbox tables)
3. archive audio through YFS AI
4. transcript + structured analysis
5. operator follow-up / Telegram notification

Planned `voice_calls` (and related) facts — conceptual, no migrations:

- caller
- identified customer
- timestamps / duration / status
- language
- transcript
- audio reference / storage
- summary
- structured result
- intent / topic
- unresolved questions
- follow-up
- callback / escalation
- Telegram notification

Do **not** store voice calls in `conversations` / `conversation_messages`. Those tables are Instagram/Facebook only.

Schema sketch: `docs/DATABASE.md` and `docs/VOICE_ASSISTANT.md` § Planned data.

---

## 7. Audio

**Current:** ElevenLabs owns and records the telephone conversation audio.

**Planned:**

- post-call audio retrieval / webhook
- save / archive through YFS AI
- transcript + structured analysis attached to `voice_calls`

YFS is not the live audio mixer and does not sit on the media path.

---

## 8. Prompt architecture

**Planned. The Voice prompt itself is not specified in this document.**

Intended structure when implemented:

- global rules
- topic-specific sections
- scenario rules
- escalation rules
- source precedence
- uncertainty handling

A short Custom LLM guardrail already prepends to gateway messages. That is not the Voice Consultant prompt architecture.

Instagram bot prompts must not be reused as the Voice prompt.

---

## 9. Source precedence

**Planned concept.** Not implemented; no live tool results yet.

When tools exist:

| Source | Role |
| --- | --- |
| YFS Core | Product / show / customer participation / history |
| Bitrix24 | Current / latest CRM context |

If sources conflict, the agent must not invent the truth. It follows explicit resolution / escalation rules (those rules are also **Planned** and are not written here).

---

## 10. Experimental / legacy — Speech Engine

Speech Engine WebSocket attach remains in `/var/www/yfs-ai/voice-runtime` (`src/speech/engine.ts` and related session code).

It is **experimental / legacy infrastructure**, not production routing. It is skipped unless a Speech Engine ID is set. Do not treat it as the Voice Consultant path. Do not delete it in documentation-only work; do not activate it for Phase 2.

---

## 11. Rejected for Phase 2

| Path | Decision |
| --- | --- |
| Gemini Live API | Not selected. Gemini is a text LLM behind Custom LLM. |
| Direct Twilio → YFS raw audio runtime | Not selected. Twilio is phone transport into ElevenLabs. |
| Speech Engine as production routing | Not selected. Experimental / legacy only. |
| ElevenLabs hosted/native LLM as business brain | Not selected. |
| OpenAI Realtime as the voice layer | Not selected for Phase 2. |
| Stacked speech: ElevenLabs STT/TTS + OpenAI Realtime speech | Not selected. |

**Production choice for Phase 2 realtime:**

ElevenLabs voice layer + YFS Custom LLM + Gemini.

---

## 12. What this architecture does not claim

The following are **not** current:

- Laravel Voice Orchestrator
- YFS Core or Bitrix24 Voice tools
- post-call persistence / analysis / Telegram for calls
- production-proven tool calling on a live call
- Voice admin (Calls / Follow-ups) beyond a Call center placeholder
- `voice_*` database tables
