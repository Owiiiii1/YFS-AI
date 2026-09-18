# Voice tool `request_human_followup`

Status: **Current**. Live Native Agent webhook plus post-call safety net.

Telegram: reuses the existing Instagram Assistant bot and group (`TelegramBotService` / Settings → Telegram). No second bot. No new Telegram credentials.

This repo does not change the ElevenLabs dashboard. Paste the fields below after backend review.

Related: `docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`, `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.

---

## When to call

Caller explicitly asks:

- to be called back
- to pass information to a manager
- for a human / Sales / Support to contact them

Do **not** call for ordinary public show questions or for identified-caller children/registration facts that `get_customer_context` answers.

Unknown Sales leads do **not** need YFS identity. Do not invent application status.

The agent must **not** say the request was sent until the tool returns `ok: true` (`created` or `already_created`).

---

## Security model

Laravel binds the caller only from trusted ElevenLabs system dynamic variables:

1. `system__conversation_id`
2. otherwise `system__caller_id`

LLM `customer_id`, `app_user_id`, `voice_contact_id`, `voice_call_id`, Bitrix ids, and other internal ids are ignored.

`callback_phone`: use the dictated number when the caller gave one. Never invent a number. If a callback was requested and no other number was dictated, the trusted calling number may be used when it is a valid E.164 phone.

---

## Native Agent tool

| Field | Value |
| --- | --- |
| Tool name | `request_human_followup` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/request-human-followup` |
| Auth | Same Bearer secret as `get_public_shows` (`ELEVENLABS_TOOL_TOKEN`). Do not create a new secret. |
| `response_timeout_secs` | `15` |
| `pre_tool_speech` | `force` |
| Execution mode | Immediate synchronous webhook (`execution_mode` immediate / default). Do **not** use async. |

### Description (paste)

Create a human follow-up for Sales or Support when the caller explicitly asks for a callback, a manager, or a person to contact them. Call this before saying the request was passed to a team. Do not confirm transfer until ok is true. Unknown Sales leads do not need YFS identity. Pass department (sales|support), reason, callback_requested, and callback_phone only when the caller dictated a number. Do not pass customer_id, app_user_id, or other internal ids. Do not invent an application status or a callback number.

### Body description (paste)

Business fields are filled by the LLM. Session identifiers are ElevenLabs system dynamic variables, not LLM parameters. Do not send internal ids.

### Properties

| Property | Source | Required | Notes |
| --- | --- | --- | --- |
| `system__caller_id` | Dynamic Variable `system__caller_id` | no | Trusted Twilio caller id. Not an LLM field. |
| `system__conversation_id` | Dynamic Variable `system__conversation_id` | no | Trusted conversation id. Not an LLM field. |
| `department` | LLM Prompt | yes | `sales` or `support`. New application / pricing / new participation / unknown lead → sales. Existing customer after contract on current participation → support. |
| `reason` | LLM Prompt | yes | Short reason in the conversation language. |
| `callback_requested` | LLM Prompt | no | True when the caller asked to be called back. |
| `callback_phone` | LLM Prompt | no | Only if the caller explicitly dictated a number. Never invent. |
| `preferred_callback_time` | LLM Prompt | no | Caller preference, not a booked appointment. |
| `customer_name` | LLM Prompt | no | If spoken. Do not invent. |
| `child_name` | LLM Prompt | no | If spoken. Do not invent. |
| `show_city` | LLM Prompt | no | If named. |
| `summary` | LLM Prompt | no | One or two useful sentences. No transcript. |

### Responses

Success:

```json
{ "ok": true, "status": "created", "department": "sales" }
```

Idempotent duplicate (same conversation):

```json
{ "ok": true, "status": "already_created", "department": "sales" }
```

Saved but Telegram not delivered (do **not** confirm transfer to the caller):

```json
{ "ok": false, "status": "queued", "department": "sales" }
```

Failure:

```json
{ "ok": false, "status": "failed" }
```

Never returned: follow-up ids, contact ids, app_user_id, Bitrix ids, tokens.

### Dashboard JSON

```json
{
  "type": "webhook",
  "name": "request_human_followup",
  "description": "Create a human follow-up for Sales or Support when the caller explicitly asks for a callback, a manager, or a person to contact them. Call this before saying the request was passed to a team. Do not confirm transfer until ok is true. Unknown Sales leads do not need YFS identity. Pass department (sales|support), reason, callback_requested, and callback_phone only when the caller dictated a number. Do not pass customer_id, app_user_id, or other internal ids. Do not invent an application status or a callback number.",
  "api_schema": {
    "url": "https://ai.youngfashionshow.com/api/voice/tools/request-human-followup",
    "method": "POST",
    "request_headers": {
      "Authorization": {
        "secret_id": "<EXISTING_ELEVENLABS_AUTHORIZATION_SECRET_ID>"
      }
    },
    "request_body_schema": {
      "type": "object",
      "description": "Business fields are filled by the LLM. Session identifiers are ElevenLabs system dynamic variables, not LLM parameters. Do not send internal ids.",
      "required": ["department", "reason"],
      "properties": {
        "system__caller_id": {
          "type": "string",
          "dynamic_variable": "system__caller_id",
          "description": "Trusted calling number from ElevenLabs. Not an LLM parameter."
        },
        "system__conversation_id": {
          "type": "string",
          "dynamic_variable": "system__conversation_id",
          "description": "Trusted conversation id from ElevenLabs. Not an LLM parameter."
        },
        "department": {
          "type": "string",
          "description": "sales for new applications, pricing, new participation, or potential clients. support for an existing customer’s current participation or organizational questions after a contract.",
          "enum": ["sales", "support"]
        },
        "reason": {
          "type": "string",
          "description": "Short reason the caller wants a human, in the conversation language."
        },
        "callback_requested": {
          "type": "boolean",
          "description": "True when the caller asked to be called back."
        },
        "callback_phone": {
          "type": "string",
          "description": "Callback number only if the caller explicitly dictated it. Do not invent a number. Omit to use the trusted calling number when a callback was requested."
        },
        "preferred_callback_time": {
          "type": "string",
          "description": "Caller preference only, not a booked appointment."
        },
        "customer_name": {
          "type": "string",
          "description": "Parent name if spoken. Do not invent."
        },
        "child_name": {
          "type": "string",
          "description": "Child name if spoken. Do not invent."
        },
        "show_city": {
          "type": "string",
          "description": "Show city or event the caller named, if any."
        },
        "summary": {
          "type": "string",
          "description": "One or two sentences of useful context. No full transcript. Do not invent application status."
        }
      }
    }
  },
  "response_timeout_secs": 15,
  "pre_tool_speech": "force",
  "tool_call_sound": "typing",
  "tool_call_sound_behavior": "auto",
  "disable_interruptions": false
}
```

After paste: reuse the existing Authorization secret. Confirm `system__caller_id` / `system__conversation_id` are **dynamic variables**. Confirm `department` and the other business fields are **LLM Prompt**. Publish the agent that owns the Twilio number.

---

## Telegram

Same bot + same group/topic as Instagram closed cases (`TelegramBotService::sendChannelText`). Voice has its own formatter. No transcript. Empty fields omitted. No internal ids. Optional Call Center link only when `voice_call_id` is already known (`/call-center?call={id}`).

Idempotency: one `voice_followups` row per `elevenlabs_conversation_id`. Telegram sends only when `telegram_sent_at` is null.

If Telegram is down: row is stored, `telegram_sent_at` stays null, tool returns `ok: false`, `status: queued`. The agent must not confirm delivery.

Recovered post-call messages use: `⚠️ Voice · Follow-up recovered after call`.

---

## Post-call safety net

After `post_call_transcription` is persisted, `AnalyzeVoiceCallJob` runs on the existing database queue (does not block the ElevenLabs webhook).

It stores `voice_call_analyses` and, if the transcript shows a promised callback / required human follow-up **and** no live follow-up exists, creates a recovered follow-up and sends Telegram once.

If a live follow-up already exists: analysis is stored, no second row, no second Telegram (unless the live row never delivered, in which case the first message is retried).
