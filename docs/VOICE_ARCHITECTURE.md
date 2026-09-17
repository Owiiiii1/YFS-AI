# Voice Architecture

Canonical source for YFS Voice AI Consultant architecture.

Product goals, roadmap, planned schema, and admin intent: `docs/VOICE_ASSISTANT.md`.  
Client Customer Support policy (verbatim source of truth for Voice Assistant behaviour settings): `docs/Voice/CLIENT_CUSTOMER_SUPPORT_POLICY_UA.md`.  
Vendor URLs and credentials locations: `docs/EXTERNAL_SERVICES.md`.  
Subsystem map: `docs/ARCHITECTURE.md`.

Do not record API keys, tokens, passwords, or other secrets in this file.

Status labels used below:

- **Current** — implemented and confirmed on production
- **Planned** — next Voice Consultant work; not implemented
- **Experimental / legacy** — code may exist; not production routing
- **Rejected for Phase 2** — not the selected architecture

---

## 1. Existing Custom LLM production routing (experimental / fallback)

**Experimental / fallback.** Existing production Custom LLM routing is unchanged. A successful inbound telephone call through this gateway was confirmed earlier. Native ElevenLabs Agent + Laravel webhook tools is the **selected Phase 2 architecture** (POC SUCCESS, § 13). The Node Custom LLM runtime is not deleted.

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

**Selected Phase 2 architecture (POC SUCCESS):** native ElevenLabs Agent LLM plus Laravel webhook tools. See § 13.

**Experimental / fallback:** the YFS Custom LLM Node runtime. Existing Custom LLM production routing is unchanged and must not be removed.

### voice-runtime (`/var/www/yfs-ai/voice-runtime`) — **Current**

- ElevenLabs-compatible Custom LLM endpoint
- provider routing from Laravel config
- streaming SSE
- Gemini protocol conversion (OpenAI Chat Completions ↔ Gemini)
- Voice Session Context + Prompt Orchestrator injection per turn
- FAST PATH (no YFS tool) and TOOL PATH (server-side Laravel tools)
- filler / buffer-word SSE before a YFS tool follow-up (`"... "`)
- YFS server-side tool execution loop (Laravel Voice Orchestrator; test tool only)
- cancellation / abort on client disconnect
- OpenAI LLM provider remains implemented for a future `bot_runtime` switch; it is not the current production LLM

Listen: `127.0.0.1:3101`  
systemd: `yfs-voice-runtime`  
nginx: `/voice-engine/` → that process (Laravel `location /` unchanged)

### Laravel — **Current** for config, test-tool execution, session context, and prompt assembly

**Current:**

- AI role connections (`bot_runtime`, `prompt_analysis`)
- encrypted provider credentials in `ai_provider_settings`
- ElevenLabs API key in Settings → ElevenLabs
- internal config: `GET /api/internal/voice-runtime/config`
- Voice Orchestrator test-tool execution: `POST /api/internal/voice/tools/execute`
- test tool `get_current_yfs_test_context` (explicitly test-only data)
- Voice Session Context (synthetic preload): assembled per turn
- Voice Prompt Orchestrator (GLOBAL / SESSION / TOPIC)
- filler phrase registry
- internal turn: `POST /api/internal/voice/session/turn`
- **Selected Phase 2 path (POC SUCCESS, smoke-test):** `POST /api/voice/tools/test-context` — dedicated Bearer `ELEVENLABS_TOOL_TOKEN`, independent of voice-runtime. Synthetic test JSON only. Not YFS Core / Bitrix.
- Voice Assistant bot settings (Call Center → Bot settings): admin-editable sections in `voice_assistant_settings`, filled from `docs/Voice/CLIENT_CUSTOMER_SUPPORT_POLICY_UA.md`.
- Native Agent runtime prompt contract: `POST /api/voice/context` — diagnostic JSON from `VoiceAssistantPromptBuilder`. Same Bearer `ELEVENLABS_TOOL_TOKEN`.
- Native Agent Conversation Initiation adapter: `POST /api/voice/elevenlabs/conversation-initiation` — ElevenLabs `conversation_initiation_client_data` with system prompt override. Same Bearer. Known callers may also receive `agent.language` from `voice_contacts.preferred_language` (en/ru/uk). Not auto-registered in the ElevenLabs UI.
- Voice contacts and completed calls: `voice_contacts` / `voice_calls`. Post-call webhook: HMAC `POST /api/voice/elevenlabs/post-call`. Call Center → Voice Assistant lists calls.

**Planned:**

- operator pastes the initiation webhook URL into ElevenLabs (this step does not change ElevenLabs via API)
- operator registers the post-call webhook in ElevenLabs (one manual HMAC secret step; this repo does not change the agent via API)
- YFS Core / Bitrix customer matching on `voice_contacts`
- YFS Core / Bitrix24 Voice tools and real connectors
- production business tools
- audio download / archive, AI post-call analysis, Telegram notifications for voice calls

### Gemini — **Current** for text and live tool calling

**Current:** call-time reasoning and text generation behind Custom LLM.

**Current:** YFS function calls execute in Laravel Voice Orchestrator, not in ElevenLabs. Live inbound tool calling has been confirmed.

**Planned:** YFS Core / Bitrix production tools.

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

## 5. Voice Orchestrator

**Current** for test-tool execution. YFS Core / Bitrix / production business tools remain **Planned**. Live tool calling on a telephone call is **Current**.

```text
ElevenLabs
  ↓
Custom LLM gateway
  ↓
Laravel Voice Session Context + Prompt Orchestrator
  ↓
FAST PATH: Gemini → SSE text
TOOL PATH: Gemini functionCall → filler SSE → Laravel tool → Gemini → SSE text
```

FAST PATH: if SESSION already has the fact (synthetic YFS Test Event), the test tool is not offered to Gemini. One Gemini round.

TOOL PATH: if the fact is missing (latest YFS test status), Gemini may call `get_current_yfs_test_context`. Before the second Gemini round, voice-runtime emits an ElevenLabs **buffer-word** chunk ending in `"... "` so TTS can start speaking, then continues the same SSE stream. Official Custom LLM contract: [buffer words](https://elevenlabs.io/docs/eleven-agents/customization/llm/custom-llm). Do not send `finish_reason` or `[DONE]` between filler and final text.

Temporary forced “always call the test tool for the YFS test event” guardrail was removed. Preloaded SESSION context answers that question.

Current tools:

| Tool | Status | Notes |
| --- | --- | --- |
| `get_current_yfs_test_context` | **Current**. Live call confirmed for tool execution. | Read-only synthetic test payload. Metadata: lookup / short / filler_enabled / read_only / source=yfs_ai_test. Native: `POST /api/voice/tools/test-context`. |
| `get_public_shows` | **Current**. Laravel Native Agent webhook. | Read-only `JfsReadService::publicEvents()`. `POST /api/voice/tools/public-shows`. |
| `get_show_brands` | **Current**. Laravel Native Agent webhook. | Read-only `JfsReadService::publicBrandLineups()`. `POST /api/voice/tools/show-brands`. Public lineup only. |
| `resolve_customer_identity` | **Current**. Laravel Native Agent webhook. | Read-only `CustomerIdentityResolver::resolveBySpokenHints()`. `POST /api/voice/tools/resolve-customer-identity`. Binds UNIQUE identity to the current `VoiceContact` when a trusted ElevenLabs session id is present. |
| `get_customer_context` | **Current**. Laravel Native Agent webhook. | Read-only YFS Core children + participations for the **already bound** unique identity. `POST /api/voice/tools/customer-context`. LLM identifiers are ignored. |

Future tools/services (still **Planned**, not live on a production call):
- Bitrix24 contact / company
- Bitrix deals
- stages
- CRM notes / history / communications
- other relevant CRM context

Rules for that layer:

- `voice-runtime` and the LLM never receive direct DB credentials or Bitrix credentials
- access goes through Laravel services / tools
- YFS Core and Bitrix24 are **read-only** for Voice Consultant until write actions are approved separately
- public show/brand Voice tools reuse Instagram's `JfsReadService`; they do not copy Instagram prompt injection
- Bitrix Voice integration does **not** exist yet

---

## 6. Post-call pipeline

**Current** for transcript persistence, language memory, and the Call Center journal. Audio archive, AI analysis, Telegram, follow-ups, and YFS/Bitrix matching remain **Planned**.

ElevenLabs remains the owner/source of telephone conversation audio. The `post_call_transcription` webhook does not include a recording URL in the documented payload, so `voice_calls.recording_url` stays nullable. A separate `post_call_audio` event carries base64 audio; YFS acknowledges it and does not persist `full_audio`.

Current post-call path:

1. ElevenLabs `post_call_transcription` → HMAC `POST /api/voice/elevenlabs/post-call`
2. idempotent persist into `voice_contacts` / `voice_calls`
3. `VoiceConversationLanguageResolver` determines the sustained conversation language from transcript tags, later substantial turns, then script analysis. `metadata.main_language` is only a fallback when the transcript is inconclusive.
4. Call Center → Voice Assistant shows the call journal

Used ElevenLabs fields (when present): `type`, `event_timestamp`, `data.conversation_id`, `data.status`, `data.transcript[]` (`role`, `message`, `time_in_call_secs`; language/voice wrappers stripped), `data.metadata.start_time_unix_secs`, `data.metadata.call_duration_secs`, `data.metadata.termination_reason`, `data.metadata.main_language` (language fallback only), `data.metadata.phone_call` (`type`, `direction`, `external_number`, `agent_number`, `call_sid`), `data.analysis.transcript_summary`, `data.analysis.call_successful`, `data.agent_id`, `data.has_audio`. Optional `recording_url` / `audio_url` is stored only if it is an `http(s)` URL.

Do **not** store voice calls in `conversations` / `conversation_messages`. Those tables are Instagram/Facebook only.

Schema: `docs/DATABASE.md`.

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

**Planned concept.** Test-tool results exist; YFS Core / Bitrix results do not.

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
| OpenAI Realtime as the voice layer | Not selected for Phase 2. |
| Stacked speech: ElevenLabs STT/TTS + OpenAI Realtime speech | Not selected. |

**Selected Phase 2 realtime (POC SUCCESS, § 13):**

Twilio → ElevenLabs Native Agent (hosted LLM) → Laravel webhook tools.

**Experimental / fallback (existing production routing, unchanged):**

ElevenLabs voice layer + YFS Custom LLM + Gemini.

The previous “native LLM is not the business brain” decision is superseded. Native ElevenLabs Agent + Laravel webhook tools is the chosen Phase 2 architecture. Node Custom LLM remains experimental/fallback and is not deleted.

---

## 12. What this architecture does not claim

The following are **not** current:

- YFS Core participant / package / payment Voice tools
- Bitrix24 Voice tools / connectors (not connected yet)
- production business tools beyond public shows, public brand lineups, spoken identity, and the Native Agent smoke-test webhook
- full production cutover of every inbound number onto Native Agent (existing Custom LLM routing is unchanged fallback)
- post-call AI analysis / Telegram / audio archive for calls
- Voice admin Follow-ups
- Bitrix24 customer matching on the initiation webhook
- ElevenLabs UI webhook URLs (backend adapters exist; this repo does not change the agent settings)
- other planned `voice_*` tables (`voice_followups`, `voice_agent_settings`). `voice_assistant_settings`, `voice_contacts`, and `voice_calls` exist.

---

## 13. Selected Phase 2 architecture — ElevenLabs Native Agent webhook tools

**POC SUCCESS.** Confirmed via ElevenLabs Test Tool and a real voice conversation.

Native ElevenLabs Agent + Laravel webhook tools is the **chosen main architecture for Phase 2**.

```text
ElevenLabs Native Agent
  → authenticated webhook tool
  → YFS AI Laravel
  → structured JSON
  → agent voice response
```

Expanded call path:

```text
Twilio
  ↓
ElevenLabs Native Agent
  ├─ STT / turn-taking / TTS (ElevenLabs)
  └─ native / hosted ElevenLabs LLM
        ↓
authenticated webhook tool
POST /api/voice/tools/test-context
        ↓
YFS AI Laravel
        ↓
structured JSON
        ↓
agent voice response
```

Smoke-test endpoint (kept for integration checks):

`https://ai.youngfashionshow.com/api/voice/tools/test-context`

Auth: `Authorization: Bearer` using `ELEVENLABS_TOOL_TOKEN` only. Do not record token values in this file.

Do **not** use `VOICE_RUNTIME_INTERNAL_TOKEN`, `VOICE_LLM_SHARED_SECRET`, Gemini, OpenAI, or ElevenLabs API keys on this endpoint.

This endpoint:

- does not go through Node `voice-runtime`
- does not call `POST /api/internal/voice/tools/execute`
- does not connect to YFS Core or Bitrix
- accepts no business parameters
- returns synthetic JSON: `event_name`, `status`, `message`, `source=yfs_ai_test`

Missing or invalid Bearer → `401` JSON `{"message":"Unauthorized"}`. Empty configured token fails closed (401).

Production read-only YFS Core tools (same auth, not the POC payload):

- `POST /api/voice/tools/public-shows` — `get_public_shows`
- `POST /api/voice/tools/show-brands` — `get_show_brands`
- `POST /api/voice/tools/resolve-customer-identity` — `resolve_customer_identity`
- `POST /api/voice/tools/customer-context` — `get_customer_context`

They wrap `JfsReadService` (shows/brands/identity/customer-context reads) and return structured JSON without secrets. ElevenLabs UI fields: `docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`, identity contract: `docs/Voice/CUSTOMER_IDENTITY.md`, customer context: `docs/Voice/CUSTOMER_CONTEXT.md`, filler: `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.

## 14. Native Agent conversation context contract

**Current (diagnostic / runtime JSON).** Unchanged. Not the ElevenLabs webhook body.

```text
Admin
  ↓
voice_assistant_settings
  ↓
VoiceAssistantPromptBuilder
  ↓
authenticated POST /api/voice/context
```

URL: `https://ai.youngfashionshow.com/api/voice/context`

Auth: `Authorization: Bearer` using `ELEVENLABS_TOOL_TOKEN` only. Do not record token values.

JSON:

```json
{
  "prompt": "...assembled prompt...",
  "version": "v5-<sha256>",
  "generated_at": "<ISO-8601 UTC>"
}
```

The assembled prompt starts with immutable runtime decision rules: A KNOWN POLICY FACT, B MISSING DYNAMIC FACT, C HUMAN REQUIRED, D LIVE SHOW TOOLS (`get_public_shows` / `get_show_brands`), E CALLER IDENTITY (`resolve_customer_identity` when a personal fact is needed and the caller is not uniquely identified). Policy section bodies are not rewritten. `version` includes wrapper version `v5`. Unique YFS phone matches add a runtime CALLER CONTEXT block (not part of the version hash).

**Current (backend adapter).** This repo does **not** change ElevenLabs agent settings. The operator pastes the URL and header into ElevenLabs.

Official contract (ElevenLabs Personalization / Twilio personalization docs): the webhook **POST**s caller metadata and must return `conversation_initiation_client_data`. `type` is included as in the current ElevenLabs examples. System prompt override is always sent. `agent.language` is added only when a supported en/ru/uk value is known (stored Voice preference first; unique JFS `app_users.language` only if Voice has none). Custom `dynamic_variables` are omitted until the agent declares them. Unique JFS phone matches preload compact identity; Bitrix is **not** connected. Details: `docs/Voice/CUSTOMER_IDENTITY.md`.

```text
Admin
  ↓
voice_assistant_settings
  ↓
VoiceAssistantPromptBuilder
  ↓
caller_id → PhoneNumberNormalizer → voice_contacts
  ↓
authenticated POST /api/voice/elevenlabs/conversation-initiation
  ↓
ElevenLabs conversation_initiation_client_data
  ↓
Native Agent system prompt override (+ language when known)
```

Production URL to paste into ElevenLabs:

`https://ai.youngfashionshow.com/api/voice/elevenlabs/conversation-initiation`

HTTP method: `POST`

Header to create in ElevenLabs (secret value stays in ElevenLabs secrets / `ELEVENLABS_TOOL_TOKEN`; do not put the value in git or docs):

`Authorization: Bearer <token>`

Example response shape (prompt body omitted):

```json
{
  "type": "conversation_initiation_client_data",
  "conversation_config_override": {
    "agent": {
      "prompt": {
        "prompt": "<assembled Voice Assistant prompt>"
      }
    }
  }
}
```

When a known contact has preferred_language en/ru/uk, `conversation_config_override.agent.language` is also returned. Unknown numbers omit `language` so ElevenLabs keeps its default detection.

Language override requires the ElevenLabs agent Security setting that allows conversation initiation overrides for language (previously enabled on this agent). First-message override is not sent.

System Prompt for a new inbound call comes from:

YFS Admin → `voice_assistant_settings` → VoiceAssistantPromptBuilder → initiation webhook → ElevenLabs.

Post-call HMAC endpoint (separate secret from `ELEVENLABS_TOOL_TOKEN`):

`https://ai.youngfashionshow.com/api/voice/elevenlabs/post-call`

Event: `post_call_transcription`. Header: `ElevenLabs-Signature`. Secret value stays in ElevenLabs / `ELEVENLABS_POST_CALL_WEBHOOK_SECRET`; do not put the value in git or docs.

Node Custom LLM (`POST /voice-engine/v1/chat/completions`) remains **experimental/fallback** and is not deleted. Existing Custom LLM production routing is unchanged.

`POST /api/voice/tools/test-context` remains the smoke-test tool.

YFS Core / Bitrix are not connected yet.
