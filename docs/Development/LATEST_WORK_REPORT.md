# Latest Work Report

## Task

Connect Call Center → Bot settings to a Laravel runtime prompt contract for ElevenLabs Native Agent. Build `VoiceAssistantPromptBuilder` and authenticated `POST /api/voice/context`. Do not change ElevenLabs agent config, YFS Core, Bitrix, Twilio, Instagram/Facebook, voice-runtime, or Custom LLM.

## Status

Done.

Enabled `voice_assistant_settings` are assembled into a structured prompt. The Native Agent can fetch it from `POST /api/voice/context` with the existing `ELEVENLABS_TOOL_TOKEN` Bearer auth. ElevenLabs conversation initiation is not wired yet.

## Commit

See git history on `main` after push.

## Architecture

```text
Admin
  ↓
voice_assistant_settings
  ↓
VoiceAssistantPromptBuilder
  ↓
authenticated POST /api/voice/context
  ↓
ElevenLabs conversation initialization
```

This step delivers the backend contract only. ElevenLabs is not updated via API. Node Custom LLM remains experimental/fallback and is not deleted.

## Endpoint contract

`POST /api/voice/context`

Auth: `Authorization: Bearer` using `ELEVENLABS_TOOL_TOKEN` (same `AuthenticateElevenLabsTool` as the smoke-test). Empty token fails closed (401). Voice-runtime token is rejected.

JSON:

```json
{
  "prompt": "...assembled prompt...",
  "version": "v1-<sha256>",
  "generated_at": "<ISO-8601 UTC>"
}
```

- `prompt`: immutable system wrapper + enabled sections in `sort_order`, each under `## {title}`. Disabled sections omitted. Section bodies not rewritten. No invented business facts.
- `version`: deterministic hash of wrapper version + enabled key/title/instructions/sort_order. Same settings → same version. Instruction edits change version. `generated_at` is not part of the hash.
- No secrets, tokens, or extra fields.

Smoke-test `POST /api/voice/tools/test-context` is unchanged.

## Files changed

- `app/Services/Voice/Prompt/VoiceAssistantPromptBuilder.php`
- `app/Services/Voice/Prompt/VoiceAssistantRuntimePrompt.php`
- `app/Http/Controllers/Api/VoiceContextController.php`
- `routes/api.php`
- `tests/Unit/Voice/VoiceAssistantPromptBuilderTest.php`
- `tests/Feature/VoiceContextEndpointTest.php`
- `tests/Feature/VoiceContextEndpointDatabaseTest.php`
- `docs/VOICE_ARCHITECTURE.md`
- `docs/VOICE_ASSISTANT.md`
- `docs/ARCHITECTURE.md`
- `docs/DATABASE.md`
- `docs/EXTERNAL_SERVICES.md`
- this report

## Tests

`php artisan test --filter VoiceAssistantPromptBuilderTest`: 3 passed (enabled/sort, no rewrite, stable vs changed version).

`php artisan test --filter VoiceContextEndpointTest`: 5 passed (auth required, wrong token, voice-runtime token rejected, empty token fail-closed, test-context unchanged).

`php artisan test --filter VoiceContextEndpointDatabaseTest`: skipped on this host (no `pdo_sqlite`). Covers valid token JSON shape, no secrets, disabled omitted, sort_order, instruction change vs identical version.

`php artisan test --filter ElevenLabsWebhookToolTest`: 4 passed.

Guest `POST /api/voice/context` on production returns `401 {"message":"Unauthorized"}`.

## Production actions

- Laravel route cache rebuilt.
- No migration (existing `voice_assistant_settings`).
- No nginx change.
- No secret/token rotation.
- ElevenLabs agent configuration not changed.
- Local builder run confirmed enabled General rules and VIP policy text are present in the assembled prompt.

## What was not changed

- YFS Core
- Bitrix
- Twilio routing
- Instagram/Facebook
- Node `voice-runtime`
- Custom LLM
- `POST /api/voice/tools/test-context`
- production ElevenLabs agent configuration
- nginx
- secrets

## Next recommended step

Wire `POST /api/voice/context` into ElevenLabs Conversation Initiation / dynamic variables / overrides so a new conversation uses the assembled admin prompt without manually editing the ElevenLabs business prompt.

Do not connect YFS Core or Bitrix in that step unless explicitly requested.
