import type { InfraConfig } from "../config/env.js";
import { log } from "../logger.js";

export type VoiceFillerHint = {
  enabled: boolean;
  language: string;
  category: string;
  phraseId: string;
  text: string;
};

export type VoiceSessionTurn = {
  sessionId: string;
  activeTopic: string;
  responsePath: "fast" | "tool";
  systemPrompt: string;
  allowedTools: string[];
  sectionNames: string[];
  promptChars: number;
  detectedLanguage: string;
  fillers: Record<string, VoiceFillerHint>;
};

export type SessionTurnRequest = {
  sessionId: string;
  userText: string;
  requestId: string;
  language?: string;
};

export type FetchSessionTurn = (request: SessionTurnRequest, signal: AbortSignal) => Promise<VoiceSessionTurn | null>;

function asStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.filter((item): item is string => typeof item === "string" && item.length > 0);
}

function parseFillers(value: unknown): Record<string, VoiceFillerHint> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return {};
  }
  const out: Record<string, VoiceFillerHint> = {};
  for (const [tool, raw] of Object.entries(value as Record<string, unknown>)) {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      continue;
    }
    const item = raw as Record<string, unknown>;
    if (typeof item.text !== "string" || !item.text) {
      continue;
    }
    out[tool] = {
      enabled: item.enabled !== false,
      language: typeof item.language === "string" ? item.language : "en",
      category: typeof item.category === "string" ? item.category : "lookup",
      phraseId: typeof item.phrase_id === "string" ? item.phrase_id : "unknown",
      text: item.text,
    };
  }
  return out;
}

export async function fetchLaravelSessionTurn(
  infra: InfraConfig,
  request: SessionTurnRequest,
  signal: AbortSignal,
): Promise<VoiceSessionTurn | null> {
  if (!infra.voiceRuntimeInternalToken) {
    return null;
  }

  const timeout = AbortSignal.timeout(8_000);
  const combined = AbortSignal.any([signal, timeout]);
  const url = `${infra.laravelInternalBaseUrl}/api/internal/voice/session/turn`;

  let response: Response;
  try {
    response = await fetch(url, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        Authorization: `Bearer ${infra.voiceRuntimeInternalToken}`,
        "X-Request-Id": request.requestId,
      },
      body: JSON.stringify({
        session_id: request.sessionId,
        user_text: request.userText,
        language: request.language,
      }),
      signal: combined,
    });
  } catch {
    log.warn("voice.session_turn.failed", { requestId: request.requestId, status: "network_error" });
    return null;
  }

  if (!response.ok) {
    log.warn("voice.session_turn.failed", { requestId: request.requestId, status: response.status });
    return null;
  }

  const body = await response.json().catch(() => null);
  if (!body || typeof body !== "object" || Array.isArray(body) || body.ok !== true) {
    return null;
  }

  const record = body as Record<string, unknown>;
  const systemPrompt = typeof record.system_prompt === "string" ? record.system_prompt : "";
  if (!systemPrompt) {
    return null;
  }

  const responsePath = record.response_path === "fast" || record.response_path === "tool"
    ? record.response_path
    : "tool";

  return {
    sessionId: typeof record.session_id === "string" && record.session_id ? record.session_id : request.sessionId,
    activeTopic: typeof record.active_topic === "string" ? record.active_topic : "general",
    responsePath,
    systemPrompt,
    allowedTools: asStringArray(record.allowed_tools),
    sectionNames: asStringArray(record.section_names),
    promptChars: typeof record.prompt_chars === "number" ? record.prompt_chars : systemPrompt.length,
    detectedLanguage: typeof record.detected_language === "string" ? record.detected_language : "en",
    fillers: parseFillers(record.fillers),
  };
}

export function createLaravelFetchSessionTurn(infra: InfraConfig): FetchSessionTurn {
  return (request, signal) => fetchLaravelSessionTurn(infra, request, signal);
}
