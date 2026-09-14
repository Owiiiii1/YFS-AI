import type { InfraConfig } from "../config/env.js";
import { log } from "../logger.js";

export type LaravelVoiceConfig = {
  llm: {
    provider: string;
    model: string;
    apiKey: string;
  };
  elevenlabs: {
    apiKey: string;
  };
};

export class LaravelConfigError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "LaravelConfigError";
  }
}

export async function fetchLaravelVoiceConfig(infra: InfraConfig): Promise<LaravelVoiceConfig> {
  if (!infra.voiceRuntimeInternalToken) {
    throw new LaravelConfigError("VOICE_RUNTIME_INTERNAL_TOKEN is empty");
  }

  const url = `${infra.laravelInternalBaseUrl}/api/internal/voice-runtime/config`;
  const response = await fetch(url, {
    method: "GET",
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${infra.voiceRuntimeInternalToken}`,
    },
    signal: AbortSignal.timeout(8_000),
  });

  if (!response.ok) {
    log.warn("laravel.config.http_error", { status: response.status });
    throw new LaravelConfigError(`Laravel config HTTP ${response.status}`);
  }

  const body = (await response.json()) as {
    llm?: { provider?: string; model?: string; api_key?: string };
    elevenlabs?: { api_key?: string };
  };

  return {
    llm: {
      provider: typeof body.llm?.provider === "string" ? body.llm.provider : "",
      model: typeof body.llm?.model === "string" ? body.llm.model : "",
      apiKey: typeof body.llm?.api_key === "string" ? body.llm.api_key : "",
    },
    elevenlabs: {
      apiKey: typeof body.elevenlabs?.api_key === "string" ? body.elevenlabs.api_key : "",
    },
  };
}
