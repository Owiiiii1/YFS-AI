import type OpenAI from "openai";
import type { ChatStreamParams } from "./types.js";

export type { ChatStreamParams } from "./types.js";

export async function streamChatCompletions(
  client: OpenAI,
  params: ChatStreamParams,
  timeoutMs: number,
  signal: AbortSignal,
): Promise<AsyncIterable<unknown>> {
  const timeout = AbortSignal.timeout(timeoutMs);
  const combined = AbortSignal.any([signal, timeout]);

  return client.chat.completions.create(
    {
      model: params.model,
      messages: params.messages,
      temperature: params.temperature,
      max_tokens: params.maxTokens,
      stream: true,
      ...(params.tools ? { tools: params.tools } : {}),
      ...(params.toolChoice !== undefined ? { tool_choice: params.toolChoice as never } : {}),
      ...(params.user ? { user: params.user } : {}),
    },
    { signal: combined },
  );
}
