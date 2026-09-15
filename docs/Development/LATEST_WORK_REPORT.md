# Latest Work Report

## Task

Correct YFS Voice Assistant decision rules so the bot answers from policy instead of escalating and collecting contacts after every question. Do not invent dynamic facts. Do not change initiation webhook contract, auth, ElevenLabs UI, YFS Core, or Bitrix.

## Status

Done.

Immutable `SYSTEM_WRAPPER` now contains runtime decision rules A/B/C. Wrapper version is `v2`, so the next inbound call through the existing initiation webhook receives the new prompt automatically.

## Reason

A live call showed the bot treating almost every question as missing information: it said it had no exact data, offered to check with the team, and asked for contact details.

## Old problematic behaviour

Missing dynamic data (and even known policy facts) were handled as if a human were required. Ordinary informational questions became a callback/lead flow.

## New decision rules

A. KNOWN POLICY FACT — answer from current bot settings. No team-check, callback, or contact collection.

B. MISSING DYNAMIC FACT — say the specific fact is not available; give the policy next step (usually App / Help Center). Not automatic escalation.

C. HUMAN REQUIRED — escalate / collect contacts only if the caller asks for a human/callback or policy requires a human.

Hallucinations remain forbidden (times, dates, addresses, prices, tickets, brands, participant/CRM data).

Client policy section bodies were not rewritten.

## Commit

See git history on `main` after push.

## Files

- `app/Services/Voice/Prompt/VoiceAssistantPromptBuilder.php` (`WRAPPER_VERSION` 1 → 2, stronger `SYSTEM_WRAPPER`)
- `tests/Unit/Voice/VoiceAssistantPromptBuilderTest.php`
- `tests/Feature/VoiceContextEndpointDatabaseTest.php` (version prefix)
- `docs/VOICE_ARCHITECTURE.md`
- this report

Initiation webhook controller, auth middleware, `/api/voice/context`, and `/api/voice/tools/test-context` were not changed.

## Tests

`php artisan test --filter VoiceAssistantPromptBuilderTest`: passed, including:
- answer-yourself / known policy fact language in wrapper
- missing dynamic data is not automatic escalation
- contact collection only when human is required
- do not invent dynamic facts
- enabled/disabled ordering still works
- section bodies not rewritten
- version prefix `v2-` because the wrapper changed

`php artisan test --filter ConversationInitiationClientDataTest`: passed.

`php artisan test --filter ElevenLabsConversationInitiationTest`: passed.

`php artisan test --filter VoiceContextEndpointTest`: passed.

`php artisan test --filter ElevenLabsWebhookToolTest`: passed.

`ElevenLabsConversationInitiationDatabaseTest` / `VoiceContextEndpointDatabaseTest`: skipped (no `pdo_sqlite`).

Combined run: 19 passed, 4 skipped.

## Production result

Local builder on production settings:
- version starts with `v2-`
- wrapper contains A/B/C rules
- General rules section still present
- initiation payload type/keys unchanged; override prompt matches builder output

No nginx change. No ElevenLabs UI change. No secret change. Route cache not rebuilt (routes unchanged). Next inbound call fetches the new prompt through the existing initiation webhook.

## Contract confirmation

Initiation endpoint remains `POST /api/voice/elevenlabs/conversation-initiation`.
Auth remains Bearer `ELEVENLABS_TOOL_TOKEN`.
Response remains `{type, conversation_config_override.agent.prompt.prompt}` without extra fields.

## Next recommended step

Place a real inbound call and confirm the agent answers Basic/Premium/VIP and routing questions from policy without asking for contacts. Then consider YFS Core for missing dynamic facts. Bitrix still not connected.
