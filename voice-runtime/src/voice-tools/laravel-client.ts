import type { InfraConfig } from "../config/env.js";
import { isAbortError } from "../llm/abort.js";
import { log } from "../logger.js";

export class VoiceToolClientError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly type: string,
  ) {
    super(message);
    this.name = "VoiceToolClientError";
  }
}

export type ExecuteVoiceTool = (
  name: string,
  args: Record<string, unknown>,
  requestId: string,
  signal: AbortSignal,
) => Promise<unknown>;

function jsonSize(value: unknown): number {
  try {
    return Buffer.byteLength(JSON.stringify(value), "utf8");
  } catch {
    return 0;
  }
}

function parseArgs(value: unknown): Record<string, unknown> {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    return value as Record<string, unknown>;
  }
  return {};
}

type FetchLike = typeof fetch;

export async function executeLaravelVoiceTool(
  infra: InfraConfig,
  name: string,
  args: Record<string, unknown>,
  requestId: string,
  signal: AbortSignal,
  fetchImpl: FetchLike = fetch,
): Promise<unknown> {
  if (!infra.voiceRuntimeInternalToken) {
    throw new VoiceToolClientError("Voice orchestrator token missing", 503, "config_unavailable");
  }

  const started = Date.now();
  log.info("voice.tool.requested", {
    requestId,
    tool: name,
    arguments_type: typeof args,
    arguments_bytes: jsonSize(args),
  });

  const timeout = AbortSignal.timeout(8_000);
  const combined = AbortSignal.any([signal, timeout]);
  const url = `${infra.laravelInternalBaseUrl}/api/internal/voice/tools/execute`;

  let response: Response;
  try {
    response = await fetchImpl(url, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        Authorization: `Bearer ${infra.voiceRuntimeInternalToken}`,
        "X-Request-Id": requestId,
      },
      body: JSON.stringify({
        tool: name,
        arguments: parseArgs(args),
      }),
      signal: combined,
    });
  } catch (error) {
    if (isAbortError(error) || signal.aborted || combined.aborted) {
      log.info("voice.tool.failed", {
        requestId,
        tool: name,
        status: "aborted",
        latency_ms: Date.now() - started,
      });
      throw error;
    }
    log.error("voice.tool.failed", {
      requestId,
      tool: name,
      status: "network_error",
      latency_ms: Date.now() - started,
    });
    throw new VoiceToolClientError("Voice orchestrator unreachable", 503, "network_error");
  }

  let body: unknown = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }

  const parsed = body && typeof body === "object" && !Array.isArray(body)
    ? body as { ok?: unknown; result?: unknown; error?: { type?: unknown } }
    : null;

  if (response.ok && parsed?.ok === true) {
    log.info("voice.tool.completed", {
      requestId,
      tool: name,
      status: "ok",
      latency_ms: Date.now() - started,
      result_type: typeof parsed.result,
      result_bytes: jsonSize(parsed.result),
    });
    return parsed.result ?? {};
  }

  const errorType = typeof parsed?.error?.type === "string" ? parsed.error.type : "tool_failed";
  log.info("voice.tool.failed", {
    requestId,
    tool: name,
    status: errorType,
    http_status: response.status,
    latency_ms: Date.now() - started,
  });

  throw new VoiceToolClientError("Voice tool execution failed", response.status, errorType);
}

export function createLaravelExecuteVoiceTool(infra: InfraConfig): ExecuteVoiceTool {
  return (name, args, requestId, signal) => executeLaravelVoiceTool(infra, name, args, requestId, signal);
}

export function safeToolResult(error: unknown): Record<string, unknown> {
  const type = error instanceof VoiceToolClientError ? error.type : "tool_failed";
  return {
    ok: false,
    error: {
      type,
      message: "Voice tool execution failed",
    },
  };
}
