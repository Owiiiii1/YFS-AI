# Sales Agent

Status: **PLANNED**

Not implemented. No outbound-calling workers, Twilio dialer, or sales-campaign tables exist in this project.

## Intent

Outbound AI sales calling for YoungFashionShow, using Twilio.

## Assumed future shape

1. Campaign or lead list is created in YFS AI.
2. A worker places outbound calls through Twilio.
3. An AI sales agent runs the conversation.
4. Outcomes (interested, callback, do-not-call) are written back to this backend.

Do not implement this module until a separate specification is approved.

Phase 2 Voice Assistant (`docs/VOICE_ASSISTANT.md`, architecture `docs/VOICE_ARCHITECTURE.md`) is inbound only. Sales Agent stays a separate outbound subsystem with its own prompts and rules. It may later reuse call storage, transcripts, contacts, analysis, follow-ups, admin patterns, and observability — not the inbound secretary agent.
