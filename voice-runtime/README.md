# YFS Voice Runtime

Current production role: **Custom LLM gateway** for the ElevenLabs Voice Agent.

Canonical architecture: `docs/VOICE_ARCHITECTURE.md`.

```text
Twilio → ElevenLabs (telephony/STT/TTS/turn-taking)
  → POST /v1/chat/completions
  → this process
  → Laravel bot_runtime provider (production: Gemini)
```

Speech Engine WebSocket attach is experimental / legacy. It is not production routing. It is skipped unless `ELEVENLABS_SPEECH_ENGINE_ID` is set.

Provider keys come from Laravel Settings, not from this `.env`.

Infrastructure `.env` only: host, port, Laravel base URL, internal token, Custom LLM shared secret.
