# Voice Assistant

Status: **PHASE 2 — IN PROGRESS**

Canonical Voice architecture: **`docs/VOICE_ARCHITECTURE.md`**.  
This file is the product spec: goals, roadmap, planned data, conversation/handoff/admin intent.

Realtime production path is confirmed by a successful inbound telephone call.

**Selected Phase 2 architecture (POC SUCCESS):** ElevenLabs Native Agent → authenticated Laravel webhook tools → structured JSON → agent voice response.

**Experimental / fallback:** ElevenLabs voice layer + YFS Custom LLM gateway + Gemini. Existing Custom LLM routing is unchanged and is not deleted.

Laravel Voice Orchestrator exists for a **test tool**, plus Voice Session Context and Prompt Orchestrator (synthetic preload). Call Center has admin-editable Voice Assistant bot settings and a Voice Assistant call journal. `POST /api/voice/context` is the diagnostic prompt JSON. `POST /api/voice/elevenlabs/conversation-initiation` is the ElevenLabs Conversation Initiation adapter (prompt override + optional language). `POST /api/voice/elevenlabs/post-call` persists completed conversations. Customer matching beyond phone / YFS Core / Bitrix is not connected. This repo does not change ElevenLabs UI settings.

Related documents:

- `docs/VOICE_ARCHITECTURE.md` — canonical architecture (Current / Planned / rejected)
- `docs/Voice/CLIENT_CUSTOMER_SUPPORT_POLICY_UA.md` — verbatim client Customer Support instruction (source of truth for Voice Assistant behaviour settings)
- `docs/PROJECT.md` — phase statuses
- `docs/ARCHITECTURE.md` — high-level subsystem map
- `docs/EXTERNAL_SERVICES.md` — vendor roles
- `docs/DATABASE.md` — schema including `voice_assistant_settings`
- `docs/SALES_AGENT.md` — Phase 3 outbound agent (not specified here)

Do not record account IDs, API keys, tokens, passwords, or other secrets in this file.

---

## 1. Current status

| Subsystem | Status |
| --- | --- |
| Instagram / Facebook Assistant | **IMPLEMENTED / CONNECTED**. Do not change this product as part of Phase 2. |
| Voice Assistant | **PHASE 2 — IN PROGRESS**. Selected architecture: Native ElevenLabs Agent + Laravel webhook tools (**POC SUCCESS**). Custom LLM experimental/fallback. Admin bot settings + Prompt Builder + initiation webhook adapter + voice contacts/calls journal **Current**. YFS Core / Bitrix / AI post-call analysis **Planned**. |
| Sales Agent | **PLANNED — PHASE 3**. Outbound calling. Not designed in detail here. Implementation not started. |

**Selected Phase 2 (POC SUCCESS, see `docs/VOICE_ARCHITECTURE.md` § 13):**

- Twilio phone transport into ElevenLabs (not first-party YFS Twilio)
- ElevenLabs Native Agent: STT, turn-taking, interruptions, language/voices, TTS, hosted LLM, conversation + audio ownership
- Authenticated Laravel webhook tools: `POST /api/voice/tools/test-context` (smoke-test; synthetic JSON)
- Confirmed via ElevenLabs Test Tool and a real voice conversation

**Experimental / fallback (existing Custom LLM routing, unchanged):**

- Custom LLM: `POST /voice-engine/v1/chat/completions`
- Laravel `bot_runtime`: **gemini** / **gemini-3.8-flash**
- Incoming ElevenLabs model label: `yfs-bot-runtime`

**Planned (not implemented):**

- first real read-only YFS Core Voice tool
- Bitrix24 Voice tools (not connected yet)
- production business tools
- AI post-call analysis, audio archive, Telegram
- live transfer / callback workflows in Laravel

Voice Assistant and Sales Agent must not share one prompt or one agent configuration.

---

## 2. Goal

Phase 2 is an **AI secretary for inbound telephone calls**.

Voice Assistant must:

1. Accept inbound telephone calls.
2. Speak naturally with the caller.
3. Determine the reason for the call.
4. Answer questions when it has reliable information.
5. Use YFS backend tools for current business data.
6. Not invent missing data.
7. Collect required contact details.
8. Decide whether the issue is resolved.
9. Transfer the call to a live operator when needed.
10. Create a callback / follow-up when live transfer is not possible.
11. Persist transcript and technical metadata after the call.
12. Produce a structured operator report.
13. Decide whether an operator action is required.
14. Show the call and follow-up in the YFS AI admin.

Realtime items 1–2 are **Current** at the voice-layer level. Native Agent webhook tool calling is **Current** for the smoke-test endpoint (POC SUCCESS). Item 5 next step is the first real read-only YFS Core tool. Item 11 is **Current** for transcript/summary persistence from the ElevenLabs post-call webhook. Items 6–10 and 12–14 remain **Planned** except the Call Center call journal.

It is **not** an outbound sales caller. That is Phase 3.

---

## 3. Position in YFS AI

YFS AI is one backend for YoungFashionShow AI services.

```text
YFS AI Laravel backend
├── Instagram / Facebook Assistant   IMPLEMENTED / CONNECTED
│   └── transport: Meta messaging
├── Voice Assistant                  PHASE 2 / IN PROGRESS
│   └── transport: inbound telephony
└── Sales Agent                      PHASE 3 / PLANNED
    └── transport: outbound telephony
```

Rules:

- Do not change the existing Instagram / Facebook Assistant to “support voice”.
- Do not store voice transcripts in `conversations` / `conversation_messages`.
- Shared platform pieces (admin shell, queue, AI provider settings, customers/contacts later) may be reused.
- Voice-specific runtime, prompts, tools, and call records stay in a Voice module.
- A later unified customer timeline may sit **above** Instagram, Facebook, Voice, and Sales calls. Transport-specific data remains in its own module.

---

## 4. Roadmap

### Phase 2.1 — Realtime voice path — **Current**

Confirmed. Details: `docs/VOICE_ARCHITECTURE.md`.

Not part of 2.1 (still **Planned** except the test-tool orchestrator in 2.3 and call persistence in 2.6): YFS Core tools, Bitrix, follow-ups.

### Phase 2.2 — Laravel Voice Core — **Current** for contacts/calls; matching **Planned**

`voice_contacts` and `voice_calls` exist. No YFS / Bitrix foreign keys yet.

See [§5 Voice data](#5-voice-data).

### Phase 2.3 — Knowledge and tools — **Current** for test tool only; production tools **Planned**

Laravel Voice Orchestrator executes `get_current_yfs_test_context` server-side. YFS Core / Bitrix / production business tools are not connected. Live telephone tool calling is **Unverified**.

See [§6 Knowledge and tools](#6-knowledge-and-tools-planned).

### Phase 2.4 — Voice prompt architecture — **Planned**

See [§7 Prompt architecture](#7-prompt-architecture-planned). Conversation behaviour: [§8](#8-conversation-behaviour-planned).

### Phase 2.5 — Human handoff — **Planned**

See [§9 Human handoff](#9-human-handoff-planned).

### Phase 2.6 — Post-call processing — **Current** for persist + language memory; analysis **Planned**

See [§10 Post-call processing](#10-post-call-processing).

### Phase 2.7 — Admin — **Current** for the call journal; Follow-ups **Planned**

See [§12 Admin](#12-admin).

---

## 5. Voice data

Do not mix Voice tables with `conversations` / `conversation_messages`.

### `voice_contacts` — **Current**

Independent interlocutor table. No YFS / Bitrix foreign keys yet.

- `phone_normalized` unique
- `phone_display`
- `name`
- `preferred_language` (en/ru/uk when known)
- `first_called_at` / `last_called_at`
- `calls_count` (completed unique calls only)
- `metadata` JSON (no secrets)

### `voice_calls` — **Current**

One completed ElevenLabs conversation.

- `voice_contact_id`
- `elevenlabs_conversation_id` unique
- `twilio_call_sid`
- `phone`
- `language`
- `started_at` / `ended_at` / `duration_seconds`
- `status`
- `transcript` JSON (normalized turns)
- `summary` (ElevenLabs `analysis.transcript_summary` when present)
- `recording_url` nullable (not provided by the transcription webhook)
- `metadata` JSON (safe subset only)

### Still planned

`voice_followups`, `voice_agent_settings`, `voice_call_messages` as a separate table (turns currently live on `voice_calls.transcript`), YFS/Bitrix FKs on `voice_contacts`.

---

## 6. Knowledge and tools (Planned production tools; test tool Current)

Production YFS Core / Bitrix Voice tools are **not implemented**. Do not treat test-tool results as live business data.

**Current (test only):** `get_current_yfs_test_context` via `POST /api/internal/voice/tools/execute`. Returns synthetic `source: yfs_ai_test` payload. Live inbound-call proof is **Unverified**.

When production tools are built, the Voice Agent must call a Laravel tool instead of guessing when information is dynamic or needs confirmation.

Planned tool/service areas:

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

Access rules:

- LLM / voice-runtime must not receive direct DB or Bitrix credentials
- all access goes through Laravel services / tools
- YFS Core and Bitrix24 are read-only for Voice Consultant until write actions are approved separately

Source precedence (**Planned** concept):

| Source | Role |
| --- | --- |
| YFS Core | Product / show / customer participation / history |
| Bitrix24 | Current / latest CRM context |

If sources conflict, the agent must not invent the truth. It follows explicit resolution / escalation rules (also **Planned**; not written here).

Each tool, when implemented, must have:

- a strict request/response schema
- validation
- authorization
- a predictable JSON response
- logging (no secrets)
- timeout and error handling

If a tool fails or returns unknown, the agent says it does not have that information. It does not invent dates, prices, availability, or policies.

Heavy or slow work must not sit on the realtime tool path.

Gateway-level OpenAI-shaped `tools` conversion to Gemini exists in voice-runtime. YFS test-tool execution is server-side (Laravel). That is **not** production-proven YFS/Bitrix tool calling on a live call.

---

## 7. Prompt architecture (Planned)

**Planned.** Do not treat Instagram prompts as the Voice prompt. Do not invent the Voice prompt in this document.

Intended sections:

- global rules
- topic-specific sections
- scenario rules
- escalation rules
- source precedence
- uncertainty handling

---

## 8. Conversation behaviour (Planned)

Target agent behaviour (product intent; Voice-specific prompt not written yet):

- introduces itself as the YoungFashionShow AI assistant
- holds a natural phone conversation
- detects the caller’s language
- supports multilingual conversation
- handles interruptions (ElevenLabs voice layer is **Current** for this)
- handles pauses and corrections
- asks clarifying questions
- keeps answers short enough for a phone call
- uses tools for dynamic facts (**Planned**)
- does not invent dates, prices, availability, or policies
- collects contact data only when needed
- recognizes a resolved question
- recognizes the need to escalate
- ends the call cleanly

Exact supported languages are chosen on the ElevenLabs voice layer, not in this document.

Inbound Voice Assistant prompts and business rules stay separate from Phase 3 Sales Agent prompts.

---

## 9. Human handoff (Planned)

Two modes. **Not implemented** in Laravel.

### Live transfer

When the caller needs a person, or the AI cannot resolve the question, the agent may transfer the Twilio call to an operator (ElevenLabs/Twilio capability; YFS orchestration **Planned**).

When possible, the operator receives context before or during transfer:

- caller number
- name
- reason
- short summary
- unresolved issue

Transfer configuration (numbers, business hours, fallback) lives in `voice_agent_settings`, not in the prompt.

### Callback / follow-up

If no operator is available:

1. The agent creates a follow-up / callback request (tool → Laravel).
2. After the call, the task appears in admin Follow-ups.

Follow-up must support:

- priority
- reason
- preferred callback details
- operator assignment
- status workflow: `new` → `assigned` → `in progress` → `completed` / `cancelled`

---

## 10. Post-call processing

**Current** for HMAC ingest, idempotent persist, language memory. Audio archive, queued AI analysis, and Telegram remain **Planned**.

ElevenLabs remains the owner/source of telephone conversation audio.

Current Laravel path after the call:

1. Verify `ElevenLabs-Signature` HMAC (`t=` + `v0=`, 30-minute tolerance) on `POST /api/voice/elevenlabs/post-call`.
2. Ignore non-`post_call_transcription` events (including `post_call_audio`) with HTTP 200.
3. Idempotent upsert on `elevenlabs_conversation_id`.
4. Store call metadata, transcript turns, and vendor summary when present.
5. Resolve conversation language via `VoiceConversationLanguageResolver` (transcript first; `metadata.main_language` only as en/ru/uk fallback).
6. If resolved language is en/ru/uk, update `voice_calls.language` and `voice_contacts.preferred_language`. Null/unknown does not overwrite a known preference.
6. Increment `calls_count` once per unique conversation.

`recording_url` is stored only if the payload actually contains an `http(s)` URL. The documented transcription webhook does not. This repo does not download audio.

Planned later: audio archive, queued structured analysis, Telegram.

---

## 11. Operator report (Planned)

The operator should not have to read the full transcript first.

Target report:

```text
Caller: +1...
Name: Jessica Smith
Reason: Miami event registration

Summary:
Customer wants to register her 12-year-old daughter.

Resolved:
- event dates
- age requirements

Unresolved:
- sponsorship/package pricing

Needs operator:
Yes

Recommended action:
Call customer back regarding package pricing.

Priority:
Normal
```

Full transcript is available **below** the report, not instead of it.

---

## 12. Admin

Call center today:

```text
Call center
├── Voice assistant     call journal (click a row for contact + transcript)
└── Bot settings        editable Voice Assistant behaviour
```

Voice Assistant behaviour settings are stored in `voice_assistant_settings` and must be based on `docs/Voice/CLIENT_CUSTOMER_SUPPORT_POLICY_UA.md`.

Runtime contract:

```text
Admin
  ↓
voice_assistant_settings
  ↓
VoiceAssistantPromptBuilder
  ↓
authenticated POST /api/voice/elevenlabs/conversation-initiation
  ↓
ElevenLabs conversation_initiation_client_data (system prompt override + optional language)
```

Diagnostic JSON remains `POST /api/voice/context`.

Paste into ElevenLabs: `https://ai.youngfashionshow.com/api/voice/elevenlabs/conversation-initiation` with header `Authorization: Bearer <token>`. Post-call: `https://ai.youngfashionshow.com/api/voice/elevenlabs/post-call` with HMAC `ElevenLabs-Signature`. This repo does not change ElevenLabs settings. YFS Core / Bitrix matching is not connected.

Still **Planned** (not built):

```text
Voice Assistant
├── Dashboard
├── Follow-ups
├── Agent Settings
└── Integrations
```

### Calls

**Current** on Call Center → Voice Assistant:

- date / time
- caller name or Unknown
- phone
- language
- duration
- status
- brief (ElevenLabs summary, else transcript preview)
- contact card + dialogue transcript in a sheet
- search with typeahead over existing `voice_contacts` (name / phone)
- “show all calls from this number” applies the phone filter on the same table

Not shown yet (no fake data): YFS participant/customer, package, show, Bitrix contact.

### Follow-ups

Operator queue with statuses `new`, `assigned`, `in progress`, `completed`, `cancelled`.

Filters: priority, status, date, operator.

### Agent Settings

- ElevenLabs agent reference
- transfer behaviour
- business hours
- fallback behaviour
- languages (voice-layer settings stay on ElevenLabs)
- Voice prompt / revision
- analysis model

`bot_runtime` LLM provider/model stays in Settings → AI. ElevenLabs API key stays in Settings → ElevenLabs.

### Integrations

Connection status for Twilio (informational), ElevenLabs, and the LLM provider. Secrets are never shown in full.

---

## 13. Webhook security

Initiation: Bearer `ELEVENLABS_TOOL_TOKEN` (unchanged).

Post-call: HMAC `ElevenLabs-Signature` with a separate `ELEVENLABS_POST_CALL_WEBHOOK_SECRET`. Fail closed if the secret is empty. Duplicate `conversation_id` deliveries update the same row and do not increment `calls_count` again. Secrets are not logged, stored in metadata, or shown in the UI.

---

## 14. Latency

Voice is a realtime system. Measure and control latency separately for:

- telephony
- speech input
- LLM / tool call
- backend tool
- speech output

Backend tools must be fast. Do not put migrations, large reports, or post-call analysis on the live call path.

---

## 15. Observability (Planned)

Planned metrics:

- total calls
- answered calls
- failed calls
- average duration
- average latency
- transfers
- callbacks
- resolved by AI
- escalated
- tool errors
- provider errors
- estimated / provider cost when the vendor exposes it

Always persist provider IDs for diagnostics.

---

## 16. Relation to Sales Agent (Phase 3)

Phase 3 is **outbound** calling. It is not specified or implemented here.

Phase 2 should keep these pieces reusable later:

- call storage
- transcripts
- tools (shared facts; sales-specific tools stay separate)
- contacts
- analysis pipeline
- follow-ups
- admin patterns
- observability

Must stay separate:

- agent configuration
- prompts
- business rules
- success criteria (secretary vs sales)

Do not implement one agent that “sometimes answers inbound and sometimes sells”.

OpenAI Realtime as an alternative full voice runtime is **not selected for Phase 2**. See rejected paths in `docs/VOICE_ARCHITECTURE.md`.

---

## 17. Data relationship

| Domain | Tables / module | Transport |
| --- | --- | --- |
| Instagram / Facebook | `conversations`, `conversation_messages` | Meta messaging |
| Voice Assistant | `voice_contacts`, `voice_calls`, `voice_assistant_settings` | Inbound phone |
| Sales Agent | TBD in Phase 3; may reuse `voice_calls.direction = outbound` | Outbound phone |

Do not unify Instagram messages and voice turns in one physical table “for simplicity”.

A later unified customer / contact timeline can read from all modules.

---

## 18. Out of scope for remaining Phase 2 documentation

Already decided and documented in `docs/VOICE_ARCHITECTURE.md` (do not reopen in this file):

- Gemini Live
- direct Twilio → YFS raw audio
- Speech Engine as production routing
- ElevenLabs hosted LLM as the business brain

Still out of scope until explicitly started:

- YFS Core / Bitrix Voice tools
- production business tools beyond the test tool
- AI post-call analysis / Telegram / audio downloader
- changing Instagram / Facebook / Telegram product behaviour
- Phase 3 Sales Agent
- writing credentials into docs
