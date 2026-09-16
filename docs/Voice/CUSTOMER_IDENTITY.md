# Voice Customer Identity (YFS Core)

Status: **Current** for phone identification on conversation initiation, spoken-name Native Agent tool `resolve_customer_identity`, and compact binding on `VoiceContact`.

Canonical audit: `docs/Voice/CUSTOMER_IDENTITY_AND_BITRIX_AUDIT.md`.  
Filler / pre-tool speech: `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.  
ElevenLabs live-tool paste fields: `docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`.

Bitrix24 is not connected. Identity reads JFS only through `JfsReadService` (SELECT). Laravel never writes `app_users.phone` or any other JFS row.

This repo does not change the ElevenLabs dashboard. Paste the JSON below into the agent tool editor.

---

## Flow

```text
Initiation (caller_id)
  → PhoneNumberNormalizer / VoiceContactDirectory
  → CustomerIdentityResolver::resolveByPhone()
  → unique | ambiguous | not_found | source_unavailable
  → compact yfs_customer on voice_contacts.metadata (when not source_unavailable)
  → store elevenlabs_conversation_id when ElevenLabs sent conversation_id
  → CALLER CONTEXT in the initiation prompt (no internal ids)

Spoken identity (personal question, unknown / ambiguous / other number)
  → agent asks name (then child name if needed)
  → POST /api/voice/tools/resolve-customer-identity
  → CustomerIdentityResolver::resolveBySpokenHints(name, child_name)
  → UNIQUE binds compact identity onto the current VoiceContact when a trusted session id is present
```

Public show questions still use `get_public_shows` / `get_show_brands` without identification.

---

## Phone matching

1. Strip to digits.
2. Require 8–15 digits.
3. US 10-digit and 11-digit (leading `1`) are equivalent. Nothing broader.
4. Compare against JFS `app_users.phone` after digit-stripping (stored values are masked).
5. `role = client` only. `status = blocked` is skipped.
6. Phone is not unique: 0 → `not_found`, 1 → `unique`, >1 → `ambiguous`.

Do not compare `voice_contacts.phone_normalized` as a string to `app_users.phone`.

---

## Name and child lookup

Used by `CustomerIdentityResolver::resolveByName()`, `resolveByChildName()`, and `resolveBySpokenHints()`. The Native Agent webhook `resolve_customer_identity` calls `resolveBySpokenHints` only. Matching logic is not duplicated in the controller.

- Parent: whole-word match on `app_users.name` (order-insensitive). `"Ann"` does not match `"Anna"`.
- Child: whole-word match on `children.first_name`; returns **parent** identity rows only. Child names are not returned.
- Several parents → `ambiguous`. Do not auto-pick. Do not list names to the agent.

---

## Compact VoiceContact metadata

Stored under `metadata.yfs_customer` only:

Unique:

```json
{
  "status": "unique",
  "match_method": "phone",
  "matched_at": "<ISO-8601>",
  "app_user_id": 123,
  "display_name": "Parent Name"
}
```

Ambiguous (initiation only): `status`, `match_method`, `matched_at`, `candidate_count`. No names, no ids.

Not found (initiation only): `status`, `match_method`, `matched_at`.

`source_unavailable`: metadata is left unchanged.

**Spoken tool binding:** only a UNIQUE result is written through `VoiceCustomerIdentityStore::bindUnique()`. Ambiguous and not_found do not bind and do not create a fake identity. `source_unavailable` does not destroy an existing unique identity.

**Not stored:** phones, emails, children lists, payments, tickets, contracts, photos, stage plans, secrets.

The calling number is **not** written to YFS/JFS. `app_users.phone` is never updated because someone identified themselves from another handset.

---

## How the tool finds the current caller

Do **not** add an LLM `phone` / `caller_id` parameter. The model must not prove identity by inventing a number.

Trusted identifiers (ElevenLabs **system** dynamic variables only):

| Body field | Source | Use |
| --- | --- | --- |
| `system__caller_id` | `dynamic_variable: system__caller_id` | Find or create the `VoiceContact` for this Twilio caller |
| `system__conversation_id` | `dynamic_variable: system__conversation_id` | Find the `VoiceContact` that stored this id at initiation |

Laravel also reads nested `dynamic_variables.system__caller_id` / `system__conversation_id` if ElevenLabs wraps them.

Top-level `phone` and `caller_id` in the tool body are **ignored**.

Initiation `POST /api/voice/elevenlabs/conversation-initiation` already receives ElevenLabs `caller_id`. When `conversation_id` is also present, it is stored on `voice_contacts.metadata.elevenlabs_conversation_id` so the live tool can bind without trusting the LLM.

If no trusted session identifier is present, lookup still runs and the agent still receives `status` / `next_action` / `display_name`, but nothing is persisted on a contact.

---

## Identity override safety

- A UNIQUE match from initiation is the current identity for this caller.
- `resolve_customer_identity` is for unknown / ambiguous / other-number callers who need a personal fact.
- If the contact already has a unique `yfs_customer` and spoken hints uniquely match a **different** `app_user_id`, Laravel does **not** switch. The tool returns `status: unique`, `next_action: already_identified`, and the **existing** `display_name`.
- Replacement is allowed only when the agent sets `on_behalf_of: true` because the caller explicitly said they are calling for a different registered parent or family.

Do not switch identity because a name appeared in an ordinary question.

---

## Native Agent tool `resolve_customer_identity`

| Field | Value |
| --- | --- |
| Tool name | `resolve_customer_identity` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/resolve-customer-identity` |
| Auth | Same as `get_public_shows`: header `Authorization` using the **existing** ElevenLabs secret (`ELEVENLABS_TOOL_TOKEN`). Do not create a new secret. |
| `response_timeout_secs` | `20` |
| `pre_tool_speech` | `force` (see filler doc) |

### Description (paste into ElevenLabs)

Call resolve_customer_identity only when personal/customer-specific information is needed and caller identity is not already uniquely established. Use name first. If ambiguous, ask for child name and call again with both name and child_name. Do not call this tool for public YFS questions. Do not guess identity. Do not enumerate candidates.

### Agent behavior

- Public questions (Chicago date, Los Angeles brands, …): do not identify; call the public show tools.
- Personal question + unique caller: do not ask the name again.
- Personal question + unknown/ambiguous: ask name and surname, then call the tool. If `ambiguous` / `ask_child_name`, ask the child’s first name and call again.
- `not_found`: do not invent a client. One careful re-ask is allowed. Then continue without identity or say personal data is not available. Not automatic escalation.
- Still ambiguous after child name (`ask_additional_identifier`): do not guess and do not list clients. We do not currently support extra identifiers beyond name + child name.

RU ask: `Подскажите, пожалуйста, ваше имя и фамилию.`  
EN / UK: natural equivalents already in wrapper v5.

### Request / response contract

LLM may send `name`, `child_name`, optional `on_behalf_of`. At least one of `name` or `child_name` must be a useful string.

UNIQUE:

```json
{
  "ok": true,
  "tool": "resolve_customer_identity",
  "status": "unique",
  "customer": { "display_name": "..." },
  "next_action": "identified"
}
```

AMBIGUOUS (no child_name yet): `ok: true`, `status: ambiguous`, `next_action: ask_child_name`.  
AMBIGUOUS (child_name already sent): `next_action: ask_additional_identifier`.  
Never list candidates.

NOT FOUND: `ok: true`, `status: not_found`, `next_action: ask_again_or_continue_without_identity`.

SOURCE UNAVAILABLE: `ok: false`, `status: source_unavailable`, `next_action: continue_without_identity`.

INVALID (missing name and child_name): `ok: false`, `status: invalid_request`, `next_action: ask_name`.

Never returned to ElevenLabs: phone, email, internal ids, candidate lists, children lists, payments, CRM dumps, secrets.

Missing/invalid Bearer: `401` `{"message":"Unauthorized"}`.

### Dashboard JSON (paste into the tool JSON editor)

Reuse the **existing** Authorization secret already attached to `get_public_shows` / `get_show_brands`. Replace only the placeholder `secret_id`. Do not commit a real secret.

`system__caller_id` and `system__conversation_id` **must** use `dynamic_variable` (system-filled). Do **not** give them an LLM `description` or the model can forge a caller.

```json
{
  "type": "webhook",
  "name": "resolve_customer_identity",
  "description": "Call resolve_customer_identity only when personal/customer-specific information is needed and caller identity is not already uniquely established. Use name first. If ambiguous, ask for child name and call again with both name and child_name. Do not call this tool for public YFS questions. Do not guess identity. Do not enumerate candidates.",
  "api_schema": {
    "url": "https://ai.youngfashionshow.com/api/voice/tools/resolve-customer-identity",
    "method": "POST",
    "request_headers": {
      "Authorization": {
        "secret_id": "<EXISTING_ELEVENLABS_AUTHORIZATION_SECRET_ID>"
      }
    },
    "request_body_schema": {
      "type": "object",
      "description": "Spoken identity hints. Session identifiers are filled by ElevenLabs system variables, not by the model.",
      "required": [],
      "properties": {
        "name": {
          "type": "string",
          "description": "Caller first and last name as spoken. Prefer this first. At least one of name or child_name is required."
        },
        "child_name": {
          "type": "string",
          "description": "Child first name. Use only after a previous call returned status ambiguous."
        },
        "on_behalf_of": {
          "type": "boolean",
          "description": "Set true only when the caller explicitly says they are calling for a different registered parent or family than the one already identified on this call. Otherwise omit or false."
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
  "response_timeout_secs": 20,
  "pre_tool_speech": "force",
  "tool_call_sound": "typing",
  "tool_call_sound_behavior": "auto",
  "disable_interruptions": false
}
```

After paste: open the Authorization header and select the **same** workspace secret used by the public show tools. Confirm `system__caller_id` / `system__conversation_id` show as dynamic variables, not LLM parameters.

---

## Conversation initiation

`POST /api/voice/elevenlabs/conversation-initiation` baseline JSON is unchanged:

```json
{
  "type": "conversation_initiation_client_data",
  "conversation_config_override": {
    "agent": {
      "prompt": { "prompt": "..." },
      "language": "ru"
    }
  }
}
```

`language` is still omitted unless en/ru/uk is known. Stored `voice_contacts.preferred_language` wins over JFS `app_users.language`. JFS language is used only when Voice has no stored preference and the phone match is unique.

JFS errors must not fail the webhook.

---

## Prompt

Wrapper **v5**, section **E. CALLER IDENTITY** (includes when to call `resolve_customer_identity` and short waiting-phrase examples). Runtime `CALLER CONTEXT` is appended when initiation resolves identity and is not part of the prompt version hash.

---

## Logs

Allowed: `status`, `match_method`, `match_count`, tool `ok`, bind outcome (`bound` / `preserved` / `skipped` / `no_session` / `unchanged`).

Not allowed: names, phones, emails, ids, candidate lists.
