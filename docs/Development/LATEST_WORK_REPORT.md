# Latest Work Report

## Task

Add a separate ElevenLabs Conversation Initiation Client Data webhook adapter. Reuse `VoiceAssistantPromptBuilder`. Do not change `/api/voice/context`, test-context, ElevenLabs UI, nginx, secrets, YFS Core, Bitrix, Twilio, Instagram, or Node voice-runtime.

## Status

Done.

Backend adapter is live. This repo did **not** paste the URL into ElevenLabs or change agent settings.

## Commit

`1cad78d` on `main`

Add ElevenLabs conversation initiation webhook adapter for admin prompts.

## Architecture

```text
Admin
  ↓
voice_assistant_settings
  ↓
VoiceAssistantPromptBuilder
  ↓
authenticated POST /api/voice/elevenlabs/conversation-initiation
  ↓
ElevenLabs conversation_initiation_client_data
  ↓
Native Agent system prompt override
```

System Prompt for a new inbound call can come from YFS Admin → DB → PromptBuilder → initiation webhook → ElevenLabs, after the operator pastes the URL.

Customer / language / YFS Core / Bitrix context is not connected.

## Endpoint

Production URL to paste into ElevenLabs:

`https://ai.youngfashionshow.com/api/voice/elevenlabs/conversation-initiation`

HTTP method: `POST`

Header to create in ElevenLabs (value is `ELEVENLABS_TOOL_TOKEN`; do not put the value in git/docs):

`Authorization: Bearer <token>`

Same `AuthenticateElevenLabsTool` as tools and `/api/voice/context`. Empty token fails closed (401).

Incoming JSON fields such as `caller_id`, `agent_id`, `called_number`, `call_sid`, and `conversation_id` are accepted and ignored.

## Exact ElevenLabs response contract

Official docs (Personalization / Twilio personalization): webhook response uses `conversation_initiation_client_data`. Current examples include `"type": "conversation_initiation_client_data"`, so this adapter sends `type`.

Only the system prompt override is returned. `llm`, `first_message`, `tts`, and `dynamic_variables` are omitted so this adapter does not change those agent fields. Custom dynamic variables are not declared on our side yet.

```json
{
  "type": "conversation_initiation_client_data",
  "conversation_config_override": {
    "agent": {
      "prompt": {
        "prompt": "<assembled Voice Assistant prompt>"
      }
    }
  }
}
```

`POST /api/voice/context` remains diagnostic JSON `{prompt, version, generated_at}` and was not changed.

## Files

- `app/Services/ElevenLabs/ConversationInitiationClientData.php`
- `app/Http/Controllers/Api/ElevenLabsConversationInitiationController.php`
- `routes/api.php`
- `tests/Unit/Voice/ConversationInitiationClientDataTest.php`
- `tests/Feature/ElevenLabsConversationInitiationTest.php`
- `tests/Feature/ElevenLabsConversationInitiationDatabaseTest.php`
- `docs/VOICE_ARCHITECTURE.md`
- `docs/VOICE_ASSISTANT.md`
- `docs/ARCHITECTURE.md`
- `docs/DATABASE.md`
- `docs/EXTERNAL_SERVICES.md`
- this report

## Tests

`php artisan test --filter ConversationInitiationClientDataTest`: passed (contract + builder prompt, no extra keys).

`php artisan test --filter ElevenLabsConversationInitiationTest`: passed (missing auth 401, wrong Bearer 401, voice-runtime token rejected, test-context unchanged).

`php artisan test --filter ElevenLabsConversationInitiationDatabaseTest`: skipped (no `pdo_sqlite`). Covers valid 200, extra ElevenLabs fields, disabled sections omitted, sort_order, instruction change, no secrets.

`php artisan test --filter VoiceContextEndpointTest`: passed.

`php artisan test --filter ElevenLabsWebhookToolTest`: passed.

Combined run: 14 passed, 4 skipped.

## Production smoke result

- Unauthenticated POST → `401 {"message":"Unauthorized"}`
- Authenticated POST with extra telephony fields → `200`
- Keys: `type`, `conversation_config_override`
- `type` = `conversation_initiation_client_data`
- Assembled prompt present (wrapper + General rules)
- Token not present in response body
- No `llm` override
- Token value was not printed

Route cache rebuilt. Three voice API routes present, including the new adapter.

## What was not changed

- ElevenLabs agent configuration / System Prompt in the ElevenLabs UI
- `/api/voice/context`
- `/api/voice/tools/test-context`
- Node voice-runtime
- Custom LLM
- Twilio
- Instagram/Facebook
- YFS Core
- Bitrix
- nginx
- secrets

## Next manual step in ElevenLabs

1. Conversation Initiation Client Data Webhook URL = `https://ai.youngfashionshow.com/api/voice/elevenlabs/conversation-initiation`
2. Method POST
3. Header `Authorization: Bearer <ELEVENLABS_TOOL_TOKEN>`
4. Confirm Security → Overrides → System prompt stays enabled
5. Confirm Security → Fetch initiation client data from a webhook stays enabled
6. Place a real inbound call and confirm the agent uses the Admin Bot settings prompt

Do not connect customer lookup, YFS Core, or Bitrix until the next step.
