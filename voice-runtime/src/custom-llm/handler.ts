import { randomUUID } from "node:crypto";
import type { IncomingMessage, ServerResponse } from "node:http";
import type { InfraConfig } from "../config/env.js";
import type { LaravelVoiceConfig } from "../laravel/config-client.js";
import type { VoiceConfigSource } from "../laravel/config-store.js";
import { isAbortError } from "../llm/abort.js";
import { createLlmProvider, UnsupportedLlmProviderError, type LlmProvider } from "../llm/provider.js";
import { log } from "../logger.js";
import { mergeChatTools, yfsToolsAllowed } from "../voice-tools/catalog.js";
import { createLaravelExecuteVoiceTool, type ExecuteVoiceTool } from "../voice-tools/laravel-client.js";
import { createLaravelFetchSessionTurn, type FetchSessionTurn } from "../voice-tools/session-client.js";
import { isAuthorizedCustomLlm } from "./auth.js";
import {
  BodyTooLargeError,
  InvalidChatRequestError,
  MalformedJsonError,
  parseChatCompletionBody,
  readRequestBody,
  lastUserText,
} from "./request.js";
import { writeSseData, writeSseDone, writeSseHeaders } from "./sse.js";
import { streamWithServerTools } from "./tool-loop.js";

export type CustomLlmHandlerOptions = {
  createProvider?: (config: LaravelVoiceConfig["llm"], timeoutMs: number) => LlmProvider;
  executeVoiceTool?: ExecuteVoiceTool;
  fetchSessionTurn?: FetchSessionTurn;
};

function sendJson(res: ServerResponse, status: number, body: Record<string, unknown>): void {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "Content-Length": Buffer.byteLength(payload),
  });
  res.end(payload);
}

function resolveProvider(
  llm: LaravelVoiceConfig["llm"],
  timeoutMs: number,
  createProvider: CustomLlmHandlerOptions["createProvider"],
): LlmProvider {
  if (createProvider) {
    return createProvider(llm, timeoutMs);
  }
  return createLlmProvider({
    provider: llm.provider,
    model: llm.model,
    apiKey: llm.apiKey,
    timeoutMs,
  });
}

export async function handleCustomLlm(
  req: IncomingMessage,
  res: ServerResponse,
  store: VoiceConfigSource,
  infra: InfraConfig,
  options: CustomLlmHandlerOptions = {},
): Promise<void> {
  const requestId = randomUUID();
  const startedAt = Date.now();

  if (!isAuthorizedCustomLlm(req, infra.voiceLlmSharedSecret)) {
    log.warn("custom-llm.unauthorized", { requestId });
    sendJson(res, 401, { error: { message: "Unauthorized", type: "unauthorized" } });
    return;
  }

  let raw: Buffer;
  try {
    raw = await readRequestBody(req, infra.maxLlmBodyBytes);
  } catch (error) {
    if (error instanceof BodyTooLargeError) {
      log.warn("custom-llm.body_too_large", { requestId });
      sendJson(res, 413, { error: { message: "Request body too large", type: "invalid_request" } });
      return;
    }
    log.warn("custom-llm.body_read_failed", { requestId, error });
    sendJson(res, 400, { error: { message: "Invalid request body", type: "invalid_request" } });
    return;
  }

  let parsed;
  try {
    parsed = parseChatCompletionBody(raw, infra.maxLlmMessages);
  } catch (error) {
    if (error instanceof MalformedJsonError) {
      log.warn("custom-llm.malformed_json", { requestId });
      sendJson(res, 400, { error: { message: "Malformed JSON", type: "invalid_request" } });
      return;
    }
    if (error instanceof InvalidChatRequestError) {
      log.warn("custom-llm.invalid_request", { requestId, reason: error.message });
      sendJson(res, 400, { error: { message: "Invalid chat completion request", type: "invalid_request" } });
      return;
    }
    log.warn("custom-llm.parse_failed", { requestId, error });
    sendJson(res, 400, { error: { message: "Invalid request", type: "invalid_request" } });
    return;
  }

  const config = await store.get();
  const model = config?.llm.model ?? "";
  const apiKey = config?.llm.apiKey ?? "";
  const provider = config?.llm.provider ?? "";

  if (!config || !apiKey || !model) {
    log.error("custom-llm.config_unavailable", {
      requestId,
      laravel_config: store.snapshot().laravel_config,
      provider: provider || "none",
      model: model || "none",
    });
    sendJson(res, 503, { error: { message: "LLM configuration unavailable", type: "config_unavailable" } });
    return;
  }

  let llm: LlmProvider;
  try {
    llm = resolveProvider(config.llm, infra.openaiTimeoutMs, options.createProvider);
  } catch (error) {
    if (error instanceof UnsupportedLlmProviderError) {
      log.warn("custom-llm.unsupported_provider", { requestId, provider: error.provider });
      sendJson(res, 400, {
        error: {
          message: `Unsupported LLM provider [${error.provider}]`,
          type: "unsupported_provider",
          provider: error.provider,
        },
      });
      return;
    }
    throw error;
  }

  const abort = new AbortController();
  const onClientDisconnect = (): void => {
    if (!res.writableFinished) {
      abort.abort();
    }
  };

  const fetchSessionTurn = options.fetchSessionTurn ?? createLaravelFetchSessionTurn(infra);
  let sessionTurn = null;
  try {
    sessionTurn = await fetchSessionTurn({
      sessionId: parsed.user || requestId,
      userText: lastUserText(parsed.messages),
      requestId,
    }, abort.signal);
  } catch {
    sessionTurn = null;
  }

  let messages = parsed.messages;
  if (sessionTurn?.systemPrompt) {
    messages = [
      { role: "system", content: sessionTurn.systemPrompt },
      ...parsed.messages.slice(1),
    ];
  }

  const tools = mergeChatTools(
    parsed.tools,
    yfsToolsAllowed(sessionTurn ? sessionTurn.allowedTools : null),
  );
  const executeVoiceTool = options.executeVoiceTool ?? createLaravelExecuteVoiceTool(infra);

  writeSseHeaders(res);
  res.once("close", onClientDisconnect);
  log.info("custom-llm.stream.started", {
    requestId,
    provider: llm.providerName,
    model,
    incomingModel: parsed.incomingModel || "none",
    messageCount: parsed.messageCount,
    hasTools: Boolean(tools?.length),
    stream: parsed.stream,
  });

  try {
    const loop = await streamWithServerTools({
      llm,
      executeVoiceTool,
      requestId,
      res,
      signal: abort.signal,
      model,
      messages,
      temperature: parsed.temperature,
      maxTokens: parsed.maxTokens,
      user: parsed.user,
      tools,
      toolChoice: parsed.toolChoice,
      fillers: sessionTurn?.fillers,
    });

    if (!res.writableEnded) {
      writeSseDone(res);
    }

    log.info("custom-llm.stream.completed", {
      requestId,
      provider: llm.providerName,
      model,
      status: abort.signal.aborted || loop.status === "aborted" ? "aborted" : "ok",
      rounds: loop.rounds,
      path: loop.path,
      filler: loop.filler,
      latencyMs: Date.now() - startedAt,
    });
    if (loop.path === "fast") {
      log.info("voice.response.fast_path", { requestId, rounds: loop.rounds });
    } else {
      log.info("voice.response.tool_path", { requestId, rounds: loop.rounds, filler: loop.filler });
    }
  } catch (error) {
    if (isAbortError(error) || abort.signal.aborted) {
      log.info("custom-llm.stream.cancelled", {
        requestId,
        latencyMs: Date.now() - startedAt,
      });
      if (!res.writableEnded) {
        writeSseDone(res);
      }
      return;
    }

    log.error("custom-llm.stream.failed", {
      requestId,
      provider: llm.providerName,
      model,
      error,
      latencyMs: Date.now() - startedAt,
    });
    if (!res.headersSent) {
      sendJson(res, 502, { error: { message: "Upstream LLM error", type: "upstream_error" } });
      return;
    }
    writeSseData(res, { error: { message: "upstream_error", type: "server_error" } });
    writeSseDone(res);
  } finally {
    res.off("close", onClientDisconnect);
  }
}
