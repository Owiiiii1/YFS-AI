# Latest Work Report

## Task

Diagnose why ElevenLabs Native Agent did not call `get_customer_context` on a real identified-caller question about registered children / last event.

## ROOT CAUSE

`get_customer_context` was **NOT CALLED** during the last real call.

Initiation ran, wrapper **v7** was already on this host, CALLER CONTEXT was **unique** with a bound YFS `app_user_id`, and the same bound contact can load customer context from JFS (`status=ok`, `children_count=2`, `participations_count=2`).

The model answered from **Missing Dynamic Fact + Bot Settings App / Help Center** policy instead of invoking the webhook. Section G existed in v7 but did not outrank those fallbacks.

Laravel does **not** send the tool list on conversation initiation. Tools exist only in the ElevenLabs Agent dashboard. This repo cannot prove the dashboard tool was attached to that conversation; it can prove the HTTP tool was never requested.

## Last real call (no PII)

- Last `voice_calls` row: `2026-09-17 14:55:15Z` start, `14:56:16Z` post-call, duration 54s, status `done`
- Sep 18 current nginx access log: **0** Voice API hits (today’s tests did not hit this Laravel)

## get_customer_context CALLED / NOT CALLED

**NOT CALLED** in the last real call window (`14:55:15Z`–`14:56:16Z`).

Same day, one `POST /api/voice/tools/customer-context` **200** at `13:12:08Z` (outside that conversation). Other tools in that log: initiation, post-call, extended-identity (earlier). No `customer-context` between initiation and post-call of the last call.

Laravel `LOG_LEVEL=error`, so `Log::info` tool events are not in `laravel.log`. Nginx path+status used instead. Tokens/PII not copied.

## initiation v7 YES/NO

**YES.** Initiation `POST /api/voice/elevenlabs/conversation-initiation` **200** at `14:55:15Z`. Wrapper v7 was committed `12:23:52Z` the same day (before this call). v7 includes **G. CUSTOMER CONTEXT** and “call `get_customer_context` before answering”. Prompt body with PII was not logged.

## identity unique YES/NO

**YES.** Bound `metadata.yfs_customer.status=unique`, `match_method=phone`, canonical YFS `app_user_id` present. Conversation id stored on that VoiceContact. `preferred_language=ru`.

## backend customer context

Invoked `GetCustomerContextVoiceTool` for that bound conversation (no HTTP, no PII printed):

- `status=ok`
- `children_count=2`
- `participations_count=2`
- JFS read failed: no

## prompt conflict

**YES.** Immutable B + enabled Bot Settings (General / App / Help Center / Self-Service) tell the model that participant facts live in the YFS App. That ran **before** the tool. Public-show tools were not the issue.

## What was fixed

Wrapper **v8** (initiation prompt only):

- Runtime tool rules outrank App / Help Center fallbacks
- B: do not use App fallback for identified caller children/registrations/packages/history until `get_customer_context` ran
- G + unique CALLER CONTEXT: ALWAYS call `get_customer_context` before Missing Dynamic Fact / App / Help Center; only then fallback; not for public calendars

No identity architecture change. No ElevenLabs dashboard API change. No JFS/Bitrix writes.

## Tests

`php artisan test --filter VoiceAssistantPromptBuilderTest|VoiceContextEndpointDatabaseTest|ElevenLabsGetCustomerContextTest|GetCustomerContextVoiceToolTest|ElevenLabsConversationInitiation`

26 passed, 13 skipped (`pdo_sqlite`). New regressions: identified caller + children / participation history must place `ALWAYS call get_customer_context…` **before** App/unavailable policy text.

## Commit hash

`45ba32cb2811d3d8c27cee46703b5db403066470` on `main`

## ElevenLabs manual

- Prompt override is Laravel initiation: next call gets **v8** automatically.
- Tools are **dashboard-only**. Keep `get_customer_context` on the published agent (same URL/Bearer as other Voice tools). This repo cannot attach it.
- No other dashboard field must change for this prompt fix.
- PHP-FPM opcache: reload workers if the next initiation is still v7.

## Production confirmation

No schema, no new endpoints, no YFS/Bitrix writes, no Instagram / neighbor-project changes.
