# Latest Work Report

## Task

Store the production Bitrix24 REST webhook outside Git, keep YFS Core as canonical Voice identity, add a read-only Bitrix fallback (exact phone/name → email → YFS), and add asynchronous extended identity search with start/status Native Agent tools. No Bitrix writes. No JFS writes. No new ElevenLabs secret. Dashboard was not changed from this repo.

## Status

Done on this host. Laravel is live with config cache. Paste new tool JSON from `docs/Voice/EXTENDED_IDENTITY_SEARCH.md`. Existing `resolve_customer_identity` JSON is unchanged unless the dashboard copy is missing system dynamic variables.

## Commit hash

See the follow-up report commit on `main` for the implementation SHA (recorded immediately after this file is first committed).

## Bitrix connection

- Env key: `BITRIX_REST_WEBHOOK_URL` (not in Git; `.env.example` empty placeholder only)
- Config: `services.bitrix.webhook_url`
- `configured=true`
- `connectivity=true`
- Scopes: `crm`, `telephony`, `call`, `user`
- Secret values are not in this report, logs, or ElevenLabs responses

## REST methods actually used

Allowlisted only:

- `scope` (connectivity)
- `crm.duplicate.findbycomm` (`type=PHONE`, CONTACT)
- `telephony.externalCall.searchCrmEntities` (CONTACT fallback)
- `crm.contact.list` (`%NAME`, optional `%LAST_NAME`, optional child UF)
- `crm.contact.get` (`ID`, `EMAIL` only)

Not used: `%PHONE`, `FIND`, deals, timeline, activities, companies, leads dump, files, comments, payments, contracts, any add/update.

## Sanitized latency (production smoke, unused synthetic lookups, no PII)

Bitrix REST:

| Metric | n | min ms | median ms | max ms |
| --- | --- | --- | --- | --- |
| `bitrix.identity.phone_lookup_ms` | 5 | 481 | 500 | 602 |
| `bitrix.identity.name_lookup_ms` | 4 | 409 | 675 | 1083 |
| `bitrix.identity.yfs_link_ms` (JFS email miss) | 3 | 0 | 0 | 10 |

Fast resolver totals (YFS then Bitrix, unused inputs, all `not_found`):

| Path | min ms | median ms | max ms |
| --- | --- | --- | --- |
| phone fast (`bitrix.identity.total_ms`) | 504 | 505 | 514 |
| name fast (`bitrix.identity.total_ms`) | 814 | 841 | 845 |

Fast budgets from these facts: initiation **1500 ms**, spoken tool **4000 ms**. Typical unused phone fallback is ~0.5s. Name fallback is ~0.8–1.1s. Unique Bitrix hits add `crm.contact.get` calls and were not timed against real contacts.

## Fast resolver flow

```text
YFS first
  UNIQUE → YFS identity, Bitrix not called
  phone AMBIGUOUS → keep YFS ambiguous (Bitrix must not pick)
  source_unavailable → keep YFS result
  otherwise Bitrix exact phone or controlled name
    unique contact → emails inside Laravel → findClientByEmail()
      UNIQUE YFS → existing CustomerIdentityResult UNIQUE
      else not a confirmed YFS customer
```

Initiation uses `resolveByPhoneFast()`. `resolve_customer_identity` uses `resolveBySpokenHintsFast()`. Public contract is unchanged.

## Bitrix → YFS linkage

Email only, server-side. Never returned to ElevenLabs. Several emails must collapse to one YFS client or the result is ambiguous. A Bitrix contact without a unique YFS email match is not authenticated identity.

`BitrixYfsLinker` is bound in `AppServiceProvider` so the container actually injects it (nullable constructor defaults were skipping autowire).

## Async architecture

ElevenLabs Native Agent has no supported Laravel push into a live Twilio conversation (`execution_mode: async` only unblocks the same HTTP call; `client_tool_result` needs the client SDK socket). Option B:

- Table `voice_identity_searches`
- Job `RunExtendedVoiceIdentitySearchJob` on the existing database queue (`queue:work` already running)
- `POST /api/voice/tools/start-extended-identity-search` → `{status: searching}`
- `POST /api/voice/tools/extended-identity-search-status` → searching / unique / ambiguous / not_found / failed
- UNIQUE binds through existing `VoiceCustomerIdentityStore::bindUnique()` and will not overwrite a different unique identity

Prompt wrapper **v6**, section **F. EXTENDED IDENTITY SEARCH**.

## ElevenLabs manual changes required

This repo does not change the dashboard.

1. Keep `resolve_customer_identity` as documented in `docs/Voice/CUSTOMER_IDENTITY.md` (`pre_tool_speech: force`).
2. Add `start_extended_identity_search` and `get_extended_identity_search_status` from `docs/Voice/EXTENDED_IDENTITY_SEARCH.md`.
3. Reuse the existing Authorization secret. Do not create a new token.
4. `system__caller_id` / `system__conversation_id` must be system dynamic variables.
5. Filler: start `pre_tool_speech: auto`; status `off`. Fast identity still uses “Секунду, сейчас посмотрю.” Extended search must say it may take a little time and continue the conversation.

## Tests

PHPUnit (no SQLite install; DB feature tests skip without `pdo_sqlite`):

Bitrix client: phone unique / ambiguous / not_found; HTTP error; timeout; malformed; rate limit; write methods not allowlisted; no `%PHONE`/`FIND`.

Linkage: unique email → YFS; missing email; unknown email; two YFS emails → ambiguous; JFS unavailable.

Resolver: YFS unique skips Bitrix; YFS miss + Bitrix email → YFS unique; Bitrix without email not unique; Bitrix email not in YFS; multiple Bitrix candidates; YFS phone ambiguous does not let Bitrix pick; Bitrix failure falls back; name miss → Bitrix → YFS; Bitrix contact alone never YFS unique; container injects client+linker.

Async: start searching; running→searching; unique display_name only; ambiguous/not_found/failed; bind VoiceContact; do not overwrite different identity; runner failed does not bind.

Security: tool JSON has no phone/email/internal ids/raw CRM; auth 401 on new routes.

Regression: public shows/brands, initiation, preferred language / post-call, prompt v6, Node-facing filler rules.

Voice-related run: **140 passed**, 30 skipped (no `pdo_sqlite`; SQLite was not installed).

## Production smoke (no PII printed)

`php artisan migrate --force` (`voice_identity_searches`) and `php artisan optimize` on this host.

| Check | Result |
| --- | --- |
| Bitrix configured / connectivity | true / true; scopes crm, telephony, call, user |
| Unauthorized identity / start / status | HTTP 401 |
| Wrong token | HTTP 401 |
| Empty identity body | HTTP 200, `invalid_request` |
| Nonsense name | HTTP 200, `not_found`; no email/phone/id keys |
| `get_public_shows` | HTTP 200, `ok: true`, count 5 |
| `get_show_brands` | HTTP 200, `ok: true` |
| POC `test-context` | HTTP 200 |
| Conversation initiation | HTTP 200, keys `type` + `conversation_config_override` |
| JFS | configured; `lastReadFailed` false; 5 public events |
| Queue worker | existing `yfs-ai` `queue:work` running |

## Safety

- Bitrix: **read-only**. Allowlist only. No contact/deal/lead/timeline/activity/telephony writes.
- JFS: **read-only** SELECTs. No INSERT/UPDATE/DELETE.
- YFS identity remains canonical. Bitrix contact_id is not Voice proof of identity.
- ElevenLabs never receives raw CRM, phones, emails, candidate lists, or the webhook URL.
- Existing Voice tools, initiation, language memory, post-call, Instagram, Node fallback runtime, Twilio routing unchanged aside from identity fast-path + new optional tools.
- Logs: status, match_method, match_count, method name, milliseconds, sanitized error codes only.

## Unresolved limitations

- ElevenLabs dashboard still needs a manual paste of the two new tools.
- Live mutation of a running extended search when the caller adds a hint later is not in this version; an in-flight row is reused.
- Bitrix `crm.contact.get` latency on unique real contacts was not measured (no real-client PII in the smoke).
- Name search uses `%NAME` because most contacts on this portal have empty `LAST_NAME`; totals above 8 are treated as ambiguous.
- Phone is not unique in YFS or Bitrix; ambiguous stays ambiguous.
- pdo_sqlite is still absent; isolated DB feature tests skip rather than installing SQLite.
