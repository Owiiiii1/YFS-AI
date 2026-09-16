# Voice Customer Identity (YFS Core)

Status: **Current** for phone identification on conversation initiation, plus name/child resolver methods for a later Native Agent tool.

Canonical audit: `docs/Voice/CUSTOMER_IDENTITY_AND_BITRIX_AUDIT.md`.

Bitrix24 is not connected. Identity reads JFS only through `JfsReadService`.

---

## Flow

```text
caller_id
  → PhoneNumberNormalizer / VoiceContactDirectory
  → CustomerIdentityResolver::resolveByPhone()
  → JfsReadService::findClientsByPhoneDigits()
  → unique | ambiguous | not_found | source_unavailable
  → compact yfs_customer on voice_contacts.metadata (when not source_unavailable)
  → CALLER CONTEXT in the initiation prompt (no internal ids)
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

Used by `CustomerIdentityResolver::resolveByName()`, `resolveByChildName()`, and `resolveBySpokenHints()` (future `resolve_customer_identity` tool). No public ElevenLabs webhook yet.

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

Ambiguous: `status`, `match_method`, `matched_at`, `candidate_count`. No names, no ids.

Not found: `status`, `match_method`, `matched_at`.

`source_unavailable`: metadata is left unchanged.

**Not stored:** phones, emails, children lists, payments, tickets, contracts, photos, stage plans, secrets.

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

Wrapper **v4**, section **E. CALLER IDENTITY**. Runtime `CALLER CONTEXT` is appended when initiation resolves identity and is not part of the prompt version hash.

---

## Future tool (not registered in ElevenLabs)

Laravel already has `CustomerIdentityResolver::resolveBySpokenHints($name, $childName)`. A later Native Agent tool can wrap that. Do not add a public endpoint until that step.
