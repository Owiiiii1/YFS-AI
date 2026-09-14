# Cursor Work Report — ElevenLabs Custom LLM Gateway

**Historical snapshot (2026-08-31).** Not current architecture. Canonical Voice architecture: `docs/VOICE_ARCHITECTURE.md`. Production LLM is Gemini via Custom LLM; a live inbound phone call has been confirmed since this report.

Date: 2026-08-31  
Project: `/var/www/yfs-ai`  
Git: not used.

---

## 1. ElevenLabs docs studied

Official sources read before implementation (not from memory):

- https://elevenlabs.io/docs/eleven-agents/customization/llm/custom-llm  
  Custom LLM must be OpenAI-compatible. Supported APIs: `/v1/chat/completions` and `/v1/responses`. Both must return SSE `Content-Type: text/event-stream`.
- https://elevenlabs.io/docs/eleven-agents/integrate/environment-variables  
  Custom LLM URL, API key, and request headers can use workspace secrets / env var references.
- https://github.com/elevenlabs/skills/blob/main/agents/references/agent-configuration.md  
  Agent field `conversation_config.agent.prompt.custom_llm` with `url`, `model_id`, `api_key`, `api_type`.

UI for an existing agent: Agent settings → LLM field → **Custom LLM**. Then server URL, model ID, API key (workspace secret), and API type.

ElevenLabs sends:

- `messages[]` with `system` (agent prompt), prior `assistant`/`user` turns, current user text
- `model` = Custom LLM Model ID from the UI
- `stream: true`, optional `temperature`, `max_tokens`, `user_id`, `elevenlabs_extra_body`
- `tools` / `tool_choice` when system tools are configured (OpenAI function-calling shape)

---

## 2. Why Chat Completions

Chosen API: **Chat Completions** (`POST /v1/chat/completions`).

Reasons from current docs:

- Primary custom-server example is Chat Completions.
- System tools (`end_call`, `language_detection`, `skip_turn`, transfer, …) are documented in Chat Completions request/response form.
- Agent `api_type` values include `chat_completions`, `responses`, and `websocket`. Chat Completions is the documented default for a hosted OpenAI-style server.
- Responses API is also fully supported, but its event stream (`event: response.output_text.delta`) is a second protocol. One endpoint is enough.

---

## 3. Public endpoint

`https://ai.youngfashionshow.com/voice-engine/v1/chat/completions`

nginx `/voice-engine/` strips the prefix. Node receives `POST /v1/chat/completions`.

---

## 4. Auth

`Authorization: Bearer <VOICE_LLM_SHARED_SECRET>`

Also accepted: `X-YFS-Voice-Token`.

Secret is generated on the server, stored only in `voice-runtime/.env`, loaded by systemd `EnvironmentFile`. It is not a Laravel setting and is not the OpenAI key. Missing/wrong secret → **401**.

In the ElevenLabs UI this maps to Custom LLM **API key** (workspace secret). Do not put the OpenAI key there.

---

## 5. Request schema

Accepted OpenAI-compatible body (ElevenLabs Chat Completions):

```json
{
  "model": "label-from-elevenlabs-ui",
  "messages": [
    { "role": "system", "content": "agent prompt" },
    { "role": "user", "content": "..." }
  ],
  "stream": true,
  "temperature": 0.7,
  "max_tokens": 5000,
  "tools": [],
  "tool_choice": "auto",
  "user_id": "optional",
  "elevenlabs_extra_body": {}
}
```

Rules:

- `model` from ElevenLabs is ignored for routing.
- Incoming `messages` are kept, including the agent system prompt.
- A short YFS guardrail system message is prepended. It does not replace the agent prompt.
- `elevenlabs_extra_body` is dropped and not sent to OpenAI.
- `user_id` is mapped to OpenAI `user` if present.
- OpenAI-compatible `tools` / `tool_choice` are passed through. No YFS business tools are added.
- Body limit 256 KB. Conversation cap 48 messages (system kept, older turns trimmed).
- Malformed JSON / empty messages → **400**. Oversized body → **413**. Laravel config missing → **503**.

---

## 6. Streaming response schema

Always SSE, including when `stream` is false (ElevenLabs docs require `text/event-stream`).

```text
data: {"id":"chatcmpl-...","object":"chat.completion.chunk","created":123,"model":"<laravel-model>","choices":[{"index":0,"delta":{"content":"..."},"finish_reason":null}]}

data: [DONE]
```

Headers: `Content-Type: text/event-stream; charset=utf-8`, `Cache-Control: no-cache, no-transform`, `X-Accel-Buffering: no`, chunked transfer. Client disconnect aborts the OpenAI request via `AbortSignal`.

---

## 7. Laravel `bot_runtime`

Unchanged path:

YFS Admin → Settings → AI (`bot_runtime`) → `ai_provider_settings` (encrypted) → `GET /api/internal/voice-runtime/config` → Node memory.

Node does not put the OpenAI key in its `.env`. Credentials are never returned to ElevenLabs.

---

## 8. Resolved provider / model

From Laravel internal config at runtime load:

- provider: `openai`
- model: `gpt-5.6-luna`

---

## 9. nginx / SSE

Existing `location /voice-engine/` already has `proxy_buffering off`, HTTP/1.1, 3600s read/send timeouts, no cache. It was left unchanged.

Public authenticated smoke returned `200` + `text/event-stream` + chunked encoding through that location. No nginx backup/reload.

---

## 10. Files changed

Voice runtime:

- `voice-runtime/src/config/env.ts`
- `voice-runtime/src/config/system-prompt.ts`
- `voice-runtime/src/custom-llm/auth.ts`
- `voice-runtime/src/custom-llm/request.ts`
- `voice-runtime/src/custom-llm/sse.ts`
- `voice-runtime/src/custom-llm/handler.ts`
- `voice-runtime/src/llm/chat-completions.ts`
- `voice-runtime/src/server/http.ts`
- `voice-runtime/src/index.ts`
- `voice-runtime/src/laravel/config-store.ts`
- `voice-runtime/src/speech/engine.ts` (comment only: experimental / not production)
- `voice-runtime/src/smoke.ts`
- `voice-runtime/src/smoke-custom-llm.ts`
- `voice-runtime/test/custom-llm.test.ts`
- `voice-runtime/package.json`
- `voice-runtime/.env.example`
- `voice-runtime/.env` (`VOICE_LLM_SHARED_SECRET` added; value not recorded here)
- `voice-runtime/README.md`

Docs:

- `docs/VOICE_ASSISTANT.md`
- `docs/ARCHITECTURE.md`
- `docs/EXTERNAL_SERVICES.md`
- `docs/Development/Cursor_Work_Report.md`

Not changed: Laravel routes, Twilio, nginx, existing ElevenLabs agent, Speech Engine ID, frontend.

---

## 11. Tests

`npm test` in `voice-runtime` (10/10):

1. no auth → 401  
2. wrong auth → 401  
3. malformed JSON → 400  
4. non-stream request still returns SSE  
5. `stream=true` → SSE with OpenAI-compatible deltas + `[DONE]`  
6. multiple messages / ElevenLabs system prompt preserved; UI model ignored  
7. mocked OpenAI deltas → correct SSE  
8. client disconnect aborts upstream  
9. Laravel config unavailable → 503  
10. health 200 + `custom_llm: ready`; no secrets in logs  

Also: `npm run typecheck`, `npm run build`.

---

## 12. Local authenticated smoke

`npm run smoke:llm` against `127.0.0.1:3101`:

- HTTP 200  
- `content-type: text/event-stream; charset=utf-8`  
- OpenAI answered through Laravel `openai` / `gpt-5.6-luna`  
- Stream ended with `[DONE]`  

A longer public/local probe used chunked SSE. Short replies from this model often arrive as one completion chunk, then `[DONE]`. The gateway does not wait for a full non-stream JSON body.

Transcript was not stored in this report.

---

## 13. Production systemd

`sudo systemctl restart yfs-voice-runtime` requires a password. Recycled by killing the unit MainPID; `Restart=on-failure` brought it back.

```text
yfs-voice-runtime.service — active (running)
User: deploy
ExecStart: /usr/bin/node dist/index.js
speech_engine.attach: skipped (no Speech Engine ID)
```

Manual restart when needed:

```bash
sudo systemctl restart yfs-voice-runtime
```

---

## 14. Health

`GET http://127.0.0.1:3101/health` and `GET https://ai.youngfashionshow.com/voice-engine/health`:

```json
{
  "status": "ok",
  "service": "yfs-voice-runtime",
  "laravel_config": "loaded",
  "openai": "configured",
  "elevenlabs": "configured",
  "custom_llm": "ready",
  "speech_engine": "waiting"
}
```

No secrets in the body.

---

## 15. What was tested

- Unit/integration tests above  
- Typecheck + production build  
- Process recycle and systemd running  
- Local + public health  
- Unauthenticated Custom LLM → 401  
- Authenticated local Custom LLM → 200 SSE, real OpenAI  
- Authenticated public Custom LLM through nginx → 200 SSE  
- Speech Engine attach skipped; process did not crash  

---

## 16. What was not tested

- Live inbound phone call  
- ElevenLabs agent switched to Custom LLM (not changed)  
- Interruptions / turn-taking on a real call  
- System tool execution (`end_call`, language switch, transfer)  
- Twilio / number / CallCenter24  
- Media Streams  
- Business tools, CRM, Bitrix, payments, follow-ups  

---

## 17. Speech Engine status

Experimental / not current production architecture. Code remains. No engine created. `ELEVENLABS_SPEECH_ENGINE_ID` empty. Runtime stays up without it.

---

## 18. Twilio

Twilio was not read or changed: no webhooks, Voice Configuration, Media Streams, credentials, phone assignment, CallCenter24, or test-number routing.

---

## 19. Secrets

None written in this report, docs, or logs. Do not commit `.env`.

---

## NEXT MANUAL STEP

Switch **only the LLM** on the existing agent **YFS Voice Assistant**. Do not create a new agent. Do not touch Twilio, the phone number, languages, or voice overrides.

1. Open ElevenLabs → Agents → **YFS Voice Assistant**.
2. Agent settings → LLM (right / prompt panel).
3. Open the LLM control and choose **Custom LLM**.
4. Server URL:  
   `https://ai.youngfashionshow.com/voice-engine/v1/chat/completions`
5. API type: **Chat Completions** (`chat_completions`) if the UI shows it.
6. Model ID: `yfs-bot-runtime`  
   (label only; Node uses Laravel `bot_runtime` / `gpt-5.6-luna`).
7. API key: **Create new secret**. Paste `VOICE_LLM_SHARED_SECRET` from `/var/www/yfs-ai/voice-runtime/.env`.  
   Do not paste the OpenAI key.
8. Leave unchanged: first message, system prompt, EN/RU/UK, voice overrides, turn-taking, telephony, phone assignment.
9. Optional token limit: keep the current value or set ~5000 as in the ElevenLabs custom-server guide.
10. Review the form. **Stop before Save / Publish** until you are ready for a live-call test. Publishing changes the production agent LLM immediately; it can be switched back, but incoming calls will use Custom LLM as soon as it is published.
