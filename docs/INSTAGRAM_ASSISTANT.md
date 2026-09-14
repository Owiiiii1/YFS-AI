# Instagram assistant

Product rules: `docs/INSTAGRAM_BOT.md`. This file is the Meta / admin / webhook runbook.

The Facebook admin section (dialogs and Settings tab) is hidden. Facebook webhook/OAuth routes still exist in the codebase but are not a product surface.

## Current YFS behaviour

Instagram Direct only. The bot answers questions, sends official form **links**, and passes cases to a manager. It does not run an in-chat questionnaire.

Closed cases (form sent, manager request, operator needed, JFS found/not found) appear at `/questionnaires` under the title **Ответы бота** and in the connected Telegram channel.

JFS is read-only for public events and email client lookup. See `docs/INSTAGRAM_BOT.md`.

Cake order intake, calendar booking and the Orders/Calendar admin pages are not part of the live YFS flow. Leftover tables/services may remain in the tree.

Bot topic editor and AI prompt analysis stay on `/bot-management`. Topics have an `always_include` flag; only the core set loads on every reply.

The global bot switch may be off after a prompt reset. Turn it on in Bot Management when Instagram should answer.

## What exists

- Instagram settings UI: `/settings?tab=instagram` and `/instagram`
- Dialogs: `/dialogs/instagram`
- Bot editor: `/bot-management`
- Bot replies (former questionnaires page): `/questionnaires`
- Meta OAuth start/callback routes for Instagram and Facebook
- Webhooks:
  - `GET|POST /api/webhooks/meta/instagram`
  - `GET|POST /api/webhooks/meta/facebook`

## Exact public URLs

Meta Business Login settings:

- OAuth redirect: `https://ai.youngfashionshow.com/instagram/connect/meta/callback`
- Deauthorize callback: `https://ai.youngfashionshow.com/api/meta/instagram/deauthorize`
- Data deletion request: `https://ai.youngfashionshow.com/api/meta/instagram/data-deletion`

Webhooks:

- Instagram: `https://ai.youngfashionshow.com/api/webhooks/meta/instagram`
- Facebook (hidden leftover): `https://ai.youngfashionshow.com/api/webhooks/meta/facebook`

These are the values to put in the Meta App. Do not use `/admin/...` paths.

Deauthorize and data deletion callbacks accept Meta `signed_request`, verify it with the current Instagram App Secret (UI or `.env`, never hardcoded), and return 400/403 on invalid input. Data deletion writes `meta_data_deletion_requests` and returns a public status URL. Conversations are not deleted by these callbacks.

## Data model

- `instagram_accounts` — one primary account (`InstagramAccount::primary()`)
- `facebook_page_accounts` — one primary page (`FacebookPageAccount::primary()`)
- `conversations` + `conversation_messages`
- `customers` — linked when messages arrive
- `meta_oauth_states` — OAuth CSRF/state
- `bot_replies` — closed bot cases (form / manager / operator / JFS lookup)

On a clean database the primary Instagram and Facebook rows are created the first time Settings is opened. They are `not_configured` until OAuth succeeds.

## Bot runtime

- Global switch: `bot_settings.bot_enabled`
- Per-conversation bot enable/disable
- Scheduler `bot:reenable-after-manual-inactivity`
- Follow-ups: `bot:send-follow-ups` every 5 minutes
- Missed-reply retry: `bot:retry-missed-replies` every 2 minutes
- Conversation reconcile: `instagram:reconcile-conversations` every 5 minutes
- Token refresh: `instagram:tokens:refresh` daily; `facebook:tokens:check` daily

## AI providers

Keys live in `ai_provider_settings`, not in `.env`.

Roles in `ai_role_connections`:

- `bot_runtime` — live replies
- `prompt_analysis` — `/bot-management` AI analysis

Both exist as disconnected placeholders until a key is saved in Settings → AI.

## Credentials: `.env` vs UI

The same Meta App ID / secret / verify token can be stored in `.env` **or** entered in Instagram Settings UI. UI values on the primary Instagram account override env when present (`MetaAppSettings`). Do not fill both unless you intend the UI to win.

Facebook Page OAuth uses the same App ID/secret (Instagram UI / `META_INSTAGRAM_*` / `META_APP_*`). Its callback is the Facebook route above, not `META_OAUTH_REDIRECT_URI`.

## Token refresh

- Instagram: `InstagramTokenService::refreshLongLivedToken()` via `instagram:tokens:refresh`. Uses `INSTAGRAM_TOKEN_REFRESH_URL` (default Graph Instagram `refresh_access_token`). Runs when expiry is within `INSTAGRAM_TOKEN_REFRESH_DAYS_BEFORE_EXPIRY` (default 14) or `--force`.
- Facebook Page: `facebook:tokens:check` **validates** the page token and re-subscribes webhooks. It does not mint a new page token by itself. Reconnect via Facebook OAuth if the page token dies.

## Meta App checklist (do not connect until asked)

1. Create a **new** Meta App for YoungFashionShow (do not reuse Mousse).
2. Add products:
   - Instagram (Instagram API / Instagram Business Login) for the default `META_OAUTH_FLOW=instagram_login`
   - Facebook Login for Business if you switch to `facebook_business_login`
   - Messenger / webhooks for Page messaging
   - Webhooks
3. Valid OAuth redirect URLs in Meta:
   - `https://ai.youngfashionshow.com/instagram/connect/meta/callback`
   - `https://ai.youngfashionshow.com/facebook/connect/meta/callback`
4. Webhook callback URL in Meta:
   - Instagram: `https://ai.youngfashionshow.com/api/webhooks/meta/instagram`
   - Facebook Page: `https://ai.youngfashionshow.com/api/webhooks/meta/facebook`
5. Verify token: generate a long random string, put it in `META_WEBHOOK_VERIFY_TOKEN` **or** Instagram Settings → webhook verify token. Same value in the Meta webhook form.
6. Instagram scopes used by code (`META_OAUTH_SCOPES` default):
   - `instagram_business_basic`
   - `instagram_business_manage_messages`
   - `instagram_business_manage_comments`
7. Facebook Page scopes used by code:
   - `pages_show_list`
   - `pages_messaging`
   - `pages_manage_metadata`
   - `pages_read_engagement`
   - `business_management`
8. Webhook subscriptions the code expects:
   - Instagram: `messages`, `messaging_postbacks`, `messaging_seen` (object `instagram`)
   - Facebook: `messages`, `messaging_postbacks` (object `page`)
9. After OAuth connect, the app calls Meta `subscribed_apps` for those fields.

## Facebook Page requirement

- Instagram DMs with `instagram_login`: an Instagram Business (or Creator) account linked to a Facebook Page in Meta is still required by Meta. This codebase can send IG DMs with the Instagram user token and does not require a Page token for Instagram replies.
- Facebook Messenger admin UI is hidden. Do not connect a Page through this app unless that product is brought back.

## Readiness

The UI and routes are ready. You can start OAuth from Settings as soon as App ID, App Secret and verify token are set (env or UI). No extra feature development is required for connect. Do not enter those credentials until explicitly asked.

## What is not live yet

YFS has not entered App ID, App Secret, verify token, Instagram login, Facebook Page, or AI keys.

Cake/order/calendar application code has been removed. Legacy MySQL tables may still exist from old migrations. Do not run `db:seed` for bakery demo data.
