export type InfraConfig = {
  host: string;
  port: number;
  openaiTimeoutMs: number;
  speechEngineId: string;
  wsPath: string;
  laravelInternalBaseUrl: string;
  voiceRuntimeInternalToken: string;
  voiceLlmSharedSecret: string;
  configTtlMs: number;
  maxLlmBodyBytes: number;
  maxLlmMessages: number;
};

function readNumber(name: string, fallback: number): number {
  const raw = process.env[name];
  if (!raw) {
    return fallback;
  }
  const value = Number(raw);
  if (!Number.isFinite(value) || value <= 0) {
    throw new Error(`${name} must be a positive number`);
  }
  return value;
}

export function loadInfraConfig(): InfraConfig {
  const wsPath = process.env.VOICE_WS_PATH?.trim() || "/ws";
  if (!wsPath.startsWith("/")) {
    throw new Error("VOICE_WS_PATH must start with /");
  }

  const laravelInternalBaseUrl = (process.env.LARAVEL_INTERNAL_BASE_URL?.trim() || "https://ai.youngfashionshow.com").replace(/\/$/, "");

  return {
    host: process.env.HOST?.trim() || "127.0.0.1",
    port: readNumber("PORT", 3101),
    openaiTimeoutMs: readNumber("VOICE_OPENAI_TIMEOUT_MS", 20_000),
    speechEngineId: process.env.ELEVENLABS_SPEECH_ENGINE_ID?.trim() || "",
    wsPath,
    laravelInternalBaseUrl,
    voiceRuntimeInternalToken: process.env.VOICE_RUNTIME_INTERNAL_TOKEN?.trim() || "",
    voiceLlmSharedSecret: process.env.VOICE_LLM_SHARED_SECRET?.trim() || "",
    configTtlMs: readNumber("VOICE_CONFIG_TTL_MS", 60_000),
    maxLlmBodyBytes: readNumber("VOICE_LLM_MAX_BODY_BYTES", 262_144),
    maxLlmMessages: readNumber("VOICE_LLM_MAX_MESSAGES", 48),
  };
}
