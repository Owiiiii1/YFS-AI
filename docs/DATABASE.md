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

`ai_prompt_analysis_messages`, `ai_prompt_analysis_sessions`, `ai_prompt_change_proposals`, `ai_provider_settings`, `ai_role_connections`, `ai_runs`, `bot_decision_traces`, `bot_prompt_revisions`, `bot_replies`, `bot_settings`, `cache`, `cache_locks`, `calendar_day_settings`, `calendar_settings`, `calendar_slots`, `conversation_messages`, `conversations`, `customers`, `facebook_page_accounts`, `failed_jobs`, `instagram_accounts`, `job_batches`, `jobs`, `meta_data_deletion_requests`, `meta_oauth_states`, `telegram_settings`, `migrations`, `order_allergies`, `order_occasions`, `order_staff`, `orders`, `password_reset_tokens`, `services`, `sessions`, `staff`, `users`, `voice_assistant_settings`

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
| `voice_assistant_settings` | Editable Voice Assistant behaviour sections (Call Center → Bot settings). Filled from the client Customer Support policy. Not injected into ElevenLabs yet. |
| `orders` / `calendar_*` | Legacy cake/booking tables. Hidden from the live YFS UI. |
| `ai_runs` / `bot_decision_traces` / `bot_prompt_revisions` | Bot telemetry / prompt history. Empty. |

## Planned Voice Assistant entities (NOT IMPLEMENTED)

Phase 2 architecture: `docs/VOICE_ARCHITECTURE.md`. Product/schema intent: `docs/VOICE_ASSISTANT.md`. Behaviour settings table `voice_assistant_settings` is implemented. Post-call tables below remain conceptual. **No `voice_calls` migrations exist.**

Do not store voice turns in `conversations` / `conversation_messages`.

| Planned table | Purpose |
| --- | --- |
| `voice_calls` | One call: provider, provider_call_id, direction, numbers, timestamps, duration, status, language, outcome, needs_followup, transcript/summary refs, metadata. |
| `voice_call_messages` | Ordered transcript turns (speaker, text, time, optional provider metadata). |
| `voice_contacts` | Normalized contact data collected on calls. May later link to `customers`. |
| `voice_followups` | Operator queue: reason, priority, status, assignee, callback request, notes. |
| `voice_agent_settings` | Non-secret runtime config: active provider, agent IDs, transfer, behaviour. Secrets stay out of this table and out of git. Distinct from `voice_assistant_settings` (policy text). |

This is not a final column list. Sales Agent (Phase 3) may reuse call storage with `direction = outbound`; it must not reuse Voice Assistant prompts.

## Expected empty / clean state after first admin login

- `users` = 1 (YFS Admin)
- `orders` = 0
- `conversations` = 0
- `conversation_messages` = 0
- `meta_oauth_states` = 0
- Instagram / Facebook placeholders only, no tokens
- AI provider `api_key` empty
- `bot_settings.prompt_config` empty
