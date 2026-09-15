# Customer Identity and Bitrix24 Audit

Status: **audit only**. No code, schema, config, or production changes were made for this document.

Evidence labels used below:

- **VERIFIED** — observed in source, migrations, or live read-only JFS aggregates
- **INFERRED** — reasonable reading of verified structure, not proven in production Voice
- **NOT AVAILABLE** — searched and not found

Do not record API keys, tokens, passwords, phone numbers, emails, or names in this file. Counts and schema names only.

Repositories examined (read-only):

| Path | Role |
| --- | --- |
| `/var/www/yfs-ai` | Voice Assistant (this repo) |
| `/var/www/jfs` | YFS Core / JFS main project |
| `/var/www/fashion-planner` | Neighbor; no Bitrix REST client found in app sources |
| `/var/www/yfs-ai-sorter` | Neighbor; no Bitrix REST client found |

---

## 1. Executive summary

Caller identification for Voice cannot be a unique phone match into a Bitrix Contact.

**VERIFIED:**

- The operational customer record lives in JFS `app_users` (role `client`) with a single `phone` column and a single `name` column. Children live in `children` (`first_name` only; `last_name` was removed). Participation is `child_event_assignments`.
- YFS-AI already has a read-only `jfs` MySQL connection and `JfsReadService`. Live Voice tools `get_public_shows` and `get_show_brands` use public event/brand reads only. Client lookup today is **email-only** (`findClientByEmail`).
- Conversation initiation (`POST /api/voice/elevenlabs/conversation-initiation`) already normalizes `caller_id` into `voice_contacts`. It does **not** query JFS or Bitrix.
- There is **no** Bitrix24 REST/SDK client in YFS-AI or JFS. There are **no** `bitrix_contact_id` / `bitrix_deal_id` columns on customers.
- Bitrix is present in JFS as: catalog `code` fields labeled “Bitrix code” on events/brands/packages, plus inbound `yfs-report-api` drafts (`POST /api/incoming/new-client`) that upsert clients by **email**, not by phone.

**VERIFIED live JFS aggregates (counts only, 2026-09-15):**

- 793 client `app_users`; 791 have a phone; 2 have none
- Every stored client phone starts with `+` and contains dashes (`AppUser::formatPhoneMasked`)
- Digit-normalized phone is **not unique**: 125 duplicate groups (115 pairs, 8 triples, one group of 4, one group of 9), covering 267 client rows
- Email is unique across `app_users` (856 users / 856 distinct emails)
- Child `last_name` column does not exist; 586 children; almost all names are unique enough that only one parent has two children with the same `first_name`

**Recommended architecture (not implemented):** treat **YFS Core as the identity system of record for Voice**. Do not call Bitrix during conversation initiation. Reuse data already synced into JFS. Add `CustomerIdentityResolver` in Laravel YFS-AI that reads JFS. Use ElevenLabs Native Agent `pre_tool_speech` / tool-call sounds / soft timeout for fillers — not a second Laravel LLM call.

---

## 2. YFS Core findings

### 2.1 Connection from YFS-AI

**VERIFIED.** `config/database.php` connection `jfs` (`JFS_DB_*`). `JfsReadService::isConfigured()` requires database name + username. All reads are `DB::connection('jfs')->table(...)`. Instagram bot and Voice public tools share this service. Writes to JFS from YFS-AI: none in this service.

### 2.2 Parent / client

**VERIFIED model:** `App\Models\AppUser` → table `app_users`.

| Field | Notes |
| --- | --- |
| `id` | PK. Use as `yfs_app_user_id`. |
| `name` | Single display name. Filament labels it “first name”, but it is not split. |
| `email` | Unique. Import and incoming profile lookup key. |
| `phone` | Nullable, **not unique**. Mutator masks to `+CC-XXX-XXX-XXXX`. |
| `role` | Clients are `role = client`. Staff also live in this table. |
| `language` | Nullable (`en`/`ru`/`uk`/`es` observed). |
| `status` | `active` / `inactive` / `blocked`. |
| `account_source` | `legacy` / `client` / `import` / `admin`. |
| `contract_*` | Signed-contract metadata. Do not put PDFs/passwords into Voice. |

**NOT AVAILABLE on `app_users`:** `first_name`, `last_name`, second phone, `bitrix_contact_id`.

**VERIFIED live:** 738/793 client names contain a space (likely “First Last”); 55 do not.

### 2.3 Phone storage and lookup

**VERIFIED format:** setter `AppUser::formatPhoneMasked()` strips non-digits then rebuilds `+{country}-{operator}-{middle}-{tail}`. Live: 791/791 non-empty client phones start with `+` and contain `-`. Digit lengths: 11 (706), 10 (44), 12 (31), 13 (9), 9 (1).

**VERIFIED uniqueness:** no unique index on `phone`. Digit-normalized duplicates exist (see §1). Admin search uses SQL `LIKE` on the **masked** string (`ClientUserResource`, photo-service filters). Exact E.164 equality against `app_users.phone` will miss rows unless the same mask is applied.

**VERIFIED Voice normalizer:** `PhoneNumberNormalizer` stores `+` + digits (E.164-like) on `voice_contacts.phone_normalized`. That format does **not** equal JFS `app_users.phone`.

**INFERRED matching recipe for a future resolver (not implemented):**

1. Normalize caller to digits (existing Voice normalizer).
2. Compare to `REGEXP_REPLACE(app_users.phone, '[^0-9]', '')`.
3. Optionally also match last 10 digits when US-length variance is present (10 vs 11).
4. Restrict `role = client` and skip `blocked` unless product says otherwise.
5. If 0 rows → unknown. If 1 → unique. If >1 → ambiguous.

**Can one phone belong to several clients?** **VERIFIED yes.**

**Can one client have several phones?** **VERIFIED no** as a first-class field — one `phone` column. A parent might still call from another person’s number; that is a process case, not a schema case.

### 2.4 Name lookup

**VERIFIED:** parent name is `app_users.name` (one string). Child given name is `children.first_name`. Child `last_name` was dropped in migration `2026_03_25_090000_remove_last_name_weight_shoulders_from_children.php`. Import writes the yfs-report-api `participant` string into `children.first_name` (can be a full participant name, not only a first name).

**INFERRED name search:**

- Parent: case-insensitive match / prefix on `app_users.name` (and optionally `LIKE first%` + `LIKE % last`).
- Child: case-insensitive match on `children.first_name` (Filament/import already use fuzzy ±2 characters for inbound sync).
- Ambiguity resolution: child name, email, event/city, package — all exist in JFS.

### 2.5 Children and parent link

**VERIFIED:** `children.client_app_user_id` → `app_users.id`. Relation `AppUser::children()`. One parent can have several children (420 clients with 1, 67 with 2, 8 with 3, 2 with 4). `ChildData` holds measurements and photos — **not** for Voice preload.

### 2.6 Events / registrations / packages / brands

**VERIFIED** participation table `child_event_assignments` (unique `child_id + event_id`):

- `event_id`, `package_template_id`, `brand_id`, `second_brand_id`, `family_look_brand_id`
- `status` (`draft`/`ready`/`in_progress`/`completed`; live mostly `in_progress`)
- `payment_status` enum `paid` / `advance` / `unpaid` (live 321 / 147 / 13)
- `location` (FK-like code to `crm_locations` catalog — **not** Bitrix CRM)
- `privilege`, family-look flags, badge codes, photo/video links
- rehearsal: `rehearsal_slot_id`, `rehearsal_booked_at`, plus `child_event_assignment_rehearsal_bookings`

Related **VERIFIED** tables (row counts at audit time): tickets 432, parking 141, extra tickets 53, backstage 76, meal payments 412, paid rehearsal bookings 26, stage plan rows 9358, aux events 0, client-admin chat messages 158.

Events: `events` (`code` unique, labeled Bitrix code in Filament; `import_code`; `name`; `city`; `location`; `starts_at`/`ends_at`; `client_show_event_date`). Brands: `brands.code` + `event_brand`. Packages: `package_templates.code` + `name`.

### 2.7 Schedules, rehearsals, workshop, backstage, fitting

**VERIFIED:**

- Rehearsals: `event_rehearsal_slots`, `child_event_assignment_rehearsal_bookings`, `event_brand_rehearsals`, `event_paid_rehearsal_*`, stage code `jfs_rehearsal` and `jfs_brand_reh_e{event}_b{brand}[_rN]`
- Backstage: `event_backstage_tickets` + payments; stage code `foto_na_beksteidze`
- “Workshop” as a named table: **NOT AVAILABLE**. Closest: preparatory stages / aux events (`child_aux_events` currently empty)
- “Fitting” as a named table: **NOT AVAILABLE**. Closest operational stages: `interviu`, `beauty`, `hair_style`, `foto_v_obraze`, `CH`
- Schedule of a child at a show: `child_stage_plan` (timed stages, completion, evidence photos)

### 2.8 Payments

**VERIFIED in JFS (operational, not Stripe objects in Voice):**

- Assignment `payment_status` derived on import from yfs-report-api `status` / `balance` / `paid` / `total` (`IncomingClientDraftImportService::resolvePaymentStatus`)
- Stripe-backed extras: meals, parking, extra tickets, backstage, paid rehearsals, additional photos (`*_payments` tables)
- Photo assets have their own `payment_status`

Raw Bitrix deal payment schedule fields (`paid`, `total`, `balance`, `overdue`, `payment_schedule`) arrive on inbound payload and are **not** copied onto `app_users`. They are only reflected as assignment `payment_status` plus import-log `details`.

### 2.9 What `JfsReadService` already returns

**VERIFIED methods:**

| Method | Data | Safe for Voice? |
| --- | --- | --- |
| `publicEvents()` | Public show list with date-announcement guard | Yes (already a live tool) |
| `publicBrandLineups()` | Public brand names per show | Yes (already a live tool; not child assignment) |
| `findClientByEmail()` | One client by unique email + children `first_name`/`birthdate` | Exists for Instagram; **not** used by Voice. Returns phone. `clientFactBlock()` currently **forbids** listing children to the LLM. |
| `eventsFactBlock` / `brandsFactBlock` / `clientFactBlock` | Instagram prompt prose | Do not copy into Native Agent tools |

`lastReadFailed()` covers unconfigured DB or query exceptions.

### 2.10 What requires extending `JfsReadService`

**VERIFIED missing (needed for identity):**

- `findClientsByPhoneDigits(string $digits): list`
- `findClientsByName(string $query): list` on `app_users.name`
- `findChildrenByName(string $query)` / disambiguate by child first_name
- `loadCustomerSupportSnapshot(int $appUserId)` — children, assignments, event city, package name, payment_status, rehearsal bookings — **read-only SELECT**

Do **not** return passwords, `client_password_display`, contract PDFs, badge QR secrets, photo URLs, or full stage-plan dumps in initiation.

---

## 3. Bitrix findings

### 3.1 REST client

**NOT AVAILABLE** in `/var/www/jfs` and `/var/www/yfs-ai`:

- no Bitrix SDK / `crm.contact.*` / `crm.deal.*` HTTP client
- no Bitrix webhook URL config in YFS-AI `config/services.php`
- no `bitrix_contact_id` / `bitrix_deal_id` columns

Searched JFS `app/` for `bitrix`, `crm.contact`, `BX24`. Hits are Filament labels “Bitrix code” and import comments.

### 3.2 What *does* exist (inbound, not outbound CRM)

**VERIFIED JFS inbound API** (`routes/api.php`, middleware `incoming.api`, token `INCOMING_API_TOKEN` — value not recorded):

| Endpoint | Kind | Behavior |
| --- | --- | --- |
| `POST /api/incoming/new-client` | `client_draft` | `IncomingClientDraftImportService`. Source must be `yfs-report-api`. Upserts client **by email**, child by fuzzy first_name, assignment by event+package. |
| `POST /api/incoming/client-profile` | — | Lookup **by email only**. Returns parent + children + assignments (tickets, rehearsals, brands, package). |
| `POST /api/incoming/client-password` | — | Email lookup (out of Voice scope). |
| `POST /api/incoming/assignment-brands` | `assignment_brands` | Brand sync onto assignments. |
| `POST /api/incoming/webhook` | `registr` / `payment` | **Stores payload only.** Comment: `TODO: обработка структуры JSON позже`. Live: 24 `registr`, 1 `payment`. |
| Fashion planner / AI sorter incoming | — | Staff tools, not CRM identity. |

**VERIFIED envelope** (keys only, from a successful import row):

```text
source, payload, external_id
payload: paid, email, event, phone, total, client, manual, schema, signed, status,
         balance, manager, overdue, package, canceled, category, discount, payments,
         sold_date, participant, agreement_id, contract_url, is_reservation, payment_schedule
payments[]: for, date, type, amount
```

`source` on 677699 import logs = `yfs-report-api`.

**INFERRED:** `yfs-report-api` is an upstream reporter (very likely Bitrix-backed) that **pushes** deals into JFS. Voice should consume the **already imported JFS rows**, not call Bitrix.

### 3.3 Catalog “Bitrix code”

**VERIFIED:** `events.code`, `brands.code`, `package_templates.code` are unique string codes. Filament label = Bitrix code. Used to match inbound event names (`resolveEvent` also tries `name` and `import_code`). These identify **catalog** entities, not Contacts.

### 3.4 Can existing code find a Bitrix Contact by phone / name / deals?

| Capability | Verdict |
| --- | --- |
| Contact by phone via Bitrix API | **NOT AVAILABLE** (no client) |
| Contact by name via Bitrix API | **NOT AVAILABLE** |
| Related Deals | **NOT AVAILABLE** as Bitrix entities. JFS has assignments + import `external_id` |
| Latest Deal | **INFERRED** as latest `child_event_assignments` / latest successful import for `app_user_id` |
| Payment / package | **VERIFIED** in JFS assignment + package template, sourced from inbound payload |
| Registered children | **VERIFIED** in JFS `children` |
| CRM notes / timeline comments | **NOT AVAILABLE** in JFS customer tables. Closest: `client_admin_chat_messages` (in-app chat, 158 rows), child `notes` |
| Company | **NOT AVAILABLE** |

If product later requires live Bitrix notes/timeline, that needs a **new** read-only REST wrapper (`crm.contact.list` with `PHONE`, `crm.deal.list` by `CONTACT_ID`, `crm.timeline.comment.list`). That is not reuse; it is new work. Do not put Bitrix credentials in ElevenLabs or the LLM.

---

## 4. YFS ↔ Bitrix mapping

| Identifier | Where | Strength |
| --- | --- | --- |
| Email | `app_users.email` unique; inbound lookup key | **VERIFIED** strongest cross-system key in JFS |
| `incoming_webhook_import_logs.external_id` | Present on essentially all import logs | **VERIFIED** stored. **INFERRED** as upstream deal/report id. Among **successful** imports: 0 `external_id` values map to two `app_user_id`s; 47 users have more than one `external_id` (multiple deals). 195 distinct users touched by successful import. |
| `payload.agreement_id` | Stored in import `details` JSON, not a dedicated column | **VERIFIED** key exists |
| Phone | Copied onto `app_users.phone` at import if empty/changed | **VERIFIED** not unique; cannot join systems 1:1 |
| `events.code` / `brands.code` / `package_templates.code` | Catalog | **VERIFIED** Bitrix-labeled codes, not people |
| `voice_contacts` | YFS-AI only | **VERIFIED** no YFS/Bitrix FKs (`docs/DATABASE.md`) |

**NOT AVAILABLE:** `bitrix_contact_id` on `app_users` or `voice_contacts`.

After finding a person in JFS, “find them in Bitrix” is **not** a local FK lookup. The only stored handle that looks like an upstream id is import `external_id` (deal-like, not contact-like). Live Bitrix Contact search would be a new API.

---

## 5. Phone lookup strategy

```text
Twilio caller_id
  → PhoneNumberNormalizer (existing, Voice DB)
  → digits
  → JFS SELECT app_users
       WHERE role='client'
         AND REGEXP_REPLACE(phone,'[^0-9]','') IN (full_digits, last10)
  → 0 / 1 / N
```

| Result | Voice behavior (proposed, not implemented) |
| --- | --- |
| 0 | Unknown caller. Public tools only. Later: name + child tools. |
| 1 | Unique YFS identity. Preload compact context. |
| N | Ambiguous. Do not guess. Ask child first name / event city / email. |

Do not treat `voice_contacts.phone_normalized == app_users.phone` as a match.

Do not claim uniqueness: **VERIFIED** 125 colliding digit groups.

---

## 6. Name lookup + ambiguity resolution

**VERIFIED available signals, in useful order:**

1. Child `first_name` (and participant string already stored there)
2. Parent `name` substring
3. Email (unique if they dictate it)
4. Event / city (`events.city`, assignment)
5. Package name
6. Language (`app_users.language`) — weak

**VERIFIED weak / unusable:** child last name (column removed). Bitrix Company. Second phone.

If several parents share a phone, ask for the child’s name before loading payment/package details.

---

## 7. Proposed Unified Customer Identity

Store in Voice session / `voice_contacts.metadata` (small JSON). **Not** a full CRM card.

```text
{
  match_status: none | unique | ambiguous | unresolved,
  yfs_app_user_id: int | null,
  yfs_child_ids: int[],
  display_name: string | null,          // app_users.name
  child_first_names: string[],          // first names only
  preferred_language: en|ru|uk|null,    // app_users.language if supported
  current_event: { id, name, city } | null,
  import_external_ids: string[],        // optional, not spoken
  confidence: unique | phone_collision | name_only | none,
  sources: ["yfs_core"],
  loaded_at: iso8601
}
```

**Do not preload:** measurements, photos, badge codes, passwords, contract URLs, full stage plans, Stripe ids, raw inbound payloads, Bitrix tokens.

Bitrix ids stay empty until a real REST client exists.

---

## 8. Proposed CustomerIdentityResolver

Do **not** implement a symmetric YFS+Bitrix fan-out. Bitrix outbound is missing; JFS already has the synced snapshot.

```text
caller_id
  → PhoneNumberNormalizer
  → YfsCoreIdentitySource (JfsReadService extensions)
       lookupByPhoneDigits
       lookupByName
       disambiguateByChildName
  → Unified Customer Identity
  → optional later: BitrixRestIdentitySource (NOT AVAILABLE today)
```

Laravel home: YFS-AI `app/Services/Voice/Identity/` (future). Inputs are already-normalized phones; **no DB credentials** leave Laravel. ElevenLabs receives only the compact identity fields the agent is allowed to say.

Methods:

- `lookupByPhone(string $rawPhone): IdentityResult`
- `lookupByName(string $first, string $last): IdentityResult` — internally search `app_users.name` and `children.first_name`
- `disambiguate(IdentityResult $pending, array $hints): IdentityResult` — hints: `child_name`, `email`, `city`

Instagram `findClientByEmail` remains separate; Voice may reuse the query pattern, not the Instagram fact-block wording.

---

## 9. Conversation-initiation preload analysis

**VERIFIED current flow** (`ElevenLabsConversationInitiationController`):

1. Bearer `AuthenticateElevenLabsTool`
2. `VoiceContactDirectory::findOrCreateFromCallerId` (local `voice_contacts`, no JFS)
3. `VoiceAssistantPromptBuilder::build()` (local `voice_assistant_settings`)
4. JSON `conversation_initiation_client_data` with prompt override; `agent.language` only if stored preferred language is en/ru/uk
5. `calls_count` is **not** incremented here
6. No `dynamic_variables`, no YFS ids

**Can we run CustomerIdentityResolver here?**

| Work | Where | Latency class |
| --- | --- | --- |
| Phone normalize + `voice_contacts` upsert | YFS-AI MySQL | **VERIFIED** already on this path; expected milliseconds |
| Prompt assemble | YFS-AI MySQL | **VERIFIED** already on this path |
| JFS phone SELECT | local `jfs` MySQL on same host | **INFERRED** ~10–50 ms if indexed/scan of 793 clients; even a table scan is small |
| JFS children + latest assignment JOIN | same | **INFERRED** tens of ms |
| Bitrix HTTP | — | **NOT AVAILABLE**; if added, typically 200–2000+ ms, timeout risk |

ElevenLabs initiation webhook must return before greeting continues. A local JFS lookup is compatible. A synchronous Bitrix REST call on this webhook is **not** safe.

**Recommended preload on initiation (future):** unique phone match only → compact identity into `conversation_config_override` (prompt snippet or dynamic variables **after** they are declared in the agent). Ambiguous/unknown → no personal facts in the greeting.

**Recommended lazy tools:** package, payment_status detail, rehearsal slot, tickets, stage/fitting, chat notes.

Do not block initiation on import-log scans of 677k rows; query `app_users` / `children` / `child_event_assignments` by id after phone match.

---

## 10. Bitrix latency analysis

**NOT AVAILABLE** to measure: no REST client, no production Bitrix call from these repos.

**INFERRED** if a client were added: extra RTT + Bitrix rate limits + `crm.contact.list` + `crm.deal.list` easily exceeds initiation budget and Native Agent `response_timeout_secs` (default 20s on webhook tools).

Safe scheme: **YFS lookup on initiation + Bitrix never on initiation**. If live Bitrix notes are required later, a dedicated Voice tool with ElevenLabs `pre_tool_speech=force` and timeout ≥ 8s, not the greeting webhook.

---

## 11. Proposed future Voice tools (available data only)

Do not invent Bitrix tools until a REST client exists.

| Tool (proposed name) | Source already in JFS | Init vs tool |
| --- | --- | --- |
| `get_public_shows` | `JfsReadService::publicEvents` | **Current** |
| `get_show_brands` | `publicBrandLineups` | **Current** |
| `identify_caller` / implicit initiation | phone digits → `app_users` | Initiation if unique |
| `find_customer_by_name` | `app_users.name`, `children.first_name` | Tool |
| `get_customer_children` | `children` | Tool / preload first names only |
| `get_customer_assignments` | `child_event_assignments` + event + package + brand | Tool |
| `get_assignment_payment_status` | `payment_status` | Tool |
| `get_rehearsal_bookings` | rehearsal bookings + slots | Tool |
| `get_ticket_summary` | parking / extra / backstage ticket tables | Tool |
| `get_stage_status` | `child_stage_plan` (filtered, no evidence photos) | Tool |

**Not proposable from available code:** Bitrix timeline, Bitrix Company, live deal comments, workshop entity, fitting entity as its own table.

---

## 12. Tool filler / latency strategy

Nothing implemented for Native Agent in this audit.

**VERIFIED in repo today:**

- Custom LLM fallback: `VoiceFillerPhraseService` + SSE buffer words (`"... "`). Applies to Node `voice-runtime`, **not** to Native Agent webhook tools.
- Native Agent live-tool doc (`docs/Voice/ELEVENLABS_YFS_LIVE_TOOLS.md`) does not mention filler, `pre_tool_speech`, or timeouts.
- This repo does not change ElevenLabs UI.

**VERIFIED from ElevenLabs current docs (not configured here):**

| Mechanism | Role |
| --- | --- |
| Webhook tool `pre_tool_speech` (`auto` / `force` / `off`) | Agent speaks a short acknowledgement **before** the webhook |
| `tool_call_sound` + `tool_call_sound_behavior` | Ambient sound while the tool runs |
| `execution_mode` `immediate` / `post_tool_speech` / `async` | When the HTTP call starts |
| `response_timeout_secs` (about 5–120s, default 20) | Tool HTTP timeout |
| Conversation-flow **soft timeout** | Filler while waiting for the **LLM**, not specifically the webhook |

**Requirement for the next stage (do not implement now):**

Use ElevenLabs Native Agent tool settings so the caller hears a short phrase during lookup. Do not add a Laravel/Gemini call just to generate fillers.

Suggested spoken set (for ElevenLabs agent copy / `force` pre-speech examples; not Laravel):

- RU: «Секунду, сейчас посмотрю.» / «Одну секунду, проверю информацию.» / «Сейчас посмотрю.» / «Момент, я проверю.» / «Секунду, уточню данные.»
- EN: «One moment, let me check.» / «Give me a second, I’ll look that up.» / «Let me verify that.»
- UK: «Секунду, зараз подивлюся.» / «Одну секунду, перевірю інформацію.» / «Зараз уточню дані.»

Configure `pre_tool_speech=force` (or `auto` after measuring latency) on future identity/CRM webhook tools. Keep Custom LLM buffer-word code as fallback only.

---

## 13. Security / privacy

**VERIFIED constraints to keep:**

- JFS and Bitrix remain read-only from Voice
- Never send JFS/Bitrix credentials to ElevenLabs or the LLM
- `JfsReadService::findClientByEmail` already returns phone; Voice must not dump it back into TTS unless the product explicitly wants confirmation
- Do not expose `client_password_display`, worker passwords, contract PDFs, badge codes, photo URLs, or raw import payloads
- `voice_contacts.metadata` must stay a compact identity, not a CRM dump
- Instagram Assistant and Node Custom LLM fallback stay unchanged
- Initiation retries must not increment `calls_count` (already the case)

Ambiguous phone match: do not speak another family’s child names until the caller confirms.

---

## 14. Gaps / unknowns

| Item | Label |
| --- | --- |
| Bitrix REST base URL, auth method, and field map for Contact/Deal | **NOT AVAILABLE** in these repos |
| Whether `external_id` is Bitrix deal id vs another reporter id | **INFERRED** only |
| Why 125 phone-digit collisions exist (shared family phone vs bad data vs test rows) | **NOT AVAILABLE** without PII review; do not dump rows |
| `POST /api/incoming/webhook` `registr`/`payment` payload meaning | **VERIFIED** stored, processing **NOT AVAILABLE** (TODO in controller) |
| ElevenLabs production agent `pre_tool_speech` current values | **NOT AVAILABLE** in git (UI not in this repo) |
| Initiation webhook timeout configured in ElevenLabs | **NOT AVAILABLE** in git |
| Workshop / fitting as business terms vs stage codes | **INFERRED** via stages only |
| `crm_locations` vs Bitrix | **VERIFIED** local catalog, not Bitrix API |

---

## 15. Recommended implementation order

1. Extend `JfsReadService` with digit phone lookup + name/child lookup (read-only SELECTs, tests with fakes). No Bitrix.
2. `CustomerIdentityResolver` (YFS-only) returning the compact identity in §7.
3. Optional initiation preload **only when match_status=unique**; keep greeting generic otherwise.
4. Native Agent tools for assignments / payment_status / rehearsals, wrapping the same read service.
5. Ambiguity tool (child name / city) for colliding phones.
6. ElevenLabs UI: `pre_tool_speech` + timeout on those tools (out of repo).
7. Bitrix REST **only if** live CRM notes are still required after JFS snapshot tools. That is a new client, not reuse.

Do not start with a Bitrix connector. The data Voice needs for personalization is already in JFS.
