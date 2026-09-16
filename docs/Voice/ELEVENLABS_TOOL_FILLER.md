# ElevenLabs Native Agent — tool waiting speech (filler)

This repo does **not** change the ElevenLabs dashboard. Laravel does **not** return filler text, does **not** add `sleep`, and does **not** run an extra LLM call for waiting speech.

Waiting speech is native ElevenLabs **pre-tool speech** on the webhook tool (`pre_tool_speech`). Optional typing sound: `tool_call_sound`.

Official tool fields (Agents webhook / tools create API):

| Field | Values | Role |
| --- | --- | --- |
| `pre_tool_speech` | `auto` (default), `force`, `off` | Whether the agent speaks a short phrase **before** the webhook runs |
| `force_pre_tool_speech` | deprecated boolean | Do not use; set `pre_tool_speech` instead |
| `tool_call_sound` | `typing`, `elevator1`–`elevator4`, or omit | Optional sound **during** the tool |
| `tool_call_sound_behavior` | `auto` (default), `always` | `auto` plays the sound only when there is pre-tool speech |
| `response_timeout_secs` | 1–120, default 20 | Wait for Laravel; not filler |

There is no separate Laravel “filler phrase” field. Variability comes from the agent choosing one short line in the **current conversation language**, using the examples in wrapper v6.

---

## 2–3 minute dashboard setup

1. ElevenLabs → **Agents** → YFS Voice Assistant → **Tools**.
2. Open each webhook tool (or paste JSON in the tool JSON editor).
3. Find **Pre-tool speech** / `pre_tool_speech` (same control as deprecated “force pre-tool speech”).
4. Apply the table below. Save / publish the agent.

This setting is **per tool**, not agent-wide. That is how we avoid filler on instant lookups.

| Tool | `pre_tool_speech` | `tool_call_sound` | Why |
| --- | --- | --- | --- |
| `resolve_customer_identity` | `force` | `typing` (`behavior: auto`) | Fast identity lookup should not be silent (“Секунду, сейчас посмотрю.”) |
| `start_extended_identity_search` | `auto` | omit, or `typing` + `auto` | Returns immediately; a short phrase is allowed, then the agent explains that extended search may take a little time |
| `get_extended_identity_search_status` | `off` | omit | Instant DB status; never speak a waiting phrase |
| `get_public_shows` | `auto` | omit, or `typing` + `auto` | Fast JFS read; speak only if recent calls were slow |
| `get_show_brands` | `auto` | omit, or `typing` + `auto` | Same as public shows |
| `get_current_yfs_test_context` | `off` | omit | Instant synthetic POC |

Do **not** set `pre_tool_speech: force` on every tool.

`response_timeout_secs`: keep `20` unless Test Tool shows timeouts.

Authorization: reuse the existing workspace secret already used by `get_public_shows`. Do not create a new token.

---

## Language

Pre-tool speech uses the **current conversation language** (initiation `agent.language` / in-call language detection). Do not pin filler to Russian in the dashboard.

Laravel wrapper v6 lists example phrases so the model can vary them. It must not recite a waiting line before every tool. Extended search uses a different honesty line after `start_extended_identity_search`, not “one moment” on a loop.

### RU

- Секунду, сейчас посмотрю.
- Одну секунду, проверю информацию.
- Сейчас посмотрю.
- Момент, я проверю.
- Секунду, уточню данные.

### EN

- One moment, I’ll check.
- Just a second, let me look that up.
- Give me a moment.
- I’ll check that now.
- One second.

### UK

- Секунду, зараз подивлюсь.
- Одну секунду, перевірю інформацію.
- Зараз подивлюсь.
- Момент, я перевірю.
- Секунду, уточню дані.

Keep them short. Do not name the tool. Do not promise a callback.

---

## What not to do

- Do not implement filler in the Laravel JSON response.
- Do not add delay in PHP.
- Do not call Gemini/another model to invent a filler.
- Do not configure Custom LLM buffer-words for Native Agent webhook tools (that path is the experimental Custom LLM fallback only).

Full `resolve_customer_identity` JSON (including `pre_tool_speech: force`): `docs/Voice/CUSTOMER_IDENTITY.md`.  
Extended search JSON (`start` auto / `status` off): `docs/Voice/EXTENDED_IDENTITY_SEARCH.md`.
