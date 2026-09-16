# Latest Work Report

## Task

Complete the production Voice identity loop: Native Agent webhook tool `resolve_customer_identity` for unknown/ambiguous/other-number callers, bind UNIQUE results to the current `VoiceContact`, and document native ElevenLabs filler / pre-tool speech. Bitrix24 not connected. No JFS writes. No new secrets.

## Status

Done. Laravel endpoint is live. ElevenLabs dashboard was **not** changed from this repo; paste JSON from `docs/Voice/CUSTOMER_IDENTITY.md` and filler steps from `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.

## Endpoint

`POST https://ai.youngfashionshow.com/api/voice/tools/resolve-customer-identity`

Auth: existing `AuthenticateElevenLabsTool` / `ELEVENLABS_TOOL_TOKEN`. Same secret as `get_public_shows` / `get_show_brands`. Do not create a new token.

## Request / response contract

LLM body: `name` and `child_name` (optional strings; at least one required), optional `on_behalf_of` boolean.

Trusted session fields (ElevenLabs system dynamic variables only; never LLM `phone` / `caller_id`):

- `system__caller_id`
- `system__conversation_id`

Lookup always uses `CustomerIdentityResolver::resolveBySpokenHints()`. Matching logic was not duplicated.

| Case | `ok` | `status` | `next_action` | Extra |
| --- | --- | --- | --- | --- |
| Unique | true | `unique` | `identified` | `customer.display_name` only |
| Unique, existing different identity kept | true | `unique` | `already_identified` | existing `display_name` |
| Ambiguous, no child | true | `ambiguous` | `ask_child_name` | none |
| Ambiguous after child | true | `ambiguous` | `ask_additional_identifier` | none |
| Not found | true | `not_found` | `ask_again_or_continue_without_identity` | none |
| JFS down | false | `source_unavailable` | `continue_without_identity` | none |
| Missing name and child | false | `invalid_request` | `ask_name` | none |
| Bad/missing Bearer | HTTP 401 | — | — | `{"message":"Unauthorized"}` |

Never in the ElevenLabs JSON: phone, email, internal ids, candidate lists, children lists, payments, CRM dumps, secrets.

## Binding strategy

UNIQUE results persist compact `metadata.yfs_customer` via existing `VoiceCustomerIdentityStore` (`bindUnique`) when a trusted session identifier resolves a `VoiceContact`:

1. `system__conversation_id` → contact that stored that id at initiation
2. else `system__caller_id` → `VoiceContactDirectory::findOrCreateFromCallerId`

Initiation still uses `caller_id`. When ElevenLabs also sends `conversation_id`, it is stored on `voice_contacts.metadata.elevenlabs_conversation_id`. Initiation JSON contract is unchanged.

If no trusted session id is present, lookup still returns status to the agent; persistence is skipped.

The calling number is not written to YFS/JFS. `app_users.phone` is never updated.

Ambiguous / not_found do not bind and do not create a fake unique identity. `source_unavailable` does not destroy an existing unique identity.

## Identity override safety

- Initiation UNIQUE identity is current for this caller.
- Spoken resolution is for unknown / ambiguous / other-number personal questions.
- A later unique spoken match for a **different** `app_user_id` does not silently replace the stored identity (`next_action: already_identified`).
- Replacement only when the caller explicitly says they are calling for another registered parent/family (`on_behalf_of: true`).
- Ordinary mention of a name is not a reason to call the tool or switch identity (wrapper v5).

## Exact ElevenLabs manual setup

This repo does not change the dashboard.

1. Agents → YFS Voice Assistant → Tools → create webhook tool (or JSON editor).
2. Paste the full JSON in `docs/Voice/CUSTOMER_IDENTITY.md` (includes `response_timeout_secs: 20`).
3. Set Authorization to the **existing** workspace secret already used by `get_public_shows` (replace `secret_id` placeholder). Do not create a new secret.
4. Confirm `system__caller_id` and `system__conversation_id` are **dynamic variables**, not LLM description fields.
5. Assign the tool to the agent. Do not add an LLM `phone` parameter.

Filler / waiting speech (2–3 minutes): `docs/Voice/ELEVENLABS_TOOL_FILLER.md`

- `resolve_customer_identity`: `pre_tool_speech: force`, optional `tool_call_sound: typing` with `auto`
- `get_public_shows` / `get_show_brands`: `pre_tool_speech: auto` (do not force filler on instant lookups)
- Per-tool, not agent-wide
- Phrases follow the current conversation language; examples in wrapper v5 (RU/EN/UK)
- Not implemented in Laravel, not an extra LLM call, no sleep

## Prompt

`VoiceAssistantPromptBuilder` wrapper **v5**, section **E. CALLER IDENTITY**:

- Public questions do not require identity and must not call this tool
- Unique caller is not re-asked for a name
- Unknown personal caller asks name, then the tool
- Ambiguous asks child name, then the tool again
- not_found / source_unavailable are not automatic escalation

## Tests

PHPUnit (no SQLite install; DB feature tests skip without `pdo_sqlite`):

TOOL: unique / ambiguous / not_found / name+child resolves / still ambiguous / source_unavailable / missing name+child / auth 401

BINDING: unique binds when trusted contact exists; ambiguous does not bind; not_found does not create fake identity; source_unavailable does not destroy existing unique; existing unique is not replaced

PROMPT: public questions skip identity; unknown asks name; ambiguous asks child; unique is not re-identified

REGRESSION: `get_public_shows`, `get_show_brands`, conversation initiation, preferred language, post-call language tests remain green

Voice-related run: 128 passed, 20 skipped (sqlite).

## Production smoke (no PII printed)

`php artisan optimize` on this host.

| Check | Result |
| --- | --- |
| Unauthorized identity tool | HTTP 401 |
| Wrong token | HTTP 401 |
| Empty body | HTTP 200, `invalid_request` / `ask_name` |
| Nonsense name | HTTP 200, `not_found`; no customer/phone/email/id keys |
| `get_public_shows` | HTTP 200, `ok: true`, count 5 |
| `get_show_brands` | HTTP 200, `ok: true` |
| POC `test-context` | HTTP 200 |
| Conversation initiation | HTTP 200, keys `type` + `conversation_config_override` |
| JFS | configured; `lastReadFailed` false after lookups |

## Safety

- JFS: read-only SELECTs. No INSERT/UPDATE/DELETE.
- Bitrix: not used, no credentials, no REST, untouched
- Existing Voice tools `get_public_shows` and `get_show_brands` unchanged (new route/controller only)
- Instagram / JFS Core write path / Twilio / Node voice-runtime / ElevenLabs dashboard unchanged
- Logs: `voice.tools.resolve_customer_identity` with `ok` / `status` / `match_method` / `match_count` / `bind` only

## Commit hash

pending
