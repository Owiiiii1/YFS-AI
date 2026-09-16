# Instagram Calling Audit (YFS AI)

Status: **audit only**. Checked 2026-09-16. No production, Meta App, webhook, ElevenLabs, Twilio, route, migration, credential, or Instagram Messaging Assistant changes were made.

Evidence labels:

- **DOCUMENTED FACT** — stated in official Meta / ElevenLabs / OpenAI docs or in this repo’s source
- **INFERENCE** — reasonable reading of those facts, not proven in a live Instagram call
- **UNKNOWN / NEEDS DASHBOARD CHECK** — cannot be confirmed from this codebase or public docs without Meta Developer Dashboard (or a live API eligibility call, which was not made)

Do not record access tokens, app secrets, webhook secrets, page tokens, or Instagram tokens in this file.

---

## Verdict

1. **Can Instagram Business calls be received programmatically?**  
   **NO** (for the Instagram in-app Call button to a Professional account). There is no documented Instagram Calling API, no Instagram `calls` webhook field, and no Graph `/{ig-user-id}/calls` accept flow.

2. **Is it available to our current YFS setup?**  
   **NO**. Current YFS Meta integration is Instagram Login messaging (`graph.instagram.com`, scopes `instagram_business_*`, webhook fields `messages` / `messaging_postbacks` / `messaging_seen`). Facebook Page calling is **not connected**. Messenger Calling would be a different product even if a Page were added later.

3. **Can ElevenLabs Native Agent receive it directly?**  
   **REQUIRES BRIDGE**. Native Agent does not document ingest of an arbitrary Meta WebRTC SDP session. Documented custom audio is Agents WebSocket (`user_audio_chunk`) or SIP trunking. ElevenLabs’ own WebRTC is a LiveKit client session, not a Meta peer connection.

4. **Does Twilio need to be involved?**  
   **NOT POSSIBLE** as Instagram Call → PSTN → Twilio. Meta Developer Policy forbids PSTN on any leg of Messenger/Instagram calling APIs. Twilio remains the **current telephone** path only. A future Meta VoIP path should not use Twilio PSTN.

5. **Best candidate architecture (if Meta later ships Instagram Calling analogous to Messenger Calling)**  
   Keep one Voice business layer:

   ```text
   Telephone:  Twilio → ElevenLabs Native Agent → Laravel tools → YFS Core / Bitrix
   Instagram:  Meta signalling + WebRTC → our media bridge → ElevenLabs Agents WebSocket
               → same Native Agent / same prompt / same Laravel tools / same identity + post-call
   ```

   Do **not** build a second Instagram voice bot. Do **not** route Meta media through Twilio PSTN. Do **not** treat WhatsApp Cloud API Calling or Messenger Calling as a substitute for the Instagram Call button.

Until Meta documents Instagram Calling, the Instagram Call button continues to ring a human in the Instagram / Inbox client. YFS AI cannot answer it.

---

## A / B / C split (September 2026)

| Channel | Programmatic inbound call? | Official name | Notes |
| --- | --- | --- | --- |
| A. Facebook Messenger | **YES** (documented, country-limited, Page-based) | Messenger Calling API / Messenger Business Calling API | Consumer uses the **Messenger app**. Webhook `object: page`, field `calls`. `POST /{page-id}/calls` with `platform: "messenger"`. |
| B. Instagram (in-app Call to Professional account) | **NO** | None found | Instagram Platform webhooks list has no `calls` field. Accept API `platform` documents only `messenger`. |
| C. WhatsApp | **YES** (documented, separate product) | WhatsApp Business Calling / Cloud API Calling | User- and business-initiated VoIP. WebRTC or SIP. Not used by YFS. |

**DOCUMENTED FACT.** Instagram Messaging hub copy says “APIs for messaging and calling” and then lists **Messenger API Calling** as “voice calling natively within **Messenger** chat”. That is not an Instagram Call API.

Sources (checked 2026-09-16):

- [Messenger Calling overview](https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling) (updated 2026-04-20)
- [Consumer to business calling](https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling/consumer2biz) (updated 2026-04-19)
- [Accept a call](https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling/consumer2biz/accept-c2b-call) — `platform` “Only Messenger is supported”
- [Instagram Platform webhooks](https://developers.facebook.com/docs/instagram-platform/webhooks/) (updated 2026-03-03) — field table, no `calls`
- [Instagram Messaging webhooks](https://developers.facebook.com/docs/messenger-platform/instagram/features/webhook/)
- [WhatsApp Cloud API Calling](https://developers.facebook.com/docs/whatsapp/cloud-api/calling/)
- [Instagram Messaging hub](https://developers.facebook.com/docs/instagram-messaging/) — marketing adjacency only

---

## Current YFS Meta / Instagram contour

**DOCUMENTED FACT** from this repo and live config flags (values not printed).

| Item | Finding |
| --- | --- |
| Meta App credentials | `META_INSTAGRAM_APP_ID` / `META_INSTAGRAM_APP_SECRET` (or UI override on `instagram_accounts.settings`). `configured=true` |
| Webhook verify token | `META_WEBHOOK_VERIFY_TOKEN`. `configured=true` |
| Graph version | `META_GRAPH_API_VERSION` default / live `v25.0` |
| Graph hosts | `META_GRAPH_BASE_URL` (`graph.facebook.com`), `META_INSTAGRAM_GRAPH_BASE_URL` (`graph.instagram.com`). both `configured=true` |
| OAuth flow | `META_OAUTH_FLOW=instagram_login` (live account `oauth_flow` matches) |
| Scopes in code / env | `instagram_business_basic`, `instagram_business_manage_messages`, `instagram_business_manage_comments` |
| Instagram account | primary `instagram_accounts` `connection_status=connected`, token present, `instagram_user_id` present |
| Webhook subscriptions (stored) | `messages`, `messaging_postbacks`, `messaging_seen` |
| Code default subscribe fields | same three (`MetaInstagramWebhookSubscriptionService::defaultFields()`) |
| Facebook Page | `facebook_page_accounts` `connection_status=not_configured`, no page id, no page token |
| Messenger / calling code | **none** (no WebRTC, SIP, VoIP, `calls` webhook, `/calls` Graph client) |
| Instagram inbound path | `GET\|POST /api/webhooks/meta/instagram` → `MetaInstagramWebhookService` (object must be `instagram`) → queue `ProcessIncomingInstagramMessageJob` → Direct messaging only |
| Products used | Instagram messaging (Instagram Login), webhooks, token refresh. Facebook Messenger admin UI is hidden leftover |

Runbook (may be stale on “not entered credentials”; live flags above supersede that sentence): `docs/INSTAGRAM_ASSISTANT.md`, `docs/INSTAGRAM_BOT.md`.

**INFERENCE.** The live Instagram Professional account can receive **native Instagram Calls in the Instagram app**, because users report calling it. That consumer feature is independent of the Messaging webhook this app implements.

---

## Why Instagram Call ≠ Messenger Calling

**DOCUMENTED FACT.**

Messenger Calling:

- Consumer initiates from the **Messenger app** to a **Facebook Page**
- Subscribe Page webhook field `calls`
- Connect webhook `object: "page"`, `from` = PSID, `to` = Page ID
- Accept within **60 seconds** via `POST /{page-id}/calls` with **your SDP offer**; Meta returns an SDP answer
- Feature check: `POST /{page-id}/business_messaging_feature_status` with `messenger_api_calling`
- Permission usage: `pages_messaging` “Handle inbound and outbound calls…” ([Permissions reference](https://developers.facebook.com/docs/permissions/))
- Changelog: Messenger Calling API general availability for apps with `pages_messaging` ([Messenger Platform changelog](https://developers.facebook.com/docs/messenger-platform/changelog/))
- Country list on the Calling overview is **limited** (does not include, among others, United States, United Kingdom, most of the EU). Eligibility is Page-country specific.
- Inbound routing: `POST /{page-id}/messenger_call_settings` `call_routing.ring_target` `META` (Inbox) vs `PARTNERS` (third-party). `PARTNERS` requires `calls` webhook subscription. ([Call settings](https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling/callsettings))

Instagram Messaging (YFS path):

- Instagram Login, host `graph.instagram.com`, Instagram User token
- Webhook object `instagram`
- Documented fields include `messages`, `messaging_postbacks`, `messaging_seen`, `messaging_handover`, `message_reactions`, comments, etc. **No `calls`**
- Send path is `/<IG_ID>/messages` with Instagram-scoped ID (IGSID)

**INFERENCE.** Implementing Messenger Calling would not intercept “Call” on the Instagram profile. It would only apply if the person called the **Facebook Page in Messenger**.

**UNKNOWN / NEEDS DASHBOARD CHECK** (do not change anything yet):

1. Meta App Dashboard → Webhooks → **Instagram** object: confirm field list has no `calls`.
2. Same → **Page** object: `calls` exists for Messenger; YFS Page is not connected.
3. If a Page is ever connected: `messenger_api_calling` feature status, Page country vs Calling country list, App Review / Advanced Access for `pages_messaging`.
4. Whether the Instagram Professional account’s native Call button is even enabled in the IG app (consumer setting, not this API).

A **new Meta App is not required** solely because the current app already does Instagram Messaging. Messenger Calling docs say any app with `pages_messaging` can enable it. Instagram Calling still has **no API**, so a new app would not unlock B.

---

## Messenger Calling technical flow (not Instagram; recorded for comparison)

**DOCUMENTED FACT** from consumer-initiated docs (2026-03/04).

```text
Consumer (Messenger app)
  → connect webhook (calls field, object=page, from=PSID, call_id)
  → business has 60s
  → POST /{page-id}/calls action=accept + local WebRTC SDP offer (RFC 4566)
  → Meta returns sdp_response (answer) and optional sdp_renegotiation (offer)
  → apply to local RTCPeerConnection
  → media over WebRTC (DTLS-SRTP)
  → terminate webhook (status Completed|Failed, duration seconds)
```

Signalling:

- **Who creates SDP offer on inbound?** The **business app** (accept payload `sdp_type: offer`).
- **Who creates SDP answer?** **Meta**, in the accept API response (`sdp_response`).
- Additional Meta-originated offers arrive as `media_update` (documented as **business-initiated** for that webhook type; video docs also describe consumer turning video on during a call → `media_update`).
- ICE/STUN/TURN: docs mention invalid SDP/ICE as API errors; they do not publish a TURN URL. **INFERENCE:** standard WebRTC ICE toward Meta; follow Meta JS samples / appendix SDP.
- Audio codec: **Opus only** ([Video calling](https://developers.facebook.com/documentation/business-messaging/messenger-platform/calling/videocalling), updated 2026-03-16)
- Video codecs: VP8, VP9, H264, H265, AV1
- Sample rate: **UNKNOWN** in Calling docs (Opus clock is typically 48 kHz in WebRTC; not stated)
- Caller identity: **PSID** in `from` / `recipient_id`. Username is not in the connect webhook sample.
- Access token: **Page access token**
- Hangup: `action=terminate` or `reject`; terminate webhook

Video:

- `video_enabled` exists on Page call settings.
- Consumer can start audio-only then enable video (`media_update`).
- Business can omit video in SDP / disable `DEFAULT_VIDEO` via `media_update`.
- **INFERENCE:** an audio-only SDP offer is the way to take Voice-only; the user may still see a video call UI on their device if they pressed video. Exact UX of “voice AI answering a video call” is **UNKNOWN**.
- Backend is not required to render video if it does not subscribe a video track; if the consumer sends video, the peer connection still has to complete SDP negotiation.

---

## PSTN / Twilio / SIP

**DOCUMENTED FACT.** Meta Developer Policies, section **5.7 Calling** (page last updated 2026-02-03):

> VoIP-VoIP Calling Only: You will not use public switched telephone networks (“PSTN”) on any leg of a call between you (or your clients) and a user. Only VoIP-VoIP calling is permitted.

Source: [Developer Policies](https://developers.facebook.com/devpolicy/) — applies to “messaging and calling APIs available on Facebook and Instagram, including Messenger Platform and Instagram Messaging APIs”.

Therefore:

| Path | Allowed? |
| --- | --- |
| Instagram/Messenger/WhatsApp call → PSTN → Twilio number → ElevenLabs | **No** (policy) |
| Meta VoIP → SIP that **never** breaks out to PSTN → ElevenLabs SIP | **Not forbidden by that sentence**; WhatsApp docs similarly allow SIP if it stays VoIP. Messenger Calling itself is Graph+WebRTC, not Meta SIP. |
| Current YFS phone: PSTN/Twilio → ElevenLabs | **Unrelated** to Meta calling; keep as the telephone product |

**INFERENCE.** Do not design Instagram voice as “forward the IG call to the existing Twilio number”.

---

## ElevenLabs Native Agent

**DOCUMENTED FACT.** Current YFS Voice: Twilio → ElevenLabs Native Agent → Laravel webhook tools (`docs/VOICE_ARCHITECTURE.md`).

Documented transports:

| Transport | What it is | Can it take Meta WebRTC? |
| --- | --- | --- |
| Native phone / Twilio | ElevenLabs-hosted telephony | No Meta SDP |
| Agents WebSocket | `wss://api.elevenlabs.io/v1/convai/conversation?agent_id=…` — send `{ "user_audio_chunk": "<base64 PCM>" }`, receive `audio` events | **Bridge required**: terminate Meta WebRTC, decode Opus, resample, send PCM |
| ElevenLabs WebRTC (`connectionType: webrtc`) | Client SDK → LiveKit `wss://livekit.rtc.elevenlabs.io` | **No**: different session; not Meta ICE |
| SIP trunking | INVITE to ElevenLabs SIP; G.711 8 kHz or G.722 16 kHz | Only if a VoIP SIP gateway sits after Meta; extra hop, still a bridge |

Sources (checked 2026-09-16):

- [Agents WebSocket](https://elevenlabs.io/docs/eleven-agents/libraries/web-sockets)
- [Agent WebSocket API](https://elevenlabs.io/docs/eleven-agents/api-reference/eleven-agents/websocket) — `pcm_16000` default user input
- [SIP trunking](https://elevenlabs.io/docs/eleven-agents/phone-numbers/sip-trunking)
- [Conversational AI WebRTC announcement](https://elevenlabs.io/blog/conversational-ai-webrtc)

STT / TTS / turn-taking / barge-in: **ElevenLabs Agents** on that conversation. The bridge only moves PCM (and must handle jitter, clock, and interruption by cutting outbound audio when the user speaks).

**INFERENCE.** Same agent ID, same initiation webhook, same Laravel tools can serve a WebSocket conversation. Initiation payload would carry Instagram IGSID in custom dynamic variables instead of `system__caller_id` phone. That is a future config change, not available until Meta calling exists.

---

## OpenAI Realtime (comparison only)

**DOCUMENTED FACT.** OpenAI Realtime supports:

- WebRTC peer connection to **OpenAI** ([Realtime WebRTC](https://developers.openai.com/api/docs/guides/realtime-webrtc))
- WebSocket
- SIP inbound (`realtime.call.incoming` + `POST /v1/realtime/calls/{id}/accept`) ([Realtime SIP](https://developers.openai.com/api/docs/guides/realtime-sip))

**INFERENCE.** That WebRTC session is still **not** Meta’s SDP. A Meta call would still terminate locally (or via SIP) and a **second** session would be opened to OpenAI. It is not a more direct “plug Meta ICE into OpenAI” path. SIP accept is closer to **WhatsApp SIP**, which YFS does not use, and PSTN is still forbidden.

Do not migrate the telephone Voice Assistant to OpenAI for this reason.

---

## Caller identity (Instagram)

**DOCUMENTED FACT (messaging, today).**

- Instagram message webhooks include `sender.id` = Instagram-scoped ID (IGSID)
- YFS stores `conversations.channel=instagram` + `participant_id=senderId`
- `BotConversationContextBuilder` links `customers.instagram_user_id` (or username) to that conversation
- Profile fetch can fill username (`hydrateParticipantProfile`)

**DOCUMENTED FACT (Messenger Calling, not IG).** Connect webhook `from` is **PSID**. YFS CRM also has `customers.facebook_psid` for Facebook channel. IGSID ≠ PSID.

**UNKNOWN** for Instagram Call: there is no call webhook, so there is no documented call-time identity. If Meta later copied Messenger Calling onto Instagram, the likely identifier would be IGSID — **INFERENCE only**.

**INFERENCE (future, not to implement now).** If an IG call webhook ever includes IGSID:

1. Look up `conversations` / `customers.instagram_user_id`
2. If a YFS customer is already linked from Direct, pass that into Voice as a **hint**, then still apply Voice identity rules (do not treat IGSID alone as YFS `app_user_id` without existing linkage)
3. Phone lookup is unnecessary when the Direct identity is already UNIQUE in CRM **and** that CRM row is linked to YFS Core — that last hop is **UNKNOWN** in current Instagram bot (JFS lookup is email-based for operators, not a Voice identity bind)

Trust boundary: Instagram-supplied username is not YFS identity proof.

---

## One Voice Assistant

If (and only if) Instagram Calling is documented later:

| Layer | Shared? |
| --- | --- |
| Transport | Different (Twilio vs Meta WebRTC + PCM bridge) |
| ElevenLabs agent | Same agent |
| Prompt (`VoiceAssistantPromptBuilder`) | Same |
| Laravel tools | Same |
| YFS Core / Bitrix identity | Same resolver; Instagram IGSID as extra session hint |
| Post-call | Same ElevenLabs post-call webhook if it fires for WebSocket conversations (**INFERENCE** it is agent-level; confirm before relying) |

Do not copy `docs/INSTAGRAM_BOT.md` prompt into Voice.

---

## UX (documented vs unknown)

**If there is no API (current B = NO):**

User: Instagram → YFS profile/chat → Call. Ringing is handled by Instagram’s native client / Inbox. YFS AI never sees the call. **INFERENCE** from missing API + current webhook code ignoring non-message events.

**If Messenger Calling were used (A, not requested):**

- Ringing until the app accepts (60s) or `ring_target` Inbox
- App can auto-accept
- No documented “this is an AI” banner in Calling docs
- Meta policy still: don’t confuse users; calling experiences with excessive negative feedback can be limited ([Developer Policies §5.4](https://developers.facebook.com/devpolicy/))
- Recording/consent: **UNKNOWN** in Calling docs; local law still applies. Policy §5.6.4 restricts use of call data to supporting the calling experience.

---

## Recording / transcript / storage

**DOCUMENTED FACT.** Today `voice_calls` / `voice_contacts` are **phone-shaped**: `voice_contacts.phone_normalized` is **unique**; `voice_calls` has `twilio_call_sid`, `phone`, ElevenLabs conversation id, duration, transcript, summary. `recording_url` stays unused (post-call audio not stored).

Messenger Calling terminate webhook: `duration`, `start_time`, `end_time`, `status`. No recording URL in the sample.

ElevenLabs: post-call transcription webhook already used for phone.

**Recommendation (no migration now):**

- Do not stuff IGSID into `phone_normalized`
- Later add `channel` / `source` (`twilio` \| `instagram` \| `messenger`) and a non-phone external id on contacts
- Reuse ElevenLabs conversation id as the journal key
- Keep recordings off unless a separate legal/product decision is made

---

## Scale / latency / components

**INFERENCE** from Meta WebRTC + ElevenLabs WebSocket (no Instagram API to implement):

Required if API appears:

1. Fast webhook worker (accept in 60s) — Laravel can signal; **media cannot live in PHP-FPM**
2. WebRTC terminator (ICE, DTLS, SRTP, Opus) — dedicated Node/Go/C++ media process or a SFU used as a **gateway**, not as a product pick for popularity
3. PCM resample → ElevenLabs WebSocket
4. Reverse path: ElevenLabs PCM → Opus → Meta
5. STUN; TURN only if Meta’s ICE requires it (UNKNOWN)

Extra latency vs Twilio Native Agent: one extra hop (Meta WebRTC ↔ bridge ↔ ElevenLabs). STT/TTS/VAD remain on ElevenLabs.

LiveKit/Pipecat are optional glue, not Meta requirements.

---

## Security

**DOCUMENTED FACT.**

| Topic | Finding |
| --- | --- |
| Webhook verify (GET) | `hub.verify_token` vs `META_WEBHOOK_VERIFY_TOKEN` (`MetaInstagramWebhookController`) |
| Webhook POST signature | Meta sends `X-Hub-Signature-256`. **This controller does not verify it** (existing). Calling webhooks would need verification before accept. |
| Calling auth | Page token + `pages_messaging`; Instagram User token has no documented `/calls` |
| Media | WebRTC DTLS-SRTP (Messenger Calling model) |
| Identity | IGSID/PSID are Meta-scoped IDs, not YFS `app_user_id` |
| PSTN | Forbidden on Meta calling legs |

---

## Eligibility gaps (YFS)

| Gap | Status |
| --- | --- |
| Instagram Calling API | Does not exist in public docs (2026-09-16) |
| `calls` Instagram webhook | Not in field table; not subscribed |
| Facebook Page + Page token | `not_configured` |
| `pages_messaging` / Messenger Calling | Not in `META_OAUTH_SCOPES` |
| Page country vs Messenger Calling country list | UNKNOWN |
| `messenger_api_calling` feature | UNKNOWN (no Page) |
| App Review for calling | N/A for Instagram Call; Messenger Calling needs `pages_messaging` usage |
| ElevenLabs custom WebSocket bridge | Not built (correct; nothing to connect) |

---

## Recommended next steps (still no implementation)

1. Treat Instagram Call as **human Inbox** until Meta publishes Instagram Calling (Instagram object `calls` + accept API with `platform` Instagram or equivalent).
2. Do not subscribe extra webhook fields “just in case”.
3. Do not connect Facebook Page Calling to “fix” Instagram Call.
4. Do not forward IG calls to the Twilio number.
5. Re-check Meta Instagram webhook field list and Messenger Calling `platform` enum on the next Graph major version.
6. If product wants in-app voice anyway, ask Meta partnership / App Dashboard whether Instagram Calling is a private/beta program (**UNKNOWN** from public docs).

---

## Production / code confirmation

This audit added documentation only. No routes, controllers, services, migrations, packages, Meta subscriptions, permissions, ElevenLabs config, Twilio config, WebRTC servers, or production config were changed.
