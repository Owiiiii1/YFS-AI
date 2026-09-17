# Voice tool `get_customer_context`

Status: **Current**. First personal YFS Core tool for the Native Agent.

Identity binding: `docs/Voice/CUSTOMER_IDENTITY.md`.  
ElevenLabs paste fields: `docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`.  
Filler: `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.

This repo does not change the ElevenLabs dashboard. Bitrix is not a source for this tool.

---

## Security model

Laravel resolves the current customer only from trusted ElevenLabs system dynamic variables:

1. `system__conversation_id` → `VoiceContact` that stored that conversation at initiation / live tools
2. otherwise `system__caller_id` → **existing** `VoiceContact` (no create)

Then `metadata.yfs_customer` must already be `status: unique` with `app_user_id`.

LLM `customer_id`, `name`, `email`, `phone`, `child_id`, and top-level `phone` / `caller_id` are ignored. A second `system__caller_id` cannot replace a conversation that already has a bound identity.

If identity is not unique: `status: identity_required` and no children / participations.

Read-only JFS SELECT. No JFS writes. No Bitrix calls.

---

## Data (YFS Core)

Verified tables:

- `app_users` (`role = client`, not blocked): `name`, `language`
- `children.client_app_user_id` → `first_name`
- `child_event_assignments` + `events` + optional `package_templates.name`

Returned to ElevenLabs:

- customer `display_name`, optional `preferred_language`, optional `package` only when every returned participation shares one package name
- children `display_name`
- participations: `show`, `city`, `date` (only if `events.client_show_event_date` allows it), `date_announced`, `is_past`, assignment `status`, `category: family_look` when that flag is true, optional per-show `package`

Intentionally excluded: phones, emails, passwords, contracts, notes, gender, birthdate, measurements/photos, badge/QR codes, payment_status, payment tables, brand assignment ids, rehearsal slots, tickets, parking, meals, Bitrix, staff fields, internal ids.

---

## Size limit

Upcoming / unannounced assignments are kept. At most **8** most recent past shows per child. Global cap **24** participations (drop oldest past first). Enough for “which shows did my child do” without dumping a full history.

---

## Native Agent tool

| Field | Value |
| --- | --- |
| Tool name | `get_customer_context` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/customer-context` |
| Auth | Same Bearer secret as `get_public_shows` (`ELEVENLABS_TOOL_TOKEN`) |
| `response_timeout_secs` | `20` |
| `pre_tool_speech` | `force` |

### Description (paste)

Returns compact personal YFS Core context for the already identified caller on this conversation: customer display name, children, and show participations. Call this when the question is about the caller, their children, registrations, or which shows a child took part in. Do not say personal data is unavailable until this tool has run. Do not pass customer_id, name, email, phone, or child_id. Identity comes only from the current conversation. If status is identity_required, identify the caller with resolve_customer_identity first. Answer only the asked question from the result. Do not read the whole JSON. If several children are listed and the caller said “my child”, ask which child.

### Responses

OK:

```json
{
  "ok": true,
  "tool": "get_customer_context",
  "status": "ok",
  "customer": { "display_name": "..." },
  "children": [
    {
      "display_name": "...",
      "participations": [
        {
          "show": "...",
          "city": "...",
          "date": "...",
          "date_announced": true,
          "is_past": false,
          "status": "in_progress",
          "package": "..."
        }
      ]
    }
  ]
}
```

`preferred_language` / top-level `package` / participation `category` appear only when YFS Core has those values.

Identity required: `{ "ok": true, "tool": "get_customer_context", "status": "identity_required" }`

YFS unavailable: `{ "ok": false, "tool": "get_customer_context", "status": "unavailable" }`

Never returned: phones, emails, ids, payments, Bitrix payloads, secrets.

### Dashboard JSON

```json
{
  "type": "webhook",
  "name": "get_customer_context",
  "description": "Returns compact personal YFS Core context for the already identified caller on this conversation: customer display name, children, and show participations. Call this when the question is about the caller, their children, registrations, or which shows a child took part in. Do not say personal data is unavailable until this tool has run. Do not pass customer_id, name, email, phone, or child_id. Identity comes only from the current conversation. If status is identity_required, identify the caller with resolve_customer_identity first. Answer only the asked question from the result. Do not read the whole JSON. If several children are listed and the caller said “my child”, ask which child.",
  "api_schema": {
    "url": "https://ai.youngfashionshow.com/api/voice/tools/customer-context",
    "method": "POST",
    "request_headers": {
      "Authorization": {
        "secret_id": "<EXISTING_ELEVENLABS_AUTHORIZATION_SECRET_ID>"
      }
    },
    "request_body_schema": {
      "type": "object",
      "description": "No LLM parameters. Session identifiers are filled by ElevenLabs system variables.",
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
  "response_timeout_secs": 20,
  "pre_tool_speech": "force",
  "tool_call_sound": "typing",
  "tool_call_sound_behavior": "auto",
  "disable_interruptions": false
}
```

After paste: reuse the existing Authorization secret. Confirm `system__caller_id` / `system__conversation_id` are dynamic variables, not LLM fields.
