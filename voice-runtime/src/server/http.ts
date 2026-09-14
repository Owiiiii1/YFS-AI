import { createServer, type IncomingMessage, type Server, type ServerResponse } from "node:http";
import type { InfraConfig } from "../config/env.js";
import { handleCustomLlm, type CustomLlmHandlerOptions } from "../custom-llm/handler.js";
import type { VoiceConfigSource, VoiceRuntimeSnapshot } from "../laravel/config-store.js";

function sendJson(res: ServerResponse, status: number, body: string): void {
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "Content-Length": Buffer.byteLength(body),
  });
  res.end(body);
}

function healthPayload(snapshot: VoiceRuntimeSnapshot, infra: InfraConfig): Record<string, unknown> {
  return {
    status: "ok",
    service: "yfs-voice-runtime",
    process: "running",
    laravel_config: snapshot.laravel_config,
    laravel_config_fetched_at: snapshot.laravel_config_fetched_at,
    laravel_config_loaded_at: snapshot.laravel_config_loaded_at,
    provider: snapshot.provider,
    model: snapshot.model,
    llm_key: snapshot.llm_key,
    elevenlabs_key: snapshot.elevenlabs_key,
    custom_llm: infra.voiceLlmSharedSecret ? "ready" : "missing",
    speech_engine: snapshot.speech_engine,
  };
}

async function respondHealth(
  res: ServerResponse,
  store: VoiceConfigSource,
  infra: InfraConfig,
): Promise<void> {
  try {
    await store.get();
  } catch {
    // snapshot still describes the latest fetch
  }
  sendJson(res, 200, JSON.stringify(healthPayload(store.snapshot(), infra)));
}

export type HttpServerOptions = CustomLlmHandlerOptions;

export function createHttpServer(
  store: VoiceConfigSource,
  infra: InfraConfig,
  options: HttpServerOptions = {},
): Server {
  return createServer((req: IncomingMessage, res: ServerResponse) => {
    const path = (req.url ?? "/").split("?")[0];

    if (req.method === "GET" && path === "/health") {
      void respondHealth(res, store, infra);
      return;
    }

    if (path === "/v1/chat/completions") {
      if (req.method !== "POST") {
        sendJson(res, 405, JSON.stringify({
          error: { message: "Method not allowed", type: "invalid_request" },
        }));
        return;
      }
      void handleCustomLlm(req, res, store, infra, options);
      return;
    }

    sendJson(res, 404, JSON.stringify({ status: "not_found", service: "yfs-voice-runtime" }));
  });
}
