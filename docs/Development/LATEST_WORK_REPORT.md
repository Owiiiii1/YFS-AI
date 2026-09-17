# Latest Work Report

## Task

Add the first personal YFS Core Voice tool, `get_customer_context`, so an already identified caller can be answered about their children and show participations.

## Status

Done. Read-only. Bound unique Voice identity required. Bitrix is not a context source. No Instagram / neighbor-project / ElevenLabs dashboard code changes. `resolve_customer_identity` and public show tools unchanged.

## Commit hash

`ee4e64913cc95b0779f5f29abd52bb71347cbf8b`

## Real JFS schema / relations (verified)

Live `information_schema` plus `/var/www/jfs` models (read-only). No customer rows were copied into this report.

| Table | Link | Voice-relevant columns used |
| --- | --- | --- |
| `app_users` | canonical customer when `role = client` and not `blocked` | `id` (internal only), `name`, `language` |
| `children` | `client_app_user_id` → `app_users.id` | `id` (join only), `first_name` |
| `child_event_assignments` | unique `child_id + event_id` | `status`, `family_look`, `package_template_id` |
| `events` | `child_event_assignments.event_id` | `name`, `city`, `starts_at`, `client_show_event_date` |
| `package_templates` | `child_event_assignments.package_template_id` | `name` |

Existing `JfsReadService` already had public events/brands and identity lookups. It did **not** load a customer snapshot. `findClientByEmail()` returns phone/email and is not used by this tool.

## Personal data included

- Customer `display_name` from `app_users.name`
- `preferred_language` from `app_users.language` when set
- Children `display_name` from `children.first_name`
- Participations nested under each child: show, city, date (only if the public date flag allows it), `date_announced`, `is_past`, assignment `status`, `category: family_look` only when that boolean is true, per-assignment `package` name when present
- Top-level `customer.package` only if every returned participation shares one package name

## Intentionally excluded

Phones, emails, passwords / `client_password_display`, contracts, notes, gender, birthdate, ChildData measurements/photos, badge/QR/checkin codes, `payment_status` and all payment tables, brand assignment ids, rehearsal slots/bookings, tickets/parking/meals, stage plans, staff/supervisor ids, Bitrix, internal DB ids in the ElevenLabs JSON.

## Security model

```text
ElevenLabs system__conversation_id
  → existing VoiceContact (no create)
  → metadata.yfs_customer status=unique + app_user_id
  → JfsReadService::loadCustomerContext(app_user_id)
```

If conversation id misses, existing `system__caller_id` contact is used. Conversation match wins; caller id cannot swap a bound conversation. LLM `customer_id` / `name` / `email` / `phone` / `child_id` are not read for lookup. The HTTP controller only forwards trusted system ids.

No unique bound identity → `identity_required` (no children). YFS read failure → `unavailable`. Bound id that is not a live client → `identity_required`.

Auth: existing `AuthenticateElevenLabsTool` / `ELEVENLABS_TOOL_TOKEN`.

## API contract

`POST /api/voice/tools/customer-context`  
Tool name: `get_customer_context`

Input (system dynamic variables only): `system__conversation_id`, `system__caller_id`.

OK: `{ ok: true, tool, status: "ok", customer, children }`  
Not identified: `{ ok: true, tool, status: "identity_required" }`  
YFS down: `{ ok: false, tool, status: "unavailable" }`

Participations live under each child (cleaner for “which shows did Mia do”).

## How the current customer is determined

The same VoiceContact unique bind already written by initiation phone match, `resolve_customer_identity`, or extended search (`VoiceCustomerIdentityStore`). This tool never identifies a stranger from a spoken name in the body.

## Prompt wrapper

Wrapper **v7**. New section **G. CUSTOMER CONTEXT**. Section E and unique CALLER CONTEXT tell the model to call `get_customer_context` before saying children/registrations/history are unavailable. A/B/C policy rules are unchanged. Public calendars still use `get_public_shows` / `get_show_brands`.

## Size / latency

- Queries: one user row, children for that parent, assignments+events+package names for those child ids.
- Upcoming/unannounced assignments kept; max **8** most recent past shows per child; global cap **24**.
- Typical payload is a few hundred bytes to a couple of KB. Fast JFS path; `pre_tool_speech: force` like identity.

## Files

Added: `GetCustomerContextVoiceTool`, `ElevenLabsGetCustomerContextController`, `docs/Voice/CUSTOMER_CONTEXT.md`, unit/feature tests.

Updated: `JfsReadService::loadCustomerContext`, `VoiceContactSessionResolver::findExistingTrusted`, `VoiceContactDirectory::findByCallerId`, FakeJfs, AppServiceProvider registry, `routes/api.php`, prompt builder v7, Voice docs.

## Tests / results

Relevant: GetCustomerContext (verified customer/children/participations, no children, several children, identity_required, body cannot select another customer, caller_id cannot replace conversation bind, YFS unavailable, no internal fields), session resolver, prompt v7, existing identity/public tools.

Filter run: **67 passed**, 3 skipped.

Full `php artisan test`: **327** tests, **277** passed, **46** skipped, **4** errors in `BotPromptPatchServiceTest` / `AiPromptAnalysisInfrastructureTest` (unrelated prompt-path / existing `gemini` row). Same unrelated failures as the previous identity change.

Production-safe smoke: authenticated `POST /api/voice/tools/customer-context` with no session returns `identity_required` and no names/phones/emails.

## Migration / config impact

**None.** No new tables or env keys. Existing Voice identity metadata is reused.

**Ops:** route cache was rebuilt on this host (`php artisan route:cache`) so the new URL is live. No `config:cache` change required for this feature.

## ElevenLabs (manual)

This repo does not change the dashboard. Paste the webhook tool JSON from `docs/Voice/CUSTOMER_CONTEXT.md`:

- URL `https://ai.youngfashionshow.com/api/voice/tools/customer-context`
- Reuse the existing Authorization secret
- `system__conversation_id` / `system__caller_id` as dynamic variables only
- `pre_tool_speech: force`, timeout 20s

Republish the agent so wrapper v7 is used (initiation already sends the Laravel prompt).

## Real call checklist

1. Known unique number: initiation still identifies.
2. “Какие на меня зарегистрированы дети?” after identity → tool `ok`, spoken child names, not “список недоступен”.
3. “В каких шоу участвовал мой ребёнок?” → participations; if several children, agent asks which child.
4. Unidentified caller asking the same → `identity_required` then existing name/child identity flow, then context.
5. Confirm ElevenLabs JSON has no ids/phones/emails and Bitrix is not queried.
