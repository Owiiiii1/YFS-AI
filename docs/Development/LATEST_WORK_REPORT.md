# Latest Work Report

## Task

Minimal Laravel Voice Orchestrator and server-side test-tool calling on the production Custom LLM path, without YFS Core or Bitrix24.

Prove:

ElevenLabs → Custom LLM gateway → Gemini → tool call → Laravel Voice Orchestrator → test tool → tool result → Gemini → SSE back to ElevenLabs.

## Status

Implemented and covered by automated tests. Laravel execute endpoint smoked on production HTTPS. Live telephone tool calling is **not** confirmed until the user places a real inbound call. Twilio routing and ElevenLabs agent configuration were not changed.

## Commit

`e1fd5fd97ba8c51d945185afc7defebe0fb28d54` on `main`

Add Laravel Voice Orchestrator and server-side test tool calling.

## Files changed

Laravel:

- `app/Services/Voice/VoiceOrchestrator.php`
- `app/Services/Voice/Tools/VoiceToolInterface.php`
- `app/Services/Voice/Tools/VoiceToolRegistry.php`
- `app/Services/Voice/Tools/TestVoiceTool.php`
- `app/Services/Voice/Exceptions/UnknownVoiceToolException.php`
- `app/Services/Voice/Exceptions/VoiceToolExecutionException.php`
- `app/Http/Controllers/Api/VoiceToolController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `tests/Unit/Voice/VoiceToolRegistryTest.php`
- `tests/Feature/VoiceToolExecuteTest.php`

voice-runtime:

- `voice-runtime/src/custom-llm/handler.ts`
- `voice-runtime/src/custom-llm/tool-loop.ts`
- `voice-runtime/src/custom-llm/chunks.ts`
- `voice-runtime/src/voice-tools/catalog.ts`
- `voice-runtime/src/voice-tools/laravel-client.ts`
- `voice-runtime/src/config/system-prompt.ts`
- `voice-runtime/test/custom-llm.test.ts`
- `voice-runtime/test/voice-tools.test.ts`
- `voice-runtime/package.json`

Docs:

- `docs/VOICE_ARCHITECTURE.md`
- `docs/VOICE_ASSISTANT.md`
- `docs/Development/LATEST_WORK_REPORT.md`

## Architecture

Internal contract: `name`, `description`, JSON Schema `inputSchema`, `execute(array $arguments): array`. Results are JSON-serializable. No credentials in schema or result.

Test tool `get_current_yfs_test_context` returns synthetic test-only data (`source: yfs_ai_test`, `availability: test-only`). It is not a production event, customer, or CRM record.

Internal API (same Bearer token as voice-runtime config: `VOICE_RUNTIME_INTERNAL_TOKEN`):

- `GET /api/internal/voice/tools`
- `POST /api/internal/voice/tools/execute` with `{ "tool", "arguments" }` → `{ "ok", "tool", "result" }`
- unknown tool: controlled `404` JSON
- execution error: controlled `500` JSON
- missing/wrong auth: `401`

voice-runtime execution loop:

1. Public contract unchanged: `POST /voice-engine/v1/chat/completions`, model label `yfs-bot-runtime`.
2. YFS tool schema is merged into the Gemini request even if ElevenLabs omitted it.
3. Gemini `functionCall` is converted to OpenAI `tool_calls`.
4. If the name is a YFS server tool, voice-runtime POSTs to Laravel and **does not** stream those `tool_calls` to ElevenLabs.
5. The JSON result is appended as a tool/function response; Gemini is called again.
6. Final assistant text is streamed as OpenAI-compatible SSE.
7. ElevenLabs native tools such as `end_call` are still forwarded, not executed by Laravel.
8. Temporary gateway guardrail (not production prompt architecture): if the caller asks “what is the YFS test event”, Gemini must call `get_current_yfs_test_context`.

YFS Core DB, Bitrix24, and write actions are not connected.

## Tests

Laravel (`php artisan test --filter VoiceTool`): 9 passed.

- registry returns known tool
- unknown tool rejected
- successful test-tool execution
- internal auth required
- unknown tool HTTP 404 JSON
- tool exception HTTP 500 JSON without secret leak

voice-runtime (`npm test`): 31 passed, including:

- Gemini functionCall parsed
- Laravel execute invoked
- tool result fed back to Gemini
- final text streamed, YFS `tool_calls` not leaked to ElevenLabs
- tool error handled without process crash
- cancellation still works (upstream disconnect and during tool execution)

Typecheck: `tsc --noEmit` passed.

## Runtime verification

- `POST https://ai.youngfashionshow.com/api/internal/voice/tools/execute` returned HTTP 200 with `ok=true`, tool `get_current_yfs_test_context`, `event_name=YFS Test Event`, `source=yfs_ai_test`.
- `http://127.0.0.1:3101/health` and public `/voice-engine/health`: `provider=gemini`, `model=gemini-3.8-flash`, `custom_llm=ready`.
- systemd `yfs-voice-runtime` was left inactive after a clean SIGTERM (`Restart=on-failure` does not restart on SIGTERM). A detached `node dist/index.js` is currently serving `127.0.0.1:3101` so the phone test can proceed. Restore supervision with `sudo systemctl start yfs-voice-runtime` when convenient.
- Twilio routing was not changed. ElevenLabs agent configuration was not changed.

## Live test required

This stage is **not fully confirmed** until a real inbound telephone call.

Phrase to say:

**What is the YFS test event?**

Log events to look for (journal / Laravel log; no tokens, no Authorization headers, no provider keys):

- `voice.tool.requested` — `tool=get_current_yfs_test_context`, `requestId`, later `latency`
- `voice.tool.completed` — `status=ok`, `tool=get_current_yfs_test_context`
- `custom-llm.stream.started` / `custom-llm.stream.completed` with `rounds=2` on a successful tool round
- If it fails: `voice.tool.failed` with `status` / latency, still no secrets

Expected spoken answer (paraphrase is OK; facts must stay test-only):

The assistant should say this is a **test-only** YFS Test Event / that Voice tool calling is working. It must not present this as a real production show or CRM record.

## Known limitations

- Live telephone tool calling is Unverified.
- Only one tool exists, and it is explicitly test-only.
- Temporary “YFS test event” guardrail is in the Custom LLM system prompt; remove after the live proof.
- Production business tools, YFS Core, and Bitrix24 remain Planned.
- Post-call persistence / Voice admin remain Planned.
- Speech Engine legacy code was not deleted and is not production routing.

## Next recommended step

User places a real inbound call and says **What is the YFS test event?** Confirm `voice.tool.completed` and the spoken test-only reply. After that, remove the temporary test rule and design the first real read-only YFS Core tool.
