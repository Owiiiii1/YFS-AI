import type { InfraConfig } from "../config/env.js";
import { log } from "../logger.js";
import { fetchLaravelVoiceConfig, type LaravelVoiceConfig } from "./config-client.js";

export type ConfigLoadState = "loaded" | "missing" | "error";

export type VoiceRuntimeSnapshot = {
  laravel_config: ConfigLoadState;
  laravel_config_fetched_at: string | null;
  laravel_config_loaded_at: string | null;
  provider: string | null;
  model: string | null;
  llm_key: "configured" | "missing";
  elevenlabs_key: "configured" | "missing";
  speech_engine: "waiting" | "ready";
};

export type VoiceConfigSource = {
  get(options?: { refresh?: boolean }): Promise<LaravelVoiceConfig | null>;
  snapshot(): VoiceRuntimeSnapshot;
};

function isoOrNull(value: number): string | null {
  return value > 0 ? new Date(value).toISOString() : null;
}

export class LaravelConfigStore implements VoiceConfigSource {
  private cached: LaravelVoiceConfig | null = null;
  private loadedAt = 0;
  private fetchedAt = 0;
  private lastState: ConfigLoadState = "missing";

  constructor(private readonly infra: InfraConfig) {}

  snapshot(): VoiceRuntimeSnapshot {
    const provider = this.cached?.llm.provider?.trim() || null;
    const model = this.cached?.llm.model?.trim() || null;
    return {
      laravel_config: this.lastState,
      laravel_config_fetched_at: isoOrNull(this.fetchedAt),
      laravel_config_loaded_at: isoOrNull(this.loadedAt),
      provider,
      model,
      llm_key: this.cached?.llm.apiKey && model ? "configured" : "missing",
      elevenlabs_key: this.cached?.elevenlabs.apiKey ? "configured" : "missing",
      speech_engine: this.infra.speechEngineId ? "ready" : "waiting",
    };
  }

  async get(options: { refresh?: boolean } = {}): Promise<LaravelVoiceConfig | null> {
    const fresh = Date.now() - this.loadedAt < this.infra.configTtlMs;
    if (!options.refresh && this.cached && this.lastState === "loaded" && fresh) {
      return this.cached;
    }

    this.fetchedAt = Date.now();
    try {
      const next = await fetchLaravelVoiceConfig(this.infra);
      this.cached = next;
      this.loadedAt = Date.now();
      this.fetchedAt = this.loadedAt;
      this.lastState = "loaded";
      log.info("laravel.config.loaded", {
        llmProvider: next.llm.provider || "none",
        llmModel: next.llm.model || "none",
        llmConfigured: Boolean(next.llm.apiKey),
        elevenlabsConfigured: Boolean(next.elevenlabs.apiKey),
      });
      return next;
    } catch (error) {
      this.lastState = "error";
      log.error("laravel.config.failed", { error });
      return this.cached;
    }
  }
}
