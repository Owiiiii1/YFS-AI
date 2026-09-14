// Experimental / not current production architecture.
// Production voice path is ElevenLabs native telephony + Custom LLM
// (POST /v1/chat/completions). Do not require a Speech Engine ID.
import type { Server } from "node:http";
import {
  ElevenLabsClient,
  type SpeechEngineAttachment,
  type SpeechEngineSession,
} from "@elevenlabs/elevenlabs-js";
import type { InfraConfig } from "../config/env.js";
import { LaravelConfigStore } from "../laravel/config-store.js";
import { createOpenAiClient, isAbortError, logOpenAiFailure, streamVoiceReply } from "../llm/openai.js";
import { log } from "../logger.js";
import { SessionManager } from "../session/manager.js";

export async function attachSpeechEngine(
  httpServer: Server,
  infra: InfraConfig,
  store: LaravelConfigStore,
  sessions: SessionManager,
): Promise<SpeechEngineAttachment | null> {
  const providers = await store.get({ refresh: true });
  const elevenLabsKey = providers?.elevenlabs.apiKey ?? "";

  if (!infra.speechEngineId || !elevenLabsKey) {
    log.warn("speech-engine.attach.skipped", {
      reason: "speech engine id or ElevenLabs key is not ready",
    });
    return null;
  }

  const elevenlabs = new ElevenLabsClient({
    apiKey: elevenLabsKey,
  });

  const attachment = elevenlabs.speechEngine.attach(
    infra.speechEngineId,
    httpServer,
    infra.wsPath,
    {
      debug: false,
      async onInit(conversationId: string) {
        sessions.add(conversationId);
        await store.get({ refresh: true });
        log.info("session.connected", {
          conversationId,
          activeSessions: sessions.count(),
        });
      },
      async onTranscript(transcript, signal, session) {
        const conversationId = session.conversationId ?? "unknown";
        sessions.touch(conversationId);
        const startedAt = Date.now();
        const providers = await store.get();
        const model = providers?.llm.model ?? "";
        log.info("openai.request.started", {
          conversationId,
          model: model || "none",
          turnCount: transcript.length,
        });

        if (!providers?.llm.apiKey || !model) {
          log.error("openai.request.skipped", { conversationId, reason: "missing_laravel_llm" });
          await session.sendResponse("I am having a technical issue. Please try again in a moment.");
          return;
        }

        try {
          const openai = createOpenAiClient(providers.llm.apiKey, infra.openaiTimeoutMs);
          const response = await streamVoiceReply(
            openai,
            model,
            infra.openaiTimeoutMs,
            transcript,
            signal,
          );
          await session.sendResponse(response);
          log.info("openai.request.completed", {
            conversationId,
            latencyMs: Date.now() - startedAt,
          });
        } catch (error) {
          if (isAbortError(error)) {
            log.info("openai.request.cancelled", {
              conversationId,
              latencyMs: Date.now() - startedAt,
            });
            return;
          }
          logOpenAiFailure(error, conversationId);
        }
      },
      onClose(session: SpeechEngineSession) {
        sessions.remove(session.conversationId);
        log.info("session.disconnected", {
          conversationId: session.conversationId ?? "unknown",
          reason: "close",
          activeSessions: sessions.count(),
        });
      },
      onDisconnect(session: SpeechEngineSession) {
        sessions.remove(session.conversationId);
        log.info("session.disconnected", {
          conversationId: session.conversationId ?? "unknown",
          reason: "disconnect",
          activeSessions: sessions.count(),
        });
      },
      onError(error: Error, session: SpeechEngineSession) {
        log.error("speech-engine.session.error", {
          conversationId: session.conversationId ?? "unknown",
          error,
        });
      },
    },
  );

  log.info("speech-engine.attached", {
    path: infra.wsPath,
  });

  return attachment;
}
