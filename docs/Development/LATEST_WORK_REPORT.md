# Latest Work Report

## Task

Add admin-editable Voice Assistant behaviour settings from the client Customer Support instruction. Keep the source document verbatim. Do not wire settings into ElevenLabs, YFS Core, Bitrix, Twilio, voice-runtime, or Instagram/Facebook.

## Status

Done.

Call Center now has a second item next to Voice Assistant: Bot settings. Settings are stored in `voice_assistant_settings` and filled from the client policy without inventing operational facts (rehearsal times, parking, ticket counts, etc.).

These settings are not injected into the ElevenLabs Native Agent. Runtime Prompt Builder is a later step.

## Commit

`ab92a22` on `main`

Add editable Voice Assistant bot settings from the client support policy.

## Files changed

- `docs/Voice/CLIENT_CUSTOMER_SUPPORT_POLICY_UA.md` (verbatim source document)
- `docs/ref/Nuovo documento di testo.TXT` (original drop; unchanged)
- `app/Models/VoiceAssistantSetting.php`
- `app/Support/VoiceAssistantSettingCatalog.php`
- `app/Http/Controllers/CallCenter/VoiceBotSettingsController.php`
- `database/migrations/2026_09_15_120000_create_voice_assistant_settings_table.php`
- `database/seeders/VoiceAssistantSettingsSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `resources/js/Pages/CallCenter/BotSettings.jsx`
- `resources/js/Layouts/AdminLayout.jsx`
- `routes/owl-admin-pages.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `tests/Feature/VoiceBotSettingsTest.php`
- `tests/Unit/VoiceAssistantSettingCatalogTest.php`
- `docs/VOICE_ARCHITECTURE.md`
- `docs/VOICE_ASSISTANT.md`
- `docs/ARCHITECTURE.md`
- `docs/DATABASE.md`
- this report

Not changed: `voice-runtime`, Custom LLM, Instagram/Facebook, YFS Core, Bitrix, nginx, Twilio, ElevenLabs agent config, `POST /api/voice/tools/test-context`.

Existing Call Center Voice Assistant page is unchanged.

## Architecture

Admin-only for now:

```text
Call center
├── Voice assistant     placeholder (unchanged)
└── Bot settings        editable policy sections
```

Storage: `voice_assistant_settings` (`key`, `title`, `instructions`, `enabled`, `sort_order`).

Source of truth for behaviour: `docs/Voice/CLIENT_CUSTOMER_SUPPORT_POLICY_UA.md`.

Next (not this change): admin settings → Laravel Prompt Builder → ElevenLabs conversation context.

## Behaviour captured

- Inquiry categories and approximate shares from the client analysis.
- Self-service / App as the primary source of organizational information.
- BASIC: Self-Service First. App / Help Center → Customer Support request → callback if needed.
- PREMIUM: Self-Service + Customer Support. Phone for complex, urgent, or non-standard cases.
- VIP: Priority Personal Support. Direct phone + personal accompaniment.
- SALE → CONTRACT → CUSTOMER SUPPORT.
- CUSTOMER SUPPORT → SALES only for a new commercial opportunity (upgrade, extra service, workshop, option, next show, other purchase).
- Escalation follows package model. Organizational questions after contract do not go to Sales.

No invented show facts (times, addresses, ticket counts, brands).

## Tests

`php artisan test --filter VoiceAssistantSettingCatalogTest`: 1 passed.

`php artisan test --filter VoiceBotSettingsTest`: skipped on this host (no `pdo_sqlite`; same gate as other RefreshDatabase feature tests). Tests cover guest 401/redirect to login, admin page load of 13 sections, save + persistence, unknown key 404, and unchanged `call-center.index`.

`php artisan test --filter ElevenLabsWebhookToolTest`: 4 passed.

Guest `GET /call-center/bot-settings` redirects to `/login`.

## Runtime

- Migration applied.
- `VoiceAssistantSettingsSeeder` / `ensureDefaults()` inserted 13 sections.
- Laravel route cache rebuilt.
- Frontend production assets rebuilt (`npm run build`). PHP-FPM serves the new Inertia page. No nginx / voice-runtime / ElevenLabs change.

## Next recommended step

Runtime Prompt Builder: enabled admin sections → Laravel → ElevenLabs conversation context.

Do not connect YFS Core or Bitrix in that step unless explicitly requested.
