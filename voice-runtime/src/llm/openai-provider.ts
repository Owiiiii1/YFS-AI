import { streamChatCompletions } from "./chat-completions.js";
import { createOpenAiClient } from "./openai.js";
import type { ChatStreamParams, LlmProvider } from "./types.js";

export class OpenAiLlmProvider implements LlmProvider {
  readonly providerName = "openai";

  constructor(
    private readonly apiKey: string,
    private readonly timeoutMs: number,
  ) {}

  async streamChatCompletion(params: ChatStreamParams, signal: AbortSignal): Promise<AsyncIterable<unknown>> {
    const client = createOpenAiClient(this.apiKey, this.timeoutMs);
    return streamChatCompletions(client, params, this.timeoutMs, signal);
  }
}
