# ElevenLabs Native Agent — YFS live webhook tools

Manual setup for production webhook tools on the ElevenLabs Native Agent.

This repo does not change the ElevenLabs UI. Paste the fields below into ElevenLabs.

Do **not** put token values, database credentials, or other secrets in this file.

Auth for these tools uses the **existing** ElevenLabs webhook secret already configured for YFS AI (`ELEVENLABS_TOOL_TOKEN` in Laravel). In ElevenLabs, reuse that same secret as a Bearer token. Do not create a new token.

Header:

```http
Authorization: Bearer <existing ElevenLabs tool secret>
```

Base URL:

`https://ai.youngfashionshow.com`

---

## Tool 1 — `get_public_shows`

| Field | Value |
| --- | --- |
| Tool name | `get_public_shows` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/public-shows` |
| Auth | Header `Authorization: Bearer <existing ElevenLabs tool secret>` |

### Description (paste into ElevenLabs)

Returns current public Young Fashion Show events from YFS Core: name, city, venue/location, dates only when announced, and a short description. Call when the caller asks which shows exist, when a show is, where it is, which city, venue/location, current or upcoming shows, or details about a specific show. Prefer this tool over memory or static policy for live show facts. Optional show_name and city filters narrow the list. If date_announced is false or starts_at/ends_at is null, the date is not announced — do not guess it. This is public show information only. It is not a personal schedule, rehearsal timetable, ticket, or package lookup.

### Request body schema

```json
{
  "type": "object",
  "properties": {
    "show_name": {
      "type": "string",
      "description": "Optional show name filter. Case-insensitive substring match. Omit to return all public shows."
    },
    "city": {
      "type": "string",
      "description": "Optional city filter. Case-insensitive substring match. Omit to return all public shows."
    }
  },
  "additionalProperties": false
}
```

Optional parameters: `show_name`, `city`. Both may be omitted. Empty body `{}` returns the full public list.

### Expected response

Success with matches:

```json
{
  "ok": true,
  "tool": "get_public_shows",
  "source": "yfs_core",
  "results": [
    {
      "name": "YFS EXAMPLE",
      "city": "City",
      "location": "Venue",
      "starts_at": "2099-01-01 00:00:00",
      "ends_at": null,
      "date_announced": true,
      "is_past": false,
      "description": null
    }
  ],
  "count": 1
}
```

No matches (`HTTP 200`):

```json
{
  "ok": true,
  "tool": "get_public_shows",
  "source": "yfs_core",
  "results": [],
  "count": 0,
  "message": "no_matching_shows"
}
```

Source unavailable (`HTTP 200`, not 500):

```json
{
  "ok": false,
  "tool": "get_public_shows",
  "source": "yfs_core",
  "results": [],
  "count": 0,
  "error": "source_unavailable"
}
```

Missing/invalid Bearer: `401` `{"message":"Unauthorized"}`.

If `date_announced` is `false`, `starts_at` and `ends_at` are `null`. Do not invent a date.

---

## Tool 2 — `get_show_brands`

| Field | Value |
| --- | --- |
| Tool name | `get_show_brands` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/show-brands` |
| Auth | Header `Authorization: Bearer <existing ElevenLabs tool secret>` |

### Description (paste into ElevenLabs)

Returns the public brand/designer lineup for Young Fashion Show events from YFS Core. Call when the caller asks which brands participate, which designers are on a show, the lineup for a city or show, or whether brands have been published. Prefer this tool over memory or static policy for public lineup facts. This is a public show lineup only. Do not use it to answer which brand is assigned to a specific child, family, or participant, fitting assignments, or number of looks. If brands is empty or lineup_published is false, the lineup is not published yet — do not invent brand names. Optional show_name and city filters narrow the list.

### Request body schema

```json
{
  "type": "object",
  "properties": {
    "show_name": {
      "type": "string",
      "description": "Optional show name filter. Case-insensitive substring match. Omit to return all public show lineups."
    },
    "city": {
      "type": "string",
      "description": "Optional city filter. Case-insensitive substring match. Omit to return all public show lineups."
    }
  },
  "additionalProperties": false
}
```

Optional parameters: `show_name`, `city`. Empty body `{}` returns all public lineups.

### Expected response

```json
{
  "ok": true,
  "tool": "get_show_brands",
  "source": "yfs_core",
  "results": [
    {
      "name": "YFS EXAMPLE",
      "city": "City",
      "starts_at": null,
      "date_announced": false,
      "is_past": false,
      "brand_count": 0,
      "brands": [],
      "lineup_published": false
    }
  ],
  "count": 1
}
```

`lineup_published: false` and `brands: []` mean the public lineup is not published yet.

No matching shows: same empty-results contract as `get_public_shows` (`message`: `no_matching_shows`).

Source unavailable: `ok: false`, `error: "source_unavailable"`.

This tool must **not** be used for personal brand assignment.

---

## Tool 3 — `resolve_customer_identity`

Full contract, override safety, binding, and the complete dashboard JSON (including `response_timeout_secs` and `pre_tool_speech`): `docs/Voice/CUSTOMER_IDENTITY.md`.

Native waiting speech: `docs/Voice/ELEVENLABS_TOOL_FILLER.md`.

| Field | Value |
| --- | --- |
| Tool name | `resolve_customer_identity` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/resolve-customer-identity` |
| Auth | Reuse the existing Authorization secret (`ELEVENLABS_TOOL_TOKEN`). Do not create a new secret. |
| `response_timeout_secs` | `20` |
| `pre_tool_speech` | `force` |

Do not add an LLM `phone` parameter. `system__caller_id` and `system__conversation_id` must be ElevenLabs **dynamic variables**.

---

## Tools 4–5 — extended identity search

Paste JSON from `docs/Voice/EXTENDED_IDENTITY_SEARCH.md`. Dashboard is not changed from this repo.

| Tool | URL | `response_timeout_secs` | `pre_tool_speech` |
| --- | --- | --- | --- |
| `start_extended_identity_search` | `https://ai.youngfashionshow.com/api/voice/tools/start-extended-identity-search` | `10` | `auto` |
| `get_extended_identity_search_status` | `https://ai.youngfashionshow.com/api/voice/tools/extended-identity-search-status` | `8` | `off` |

Reuse the same Authorization secret. Do not return candidates, phones, emails, or CRM payloads.

---

## Tool 6 — `get_customer_context`

Full contract: `docs/Voice/CUSTOMER_CONTEXT.md`.

| Field | Value |
| --- | --- |
| Tool name | `get_customer_context` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/customer-context` |
| Auth | Reuse the existing Authorization secret (`ELEVENLABS_TOOL_TOKEN`). Do not create a new secret. |
| `response_timeout_secs` | `20` |
| `pre_tool_speech` | `force` |

Personal operational data from **canonical YFS Core** for the already bound unique identity on this conversation. Not a public calendar. Not Bitrix.

---

## Tool 7 — `request_human_followup`

Full contract, Telegram reuse, post-call safety net, and dashboard JSON: `docs/Voice/HUMAN_FOLLOWUP.md`.

| Field | Value |
| --- | --- |
| Tool name | `request_human_followup` |
| Method | `POST` |
| URL | `https://ai.youngfashionshow.com/api/voice/tools/request-human-followup` |
| Auth | Reuse the existing Authorization secret (`ELEVENLABS_TOOL_TOKEN`). Do not create a new secret. |
| `response_timeout_secs` | `15` |
| `pre_tool_speech` | `force` |
| Execution | Immediate webhook. Do not use async. |

Creates a Voice human follow-up and notifies the existing Telegram manager group. Unknown Sales leads do not need YFS identity. The agent must not confirm transfer until `ok` is true.

---

## What not to configure

- Do not point ElevenLabs at the JFS database.
- Do not add rehearsal, ticket, payment, or Bitrix dump tools from this document. Personal children / participation goes through `get_customer_context` only.
- Keep `POST /api/voice/tools/test-context` as the POC smoke tool. It is not live show data.
