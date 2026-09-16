# Latest Work Report

## Task

First production Voice customer-identity stage: identify callers against YFS Core / JFS only. Bitrix24 not connected. No package/payment/rehearsal tools.

## Status

Done. Conversation initiation still returns the existing ElevenLabs `conversation_initiation_client_data` contract. Unique JFS phone matches store a compact identity on `voice_contacts.metadata` and add a runtime CALLER CONTEXT block. Ambiguous matches are not auto-selected.

## What was implemented

```text
caller_id
  → VoiceContactDirectory
  → CustomerIdentityResolver::resolveByPhone()
  → JfsReadService identity SELECTs
  → unique | ambiguous | not_found | source_unavailable
```

### Identity contract

| status | Meaning |
| --- | --- |
| `unique` | Exactly one client `app_users` row (`role=client`, not blocked) |
| `ambiguous` | More than one client; no `yfs_app_user_id` / display name returned to the agent |
| `not_found` | Zero clients |
| `source_unavailable` | JFS unconfigured or query failed; initiation still 200 |

Unique fields (Laravel-side): `yfs_app_user_id`, `display_name`, `preferredLanguage` from `app_users.language` when present. Phone, email, children, payments are not returned from identity lookups.

### Phone matching

- Digit-only comparison against masked JFS `app_users.phone`
- 8–15 digits
- US 10-digit ↔ 11-digit with leading `1` only
- Phone is not treated as unique

### Name / child (resolver ready, no ElevenLabs tool)

- `findClientsByName` / `resolveByName`: whole-word match on `app_users.name`
- `findClientsByChildName` / `resolveByChildName`: match `children.first_name`, return parent identity rows only (no child names)
- `resolveBySpokenHints($name, $childName)` for a later `resolve_customer_identity` tool. No new public webhook.

### VoiceContact metadata (`yfs_customer`)

Stored when status is not `source_unavailable`:

- unique: `status`, `match_method`, `matched_at`, `app_user_id`, `display_name`
- ambiguous: `status`, `match_method`, `matched_at`, `candidate_count`
- not_found: `status`, `match_method`, `matched_at`

**Not stored:** phones, emails, children lists, payments, tickets, contracts, photos, stage plans, secrets.

`source_unavailable` does not overwrite existing metadata.

### Conversation initiation

`POST /api/voice/elevenlabs/conversation-initiation` top-level JSON unchanged: `type` + `conversation_config_override.agent.prompt` (+ `language` when en/ru/uk is known).

Stored `voice_contacts.preferred_language` still wins. Unique JFS language is used only when Voice has no stored preference. JFS failure does not fail the call. `calls_count` is still not incremented here.

### Prompt

`VoiceAssistantPromptBuilder` wrapper **v4**, section **E. CALLER IDENTITY**. Runtime CALLER CONTEXT is not hashed into `version`. Policy section bodies were not rewritten.

Public questions still go through `get_public_shows` / `get_show_brands` without identification.

## Files changed

- `app/Services/Jfs/JfsReadService.php`
- `app/Services/Jfs/JfsIdentityMatch.php`
- `app/Services/Voice/Identity/CustomerIdentityResult.php`
- `app/Services/Voice/Identity/CustomerIdentityResolver.php`
- `app/Services/Voice/Identity/VoiceCustomerIdentityStore.php`
- `app/Services/Voice/Identity/VoiceConversationInitiationService.php`
- `app/Http/Controllers/Api/ElevenLabsConversationInitiationController.php`
- `app/Services/Voice/Prompt/VoiceAssistantPromptBuilder.php`
- tests (identity unit tests, initiation service tests, prompt v4, FakeJfsReadService identity methods)
- `docs/Voice/CUSTOMER_IDENTITY.md`
- `docs/VOICE_ARCHITECTURE.md`, `docs/VOICE_ASSISTANT.md`, `docs/ARCHITECTURE.md`, `docs/PROJECT.md`
- `docs/Development/LATEST_WORK_REPORT.md`

## Tests

PHPUnit (no SQLite install; DB feature tests skip without `pdo_sqlite`):

- Phone unique / unknown / duplicate → ambiguous
- Masked JFS phone vs 10/11-digit US caller
- JFS unavailable ≠ not_found
- Name unique / ambiguous / unknown
- Child resolves parent; shared child name → ambiguous; unknown child
- Initiation contract keys unchanged; ambiguous does not name clients; JFS failure still 200; preferred_language still works
- Existing `get_public_shows`, `get_show_brands`, POC test-context, post-call language tests remain green

Run: 71 passed, 13 skipped (sqlite).

## Production smoke (no PII printed)

`php artisan optimize` on this host.

| Check | Result |
| --- | --- |
| Unauthorized initiation | HTTP 401 |
| Authorized initiation | HTTP 200, keys `type` + `conversation_config_override` |
| Prompt | Contains E. CALLER IDENTITY, CALLER CONTEXT, live show tools |
| `get_public_shows` | HTTP 200, `ok: true`, count 5 |
| `get_show_brands` | HTTP 200, `ok: true` |
| Resolver nonsense phone/name/child | `not_found` |
| JFS configured | true; `lastReadFailed` false after those lookups |
| Live phone-group counts (digits only, no numbers printed) | 517 unique groups, 129 ambiguous groups (276 rows) — matcher can return both unique and ambiguous |

No JFS INSERT/UPDATE/DELETE. Bitrix not touched.

## Safety

- JFS: read-only SELECTs
- Bitrix: not used, no credentials, no REST
- Instagram Assistant behavior unchanged except shared `JfsReadService` additive identity methods
- Existing public Voice tools unchanged
- Node Custom LLM, Twilio, ElevenLabs dashboard unchanged
- Logs: `voice.identity.resolved` with status / match_method / match_count only

## Commit hash

Recorded after git commit on `main`.
