# Voice Assistant

Status: **PHASE 2 — IN PROGRESS**

Canonical Voice architecture: **`docs/VOICE_ARCHITECTURE.md`**.  
This file is the product spec: goals, roadmap, planned data, conversation/handoff/admin intent.

Realtime production path is confirmed by a successful inbound telephone call.

**Selected Phase 2 architecture (POC SUCCESS):** ElevenLabs Native Agent → authenticated Laravel webhook tools → structured JSON → agent voice response.

**Experimental / fallback:** ElevenLabs voice layer + YFS Custom LLM gateway + Gemini. Existing Custom LLM routing is unchanged and is not deleted.

Laravel Voice Orchestrator exists for a **test tool**, plus Voice Session Context and Prompt Orchestrator (synthetic preload). YFS Core / Bitrix tools, post-call pipeline, and Voice admin remain **Planned**.

Related documents:

- `docs/VOICE_ARCHITECTURE.md` — canonical architecture (Current / Planned / rejected)
- `docs/PROJECT.md` — phase statuses
- `docs/ARCHITECTURE.md` — high-level subsystem map
- `docs/EXTERNAL_SERVICES.md` — vendor roles
- `docs/DATABASE.md` — planned `voice_*` entities (not implemented)
- `docs/SALES_AGENT.md` — Phase 3 outbound agent (not specified here)

Do not record account IDs, API keys, tokens, passwords, or other secrets in this file.

---

## 1. Current status

| Subsystem | Status |
| --- | --- |
| Instagram / Facebook Assistant | **IMPLEMENTED / CONNECTED**. Do not change this product as part of Phase 2. |
| Voice Assistant | **PHASE 2 — IN PROGRESS**. Selected architecture: Native ElevenLabs Agent + Laravel webhook tools (**POC SUCCESS**). Custom LLM experimental/fallback. YFS Core / Bitrix / post-call / admin **Planned**. |
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
- post-call `voice_calls` persistence, audio archive, transcript, analysis, Telegram
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

Realtime items 1–2 are **Current** at the voice-layer level. Native Agent webhook tool calling is **Current** for the smoke-test endpoint (POC SUCCESS). Item 5 next step is the first real read-only YFS Core tool. Items 6–14 are **Planned** unless noted in `docs/VOICE_ARCHITECTURE.md`.

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

Not part of 2.1 (still **Planned** except the test-tool orchestrator in 2.3): Laravel Voice module persistence, post-call, admin.

### Phase 2.2 — Laravel Voice Core — **Planned**

Create a **separate** Voice Assistant module. Conceptual entities only until migrations are explicitly started.

See [§5 Planned data](#5-planned-data-not-implemented).

### Phase 2.3 — Knowledge and tools — **Current** for test tool only; production tools **Planned**

Laravel Voice Orchestrator executes `get_current_yfs_test_context` server-side. YFS Core / Bitrix / production business tools are not connected. Live telephone tool calling is **Unverified**.

See [§6 Knowledge and tools](#6-knowledge-and-tools-planned).

### Phase 2.4 — Voice prompt architecture — **Planned**

See [§7 Prompt architecture](#7-prompt-architecture-planned). Conversation behaviour: [§8](#8-conversation-behaviour-planned).

### Phase 2.5 — Human handoff — **Planned**

See [§9 Human handoff](#9-human-handoff-planned).

### Phase 2.6 — Post-call processing — **Planned**

See [§10 Post-call processing](#10-post-call-processing-planned).

### Phase 2.7 — Admin — **Planned**

See [§12 Admin](#12-admin-planned).

---

## 5. Planned data (NOT IMPLEMENTED)

These tables are a **conceptual schema**. They are not migrations and are not a final column list.

Do not mix them with `conversations` / `conversation_messages`.

### `voice_calls`

One inbound (later also reusable for outbound) call record.

Conceptual fields:

- `provider` — production realtime voice layer is ElevenLabs; LLM provider is separate (`gemini` today)
- `provider_call_id` — external ID for idempotency and diagnostics
- `direction` — Phase 2: inbound. Phase 3 may reuse the same store with outbound
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
- follow-up / callback / escalation
- Telegram notification metadata
- outcome
- needs_followup

### `voice_call_messages`

Turn-level transcript, not Instagram messages.

- `voice_call_id`
- `speaker` — caller / agent / operator / system
- `text`
- timestamp / order
- provider metadata if needed

### `voice_contacts`

Normalized contact data collected during calls (name, phone, email, child/participant details, preferred callback, etc.).

May later link to `customers` without replacing Instagram customer rows.

### `voice_followups`

Operator work queue after a call.

- reason
- priority
- status
- assigned operator
- callback requested
- operator notes

### `voice_agent_settings`

Non-secret runtime configuration.

- remote agent IDs
- transfer configuration
- behaviour settings (hours, fallback, languages)
- analysis model choice

Secrets are **not** stored as plaintext in this table and are not documented here.

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

## 10. Post-call processing (Planned)

**Not implemented.**

ElevenLabs remains the owner/source of telephone conversation audio.

Planned Laravel path after the call:

1. Verify webhook authenticity / signature (and/or retrieve audio + transcript from ElevenLabs).
2. Enforce idempotency (`provider` + `provider_call_id` / provider event ID).
3. Store call metadata on `voice_calls`.
4. Store the transcript.
5. Store audio reference / archive through YFS AI.
6. Store provider analysis if the vendor sent one.
7. Queue structured analysis (not inside the webhook request).
8. Telegram notification when configured.

Structured result (logical contract, not a final schema):

- `summary`
- `intent` / topic
- `customer_name`
- `contact_details`
- `questions`
- `resolved_questions`
- `unresolved_questions`
- `sentiment`
- `lead_quality`
- `sales_opportunity`
- `needs_operator`
- `callback_required`
- `urgency`
- `recommended_action`
- `outcome`

The analysis model is a later Laravel choice. It is not the Custom LLM realtime path.

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

## 12. Admin (Planned)

Future admin section (not built; Call center page today is a placeholder):

```text
Voice Assistant
├── Dashboard
├── Calls
├── Follow-ups
├── Agent Settings
└── Integrations
```

### Calls

Operator sees:

- caller
- identified customer
- date / time
- duration
- language
- status
- AI summary
- transcript
- audio reference
- extracted contact data
- resolved / unresolved questions
- outcome
- whether operator action is required

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

## 13. Webhook security (Planned)

Required for every provider webhook and tool endpoint:

- signature (or equivalent) verification
- replay / idempotency protection
- no secrets in logs
- persist provider event ID when the vendor sends one
- raw payload storage only if required and stored safely
- async processing for anything heavier than ack + persist
- retry-safe handlers (duplicate delivery must not duplicate follow-ups or analysis side effects)

Same idea as Meta / Telegram webhooks: verify first, then normalize, then process.

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
| Voice Assistant | `voice_*` (**Planned**) | Inbound phone |
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
- post-call migrations and jobs
- changing Instagram / Facebook / Telegram product behaviour
- Phase 3 Sales Agent
- writing credentials into docs
