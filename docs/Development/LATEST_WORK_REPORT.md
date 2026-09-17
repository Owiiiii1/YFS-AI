# Latest Work Report

## Task

Extend existing `CustomerIdentityResolver` with conservative multilingual name matching (RU / UA / EN) so spoken Cyrillic names can match Latin-stored YFS/Bitrix names without creating a parallel resolver.

## Status

Done. Architecture unchanged: YFS-first → narrow Bitrix fallback → YFS canonical identity. No YFS/Bitrix writes. No Instagram or ElevenLabs dashboard changes. `resolve_customer_identity` API contract unchanged.

## Commit hash

Pending first push; recorded in the follow-up commit on `main`.

## What existed before

Voice identity already:

1. Matched YFS clients by exact whole-word, order-insensitive, case-insensitive name (`Ann` ≠ `Anna`).
2. Intersected parent name with child first name.
3. Used trusted caller phone on initiation, not as an LLM tool argument.
4. Optionally fell back to allowlisted Bitrix `crm.contact.list` `%NAME` / `%LAST_NAME`, then emails inside Laravel, then `findClientByEmail`.
5. Bound only `unique` results onto `VoiceContact`.

A spoken form such as `Евгения Коваленко` did not match a stored `Yevheniia Kovalenko`. Similarity alone was correctly not a unique rule — the gap was candidate generation across scripts.

## Chosen algorithm

Shared layer `App\Services\Identity\IdentityNameNormalizer` + `IdentityNameMatcher`:

1. **Normalize:** NFKC when `Normalizer` exists; lowercase; `ё→е`; `ґ→г`; hyphens/apostrophes → spaces; collapse whitespace; Unicode letters/digits only.
2. **Exact match:** whole-word alignment on folded tokens (order-insensitive). This remains the unique-capable YFS name match.
3. **Variant match:** each query word must share a transliteration/alias **key** with a distinct stored word. Keys are a bounded BFS of UA/RU → Latin character options (cap 24 variants/word), then a **small** alias list only for language forms transliteration cannot produce (`Julia`/`Yuliia`, `Alexander`/`Oleksandr`, `Kateryna`/`Ekaterina`, …). Not a name dictionary.
4. **Resolver:** exact YFS pool first. Only if empty, variant pool. Variant-only never calls `unique`.
5. **Bitrix:** same REST methods. If the spoken string is Cyrillic and the controlled lookup is `not_found`, **one** extra `%NAME` (and existing `%NAME+%LAST_NAME` split) using `primaryLatin()` (canonical alias if any). No `FIND`, no `%PHONE`, no dumps.

First/last order is already handled by word alignment. Child names use the same exact-then-variant path.

## Where multilingual matching runs

**Both fast and extended**, because it is not a heavy fuzzy search:

| Path | What runs |
| --- | --- |
| Fast YFS | Exact in-memory scan (~800 clients). On miss, a second PHP scan with variant keys. No extra SQL shape. |
| Fast Bitrix | Only after YFS is not unique. +0 queries if already Latin/unique/ambiguous. +1 Latin controlled list on a Cyrillic miss. |
| Extended | Same `CustomerIdentityResolver` with an 8s budget; trusted `VoiceContact.phone_normalized` is passed into spoken hints so a variant name can corroborate an ambiguous/shared phone. |

Fast initiation phone path is unchanged (no name fuzzy there).

## Why this is safe

- No writes to YFS or Bitrix.
- Bitrix allowlist unchanged.
- Variant/fuzzy name is **candidate generation only**.
- `unique` still requires an existing identity signal (below).
- Similar names (`Ann`/`Anna`, `Евгения`/`Евгений`, `Юлия`/`Юліана`) do not share keys.
- Several variant candidates → `ambiguous`; never auto-pick; never list names to ElevenLabs.
- Logs still only `status` / `match_method` / `match_count` (no PII).
- Tests use synthetic names only.
- LLM still cannot send `phone`; trusted phone comes from `VoiceContactSessionResolver`.

## Signals that may confirm `unique`

- Exact YFS whole-word name (existing Latin/English behavior).
- Exact YFS child name (existing).
- Parent **and** child both match the same parent (exact or variant) — child is extra evidence.
- Trusted session phone intersects exactly one name/child candidate.
- Existing unambiguous YFS phone match (initiation / extended phone-first).
- Bitrix controlled lookup → emails in Laravel → one canonical YFS `findClientByEmail`.

A Bitrix contact without that email linkage is never a YFS customer.

## Cases that stay `ambiguous` (or `not_found`)

- Single variant-only name hit, no child, no trusted phone, no Bitrix email link → `ambiguous` (`ask_child_name`), even if `match_count` is 1.
- Several multilingual candidates.
- Exact last-name-only hits that already existed (`Ivanova`).
- Variant child-only with no extra signal.
- Trusted phone that does not corroborate the spoken variant (falls through; variant-only still not unique).
- Similar but distinct names → `not_found` or a different candidate, never a wrong `unique`.
- Bitrix contact with no unique YFS email.

## File changes

Added:

- `app/Services/Identity/IdentityNameNormalizer.php`
- `app/Services/Identity/IdentityNameMatcher.php`
- `tests/Unit/Identity/IdentityNameMatcherTest.php`
- `tests/Unit/Voice/CustomerIdentityResolverMultilingualTest.php`

Updated:

- `app/Services/Jfs/JfsIdentityMatch.php` — exact match uses the normalizer; `nameMatchesVariant()`.
- `app/Services/Jfs/JfsReadService.php` — `findClientsByNameVariants` / `findClientsByChildNameVariants`.
- `app/Services/Voice/Identity/CustomerIdentityResolver.php` — conservative spoken pipeline; optional trusted phone.
- `app/Services/Voice/Tools/ResolveCustomerIdentityVoiceTool.php` — resolve after trusted session so phone can corroborate.
- `app/Services/Voice/Identity/ExtendedVoiceIdentitySearchRunner.php` — pass trusted phone into spoken fast resolve when phone was not already unique.
- `app/Services/Bitrix/BitrixReadOnlyIdentityClient.php` — one Latin retry on Cyrillic miss.
- `tests/Support/FakeJfsReadService.php` and identity/Bitrix/tool tests.
- `docs/Voice/CUSTOMER_IDENTITY.md`, `BITRIX_IDENTITY_FALLBACK.md`, `EXTENDED_IDENTITY_SEARCH.md`.

Unchanged: Instagram module, neighbor projects, ElevenLabs tool JSON, webhook URL, credentials.

## Tests and results

Relevant suite (matcher, JFS match, resolver, Bitrix client, Bitrix fallback, spoken tool, linker, extended search, webhook):

`85 passed` (363 assertions).

Coverage includes:

- exact Latin name still unique
- Cyrillic RU → Latin stored name (candidate, not unique alone)
- Cyrillic UA → Latin stored name
- common transliteration variants
- child_name Cyrillic → Latin with parent variant → unique
- first/last order and hyphen folding
- ambiguous multilingual pair
- similar names must not become unique
- trusted phone + multilingual name → unique
- existing English/Latin unique + `Ivanova` ambiguous
- Bitrix still requires email; no `FIND` / `%PHONE`; Latin retry on Cyrillic miss

Full `php artisan test`: **312** tests, **262** passed, **46** skipped (existing SQLite/DB skips), **4** errors in `BotPromptPatchServiceTest` and `AiPromptAnalysisInfrastructureTest` (prompt-path policy / existing `gemini` provider row). Those tests do not touch identity code and are unrelated to this change.

## Latency impact

- Fast unique Latin names: one YFS scan, same as before (no variant scan, no extra Bitrix).
- Fast Cyrillic/transliteration miss: one extra in-memory pass over the same ~800 `id/name/language` rows (milliseconds).
- Fast Bitrix: +0 or +1 controlled `crm.contact.list` (plus the existing first/last split on that string). Still inside the 4s spoken-tool budget.
- No unbounded fuzzy search on the fast path.

## Bitrix API impact

Allowlist unchanged: `scope`, `profile`, `crm.duplicate.findbycomm`, `telephony.externalCall.searchCrmEntities`, `crm.contact.list`, `crm.contact.get`.

Name lookup still `%NAME` and optional `%NAME`+`%LAST_NAME`. Child still `%UF_CRM_1748955762209`. On a Cyrillic `not_found` only, one additional list with a single Latin form. No FIND, no `%PHONE`, no deals/timeline/activity/files/comments, no writes.

## Migration / config impact

**None.** No new tables, columns, env keys, or `config/services.php` keys. Existing `BITRIX_*` budgets still apply. `php artisan config:cache` is not required for this change.

## Still verify with a real call

1. Call from a known unique number: initiation identity unchanged.
2. Unknown/shared number: speak a Cyrillic name whose YFS row is Latin (synthetic or a known-safe test client). Expect `ask_child_name`, not an immediate unique, unless trusted phone or child/email already confirms.
3. Same caller: add child first name in Cyrillic matching a Latin `children.first_name` → `unique` and bind.
4. Confirm ElevenLabs still receives only `status` / `next_action` / `customer.display_name`.
5. If Bitrix is configured: a Latin CRM contact with a unique YFS email can still confirm after a Cyrillic spoken miss; a CRM-only contact still must not identify.
