# Latest Work Report

## LATEST CALL

prompt_version: **v8**
identity: **unique**
get_customer_context: **NOT_CALLED**
backend_result: not invoked on the call; later service check `status=ok` `children_count=2` `participations_count=2`
likely_breakpoint: **ElevenLabs published-agent tool attachment** (Laravel initiation sends prompt only, not tools; no Voice tool HTTP at all during this call)

Do **not** strengthen wrapper v8. The next check is manual in the ElevenLabs dashboard.

## Task

Verify the first real inbound call after wrapper v8. The agent again said personal children data was unavailable. Facts only. No architecture change. No production writes. No ElevenLabs API mutation.

## ROOT CAUSE

`get_customer_context` was **NOT CALLED** on this call.

Laravel did its job:

- conversation initiation webhook **200**
- wrapper **v8** was the live prompt builder (on disk since `12:43Z`, php-fpm workers recycled `16:06Z`, call `17:58Z`)
- CALLER CONTEXT **unique**, bound canonical YFS `app_user_id`, conversation id stored on the same VoiceContact
- `POST /api/voice/tools/customer-context` is registered and live
- the same bound conversation can load JFS context (`status=ok`, `children_count=2`, `participations_count=2`)

Native Agent never requested any Laravel Voice tool between initiation and post-call. Initiation override is **prompt + optional language only**. Tools are **not** injected by Laravel. This repo cannot prove the dashboard tool was attached to the published agent used by this Twilio conversation. It can prove the HTTP tool was never requested.

Post-call persistence also cannot show tool availability: transcript normalizer keeps only `user`/`agent` dialogue turns; `voice_calls.metadata` stores `agent_id` / status / language, not a tool list.

A dashboard Test Tool hit on `2026-09-17 13:12:08Z` already returned **200** for `customer-context`. The live phone path still does not call it.

## Last real call (no PII)

- `voice_calls` id **11** (11 rows total)
- start `2026-09-18 17:58:23Z`, end `17:58:58Z`, post-call persist `17:59:05Z`
- duration 35s, status `done`, language `ru`, inbound Twilio
- same VoiceContact as the previous failing call
- same ElevenLabs `agent_id` as that previous call (`sha256` prefix `53839c4600ff1ac3`)
- stored transcript: 5 dialogue turns; user turn mentions children/event words; last agent turn uses unavailable + App/Help language
- no tool-shaped turns stored

## 1. Initiation webhook

**YES.** nginx: `POST /api/voice/elevenlabs/conversation-initiation` **200** at `17:58:22Z`.

VoiceContact `metadata.elevenlabs_conversation_id` matches this call’s conversation id (only initiation writes that field).

## 2. Prompt version actually served

**v8.**

Evidence (initiation does not persist the prompt body; no PII prompt log):

- `VoiceAssistantPromptBuilder::WRAPPER_VERSION = '8'`
- file mtime `2026-09-18 12:43:14Z`
- opcache `validate_timestamps=On`, `revalidate_freq=2`
- php-fpm `www` workers started `2026-09-18 16:06Z` (after v8)
- reconstructing the current unique-identity prompt after the call: version `v8-8f8958fa…`, section **G. CUSTOMER CONTEXT** present, “ALWAYS call get_customer_context before applying Missing Dynamic Fact / App / Help Center fallback” present, `get_customer_context` appears 7 times

Not v7. Not another wrapper.

## 3. Identity

**unique.** `match_method=phone`. Canonical YFS `app_user_id` present. Session resolver finds the same VoiceContact by this conversation id.

## 4–5. `POST /api/voice/tools/customer-context`

**NOT_CALLED** between initiation `17:58:22Z` and post-call `17:59:05Z`.

No HTTP status / tool `status` / counts from the live call (there was no request).

Offline service invocation for the same bound conversation (no HTTP, no PII printed):

- `status=ok`
- `children_count=2`
- `participations_count=2`
- JFS read failed: no

## 6. Post-call tool visibility

**Unavailable in Laravel.**

Stored `metadata` keys: `type,status,agent_id,has_audio,phone_call,main_language,call_successful,event_timestamp,termination_reason`.  
`type=post_call_transcription`. `termination_reason=Call ended by remote party`. `call_successful=success`. No tool-call array.

Transcript extra keys: none. Tool roles are dropped before storage.

Laravel therefore **cannot** confirm whether Native Agent saw `get_customer_context` on this conversation.

## 7. Tools vs initiation

**Tools are not passed on initiation.** `ConversationInitiationClientData` returns only `type` + `conversation_config_override.agent.prompt` (+ `language` when known). No tool list.

Laravel Voice registry includes `get_customer_context`. That does not mean the published ElevenLabs agent has it.

## 8. Agent metadata

Same `agent_id` as the previous live call that also did not call this tool. Same Twilio inbound agent number present (not logged). No agent version / publish id in the webhook payload we persist.

## 9. nginx window (`18/Sep/2026` UTC)

| Time | Request | Status |
| --- | --- | --- |
| `17:58:22Z` | `POST /api/voice/elevenlabs/conversation-initiation` | 200 |
| `17:59:05Z` | `POST /api/voice/elevenlabs/post-call` | 200 |

No `customer-context`, `public-shows`, `show-brands`, `resolve-customer-identity`, or extended-identity in this window.  
Today’s Voice API hits: those two only.

Laravel `LOG_LEVEL` hides `Log::info` tool events. `laravel.log` has **0** lines in the call window. No 401/500 for this path.

## Prompt conflict

v8 priority text **was** in the builder for this call. Per the previous task: if v8 + unique + NOT_CALLED, **do not** strengthen the prompt again.

The remaining breakpoint is outside Laravel prompt text: the published ElevenLabs agent tool set (missing, unpublished, or a different agent/version than the dashboard draft where the tool was added).

## What was fixed

**Nothing in this pass.** No prompt change, no identity change, no new endpoints, no ElevenLabs dashboard API, no JFS/Bitrix writes.

## Concrete next fix (manual, not done here)

In ElevenLabs, open the **published** agent that owns this Twilio inbound (`agent_id` same as call 10 and 11):

1. Confirm webhook tool `get_customer_context` is attached to **that agent**, not only created in the workspace.
2. URL `https://ai.youngfashionshow.com/api/voice/tools/customer-context`, same Bearer as other Voice tools.
3. Publish that version. Confirm the phone number still points at the published version.
4. Re-test one identified-caller children question. Expect nginx `POST /api/voice/tools/customer-context` **between** initiation and post-call.

Optional later diagnostic (not implemented): persist post-call tool **names only** (no arguments/PII) so Laravel can see whether the agent had the tool.

## Tests

No new tests in this diagnostic pass. Existing v8 regressions remain on `main`.

## Commit hash

Recorded after git commit.

## ElevenLabs manual

**Yes — required.** Laravel cannot attach tools. Do not change dashboard automatically from this repo.
