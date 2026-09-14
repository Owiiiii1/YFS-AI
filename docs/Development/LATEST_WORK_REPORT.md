# Latest Work Report

## Task

Diagnose and fix Gemini HTTP 400 when Custom LLM requests include Voice tools (`hasTools=true`), which blocked live tool calling before Laravel execute.

## Status

Fixed in code and verified by automated tests plus a server-side Gemini → Laravel → Gemini smoke. Production `yfs-voice-runtime` is still on the previous process until a systemd restart. Live phone proof is still required after that restart.

Exact Gemini 400 on the first tools request:

`code=400` `status=INVALID_ARGUMENT`
`Invalid JSON payload received. Unknown name "additionalProperties" at 'tools[0].function_declarations[0].parameters': Cannot find field.`

After stripping `additionalProperties`, the follow-up request failed until `thoughtSignature` was replayed:

`Function call is missing a thought_signature in functionCall parts.`

## Commit

Recorded after `git commit` / `git push origin main`.

## Files changed

- `voice-runtime/src/llm/gemini-error.ts`
- `voice-runtime/src/llm/gemini-schema.ts`
- `voice-runtime/src/llm/gemini-convert.ts`
- `voice-runtime/src/llm/gemini-provider.ts`
- `voice-runtime/src/logger.ts`
- `voice-runtime/src/custom-llm/chunks.ts`
- `voice-runtime/src/smoke-gemini-tools.ts`
- `voice-runtime/test/gemini-schema.test.ts`
- `voice-runtime/test/llm-provider.test.ts`
- `voice-runtime/package.json`
- `docs/Development/LATEST_WORK_REPORT.md`

Laravel Voice tool contract was not changed.

## Architecture

Gemini FunctionDeclaration.parameters is a protobuf JSON Schema subset. OpenAI/JSON Schema keywords are stripped in the Gemini provider adapter (`openaiJsonSchemaToGemini`), not in Laravel `VoiceToolInterface`.

Gemini 3.8 function-call follow-ups require the model `thoughtSignature` on the replayed `functionCall` part. voice-runtime now preserves it internally and does not treat it as an ElevenLabs tool payload.

## Tests

voice-runtime `npm test`: 37 passed, including schema sanitizer regression and Gemini 400 body logging.

`npm run typecheck` and `npm run build`: passed.

Laravel `php artisan test --filter VoiceTool`: 9 passed.

## Runtime verification

Direct Gemini probe (same test-tool schema the gateway sends):

1. First generateContent with tools: HTTP 200, `functionCall.name=get_current_yfs_test_context`
2. Laravel `voice.tool.requested` / `voice.tool.completed`
3. Second Gemini round with functionResponse: final text streamed
4. Loop smoke: `rounds=2`, `requested=true`, `completed=true`, `hasFinalText=true`

Production systemd unit was not restarted (sudo password required). Dist is built and waiting.

## Live test required

Yes, after `sudo systemctl restart yfs-voice-runtime`.

Phrase: **What is the YFS test event?**

Expect `voice.tool.requested`, `voice.tool.completed`, `custom-llm.stream.completed` with `rounds=2`. Spoken answer should stay test-only (YFS Test Event / Voice tool calling is working).

## Known limitations

- Live telephone tool calling is still Unverified until the restart + phone call.
- Temporary test-event guardrail is still in the Custom LLM system prompt.
- YFS Core / Bitrix production tools remain Planned.

## Next recommended step

Restart systemd, then repeat the inbound call with **What is the YFS test event?**
