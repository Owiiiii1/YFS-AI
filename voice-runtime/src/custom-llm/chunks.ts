import type { ChatCompletionMessageParam } from "openai/resources/chat/completions";

export type AccumulatedToolCall = {
  id: string;
  name: string;
  arguments: string;
  thoughtSignature?: string;
};

export type StreamDelta = {
  content: string;
  toolCalls: Array<{
    index: number;
    id?: string;
    name?: string;
    arguments?: string;
    thoughtSignature?: string;
  }>;
  finishReason: string | null;
};

export function inspectOpenAiChunk(chunk: unknown): StreamDelta {
  const empty: StreamDelta = { content: "", toolCalls: [], finishReason: null };
  if (!chunk || typeof chunk !== "object") {
    return empty;
  }
  const choices = (chunk as { choices?: unknown }).choices;
  if (!Array.isArray(choices) || choices.length === 0 || !choices[0] || typeof choices[0] !== "object") {
    return empty;
  }
  const choice = choices[0] as { delta?: unknown; finish_reason?: unknown };
  const finishReason = typeof choice.finish_reason === "string" ? choice.finish_reason : null;
  const delta = choice.delta && typeof choice.delta === "object"
    ? choice.delta as Record<string, unknown>
    : {};

  const content = typeof delta.content === "string" ? delta.content : "";
  const toolCalls: StreamDelta["toolCalls"] = [];
  if (Array.isArray(delta.tool_calls)) {
    for (const item of delta.tool_calls) {
      if (!item || typeof item !== "object") {
        continue;
      }
      const raw = item as {
        index?: unknown;
        id?: unknown;
        thought_signature?: unknown;
        thoughtSignature?: unknown;
        function?: { name?: unknown; arguments?: unknown };
      };
      const index = typeof raw.index === "number" && Number.isFinite(raw.index) ? raw.index : toolCalls.length;
      const fn = raw.function && typeof raw.function === "object" ? raw.function : {};
      const thoughtSignature = typeof raw.thought_signature === "string"
        ? raw.thought_signature
        : typeof raw.thoughtSignature === "string"
          ? raw.thoughtSignature
          : undefined;
      toolCalls.push({
        index,
        id: typeof raw.id === "string" ? raw.id : undefined,
        name: typeof fn.name === "string" ? fn.name : undefined,
        arguments: typeof fn.arguments === "string" ? fn.arguments : undefined,
        thoughtSignature,
      });
    }
  }

  return { content, toolCalls, finishReason };
}

export function accumulateToolCalls(
  acc: Map<number, AccumulatedToolCall>,
  deltas: StreamDelta["toolCalls"],
): void {
  for (const delta of deltas) {
    const current = acc.get(delta.index) ?? { id: "", name: "", arguments: "" };
    if (delta.id) {
      current.id = delta.id;
    }
    if (delta.name) {
      current.name = delta.name;
    }
    if (delta.arguments) {
      current.arguments += delta.arguments;
    }
    if (delta.thoughtSignature) {
      current.thoughtSignature = delta.thoughtSignature;
    }
    acc.set(delta.index, current);
  }
}

export function parseToolArguments(raw: string): Record<string, unknown> {
  const trimmed = raw.trim();
  if (!trimmed) {
    return {};
  }
  try {
    const parsed = JSON.parse(trimmed) as unknown;
    if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
      return parsed as Record<string, unknown>;
    }
    return {};
  } catch {
    return {};
  }
}

export function toAssistantToolMessage(
  calls: AccumulatedToolCall[],
  content: string,
): ChatCompletionMessageParam {
  return {
    role: "assistant",
    content: content || null,
    tool_calls: calls.map((call, index) => ({
      id: call.id || `call_${index}`,
      type: "function" as const,
      function: {
        name: call.name,
        arguments: call.arguments || "{}",
      },
      ...(call.thoughtSignature ? { thought_signature: call.thoughtSignature } : {}),
    })),
  } as ChatCompletionMessageParam;
}

export function toToolResultMessage(
  call: AccumulatedToolCall,
  result: unknown,
): ChatCompletionMessageParam {
  let content = "{}";
  try {
    content = JSON.stringify(result ?? {});
  } catch {
    content = "{\"ok\":false,\"error\":{\"type\":\"invalid_result\"}}";
  }
  return {
    role: "tool",
    tool_call_id: call.id || call.name,
    content,
    name: call.name,
  } as ChatCompletionMessageParam;
}
