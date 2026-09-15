# Latest Work Report

## Task

Connect Voice Assistant to the existing read-only `JfsReadService` / `jfs` MySQL source and ship two production Native Agent webhook tools: public shows and public show brands.

## Status

Done. Native Agent endpoints are live on production Laravel. ElevenLabs UI was not changed; register the two tools using `docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`.

## What was implemented

ElevenLabs Native Agent → `AuthenticateElevenLabsTool` (`ELEVENLABS_TOOL_TOKEN`) → Laravel Voice tools → `JfsReadService` → existing `jfs` connection → YFS Core.

No new JFS credentials. ElevenLabs never sees the database. Instagram prompt injection was not copied. SQL in `JfsReadService` was not rewritten.

### Endpoints

| Tool | Method | URL |
| --- | --- | --- |
| `get_public_shows` | POST | `/api/voice/tools/public-shows` |
| `get_show_brands` | POST | `/api/voice/tools/show-brands` |
| `get_current_yfs_test_context` (POC, unchanged) | POST | `/api/voice/tools/test-context` |

Auth: `Authorization: Bearer` with the existing `ELEVENLABS_TOOL_TOKEN`. Missing/wrong token → `401 {"message":"Unauthorized"}`.

### Services / classes

| Piece | Path |
| --- | --- |
| Public shows tool | `app/Services/Voice/Tools/GetPublicShowsVoiceTool.php` |
| Show brands tool | `app/Services/Voice/Tools/GetShowBrandsVoiceTool.php` |
| JSON/filter helper | `app/Services/Voice/Tools/YfsCoreLiveToolSupport.php` |
| Native Agent controller | `app/Http/Controllers/Api/ElevenLabsYfsLiveToolController.php` |
| Registry | `app/Providers/AppServiceProvider.php` (also registers both tools next to `TestVoiceTool`) |
| JFS reads | `app/Services/Jfs/JfsReadService.php` (`publicEvents()`, `publicBrandLineups()`) |
| Availability flag | `JfsReadService::lastReadFailed()` — set on unconfigured/query failure; empty lists are not a failure |

Optional `show_name` / `city` filters run in PHP after `JfsReadService` (case-insensitive substring). No new SQL.

### Response contracts

Success:

```json
{
  "ok": true,
  "tool": "get_public_shows",
  "source": "yfs_core",
  "results": [],
  "count": 0
}
```

No matches: `ok: true`, `results: []`, `count: 0`, `message: "no_matching_shows"` (HTTP 200).

JFS unavailable: `ok: false`, `results: []`, `count: 0`, `error: "source_unavailable"` (HTTP 200, not 500).

Unannounced dates stay `null`. Empty brand lists use `brands: []`, `brand_count: 0`, `lineup_published: false`. No Instagram fact-block prose.

### Prompt changes

`VoiceAssistantPromptBuilder` wrapper version **v3**. Policy section bodies were not rewritten.

Added immutable rule **D. LIVE SHOW TOOLS**: use `get_public_shows` / `get_show_brands` for live facts; tool results override static policy for those facts; do not guess unannounced dates; unpublished lineup is said as unpublished; empty tool data is not automatic escalation or a callback.

Conversation Initiation payload shape is unchanged (`type` + `conversation_config_override.agent.prompt`).

## Tests

PHPUnit (no SQLite install; DB feature tests still skip without `pdo_sqlite`):

- Auth required on both live endpoints; runtime token rejected
- Tools call `JfsReadService` (fake; `publicEvents` / `publicBrandLineups` call counts)
- `show_name` and `city` filters
- No matches → `ok=true`, `count=0`
- Unannounced dates not emitted
- Empty lineup → `lineup_published: false`, `brands: []`
- JFS unavailable → `source_unavailable`, no 500, no traces/credentials
- POC `test-context` exact payload unchanged
- Wrapper v3 contains live-tool instructions
- Conversation Initiation contract unit test still passes

Targeted run after `route:cache`: 39 passed (live tools, prompt, initiation contract, POC webhook, JFS fact blocks, internal execute).

## Production smoke

`php artisan optimize` on this host. Existing `ELEVENLABS_TOOL_TOKEN` used; token not recorded.

| Check | Result |
| --- | --- |
| Unauthorized public-shows / show-brands | HTTP 401 |
| Authorized public-shows | HTTP 200, `ok: true`, `source: yfs_core`, `count: 5` |
| Authorized show-brands | HTTP 200, `ok: true`, `source: yfs_core`, `count: 5` |
| POC test-context | HTTP 200, synthetic `yfs_ai_test` unchanged |
| Writes to JFS | None (SELECT-only `JfsReadService`) |

### Sanitized live JFS payload (names allowed, secrets omitted)

Public shows (abbreviated):

- YOUNG FASHION SHOW MIAMI — Miami — Historic Alfred I. duPont Building… — `starts_at` 2026-04-26, `date_announced: true`, `is_past: true`
- YOUNG FASHION SHOW - LOS ANGELES — The Biltmore Los Angeles — 2026-08-09 announced, past
- YOUNG FASHION SHOW - CHICAGO — Illinois — `date_announced: false`, `starts_at`/`ends_at` null
- YOUNG FASHION SHOW - NEW YORK — NY — date not announced
- YFS Miami 2027 — Miami — date not announced

Public brands (abbreviated):

- Miami: `lineup_published: true`, `brand_count: 20` (e.g. Bebeelegante, HOOLA, Lia Lea, Young Gods, …)
- Los Angeles: `lineup_published: true`, `brand_count: 15`
- Chicago / New York / YFS Miami 2027: `brands: []`, `brand_count: 0`, `lineup_published: false`

## Deployment

- `php artisan optimize` (config, events, routes, views)
- New routes are on `https://ai.youngfashionshow.com`
- ElevenLabs dashboard: still needs the two webhook tools created manually (out of repo)

## What was not changed

- Instagram / Facebook product behaviour (only additive `lastReadFailed` on the shared read service)
- JFS Core project / schema
- ElevenLabs UI / Twilio / Node `voice-runtime`
- Participant, phone, rehearsal, ticket, package, payment, Bitrix tools
- Policy section text in admin catalog
- New credentials

## Changed files

- `app/Services/Jfs/JfsReadService.php`
- `app/Services/Voice/Tools/GetPublicShowsVoiceTool.php`
- `app/Services/Voice/Tools/GetShowBrandsVoiceTool.php`
- `app/Services/Voice/Tools/YfsCoreLiveToolSupport.php`
- `app/Http/Controllers/Api/ElevenLabsYfsLiveToolController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Voice/Prompt/VoiceAssistantPromptBuilder.php`
- `routes/api.php`
- tests under `tests/Feature/ElevenLabsYfsLiveToolsTest.php`, `tests/Unit/Voice/YfsCoreLiveVoiceToolsTest.php`, prompt/initiation/context tests, `tests/Support/FakeJfsReadService.php`
- `docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`
- `docs/VOICE_ASSISTANT.md`, `docs/VOICE_ARCHITECTURE.md`, `docs/ARCHITECTURE.md`, `docs/EXTERNAL_SERVICES.md`, `docs/PROJECT.md`
- `docs/Development/LATEST_WORK_REPORT.md`

## Commit hash

Recorded after git commit on `main`.
