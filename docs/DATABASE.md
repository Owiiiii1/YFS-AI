# Database

## Instance

- Engine: MySQL 8
- Database: `yfs_ai`
- User: `yfs_ai_user` (privileges only on `yfs_ai.*`)
- Host: `127.0.0.1`

Created empty for this project. Mousse Bakery production data was not imported.

## Origin

- Schema comes from Laravel migrations in this repository.
- `DatabaseSeeder` and Mousse-specific seeders were **not** run.
- Users, conversations, orders, Instagram/Facebook accounts, OAuth states, and AI keys from Mousse were **not** copied.

## Tables

`ai_prompt_analysis_messages`, `ai_prompt_analysis_sessions`, `ai_prompt_change_proposals`, `ai_provider_settings`, `ai_role_connections`, `ai_runs`, `bot_decision_traces`, `bot_prompt_revisions`, `bot_replies`, `bot_settings`, `cache`, `cache_locks`, `calendar_day_settings`, `calendar_settings`, `calendar_slots`, `conversation_messages`, `conversations`, `customers`, `facebook_page_accounts`, `failed_jobs`, `instagram_accounts`, `job_batches`, `jobs`, `meta_data_deletion_requests`, `meta_oauth_states`, `telegram_settings`, `migrations`, `order_allergies`, `order_occasions`, `order_staff`, `orders`, `password_reset_tokens`, `services`, `sessions`, `staff`, `users`, `voice_assistant_settings`, `voice_contacts`, `voice_calls`, `voice_followups`, `voice_call_analyses`

YFS AI also has a **read-only** MySQL connection `jfs` (`JFS_DB_*`) to the main project database. It is not a table in `yfs_ai`. No writes.

## Main entities

| Table | Purpose |
| --- | --- |
| `users` | Admin users. After deploy: only `admin@youngfashionshow.com`. |
| `sessions` / `cache` / `jobs` | Laravel session, cache, queue |
| `bot_settings` | Singleton bot config. Auto-created empty on first `/bot-management` visit. No Mousse prompt/prices/Zelle. |
| `ai_provider_settings` | OpenAI / Anthropic / Gemini rows. Auto-created without API keys. |
| `ai_role_connections` | `bot_runtime` and `prompt_analysis`. Auto-created, not connected. |
| `instagram_accounts` | Single-tenant Instagram account. Placeholder `not_configured`. |
| `facebook_page_accounts` | Single-tenant Facebook Page. Placeholder `not_configured`. |
| `meta_oauth_states` | Short-lived OAuth state. Empty. |
| `conversations` / `conversation_messages` | Inbox. Empty. |
| `customers` / `staff` / `services` | CRM. `customers` has one virtual `Staff` row from an old migration. Not Mousse production data. |
| `bot_replies` | Closed Instagram bot cases: form sent, manager request, operator needed, JFS found/not found. |
| `meta_data_deletion_requests` | Meta data-deletion callback receipts. Status is public via confirmation code only. |
| `telegram_settings` | Telegram bot token (encrypted) and optional channel. Configured from Settings UI. |
| `voice_assistant_settings` | Editable Voice Assistant behaviour sections (Call Center → Bot settings). Assembled into `POST /api/voice/context` and the ElevenLabs initiation adapter. |
| `voice_contacts` | Independent voice interlocutors keyed by normalized phone. No YFS/Bitrix FKs yet. |
| `voice_calls` | Completed ElevenLabs conversations: transcript, language, summary, Twilio SID. |
| `orders` / `calendar_*` | Legacy cake/booking tables. Hidden from the live YFS UI. |
| `ai_runs` / `bot_decision_traces` / `bot_prompt_revisions` | Bot telemetry / prompt history. Empty. |

## Voice Assistant entities

Phase 2 architecture: `docs/VOICE_ARCHITECTURE.md`. Product/schema intent: `docs/VOICE_ASSISTANT.md`.

**Current:** `voice_assistant_settings`, `voice_contacts`, `voice_calls`, `voice_followups`, `voice_call_analyses`.

Do not store voice turns in `conversations` / `conversation_messages`.

| Table | Status | Purpose |
| --- | --- | --- |
| `voice_contacts` | Current | Normalized phone, preferred language, call counts. Matching to YFS/Bitrix is later. |
| `voice_calls` | Current | One ElevenLabs conversation: ids, timestamps, duration, status, language, transcript JSON, vendor summary, optional recording URL. |
| `voice_followups` | Current | One human follow-up per ElevenLabs conversation: department, reason, callback fields, Telegram delivery timestamp. |
| `voice_call_analyses` | Current | Structured post-call analysis for Call Center (intent, department, callback flags, summary). |
| `voice_agent_settings` | Planned | Non-secret runtime config. Distinct from `voice_assistant_settings` (policy text). |

`voice_calls.recording_url` is nullable: the ElevenLabs `post_call_transcription` webhook does not document a recording URL. Do not invent one.

Sales Agent (Phase 3) may reuse call storage with an outbound direction later; it must not reuse Voice Assistant prompts.

## Expected empty / clean state after first admin login

- `users` = 1 (YFS Admin)
- `orders` = 0
- `conversations` = 0
- `conversation_messages` = 0
- `meta_oauth_states` = 0
- Instagram / Facebook placeholders only, no tokens
- AI provider `api_key` empty
- `bot_settings.prompt_config` empty
