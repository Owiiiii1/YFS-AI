# Latest Work Report

## Task

Read-only audit: how Instagram AI Assistant already gets live data from the main YFS/JFS project, and a precise reuse plan for Voice Assistant. No application code on this step.

## Status

Audit complete. Production code, Instagram bot, ElevenLabs, Voice prompt, and YFS Core were not changed. This file is the only update.

## Scope and constraints honoured

- No new YFS Core integration was created.
- ElevenLabs is not connected to the main project database.
- No writes to JFS / YFS Core.
- No new credentials.
- No secret values in this report (env/config key names only).
- Neighbouring projects were not opened for writes.

---

## 1. How Instagram gets YFS Core data

**Mechanism: direct read-only MySQL connection from Laravel YFS AI to the main ops database.**

Not HTTP API for show/brand/client facts. Not an internal REST endpoint on JFS. Not a repository in `/var/www/jfs`. Not ElevenLabs.

Laravel connection name: `jfs` in `config/database.php`. Driver: `mysql`. The service issues `SELECT` / `get()` only.

A separate HTTP path exists, but it is **admin SSO only**, not bot facts:

- `JfsSsoController` → `POST` to `INCOMING_SSO_VERIFY_ENDPOINT` with `INCOMING_API_TOKEN`.
- Used to log an admin into YFS AI. It does not load shows, brands, or schedules.

Facebook Messenger processing does **not** inject LIVE EVENT / BRAND / CLIENT fact blocks. It only reuses `EventDateGuard` (which can call `JfsReadService::publicEvents()` to strip invented dates from an outgoing reply). Live fact injection is Instagram-only.

---

## 2. Classes / services / controllers in the Instagram → JFS fact flow

| Role | Path |
| --- | --- |
| Incoming Instagram webhook | `app/Http/Controllers/Api/MetaInstagramWebhookController.php` |
| Instagram pipeline | `app/Services/Instagram/ProcessIncomingInstagramMessageService.php` |
| Intent (regex, not tools) | `app/Services/Bot/YfsIntentRouter.php` |
| JFS SELECT layer | `app/Services/Jfs/JfsReadService.php` |
| Prompt assembly | `app/Services/Bot/BotPromptAssembler.php` |
| Conversation prompt wrapper | `app/Services/Instagram/BotConversationContextBuilder.php` |
| LLM generate | `app/Services/Ai/AiReplyGenerator.php` (used from the Instagram service) |
| Date sanitizer after LLM | `app/Services/Bot/EventDateGuard.php` |
| Instagram-local city/form gate (writes **yfs_ai** `conversations.intake_data`, not JFS) | `app/Services/Instagram/ParticipationLocationService.php` |
| DB connection | `config/database.php` → `connections.jfs` |
| Tests of fact-block text | `tests/Unit/JfsEventFactsTest.php` |
| Tests of brand vs designer intent | `tests/Unit/YfsBrandFactsIntentTest.php` |

Instagram call site (fact injection, not function calling):

```415:480:app/Services/Instagram/ProcessIncomingInstagramMessageService.php
        $route = $this->intentRouter->route($effectiveText);
        $factBlocks = [];
        // ...
        $events = $this->jfs->publicEvents();
        if ($route['wants_events'] || $loadShowBrands) {
            $factBlocks[] = $this->jfs->eventsFactBlock($events);
        }
        if ($loadShowBrands) {
            $factBlocks[] = $this->jfs->brandsFactBlock($this->jfs->publicBrandLineups());
        }
        if ($route['email'] !== null) {
            $lookup = $this->jfs->findClientByEmail($route['email']);
            $factBlocks[] = $this->jfs->clientFactBlock($lookup);
        }
        // ... then LLM generate with factBlocks in the system prompt,
        // then EventDateGuard::sanitize(...)
```

Related, **not** part of the JFS fact source:

- YFS AI CRM `Customer` / Instagram profile in `BotConversationContextBuilder` — local `yfs_ai` data.
- Telegram case posting, `BotOutcomeService`, `BotReply`.
- Voice POC: `TestVoiceTool`, `VoiceToolRegistry`, `POST /api/voice/tools/test-context` — synthetic test data only, no `JfsReadService`.

---

## 3. Credentials / config (names only)

### JFS read (Instagram facts and the reusable Voice source)

| Key | Where |
| --- | --- |
| `JFS_DB_HOST` | `config/database.php` → `connections.jfs.host` |
| `JFS_DB_PORT` | `connections.jfs.port` (default `3306`) |
| `JFS_DB_DATABASE` | `connections.jfs.database` |
| `JFS_DB_USERNAME` | `connections.jfs.username` |
| `JFS_DB_PASSWORD` | `connections.jfs.password` |
| `JFS_DB_SOCKET` | `connections.jfs.unix_socket` (optional) |

Auth to JFS: Laravel MySQL user/password (or socket). `JfsReadService::isConfigured()` is true when database and username are filled.

The code path is SELECT-only. This report does not claim the MySQL account itself is GRANT-limited to SELECT; that is a DB-side property, not verified here.

### Admin SSO to JFS (not Voice data)

| Key | Where |
| --- | --- |
| `INCOMING_API_TOKEN` | `config/services.php` → `young_fashion_show.incoming_api_token` |
| `INCOMING_SSO_VERIFY_ENDPOINT` | `young_fashion_show.sso_verify_endpoint` |

Auth: Bearer + `X-Incoming-Api-Token` on an HTTP POST. Do not reuse this for Voice tools.

### Existing Voice / ElevenLabs webhook auth (already in YFS AI)

| Key | Where |
| --- | --- |
| `ELEVENLABS_TOOL_TOKEN` | `config/services.php` → `services.elevenlabs.tool_token` |
| Middleware | `AuthenticateElevenLabsTool` — `Authorization: Bearer …` |
| `VOICE_RUNTIME_INTERNAL_TOKEN` | `services.voice_runtime.internal_token` — Custom LLM / internal catalog execute only |

ElevenLabs must keep seeing **only** `ELEVENLABS_TOOL_TOKEN`. It must never receive `JFS_DB_*` or `INCOMING_API_TOKEN`.

---

## 4. Data Instagram already can load from JFS

Source of truth: `JfsReadService`. Tables touched: `events`, `event_infos`, `app_users`, `children`, `event_brand`, `brands`.

### Shows / events — yes

`publicEvents()` returns, per event:

- `name`
- `city`
- `location` (venue/area string as stored on `events.location`)
- `starts_at` / `ends_at` **only if** `events.client_show_event_date` is treated as announced (`null` or truthy)
- `date_announced`
- `is_past` (start date before today)
- `description` from `event_infos.description_i18n` (first of `en` / `ru` / `uk` / `es`, stripped HTML, max ~800 chars)

There is **no** published-event filter (`event_infos.type = 5`) in the current queries. All rows returned by those SELECTs are treated as public facts. `leftJoin event_infos` can also duplicate event rows if several info rows exist. Voice tools should keep the same public contract unless a later, explicit filter is approved.

### Dates — yes, with the same announcement gate

If the date is not announced, Instagram is instructed to say it is at confirmation stage and **never name or guess it**. `EventDateGuard` additionally strips invented dates from the outgoing Instagram reply.

No rehearsal calendar. No clock-time schedule beyond optional non-midnight `starts_at`/`ends_at`.

### Locations — partial

City + `events.location` string only. No parking, hotel, entrance, registration desk, or “what to bring”.

### Brands / designers — public lineup only

`publicBrandLineups()`: per event, list of `brands.name` via `event_brand`, skipping inactive brands (`is_active` null or 1). Empty list is worded as “lineup not published yet”.

This is **not** “which brand is assigned to this child / this family”. Instagram brand questions are treated as public lineup (`show_brands`), not a personal assignment.

### Participant-related — email lookup only, and the customer is not told the record

`findClientByEmail()` can read:

- `app_users`: id, name, email, phone, role (and `status` is selected)
- `children` by `client_app_user_id`: first_name, birthdate

`clientFactBlock()` **deliberately hides** children and internal fields from the customer. If found, the bot may only say they were found and the request was passed to a manager. Product rule in `docs/INSTAGRAM_BOT.md`: do not search by phone; do not tell the customer “you are / are not in the database”.

No package, ticket, look count, or assigned-brand fields in this lookup.

### Not available in this integration

| Topic | In `JfsReadService`? |
| --- | --- |
| Rehearsals (General / Brand, time, room) | No |
| Day-by-day / clock schedule | No |
| Packages (Basic / Premium / VIP contents) | No |
| Tickets, QR, guest transfer | No |
| Fitting time / footwear / look count | No |
| Workshop time | No |
| Backstage pass | No |
| Payments | No |
| Lookup by phone | No (explicit Instagram rule) |

---

## 5. How Instagram invokes that data

**Prompt enrichment after regex intent. Not function/tool calling.**

1. `YfsIntentRouter::route($message)` sets `wants_events`, `wants_brands`, optional email.
2. Matching fact blocks are appended into the system prompt (`LIVE EVENT FACTS`, `LIVE BRAND FACTS`, `CLIENT LOOKUP FACTS`).
3. Bot Management topics (`events`, `show_brands`, `client_lookup`, …) load extra static prompt slices.
4. LLM generates a chat reply.
5. `EventDateGuard` rewrites unsafe date claims.

There is no Instagram tool schema, no webhook from Meta into `JfsReadService`, and no preload of JFS into every turn (events are always queried once per reply; fact blocks are attached only when intent matches, except `publicEvents()` is always called for the guard).

Voice must **not** copy this regex router into ElevenLabs. Native Agent already has webhook tools; that is the right call pattern.

---

## 6. Reusable layer for Voice (without copying Instagram logic)

**Yes: `App\Services\Jfs\JfsReadService` is the universal read-only YFS Core access in this repo.**

Voice should call:

- `publicEvents()`
- `publicBrandLineups()`
- optionally later `findClientByEmail()` — not in the first Voice set (callers have a phone, not an email; PII; Instagram hides the record from the customer)

Do **not** copy into Voice:

- `YfsIntentRouter` (Instagram regex)
- `BotPromptAssembler` / Instagram `prompt_config`
- `ParticipationLocationService` (writes local intake; form-link gating)
- `EventDateGuard` as a speech post-processor (dates are already gated in `JfsReadService`; Voice tools should return structured JSON, not Instagram prose blocks)

**Existing Voice tool layer (already suitable to extend, currently test-only):**

| Piece | Path / route |
| --- | --- |
| Contract | `VoiceToolInterface` |
| Registry | `VoiceToolRegistry` (today only `TestVoiceTool`) |
| Execute (Custom LLM / internal) | `POST /api/internal/voice/tools/execute` + `VOICE_RUNTIME_INTERNAL_TOKEN` |
| Native Agent POC | `POST /api/voice/tools/test-context` + `AuthenticateElevenLabsTool` |
| Catalog | `GET /api/internal/voice/tools` |

`TestVoiceTool` (`get_current_yfs_test_context`) returns synthetic “YFS Test Event”. It must stay separate from production JFS tools.

Conversation Initiation / `POST /api/voice/context` currently builds policy prompt only. It does **not** inject JFS facts. That is correct for this reuse plan: live facts belong in webhook tools, not a stale prompt dump.

---

## 7. Read vs write against main YFS

| Operation | Target | Verdict |
| --- | --- | --- |
| `JfsReadService` queries | JFS MySQL | **READ-ONLY** |
| `EventDateGuard` | JFS via `publicEvents()` | **READ-ONLY** |
| Instagram/Facebook replies, `conversations`, `bot_replies`, Telegram | **yfs_ai** | Local writes; not JFS |
| `ParticipationLocationService` | `conversations.intake_data` in **yfs_ai** | Local write; not JFS |
| `JfsSsoController` | JFS HTTP SSO verify | Auth only; may create a **yfs_ai** `users` row |
| Any `insert`/`update`/`delete` on `connection('jfs')` | — | **Not found** |

Voice first tools must wrap only `JfsReadService` public event/brand methods. No client-mutating APIs. No new JFS writes.

---

## 8. Minimal architecture for ElevenLabs Native Agent

Preferred path (already matches the existing Native Agent webhook POC):

```
ElevenLabs Native Agent
    → webhook tool  POST /api/voice/tools/...
         Authorization: Bearer ELEVENLABS_TOOL_TOKEN
YFS AI Laravel
    → VoiceToolInterface implementation (new, next step)
    → JfsReadService
    → Laravel connection `jfs` (JFS_DB_* stay on the server)
YFS Core MySQL
    → JSON fact payload (no credentials)
YFS AI
    → ElevenLabs Agent speaks from the tool result
```

Rules:

- Voice Assistant never sees JFS credentials.
- Do not add a second DB client, HTTP client to JFS app APIs, or ElevenLabs → JFS link.
- Do not preload full LIVE EVENT FACTS into Conversation Initiation (prompt bloat + stale dates). Tools on demand.
- Keep Instagram pipeline untouched.
- Register new tools in the ElevenLabs dashboard only in the **next** implementation step (not done now).
- Return **structured JSON** from `publicEvents()` / `publicBrandLineups()`, not the Instagram English fact-block strings (those strings are chat-prompt glue). Preserve the same announcement / past-show semantics in the JSON (`date_announced`, `is_past`, null dates).

Optional later: the same tool classes also register in `VoiceToolRegistry` so Custom LLM fallback can call them without a second implementation.

---

## 9. Minimal first Voice tool set (from real Instagram capabilities)

Two tools. Not dozens. Names chosen from actual methods, not from the example list in the request.

### `get_public_shows`

- Wraps `JfsReadService::publicEvents()`.
- Arguments: none required; optional `city` or `name` filter in Laravel (filter in PHP, no new SQL).
- Result: list of shows with name, city, location, dates only if announced, `date_announced`, `is_past`, short description.
- `readOnly: true`, `source: jfs`.

### `get_show_brands`

- Wraps `JfsReadService::publicBrandLineups()`.
- Arguments: none required; optional city/show name filter.
- Result: per-show brand names and `brand_count`, same date gate.
- Answers “which brands are on the public lineup”, **not** “which brand is mine”.

### Explicitly **not** in v1

| Candidate | Why not |
| --- | --- |
| `get_participant_information` | Email-only; Voice has phone; Instagram hides the record; children must not be spoken |
| Rehearsal / schedule / ticket / package tools | No JFS methods exist |
| Merging both into one mega-tool | Possible, but two small tools match Native Agent “call when the caller asks X” better than a catch-all |
| Copying `eventsFactBlock()` text into the Voice prompt | Wrong channel; keep JSON |

Keep `get_current_yfs_test_context` as the POC tool until the real tools are live; do not point it at JFS.

---

## 10. Which current Voice customer-support questions become exactly answerable

Mapped to `VoiceAssistantSettingCatalog` / client Customer Support policy. **Only** questions that `JfsReadService` can actually ground.

### After these two tools — can give a precise, live answer

From **General** / show identity (today the catalog says live show facts must come from YFS Core later):

- What shows exist (upcoming vs past).
- Show **name**.
- **City**.
- **Venue/location string** as stored on the event.
- **Date** of a show **if** `client_show_event_date` allows it.
- If the date is not announced: say it is being confirmed (same rule as Instagram), do not invent it.
- Public **description** snippet when `description_i18n` has text.

From **Brands, looks, fitting** — **only the public lineup subset**:

- Which brand names are listed for a published show lineup.
- How many names are on that public list (`brand_count`).
- That the lineup is not published yet, when the list is empty.

### Still not answerable from this source (policy must keep App / escalate)

**Rehearsals and schedule (~30%):** when is rehearsal, General vs Brand, can I reschedule, overlapping rehearsals, “where do I see my timetable”. JFS tools have no rehearsal rows. “Schedule” in Instagram intent currently maps to **show date/city**, not a rehearsal timetable.

**App (~18%):** login, QR in the app, Family Code, missing app data.

**Brands — personal / fitting:** “which brand do *we* have”, how many looks, what to wear, shoes, fitting time, can we change the brand.

**Tickets and guests (~14%):** ticket count, QR, second ticket, guest transfer.

**Packages (~8%):** what is in Basic/Premium/VIP, photo/video, extra purchases.

**Show Day (~6%):** arrival time, registration, entrance, parking, what to bring, **start time of the show** unless it happens to appear in `starts_at` (usually date-only at midnight) or description.

**Backstage, Workshop, Payments, service-tier process, Sales routing:** unchanged; still policy + App / human.

After connection, the Voice wrapper should still forbid inventing anything not in the tool JSON. Public city/date/brand-list questions can stop being empty “check the App” when the tool returns a fact. Personal operational questions stay on App / escalation.

---

## Risks and limits

- `JfsReadService` is the integration. Voice reuse is wrapping it, not a new Core client.
- `publicEvents()` joins `event_infos` without filtering info type; duplicate or extra rows are possible.
- Date gate treats `client_show_event_date` null as announced (`null || bool`). Voice must not “fix” that independently of Instagram.
- JFS MySQL privilege of the configured user was not audited; application code does not write.
- Email client lookup is PII. Do not expose children, phone, or “found in database” on a phone call in v1.
- Facebook does not get LIVE FACTS today; do not treat Facebook as a second template.
- Tool latency: extra MySQL round-trip on the call. Same as Instagram per-reply SELECT.
- ElevenLabs dashboard tool registration is outside this repo and was not changed.
- If `JFS_DB_*` is empty, `isConfigured()` returns empty lists; tools must return a safe “unavailable” JSON, not a guessed show.

---

## Next implementation plan (code in a later step, not now)

1. Add two `VoiceToolInterface` classes wrapping `publicEvents()` and `publicBrandLineups()`, `readOnly: true`, JSON only, no credentials in schema or result.
2. Register them in `VoiceToolRegistry` beside `TestVoiceTool`.
3. Add Native Agent webhook routes under `POST /api/voice/tools/...` with existing `AuthenticateElevenLabsTool` (`ELEVENLABS_TOOL_TOKEN`). Prefer dedicated endpoints (same pattern as `test-context`) or one generic execute that only allows the new read-only names.
4. Unit tests with a fake/mock `JfsReadService` (no live JFS writes; no secret dumps). Reuse announcement/past-show assertions from `JfsEventFactsTest`.
5. Feature test: Bearer required; unauthenticated 401; payload has no env values.
6. Register the two webhook tools on the ElevenLabs Native Agent (dashboard). Do not change the Voice policy prompt body except a later, explicit one-line “use tools for live show/brand facts” if the agent otherwise never calls them.
7. Do not change Instagram, JFS Core, Bitrix, or Conversation Initiation preload.
8. Do not add phone lookup, participant tool, or any INSERT/UPDATE against `jfs`.

---

## Confirmation

Application code, Instagram bot, ElevenLabs configuration, Voice prompt, and the main YFS project were **not modified** in this audit. Only `docs/Development/LATEST_WORK_REPORT.md` is updated.
