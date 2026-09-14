import type { ChatCompletionMessageParam, ChatCompletionTool } from "openai/resources/chat/completions";
import { openaiJsonSchemaToGemini } from "./gemini-schema.js";

export type GeminiPart = {
  text?: string;
  thought?: boolean;
  thoughtSignature?: string;
  functionCall?: { name?: string; args?: Record<string, unknown> };
  functionResponse?: { name?: string; response?: Record<string, unknown> };
};

export type GeminiContent = {
  role: "user" | "model";
  parts: GeminiPart[];
};

export type GeminiFunctionDeclaration = {
  name: string;
  description?: string;
  parameters?: Record<string, unknown>;
};

export type GeminiGenerateRequest = {
  systemInstruction?: { parts: Array<{ text: string }> };
  contents: GeminiContent[];
  generationConfig?: {
    temperature?: number;
    maxOutputTokens?: number;
  };
  tools?: Array<{ functionDeclarations: GeminiFunctionDeclaration[] }>;
  toolConfig?: {
    functionCallingConfig: {
      mode: "AUTO" | "NONE" | "ANY";
      allowedFunctionNames?: string[];
    };
  };
};

function textOf(message: ChatCompletionMessageParam): string {
  const content = "content" in message ? message.content : null;
  if (typeof content === "string") {
    return content;
  }
  if (Array.isArray(content)) {
    return content
      .map((part) => {
        if (typeof part === "string") {
          return part;
        }
        if (part && typeof part === "object" && "text" in part && typeof part.text === "string") {
          return part.text;
        }
        return "";
      })
      .filter(Boolean)
      .join("\n");
  }
  return "";
}

function pushOrMerge(contents: GeminiContent[], role: "user" | "model", parts: GeminiPart[]): void {
  const usable = parts.filter((part) => {
    if (part.text && part.text.length > 0) {
      return true;
    }
    if (part.functionCall?.name) {
      return true;
    }
    if (part.functionResponse?.name) {
      return true;
    }
    return false;
  });
  if (usable.length === 0) {
    return;
  }
  const last = contents[contents.length - 1];
  if (last && last.role === role) {
    last.parts.push(...usable);
    return;
  }
  contents.push({ role, parts: usable });
}

function assistantToolCallParts(message: ChatCompletionMessageParam): GeminiPart[] {
  if (message.role !== "assistant" || !("tool_calls" in message) || !Array.isArray(message.tool_calls)) {
    return [];
  }
  const parts: GeminiPart[] = [];
  for (const call of message.tool_calls) {
    if (!call || typeof call !== "object") {
      continue;
    }
    const fn = "function" in call ? call.function : undefined;
    const name = fn && typeof fn === "object" && typeof fn.name === "string" ? fn.name : "";
    if (!name) {
      continue;
    }
    let args: Record<string, unknown> = {};
    const rawArgs = fn && typeof fn === "object" ? fn.arguments : undefined;
    if (typeof rawArgs === "string" && rawArgs.trim()) {
      try {
        const parsed = JSON.parse(rawArgs) as unknown;
        if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
          args = parsed as Record<string, unknown>;
        }
      } catch {
        args = { _raw: rawArgs };
      }
    } else if (rawArgs && typeof rawArgs === "object" && !Array.isArray(rawArgs)) {
      args = rawArgs as Record<string, unknown>;
    }
    const extra = call as { thought_signature?: unknown; thoughtSignature?: unknown };
    const thoughtSignature = typeof extra.thought_signature === "string" && extra.thought_signature
      ? extra.thought_signature
      : typeof extra.thoughtSignature === "string" && extra.thoughtSignature
        ? extra.thoughtSignature
        : "";
    parts.push({
      functionCall: { name, args },
      ...(thoughtSignature ? { thoughtSignature } : {}),
    });
  }
  return parts;
}

/**
 * Convert OpenAI Chat Completions messages into Gemini generateContent payload.
 * All system messages (YFS guardrail + ElevenLabs agent prompt) become systemInstruction.
 */
export function openaiMessagesToGemini(messages: ChatCompletionMessageParam[]): Pick<
  GeminiGenerateRequest,
  "systemInstruction" | "contents"
> {
  const systemParts: string[] = [];
  const contents: GeminiContent[] = [];

  for (const message of messages) {
    const role = message.role;
    if (role === "system") {
      const text = textOf(message).trim();
      if (text) {
        systemParts.push(text);
      }
      continue;
    }

    if (role === "user") {
      pushOrMerge(contents, "user", [{ text: textOf(message) }]);
      continue;
    }

    if (role === "assistant") {
      const parts: GeminiPart[] = [];
      const text = textOf(message);
      if (text) {
        parts.push({ text });
      }
      parts.push(...assistantToolCallParts(message));
      pushOrMerge(contents, "model", parts);
      continue;
    }

    if (role === "tool") {
      const name = ("name" in message && typeof message.name === "string" && message.name)
        || ("tool_call_id" in message && typeof message.tool_call_id === "string" ? message.tool_call_id : "tool");
      const text = textOf(message);
      let response: Record<string, unknown> = { result: text };
      if (text.trim().startsWith("{")) {
        try {
          const parsed = JSON.parse(text) as unknown;
          if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
            response = parsed as Record<string, unknown>;
          }
        } catch {
          response = { result: text };
        }
      }
      pushOrMerge(contents, "user", [{ functionResponse: { name, response } }]);
    }
  }

  if (contents.length === 0) {
    contents.push({ role: "user", parts: [{ text: "Hello" }] });
  } else if (contents[0]?.role !== "user") {
    contents.unshift({ role: "user", parts: [{ text: "Continue." }] });
  }

  return {
    systemInstruction: systemParts.length > 0
      ? { parts: [{ text: systemParts.join("\n\n") }] }
      : undefined,
    contents,
  };
}

export function openaiToolsToGemini(tools: ChatCompletionTool[] | undefined): GeminiFunctionDeclaration[] {
  if (!tools || tools.length === 0) {
    return [];
  }
  const declarations: GeminiFunctionDeclaration[] = [];
  for (const tool of tools) {
    if (!tool || tool.type !== "function" || !tool.function?.name) {
      continue;
    }
    const declaration: GeminiFunctionDeclaration = { name: tool.function.name };
    if (typeof tool.function.description === "string" && tool.function.description) {
      declaration.description = tool.function.description;
    }
    if (tool.function.parameters && typeof tool.function.parameters === "object") {
      const parameters = openaiJsonSchemaToGemini(tool.function.parameters);
      if (parameters) {
        declaration.parameters = parameters;
      }
    }
    declarations.push(declaration);
  }
  return declarations;
}

export function openaiToolChoiceToGemini(toolChoice: unknown): GeminiGenerateRequest["toolConfig"] | undefined {
  if (toolChoice == null) {
    return undefined;
  }
  if (toolChoice === "auto") {
    return { functionCallingConfig: { mode: "AUTO" } };
  }
  if (toolChoice === "none") {
    return { functionCallingConfig: { mode: "NONE" } };
  }
  if (toolChoice === "required") {
    return { functionCallingConfig: { mode: "ANY" } };
  }
  if (typeof toolChoice === "object" && toolChoice && "function" in toolChoice) {
    const fn = (toolChoice as { function?: { name?: unknown } }).function;
    if (typeof fn?.name === "string" && fn.name) {
      return { functionCallingConfig: { mode: "ANY", allowedFunctionNames: [fn.name] } };
    }
  }
  return undefined;
}

export function buildGeminiGenerateRequest(params: {
  messages: ChatCompletionMessageParam[];
  temperature?: number;
  maxTokens?: number;
  tools?: ChatCompletionTool[];
  toolChoice?: unknown;
}): GeminiGenerateRequest {
  const converted = openaiMessagesToGemini(params.messages);
  const request: GeminiGenerateRequest = {
    ...converted,
  };
  if (params.temperature != null || params.maxTokens != null) {
    request.generationConfig = {};
    if (params.temperature != null) {
      request.generationConfig.temperature = params.temperature;
    }
    if (params.maxTokens != null) {
      request.generationConfig.maxOutputTokens = params.maxTokens;
    }
  }
  const declarations = openaiToolsToGemini(params.tools);
  if (declarations.length > 0) {
    request.tools = [{ functionDeclarations: declarations }];
    const toolConfig = openaiToolChoiceToGemini(params.toolChoice);
    if (toolConfig) {
      request.toolConfig = toolConfig;
    }
  }
  return request;
}

export function geminiModelPath(model: string): string {
  const trimmed = model.trim();
  return trimmed.startsWith("models/") ? trimmed.slice("models/".length) : trimmed;
}

export function mapGeminiFinishReason(reason: string | undefined): string | null {
  if (!reason) {
    return null;
  }
  switch (reason) {
    case "STOP":
      return "stop";
    case "MAX_TOKENS":
      return "length";
    case "SAFETY":
    case "BLOCKLIST":
    case "PROHIBITED_CONTENT":
      return "content_filter";
    case "MALFORMED_FUNCTION_CALL":
      return "stop";
    default:
      return "stop";
  }
}

export function extractGeminiTextParts(event: unknown): {
  texts: string[];
  functionCalls: Array<{ name: string; args: Record<string, unknown>; thoughtSignature?: string }>;
  finishReason: string | null;
} {
  const texts: string[] = [];
  const functionCalls: Array<{ name: string; args: Record<string, unknown>; thoughtSignature?: string }> = [];
  let finishReason: string | null = null;

  if (!event || typeof event !== "object") {
    return { texts, functionCalls, finishReason };
  }
  const candidates = (event as { candidates?: unknown }).candidates;
  if (!Array.isArray(candidates) || candidates.length === 0) {
    return { texts, functionCalls, finishReason };
  }
  const first = candidates[0];
  if (!first || typeof first !== "object") {
    return { texts, functionCalls, finishReason };
  }
  const rawReason = "finishReason" in first && typeof first.finishReason === "string"
    ? first.finishReason
    : undefined;
  finishReason = mapGeminiFinishReason(rawReason);

  const content = "content" in first ? first.content : undefined;
  const parts = content && typeof content === "object" && "parts" in content && Array.isArray(content.parts)
    ? content.parts
    : [];

  for (const part of parts) {
    if (!part || typeof part !== "object") {
      continue;
    }
    const record = part as GeminiPart & { thought_signature?: unknown };
    if (record.thought === true) {
      continue;
    }
    if (typeof record.text === "string" && record.text) {
      texts.push(record.text);
    }
    if (record.functionCall?.name) {
      const args = record.functionCall.args && typeof record.functionCall.args === "object"
        ? record.functionCall.args
        : {};
      const thoughtSignature = typeof record.thoughtSignature === "string" && record.thoughtSignature
        ? record.thoughtSignature
        : typeof record.thought_signature === "string" && record.thought_signature
          ? record.thought_signature
          : undefined;
      functionCalls.push({
        name: record.functionCall.name,
        args,
        ...(thoughtSignature ? { thoughtSignature } : {}),
      });
    }
  }

  return { texts, functionCalls, finishReason };
}
