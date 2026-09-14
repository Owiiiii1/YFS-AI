import type { OpenAiChatChunk } from "./types.js";

export function createChatCompletionId(): string {
  return `chatcmpl-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

export function createChatCompletionChunk(
  id: string,
  model: string,
  delta: Record<string, unknown>,
  finishReason: string | null = null,
  created = Math.floor(Date.now() / 1000),
): OpenAiChatChunk {
  return {
    id,
    object: "chat.completion.chunk",
    created,
    model,
    choices: [{ index: 0, delta, finish_reason: finishReason }],
  };
}
