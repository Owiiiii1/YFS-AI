import type { ChatCompletionMessageParam, ChatCompletionTool } from "openai/resources/chat/completions";

export type ChatStreamParams = {
  model: string;
  messages: ChatCompletionMessageParam[];
  temperature?: number;
  maxTokens?: number;
  tools?: ChatCompletionTool[];
  toolChoice?: unknown;
  user?: string;
};

export type OpenAiChatChunk = {
  id: string;
  object: "chat.completion.chunk";
  created: number;
  model: string;
  choices: Array<{
    index: number;
    delta: Record<string, unknown>;
    finish_reason: string | null;
  }>;
};

export interface LlmProvider {
  readonly providerName: string;
  streamChatCompletion(params: ChatStreamParams, signal: AbortSignal): Promise<AsyncIterable<unknown>>;
}
