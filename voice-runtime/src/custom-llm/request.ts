import type { IncomingMessage } from "node:http";
import type { ChatCompletionMessageParam, ChatCompletionTool } from "openai/resources/chat/completions";
import { SYSTEM_PROMPT } from "../config/system-prompt.js";

export class BodyTooLargeError extends Error {
  constructor() {
    super("request_body_too_large");
    this.name = "BodyTooLargeError";
  }
}

export class MalformedJsonError extends Error {
  constructor() {
    super("malformed_json");
    this.name = "MalformedJsonError";
  }
}

export class InvalidChatRequestError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "InvalidChatRequestError";
  }
}

export type NormalizedChatRequest = {
  incomingModel: string;
  stream: boolean;
  temperature?: number;
  maxTokens?: number;
  user?: string;
  messageCount: number;
  hasTools: boolean;
  messages: ChatCompletionMessageParam[];
  tools?: ChatCompletionTool[];
  toolChoice?: unknown;
};

const ALLOWED_ROLES = new Set(["system", "user", "assistant", "tool"]);

export function readRequestBody(req: IncomingMessage, maxBytes: number): Promise<Buffer> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    let size = 0;
    let settled = false;

    const fail = (error: Error): void => {
      if (settled) {
        return;
      }
      settled = true;
      req.removeAllListeners("data");
      req.removeAllListeners("end");
      reject(error);
    };

    req.on("data", (chunk: Buffer | string) => {
      const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
      size += buffer.length;
      if (size > maxBytes) {
        fail(new BodyTooLargeError());
        req.destroy();
        return;
      }
      chunks.push(buffer);
    });
    req.on("end", () => {
      if (settled) {
        return;
      }
      settled = true;
      resolve(Buffer.concat(chunks));
    });
    req.on("error", (error) => fail(error instanceof Error ? error : new Error("body_read_failed")));
  });
}

function asTextContent(content: unknown): string | null {
  if (typeof content === "string") {
    return content;
  }
  if (Array.isArray(content)) {
    const parts = content
      .map((part) => {
        if (typeof part === "string") {
          return part;
        }
        if (part && typeof part === "object" && "text" in part && typeof part.text === "string") {
          return part.text;
        }
        return "";
      })
      .filter(Boolean);
    return parts.length > 0 ? parts.join("\n") : "";
  }
  if (content == null) {
    return null;
  }
  return "";
}

function sanitizeMessage(value: unknown): ChatCompletionMessageParam | null {
  if (!value || typeof value !== "object") {
    return null;
  }
  const raw = value as Record<string, unknown>;
  const role = typeof raw.role === "string" ? raw.role : "";
  if (!ALLOWED_ROLES.has(role)) {
    return null;
  }

  const content = asTextContent(raw.content);
  const message: Record<string, unknown> = { role };

  if (content != null) {
    message.content = content;
  }

  if (role === "assistant" && Array.isArray(raw.tool_calls)) {
    message.tool_calls = raw.tool_calls;
  }
  if (role === "tool" && typeof raw.tool_call_id === "string") {
    message.tool_call_id = raw.tool_call_id;
  }
  if (typeof raw.name === "string" && raw.name) {
    message.name = raw.name;
  }

  if (role === "tool" && !message.tool_call_id) {
    return null;
  }
  if ((role === "system" || role === "user") && typeof message.content !== "string") {
    return null;
  }

  return message as unknown as ChatCompletionMessageParam;
}

function sanitizeTools(value: unknown): ChatCompletionTool[] | undefined {
  if (!Array.isArray(value) || value.length === 0) {
    return undefined;
  }

  const tools: ChatCompletionTool[] = [];
  for (const item of value) {
    if (!item || typeof item !== "object") {
      continue;
    }
    const tool = item as { type?: unknown; function?: { name?: unknown } };
    if (tool.type === "function" && typeof tool.function?.name === "string" && tool.function.name) {
      tools.push(item as ChatCompletionTool);
    }
  }
  return tools.length > 0 ? tools : undefined;
}

function capMessages(
  messages: ChatCompletionMessageParam[],
  maxMessages: number,
): ChatCompletionMessageParam[] {
  if (messages.length <= maxMessages) {
    return messages;
  }
  const system = messages.filter((message) => message.role === "system");
  const rest = messages.filter((message) => message.role !== "system");
  const budget = Math.max(1, maxMessages - system.length);
  return [...system, ...rest.slice(-budget)];
}

export function parseChatCompletionBody(raw: Buffer, maxMessages: number): NormalizedChatRequest {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw.toString("utf8") || "");
  } catch {
    throw new MalformedJsonError();
  }

  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    throw new InvalidChatRequestError("request_must_be_object");
  }

  const body = parsed as Record<string, unknown>;
  if (!Array.isArray(body.messages) || body.messages.length === 0) {
    throw new InvalidChatRequestError("messages_required");
  }

  const incoming = body.messages
    .map((message) => sanitizeMessage(message))
    .filter((message): message is ChatCompletionMessageParam => message !== null);

  if (incoming.length === 0) {
    throw new InvalidChatRequestError("messages_invalid");
  }

  const messages = capMessages(
    [{ role: "system", content: SYSTEM_PROMPT }, ...incoming],
    maxMessages,
  );

  const tools = sanitizeTools(body.tools);
  const temperature = typeof body.temperature === "number" && Number.isFinite(body.temperature)
    ? body.temperature
    : undefined;
  const maxTokens = typeof body.max_tokens === "number" && body.max_tokens > 0
    ? Math.floor(body.max_tokens)
    : undefined;
  const user = typeof body.user === "string"
    ? body.user
    : typeof body.user_id === "string"
      ? body.user_id
      : undefined;

  return {
    incomingModel: typeof body.model === "string" ? body.model : "",
    stream: body.stream !== false,
    temperature,
    maxTokens,
    user,
    messageCount: incoming.length,
    hasTools: Boolean(tools),
    messages,
    tools,
    toolChoice: body.tool_choice,
  };
}

export function lastUserText(messages: ChatCompletionMessageParam[]): string {
  for (let index = messages.length - 1; index >= 0; index -= 1) {
    const message = messages[index];
    if (message?.role === "user" && "content" in message && typeof message.content === "string") {
      return message.content;
    }
  }
  return "";
}
