# Extended identity search (asynchronous)

Status: **Current**. Used only after fast Voice identity lookup did not uniquely identify a YFS customer and a personal fact is still needed.

Fast path: `docs/Voice/CUSTOMER_IDENTITY.md` and `docs/Voice/BITRIX_IDENTITY_FALLBACK.md`.  
Filler: `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.

This repo does not change the ElevenLabs dashboard. Paste the JSON below.

---

## Why start + status (option B)

ElevenLabs Native Agent webhook tools are HTTP request/response. Official options:

- Webhook `execution_mode: async` only unblocks speech **while that same HTTP call is still running**. It does not let Laravel later POST a result into the live conversation.
- `client_tool_result` / `contextual_update` require the conversation **client SDK WebSocket**. Twilio Native Agent phone calls do not give this Laravel app a socket into the live call.
- There is no supported server API to push an identity payload into an already-running Native Agent conversation.

So we do **not** invent a push channel (option A). The supported, simple path is option B:

1. `start_extended_identity_search` returns immediately with `{ "status": "searching" }` and dispatches a Laravel queue job.
2. The agent continues the conversation.
3. Later it calls `get_extended_identity_search_status`, which is a fast DB read.

---

## Fast vs extended

| | Fast search | Extended search |
| --- | --- | --- |
| Where | Initiation / `resolve_customer_identity` | Queue job `RunExtendedVoiceIdentitySearchJob` |
| Budget | ~1500 ms initiation, ~4000 ms spoken tool (after YFS) | Longer; job timeout 45s |
| Work | YFS exact phone/name, then a cheap in-memory multilingual candidate scan; Bitrix exact phone/controlled name (one Latin retry on a Cyrillic miss); email → YFS | Same resolver with a larger budget, plus optional explicit email and child/show hints as available |
| Voice | Existing pre-tool speech: “Секунду, сейчас посмотрю.” | Honest: search may take a little time; do not sit in silence |

Do not run a long Bitrix chain inside the fast tool if the budget is already gone.

---

## Storage

A dedicated `voice_identity_searches` table owns the async lifecycle (`pending` / `running` / `unique` / `ambiguous` / `not_found` / `failed`), timestamps, and expiry (30 minutes). Compact `VoiceContact` metadata remains the YFS identity store; it is not a job queue.

Hints stored only when needed: parent name, child name, show/city, package, email **only if the caller said it**. Boolean `has_*` flags are also stored. Do not collect extra CRM fields “just in case”.

Result metadata: status, match_method, match_count, and for UNIQUE only `app_user_id` + `display_name` (server-side). Public tool JSON never includes ids or emails.

---

## Job

`RunExtendedVoiceIdentitySearchJob` on the existing database queue (`queue:work` already used by this app).

Sequence:

1. YFS / Bitrix phone fast lookup from the trusted `VoiceContact` number
2. Explicit caller email → YFS, if present
3. Spoken name / child fast lookup
4. UNIQUE → `VoiceCustomerIdentityStore::bindUnique()` on that contact
5. Ambiguous / not_found / failed → no bind, no random pick

An existing **different** unique YFS identity is not overwritten.

---

## Conversation while searching

After `status: searching`, the assistant continues talking. It must not invent progress or say the search is complete until the status tool says so.

Allowed:

1. One useful clarification (show/city, child name). Live mutation of a running search is not required in this version; a later `start` reuses an in-flight row.
2. Opt-in offer to talk about upcoming YFS shows. If the caller says no, stop.
3. If the caller changes topic, answer that question; keep the search in the background.
4. When status becomes `unique`, return to it on the next suitable turn (“Кстати, я нашла вашу запись…”) using `customer.display_name` only.

Do not say “a couple of minutes”. Say it **may take a little time**.

RU / EN / UK examples live in wrapper v6 section F.

---

## Native Agent tools

Reuse the **existing** Authorization secret (`ELEVENLABS_TOOL_TOKEN`). Do not create a new secret.

`system__caller_id` and `system__conversation_id` must be system dynamic variables.

### `start_extended_identity_search`

| Field | Value |
| --- | --- |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/start-extended-identity-search` |
| `response_timeout_secs` | `10` |
| `pre_tool_speech` | `auto` (a short natural phrase is allowed; then explain that extended search may take a little time) |
| `tool_call_sound` | omit or `typing` + `auto` |

Description (paste):

Start a background identity search only after resolve_customer_identity did not uniquely identify the caller and personal information is still needed. Returns immediately with status searching. Do not wait silently. Continue the conversation and later call get_extended_identity_search_status. Do not call for public show questions. Do not guess identity.

Typical start response:

```json
{
  "ok": true,
  "tool": "start_extended_identity_search",
  "status": "searching",
  "next_action": "continue_conversation"
}
```

If the contact is already uniquely identified: `status: unique`, `next_action: already_identified`, `customer.display_name` only.

```json
{
  "type": "webhook",
  "name": "start_extended_identity_search",
  "description": "Start a background identity search only after resolve_customer_identity did not uniquely identify the caller and personal information is still needed. Returns immediately with status searching. Do not wait silently. Continue the conversation and later call get_extended_identity_search_status. Do not call for public show questions. Do not guess identity.",
  "api_schema": {
    "url": "https://ai.youngfashionshow.com/api/voice/tools/start-extended-identity-search",
    "method": "POST",
    "request_headers": {
      "Authorization": {
        "secret_id": "<EXISTING_ELEVENLABS_AUTHORIZATION_SECRET_ID>"
      }
    },
    "request_body_schema": {
      "type": "object",
      "description": "Optional spoken hints. Session identifiers are filled by ElevenLabs system variables, not by the model.",
      "required": [],
      "properties": {
        "name": {
          "type": "string",
          "description": "Caller first and last name if known."
        },
        "child_name": {
          "type": "string",
          "description": "Child first name if known."
        },
        "show_city": {
          "type": "string",
          "description": "Show city if the caller named one."
        },
        "package": {
          "type": "string",
          "description": "Package name if the caller named one."
        },
        "email": {
          "type": "string",
          "description": "Email only if the caller explicitly said it. Never invent an email."
        },
        "system__caller_id": {
          "type": "string",
          "dynamic_variable": "system__caller_id"
        },
        "system__conversation_id": {
          "type": "string",
          "dynamic_variable": "system__conversation_id"
        }
      }
    }
  },
  "response_timeout_secs": 10,
  "pre_tool_speech": "auto",
  "disable_interruptions": false
}
```

### `get_extended_identity_search_status`

| Field | Value |
| --- | --- |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/extended-identity-search-status` |
| `response_timeout_secs` | `8` |
| `pre_tool_speech` | `off` (status must be instant; no waiting phrase) |
| `tool_call_sound` | omit |

Description (paste):

Check whether a previously started extended identity search has finished. Call this after start_extended_identity_search, not instead of resolve_customer_identity. Do not invent progress. If status is searching, continue the conversation. If unique, use customer.display_name. Do not enumerate candidates.

Status responses (never include candidates, phones, emails, or ids):

```json
{ "ok": true, "tool": "get_extended_identity_search_status", "status": "searching" }
```

```json
{
  "ok": true,
  "tool": "get_extended_identity_search_status",
  "status": "unique",
  "customer": { "display_name": "..." },
  "next_action": "identified"
}
```

Also: `ambiguous`, `not_found`, `failed`, `no_search`.

```json
{
  "type": "webhook",
  "name": "get_extended_identity_search_status",
  "description": "Check whether a previously started extended identity search has finished. Call this after start_extended_identity_search, not instead of resolve_customer_identity. Do not invent progress. If status is searching, continue the conversation. If unique, use customer.display_name. Do not enumerate candidates.",
  "api_schema": {
    "url": "https://ai.youngfashionshow.com/api/voice/tools/extended-identity-search-status",
    "method": "POST",
    "request_headers": {
      "Authorization": {
        "secret_id": "<EXISTING_ELEVENLABS_AUTHORIZATION_SECRET_ID>"
      }
    },
    "request_body_schema": {
      "type": "object",
      "description": "Session identifiers are filled by ElevenLabs system variables, not by the model.",
      "required": [],
      "properties": {
        "system__caller_id": {
          "type": "string",
          "dynamic_variable": "system__caller_id"
        },
        "system__conversation_id": {
          "type": "string",
          "dynamic_variable": "system__conversation_id"
        }
      }
    }
  },
  "response_timeout_secs": 8,
  "pre_tool_speech": "off",
  "disable_interruptions": false
}
```

After paste: select the same Authorization secret as `get_public_shows`. Confirm the two `system__*` fields are dynamic variables.
