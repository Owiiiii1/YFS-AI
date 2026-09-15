# YoungFashionShow AI

## Purpose

YFS AI is the dedicated backend for YoungFashionShow AI services.

It is a new isolated project. It is not a migration of Mousse Bakery as a client. The Laravel codebase was copied as a technical base only.

Domain: `https://ai.youngfashionshow.com`  
Path: `/var/www/yfs-ai`

## Subsystems

| Module | Phase | Status |
| --- | --- | --- |
| Instagram / Facebook Assistant | 1 | **IMPLEMENTED / CONNECTED**. Instagram Direct product. Rules: `docs/INSTAGRAM_BOT.md`. Facebook admin UI is hidden. |
| Voice Assistant | 2 | **IN PROGRESS**. Selected Phase 2 architecture: ElevenLabs Native Agent → Laravel webhook tools (**POC SUCCESS**). Node Custom LLM remains experimental/fallback. YFS Core / Bitrix / post-call **Planned**. Canonical architecture: `docs/VOICE_ARCHITECTURE.md`. Product/roadmap: `docs/VOICE_ASSISTANT.md`. |
| Sales Agent | 3 | **PLANNED**. Implementation not started. Outbound AI sales calling. Not specified in detail yet. |

Voice Assistant and Sales Agent are separate subsystems of this backend. They must not share one agent prompt or one business-rule set. Shared platform pieces (provider interface, call storage, tools, analysis, admin patterns) may be reused later.

## Isolation

- Separate MySQL database `yfs_ai` and user `yfs_ai_user`
- Separate nginx site and Let's Encrypt certificate
- Separate cron scheduler and queue worker
- No import of Mousse Bakery users, customers, orders, conversations, OAuth state, or API keys
