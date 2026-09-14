import { GeminiLlmProvider } from "./gemini-provider.js";
import { OpenAiLlmProvider } from "./openai-provider.js";
import type { LlmProvider } from "./types.js";

export class UnsupportedLlmProviderError extends Error {
  constructor(public readonly provider: string) {
    super(`Unsupported LLM provider [${provider || "empty"}]`);
    this.name = "UnsupportedLlmProviderError";
  }
}

export type LlmProviderConfig = {
  provider: string;
  model: string;
  apiKey: string;
  timeoutMs: number;
};

export function normalizeLlmProviderName(provider: string): string {
  return provider.trim().toLowerCase();
}

export function createLlmProvider(config: LlmProviderConfig): LlmProvider {
  const name = normalizeLlmProviderName(config.provider);
  if (name === "openai") {
    return new OpenAiLlmProvider(config.apiKey, config.timeoutMs);
  }
  if (name === "gemini") {
    return new GeminiLlmProvider(config.apiKey, config.timeoutMs);
  }
  throw new UnsupportedLlmProviderError(name);
}

export type { LlmProvider } from "./types.js";
