import type { ChatCompletionMessageParam, ChatCompletionTool } from "openai/resources/chat/completions";
import type { ServerResponse } from "node:http";
import { isAbortError } from "../llm/abort.js";
import type { ChatStreamParams, LlmProvider } from "../llm/types.js";
import { log } from "../logger.js";
import { isYfsServerTool } from "../voice-tools/catalog.js";
import type { ExecuteVoiceTool } from "../voice-tools/laravel-client.js";
import { safeToolResult } from "../voice-tools/laravel-client.js";
import {
  accumulateToolCalls,
  inspectOpenAiChunk,
  parseToolArguments,
  toAssistantToolMessage,
  toToolResultMessage,
  type AccumulatedToolCall,
} from "./chunks.js";
import { writeSseData } from "./sse.js";
import { selectFiller, writeFillerSse } from "./filler.js";
import type { VoiceFillerHint } from "../voice-tools/session-client.js";

const MAX_TOOL_ROUNDS = 3;

export type ToolAwareStreamParams = {
  llm: LlmProvider;
  executeVoiceTool: ExecuteVoiceTool;
  requestId: string;
  res: ServerResponse;
  signal: AbortSignal;
  model: string;
  messages: ChatCompletionMessageParam[];
  temperature?: number;
  maxTokens?: number;
  user?: string;
  tools?: ChatCompletionTool[];
  toolChoice?: unknown;
  maxRounds?: number;
  fillers?: Record<string, VoiceFillerHint>;
};

function writable(res: ServerResponse): boolean {
  return !res.writableEnded && res.writable;
}

async function executeYfsCalls(
  calls: AccumulatedToolCall[],
  executeVoiceTool: ExecuteVoiceTool,
  requestId: string,
  signal: AbortSignal,
): Promise<ChatCompletionMessageParam[]> {
  const messages: ChatCompletionMessageParam[] = [];
  for (const call of calls) {
    if (signal.aborted) {
      break;
    }
    try {
      const result = await executeVoiceTool(
        call.name,
        parseToolArguments(call.arguments),
        requestId,
        signal,
      );
      messages.push(toToolResultMessage(call, result));
    } catch (error) {
      if (isAbortError(error) || signal.aborted) {
        throw error;
      }
      log.warn("voice.tool.failed", {
        requestId,
        tool: call.name,
        status: "error",
      });
      messages.push(toToolResultMessage(call, safeToolResult(error)));
    }
  }
  return messages;
}

export async function streamWithServerTools(params: ToolAwareStreamParams): Promise<{
  status: "ok" | "aborted";
  rounds: number;
  path: "fast" | "tool";
  filler: boolean;
}> {
  const maxRounds = params.maxRounds ?? MAX_TOOL_ROUNDS;
  let messages = params.messages;
  let rounds = 0;
  let usedTools = false;
  let usedFiller = false;

  for (let round = 0; round < maxRounds; round += 1) {
    if (params.signal.aborted || !writable(params.res)) {
      return { status: "aborted", rounds, path: usedTools ? "tool" : "fast", filler: usedFiller };
    }

    rounds = round + 1;
    const streamParams: ChatStreamParams = {
      model: params.model,
      messages,
      temperature: params.temperature,
      maxTokens: params.maxTokens,
      tools: params.tools,
      toolChoice: params.toolChoice,
      user: params.user,
    };

    const stream = await params.llm.streamChatCompletion(streamParams, params.signal);
    const acc = new Map<number, AccumulatedToolCall>();
    const held: unknown[] = [];
    let assistantText = "";

    for await (const chunk of stream) {
    if (params.signal.aborted || !writable(params.res)) {
      return { status: "aborted", rounds, path: usedTools ? "tool" : "fast", filler: usedFiller };
    }
      const delta = inspectOpenAiChunk(chunk);
      if (delta.toolCalls.length > 0) {
        accumulateToolCalls(acc, delta.toolCalls);
      }
      if (delta.content) {
        assistantText += delta.content;
      }
      if (delta.toolCalls.length > 0 || delta.finishReason) {
        held.push(chunk);
        continue;
      }
      writeSseData(params.res, chunk);
    }

    if (params.signal.aborted || !writable(params.res)) {
      return { status: "aborted", rounds, path: usedTools ? "tool" : "fast", filler: usedFiller };
    }

    const completed = [...acc.values()].filter((call) => call.name);
    const yfsCalls = completed.filter((call) => isYfsServerTool(call.name));
    const otherCalls = completed.filter((call) => !isYfsServerTool(call.name));

    if (yfsCalls.length === 0) {
      for (const chunk of held) {
        if (!writable(params.res)) {
          return { status: "aborted", rounds, path: usedTools ? "tool" : "fast", filler: usedFiller };
        }
        writeSseData(params.res, chunk);
      }
      return { status: "ok", rounds, path: usedTools ? "tool" : "fast", filler: usedFiller };
    }

    usedTools = true;

    if (otherCalls.length > 0) {
      log.warn("voice.tool.mixed_calls", {
        requestId: params.requestId,
        yfs: yfsCalls.map((call) => call.name),
        other: otherCalls.map((call) => call.name),
      });
    }

    if (!assistantText.trim()) {
      const filler = selectFiller(params.fillers, yfsCalls[0]?.name ?? "");
      if (filler) {
        writeFillerSse(params.res, params.model, filler, params.requestId);
        usedFiller = true;
      }
    }

    const toolMessages = await executeYfsCalls(yfsCalls, params.executeVoiceTool, params.requestId, params.signal);
    messages = [
      ...messages,
      toAssistantToolMessage(yfsCalls, assistantText),
      ...toolMessages,
    ];
  }

  log.warn("voice.tool.round_limit", {
    requestId: params.requestId,
    rounds,
  });
  return { status: "ok", rounds, path: usedTools ? "tool" : "fast", filler: usedFiller };
}
