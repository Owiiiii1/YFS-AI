# Latest Work Report

## Task

Fix Voice Assistant post-call language memory so it stores the language the conversation actually settled on, not ElevenLabs `metadata.main_language`. Strip ElevenLabs language/voice wrapper tags from saved and displayed transcripts.

## Status

Done.

## Root cause

A real call started with the default English greeting, then continued in Russian after the caller switched. ElevenLabs `metadata.main_language` was `en` (first-greeting language). Persistence copied that value to `voice_calls.language` and `voice_contacts.preferred_language`. The next inbound call would therefore force English. Assistant turns also stored wrappers such as `<Rus>Доброжелательно> …</Rus>`.

## Algorithm

`VoiceConversationLanguageResolver` (no external LLM):

1. Read allowlisted language/voice tags in raw transcript turns (`Rus`/`Ukr`/`English`/… → `ru`/`uk`/`en`).
2. Detect script language of each spoken turn after sanitizing (Latin → `en`; Cyrillic → `uk` if і/ї/є/ґ or Ukrainian markers, otherwise `ru` via ы/э/ъ/ё or Russian markers).
3. Ignore short turns (< 12 letters) and the opening assistant greeting when later substantial turns exist.
4. If the last three substantial turns agree, use that language. Otherwise majority (≥ 75%) of the last four. An isolated foreign phrase in an otherwise stable conversation does not win.
5. If the transcript is empty or inconclusive, fall back to `metadata.main_language` only when it is `en`/`ru`/`uk`.
6. Otherwise `language = null`. Initiation override is not used as conversation language.

Resolved language is written to `voice_calls.language` and, when non-null, to `voice_contacts.preferred_language`. Null/unknown does not overwrite a stored preference. The Conversation Initiation contract is unchanged: a later inbound call still returns `conversation_config_override.agent.language` from `preferred_language`.

## Transcript sanitization

`VoiceTranscriptSanitizer` unwraps an allowlist of XML-like ElevenLabs wrappers (`Rus`, `Ukr`, `English`, `lang`, `voice`, …) with a regex, not an HTML parser. A leading style annotation such as `Доброжелательно>` is removed. Spoken text inside the wrappers is kept. Normalization runs on persist; admin display sanitizes again so one already-saved test row can render cleanly without a data migration.

No bulk rewrite of old calls. Language on the existing test row is left as stored; the next real call uses the new resolver.

## Tests

Unit (no SQLite):

1. English greeting + sustained Russian → `ru`
2. English greeting + sustained Ukrainian → `uk`
3. Entire English conversation → `en` (even if fallback is `uk`)
4. Russian conversation → `ru`
5. Ukrainian conversation → `uk`
6. One isolated foreign phrase does not switch established English
7. Later sustained switch → `ru`
8. Empty/ambiguous transcript falls back to `main_language`
9. Unsupported fallback does not yield an invalid language
13–15. `<Rus>`, `<Ukr>`, `<English>` stripped; spoken text remains; style prefix removed

Feature (skipped on this host without `pdo_sqlite`, not run against production MySQL):

10–12. resolved language updates call + contact; null does not overwrite
16. duplicate post-call webhook remains idempotent
17. initiation preferred-language override tests unchanged

`php -d opcache.enable_cli=0 artisan test --filter 'VoiceConversationLanguageResolverTest|VoiceTranscriptNormalizerTest|ConversationInitiationClientDataTest|ElevenLabsPostCallWebhookTest|ElevenLabsConversationInitiationTest|ElevenLabsPostCallWebhookDatabaseTest'`: 24 passed, 7 skipped (no `pdo_sqlite`). SQLite was not installed.

## Production deployment

- PHP classes only; no migration; no ElevenLabs UI change
- frontend not rebuilt (display sanitization is server-side)
- route/config caches not rebuilt (routes and env unchanged)
- php-fpm reloaded if available so opcache picks up the new classes

## Changed files

- `app/Services/Voice/Calls/VoiceTranscriptSanitizer.php`
- `app/Services/Voice/Calls/VoiceTranscriptNormalizer.php`
- `app/Services/Voice/Language/VoiceConversationLanguageResolver.php`
- `app/Services/Voice/Calls/ElevenLabsPostCallPersister.php`
- `app/Http/Controllers/CallCenter/VoiceCallsController.php` (display-time sanitize; also caller search/filter)
- `resources/js/Pages/CallCenter/Index.jsx`, `routes/owl-admin-pages.php`, `tests/Feature/VoiceCallsAdminTest.php`, `tests/Feature/VoiceCallsAdminAuthTest.php` (caller search/filter already on production)
- `tests/Unit/Voice/VoiceConversationLanguageResolverTest.php`
- `tests/Unit/Voice/VoiceTranscriptNormalizerTest.php`
- `tests/Feature/ElevenLabsPostCallWebhookDatabaseTest.php`
- `docs/VOICE_ARCHITECTURE.md`, `docs/VOICE_ASSISTANT.md`
- this report

Not changed: HMAC auth, initiation contract, prompt, voices, agent settings, YFS Core, Bitrix, Instagram/Facebook, voice-runtime.

## Commit

`COMMIT_HASH` on `main`
