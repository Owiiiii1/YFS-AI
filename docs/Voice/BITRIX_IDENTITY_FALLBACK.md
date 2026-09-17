# Bitrix24 identity fallback (read-only)

Status: **Current**. Bitrix24 is an optional, read-only fallback after YFS Core. It is not the Voice identity system of record.

YFS identity contract: `docs/Voice/CUSTOMER_IDENTITY.md`.  
Extended search: `docs/Voice/EXTENDED_IDENTITY_SEARCH.md`.  
Audit of this portal’s CRM shape: `docs/Voice/CUSTOMER_IDENTITY_AND_BITRIX_AUDIT.md`.

Do not put webhook URLs, tokens, phones, emails, names, or raw CRM payloads in this file.

---

## Connection

Production stores the incoming webhook **base URL** outside Git:

- Env key: `BITRIX_REST_WEBHOOK_URL`
- Config: `config/services.php` → `services.bitrix.webhook_url`
- `.env.example` has an empty placeholder only

The value must be the webhook base (no trailing method such as `profile.json`). Laravel strips a trailing `/profile.json` or `/scope.json` if present.

Never log, document, or return the URL to ElevenLabs.

If the env key is empty, Bitrix is treated as unconfigured. Voice continues with YFS-only identity.

Related config (not secrets):

| Key | Default | Role |
| --- | --- | --- |
| `BITRIX_REST_TIMEOUT_SECONDS` | `3` | HTTP timeout per REST call (clamped 1–8s) |
| `BITRIX_IDENTITY_FAST_BUDGET_MS` | `1500` | Initiation fast-path budget after YFS |
| `BITRIX_IDENTITY_FAST_TOOL_BUDGET_MS` | `4000` | Spoken-tool fast-path budget after YFS |
| `BITRIX_IDENTITY_MAX_FAST_CONTACTS` | `3` | Max contacts to fetch emails for |

---

## Client

`BitrixReadOnlyIdentityClient` is a Laravel-internal allowlist. Voice tools cannot call arbitrary Bitrix methods.

Allowlisted methods:

- `scope` / `profile` (connectivity smoke only)
- `crm.duplicate.findbycomm`
- `telephony.externalCall.searchCrmEntities`
- `crm.contact.list`
- `crm.contact.get`

Not used and not allowlisted: contact/deal/lead add or update, timeline, activities, telephony registration writes, companies, files, comments, payments, contracts.

Failures (timeout, HTTP error, malformed JSON, Bitrix `error`, HTTP 429) become `unavailable` / a safe YFS fallback. They never fail a call.

---

## Fast path

YFS Core is always first.

```text
incoming caller_id
  → YFS Core phone lookup
  → UNIQUE? use YFS identity
  → AMBIGUOUS? keep YFS ambiguous (Bitrix must not pick)
  → source_unavailable? keep YFS result
  → Bitrix exact phone
      → not unique? unknown / ambiguous
      → unique contact → emails inside Laravel → findClientByEmail()
          → UNIQUE YFS customer → normal CustomerIdentityResult UNIQUE
          → missing / unknown / several YFS customers → not a confirmed YFS client
```

Spoken name:

```text
resolve_customer_identity
  → YFS name / child lookup first
  → UNIQUE? use YFS identity
  → otherwise Bitrix controlled name lookup (optional child-field intersect)
  → emails inside Laravel → YFS
```

A Bitrix contact without a unique YFS email match is only a hint for extended search. It is not `authenticated` / `identified`.

---

## Phone search

Primary: `crm.duplicate.findbycomm` with `entity_type=CONTACT`, `type=PHONE`.

Fallback if that returns no CONTACT ids: `telephony.externalCall.searchCrmEntities`, CONTACT rows only.

Do **not** use `%PHONE` or `FIND` on this portal. The audit showed those filters can return almost the whole CRM.

Phone may be zero, unique, or ambiguous. It is not globally unique.

---

## Name search

`crm.contact.list` with `%NAME` on the spoken string. If that result is not unique and the spoken string has at least two words, a second list uses `%NAME` + `%LAST_NAME`. Totals above 8 are treated as ambiguous without fetching ids.

If the spoken string is Cyrillic and the first controlled lookup is `not_found`, Laravel issues **one** extra `crm.contact.list` using a single Latin form (`IdentityNameMatcher::primaryLatin`). No additional REST methods, no `FIND`, no `%PHONE`, no CRM dumps. The extra query is skipped when the original string is already Latin, unique, ambiguous, or unavailable.

Child hint (extended / remaining budget only): `%UF_CRM_1748955762209`, with the same optional one-shot Latin retry on a Cyrillic miss.

Bitrix name match never becomes YFS unique by itself.

---

## Bitrix → YFS linkage

Only email, and only inside Laravel:

1. `crm.contact.get` with `select: ID, EMAIL` for at most a few unique contacts
2. Normalize emails
3. `JfsReadService::findClientByEmail()` for each
4. One YFS client → UNIQUE
5. Several different YFS clients → ambiguous
6. None → not a YFS identity

Email is never returned to ElevenLabs. Multiple emails on one contact are each checked; they must collapse to one YFS customer.

---

## What Voice / ElevenLabs never receive

- Raw contact / deal / lead
- Phone, email, address, tax id
- Payment or contract URLs
- Manager comments, timeline, social networks, photos, files
- Arbitrary custom fields
- Candidate lists
- Bitrix contact ids as identity proof

ElevenLabs receives the existing YFS identity contract only (`status`, optional `customer.display_name`, `next_action`).

---

## Metrics (no PII)

Logs may include:

- `bitrix.identity.phone_lookup` / `phone_lookup_ms`
- `bitrix.identity.name_lookup` / `name_lookup_ms`
- `bitrix.identity.yfs_link` / `yfs_link_ms`
- `bitrix.identity.total` / `total_ms`

Allowed fields: status, match_count, method name, milliseconds, HTTP status code, sanitized Bitrix error code. No phones, emails, names, ids, URLs.

Sanitized production latency is recorded in `docs/Development/LATEST_WORK_REPORT.md`.
