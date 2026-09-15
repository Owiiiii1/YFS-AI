# Latest Work Report

## Task

Add YFS AI’s own voice-call history and interlocutor table. Persist completed ElevenLabs conversations, remember each contact’s last known language, pass that language on the next inbound call, and show a call journal at Call Center → Voice Assistant.

## Status

Done.

## What was implemented

- Independent `voice_contacts` and `voice_calls` tables (no YFS Core / Bitrix foreign keys).
- One phone normalizer (`PhoneNumberNormalizer`) used for all caller matching.
- Conversation Initiation webhook still returns `conversation_initiation_client_data` with the same System Prompt override. Known contacts with preferred language `en` / `ru` / `uk` also receive `conversation_config_override.agent.language`. Unknown numbers do not force a language.
- `calls_count` is not incremented on initiation (retries are not completed calls).
- First-message override was not added (language override is the job of this stage; first-message would be extra agent-config coupling).
- Authenticated post-call webhook `POST /api/voice/elevenlabs/post-call` (HMAC `ElevenLabs-Signature`, separate secret from the initiation Bearer token).
- Idempotent persist on `elevenlabs_conversation_id`. Duplicate delivery does not create a second call or increment `calls_count` again.
- After a completed conversation, a supported detected language updates `voice_calls.language` and `voice_contacts.preferred_language`. Unknown languages do not overwrite a known preference.
- Call Center → Voice Assistant is a newest-first paginated journal. A row opens a sheet with the contact card and a Client / YFS Assistant transcript. No fake YFS/Bitrix fields.

## Schema

`voice_contacts`: `id`, `phone_normalized` (unique), `phone_display`, `name`, `preferred_language`, `first_called_at`, `last_called_at`, `calls_count` default 0, `metadata` JSON, timestamps.

`voice_calls`: `id`, `voice_contact_id` FK, `elevenlabs_conversation_id` unique, `twilio_call_sid`, `phone`, `language`, `started_at`, `ended_at`, `duration_seconds`, `status`, `transcript` JSON, `summary`, `recording_url`, `metadata` JSON, timestamps.

Secrets / auth headers / API tokens are not stored in metadata.

## Inbound language memory

```text
caller_id
  → PhoneNumberNormalizer (E.164 when possible; stable fallback otherwise)
  → find/create VoiceContact
  → update first_called_at / last_called_at
  → read preferred_language
  → if en/ru/uk, return agent.language
  → always return agent.prompt.prompt from VoiceAssistantPromptBuilder
```

Unknown / unsupported language: omit `agent.language`. ElevenLabs default detection remains.

Language override still requires the ElevenLabs agent Security toggle that allows conversation initiation overrides for language (already enabled on this agent previously). This repo does not change ElevenLabs via API.

## Post-call flow

```text
ElevenLabs post_call_transcription
  → HMAC ElevenLabs-Signature
  → POST /api/voice/elevenlabs/post-call
  → upsert VoiceCall by conversation_id
  → find/create VoiceContact from phone_call.external_number
  → increment calls_count once
  → if main_language is en/ru/uk, store it on the call and contact
```

`post_call_audio` is acknowledged with HTTP 200 and ignored (no `full_audio` persistence).

## ElevenLabs payload fields used

From the current `post_call_transcription` contract, when present:

- `type`, `event_timestamp`
- `data.agent_id`, `data.conversation_id`, `data.status`, `data.has_audio`
- `data.transcript[]`: `role`, `message`, `time_in_call_secs` (tool_calls are not stored)
- `data.metadata.start_time_unix_secs`, `call_duration_secs`, `termination_reason`, `main_language`
- `data.metadata.phone_call`: `type`, `direction`, `external_number`, `agent_number`, `call_sid`
- `data.analysis.transcript_summary`, `data.analysis.call_successful`
- optional `recording_url` / `audio_url` only if it is an `http(s)` URL

Not provided directly by the transcription webhook (left nullable / unused):

- recording URL (audio is a separate `post_call_audio` event with base64, not a URL)
- YFS customer / Bitrix contact
- first-party AI summary (vendor `transcript_summary` is stored when present; no extra OpenAI/Gemini post-call job)

## Admin UI

Call Center → Voice Assistant (`/call-center`):

Columns: date/time, caller, phone, language, duration, status, brief (summary else transcript preview). Newest first. Pagination 20. Click opens a sheet: contact (name or Unknown, phone, preferred language, this-call language, calls count, first/last call) and conversation dialogue. Playback control only if `recording_url` is a real http(s) URL.

## Tests

Covered:

1. Phone normalization
2. Unknown caller creates/fetches a contact without language override
3. Known `ru` → language override
4. Known `uk` → language override
5. Unsupported language does not create an invalid override
6. System Prompt override structure remains `{ type, conversation_config_override.agent.prompt.prompt }`
7. Post-call webhook creates one `voice_call`
8. Duplicate webhook is idempotent
9. `calls_count` increments once
10. Detected language updates `preferred_language`
11. Unknown language does not overwrite a known preference
12. Transcript parser/normalizer
13. Missing optional / extra ElevenLabs fields do not crash
14. Secrets are not persisted in metadata/response
15. Voice Assistant admin routes require admin access
16. Calls list newest first
17. Call details return the correct contact + transcript

`pdo_sqlite` is not installed on this host. Database feature tests skip; they are not rewritten onto the production MySQL database. No SQLite package was installed.

## Production deployment

- `php artisan migrate --force` — `voice_contacts` / `voice_calls` created
- frontend Vite build (Call Center journal)
- Laravel `route:cache` / `config:cache` / `view:cache` because routes and config keys changed
- smoke: initiation 401 without Bearer, 200 with valid auth (`type=conversation_initiation_client_data`, prompt present, no language for unknown number, token not in body); post-call 401 without HMAC
- existing inbound initiation contract kept

`ELEVENLABS_POST_CALL_WEBHOOK_SECRET` is present in `.env` / `.env.example` as an empty placeholder until the operator pastes the secret ElevenLabs generates. While it is empty, post-call requests fail closed (401).

## Manual ElevenLabs step

Required once after deploy. Do not put the secret in git, docs, or this report.

1. Open ElevenLabs Agents: `https://elevenlabs.io/app/agents/settings`
2. Scroll to **Post-Call Webhook** → **Select Webhook** → **Create Webhook**.
3. URL: `https://ai.youngfashionshow.com/api/voice/elevenlabs/post-call`
4. Auth method: HMAC (default).
5. Events: **Transcript** only (`post_call_transcription`). Do not enable **Audio**.
6. Create, copy the webhook secret into production `.env` as `ELEVENLABS_POST_CALL_WEBHOOK_SECRET`, then `php artisan config:cache`.
7. Assign it to the Voice Assistant agent: Agents → this agent → **Advanced** → webhook / post-call webhook selector.
8. Confirm **Allow conversation initiation client data overrides** for **language** remains enabled on the agent Security tab (needed for `agent.language`). Prompt override stays enabled as before.

Initiation webhook URL and Bearer auth are unchanged and must keep working.

## Changed files

- `app/Models/VoiceContact.php`, `app/Models/VoiceCall.php`
- `database/migrations/2026_09_15_150000_create_voice_contacts_and_voice_calls_tables.php`
- `app/Services/Voice/Phone/PhoneNumberNormalizer.php`
- `app/Support/VoiceSupportedLanguage.php`
- `app/Services/Voice/Contacts/VoiceContactDirectory.php`
- `app/Services/Voice/Calls/VoiceTranscriptNormalizer.php`
- `app/Services/Voice/Calls/ElevenLabsPostCallPersister.php`
- `app/Services/ElevenLabs/ConversationInitiationClientData.php`
- `app/Services/ElevenLabs/ElevenLabsWebhookSignatureVerifier.php`
- `app/Http/Middleware/AuthenticateElevenLabsPostCallWebhook.php`
- `app/Http/Controllers/Api/ElevenLabsConversationInitiationController.php`
- `app/Http/Controllers/Api/ElevenLabsPostCallWebhookController.php`
- `app/Http/Controllers/CallCenter/VoiceCallsController.php`
- `resources/js/Pages/CallCenter/Index.jsx`
- `routes/api.php`, `routes/owl-admin-pages.php`
- `config/services.php`, `.env.example`
- tests under `tests/Unit` and `tests/Feature`
- `docs/VOICE_ARCHITECTURE.md`, `docs/VOICE_ASSISTANT.md`, `docs/DATABASE.md`, `docs/ARCHITECTURE.md`, `docs/EXTERNAL_SERVICES.md`, `docs/PROJECT.md`
- this report

Not changed: Instagram/Facebook, Node Custom LLM, YFS Core, Bitrix, voice-runtime.

## Commit

`ab4fab6` on `main`
