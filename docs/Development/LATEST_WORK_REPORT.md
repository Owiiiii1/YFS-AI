# Latest Work Report

## Task

Voice Human Follow-up: live `request_human_followup` webhook, Telegram via the existing Instagram bot/group, and post-call safety net.

## Architecture implemented

Twilio → ElevenLabs Native Agent → Laravel `POST /api/voice/tools/request-human-followup` → `voice_followups` → same `TelegramBotService::sendChannelText` as Instagram.

After `post_call_transcription` persist: `AnalyzeVoiceCallJob` (database queue) → structured `voice_call_analyses` → if the agent promised a callback / human follow-up and no live row exists, create a recovered follow-up and notify Telegram once.

Laravel still does **not** inject tools on initiation. `request_human_followup` must be pasted into the ElevenLabs dashboard and published.

## Migration / schema

`2026_09_18_220000_create_voice_followups_and_voice_call_analyses_tables` — **ran** on production.

`voice_followups`: nullable `voice_call_id`, `voice_contact_id`, unique `elevenlabs_conversation_id`, `department`, `reason`, `status` (`open|completed|cancelled`), callback fields, optional names/city/summary, `source=voice`, `telegram_sent_at`, `metadata`.

`voice_call_analyses`: unique `voice_call_id`, intent, department, flags, summary, `unresolved_questions` JSON.

## Live tool contract

`POST /api/voice/tools/request-human-followup` + `AuthenticateElevenLabsTool`.

Trusted: `system__conversation_id`, `system__caller_id`. LLM: `department`, `reason`, `callback_requested`, `callback_phone`, `preferred_callback_time`, `customer_name`, `child_name`, `show_city`, `summary`. Internal ids ignored.

Responses (no ids):

- `{ok:true,status:created,department}` — saved and Telegram delivered
- `{ok:true,status:already_created,department}` — same conversation, Telegram already sent
- `{ok:false,status:queued,department}` — saved, Telegram not delivered; agent must **not** confirm transfer
- `{ok:false,status:failed}` — no conversation / invalid department or reason

Unknown Sales leads do not need YFS identity. Dictated `callback_phone` wins; otherwise a valid E.164 trusted caller number may be used. Never invent a number.

## Telegram reuse

Same Settings Telegram bot + group/topic. No new credentials. Instagram `BotOutcomeService` unchanged. Voice formatter omits empty lines and internal ids. Optional admin URL `/call-center?call={id}` only when `voice_call_id` is known (usually post-call).

## Delivery semantics

Confirm to the caller only when `ok=true`. `queued` keeps the row for retry (`telegram_sent_at` null). One Telegram send per follow-up.

## Idempotency

One follow-up per `elevenlabs_conversation_id` (`lockForUpdate`). Duplicate live tool → `already_created`, no second Telegram. Safety net does not insert a second row if a live row exists.

## Post-call analyzer

`AnalyzeVoiceCallJob` (`ShouldBeUnique` per call) dispatched after persist; does not block the ElevenLabs webhook. Uses existing `AiReplyGenerator` / `bot_runtime`. No new API keys. Failures are logged; analysis defaults to no follow-up rather than inventing one.

## Safety-net behavior

A. Live follow-up exists → store analysis, no duplicate row/Telegram (retry first send only if `telegram_sent_at` is null).  
B. Agent promised callback / human follow-up required and no live row → create `metadata.created_by=post_call_safety_net`, Telegram header `⚠️ Voice · Follow-up recovered after call`.  
C. Ordinary call → analysis only, no follow-up.

## Prompt

Wrapper **v9** section **H. HUMAN FOLLOW-UP ACTION**. Never promise transfer before `ok true` + `created|already_created`. SALE → CONTRACT → CUSTOMER SUPPORT for department.

## Tests / results

Focused (sqlite in-memory, `pdo_sqlite` loaded only in the test process):

- `ElevenLabsRequestHumanFollowupTest` — 6 passed (unknown lead, no identity, idempotent Telegram, queued on Telegram failure, dictated phone, auth)
- `VoiceCallFollowupSafetyNetTest` — 4 passed (A/B/C + job)
- `VoiceFollowupTelegramFormatterTest` — 1 passed
- Prompt v9 + confirmation-before-tool regression
- Post-call webhook still dispatches `AnalyzeVoiceCallJob` (`Queue::fake`)

**11 passed / 92 assertions** on those filters.

Full suite with sqlite: **328 passed**, **8 failed**, **5 errors**. Failures are **pre-existing** (Inertia page components missing in test, `BotPromptPatchServiceTest` forbidden paths, `Monolog\Logger::fake()`). None are in the new follow-up files.

`phpunit.xml` now `force="true"` on `APP_ENV` / `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` so tests cannot use production MySQL.

No automated test sent Telegram to the manager group (bot mocked).

## Production deployment status

- Migration applied
- `route:clear` + `config:clear` during deploy; `php artisan optimize` after commit
- Endpoint live: `POST /api/voice/tools/request-human-followup`
- Two `AnalyzeVoiceCallJob` rows had failed earlier (queue worker booted before the analyzer binding). Retry after worker restart
- PHP-FPM picks up wrapper v9 via opcache timestamp revalidation

## Files changed

Live path: migration, `VoiceFollowup` / `VoiceCallAnalysis`, recorder/notifier/formatter, `RequestHumanFollowupVoiceTool` + controller, routes, registry, prompt v9, post-call job/analyzer/safety net.

Docs: `docs/Voice/HUMAN_FOLLOWUP.md`, live tools list, DATABASE, VOICE_*, EXTERNAL_SERVICES, CUSTOMER_IDENTITY.

Tests as above.

## Security review

No Telegram / ElevenLabs / Bitrix / AI keys in git or tool JSON. No JFS/Bitrix writes. No Instagram behavior change. LLM cannot pass internal ids. Callback phone is E.164 only. Logs: follow-up id / status, not tokens or full prompts with secrets. Telegram formatter has no conversation ids.

## Exact ElevenLabs manual configuration

Paste from `docs/Voice/HUMAN_FOLLOWUP.md` onto the **published** Twilio agent:

- Name `request_human_followup`
- URL `https://ai.youngfashionshow.com/api/voice/tools/request-human-followup`
- Same Bearer secret as other Voice tools
- `system__*` = Dynamic Variables; business fields = LLM Prompt
- `response_timeout_secs=15`, `pre_tool_speech=force`, execution immediate (not async)
- Publish

Until that is done, live calls still will not call the tool; post-call safety net can still recover.

## Known limitations

- Tools are dashboard-only (initiation prompt override has no tool list)
- Call Center UI for analysis/follow-up status is not in this pass (columns exist)
- Post-call quality depends on `bot_runtime` JSON extract
- `queued` requires a later tool retry or safety-net retry to finish Telegram
- Host PHP CLI has no `pdo_sqlite`; full sqlite suite needs the module loaded in-process (as in this run)

## Commit hash

`d52d63be31603cce05b510bcf9f5b953bc92a7f8` on `main`

## ElevenLabs manual

**Yes — required** to attach `request_human_followup` to the published agent. Dashboard not changed from this repo.
