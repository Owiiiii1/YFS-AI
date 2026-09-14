import OpenAI from "openai";
import { SYSTEM_PROMPT } from "../config/system-prompt.js";
import { log } from "../logger.js";

type VoiceTurn = {
  role: "user" | "agent";
  content: string;
};

function asInput(
  transcript: VoiceTurn[],
): Array<{ role: "user" | "assistant"; content: string }> {
  const input: Array<{ role: "user" | "assistant"; content: string }> = [];

  for (const message of transcript) {
    if (!message || typeof message !== "object") {
      continue;
    }
    const content = typeof message.content === "string" ? message.content.trim() : "";
    if (!content) {
      continue;
    }
    input.push({
      role: message.role === "agent" ? "assistant" : "user",
      content,
    });
  }

  return input;
}

export function createOpenAiClient(apiKey: string, timeoutMs: number): OpenAI {
  return new OpenAI({
    apiKey,
    timeout: timeoutMs,
  });
}

export async function streamVoiceReply(
  client: OpenAI,
  model: string,
  timeoutMs: number,
  transcript: VoiceTurn[],
  signal: AbortSignal,
): Promise<AsyncIterable<unknown>> {
  const input = asInput(Array.isArray(transcript) ? transcript : []);
  if (input.length === 0) {
    throw new Error("empty_transcript");
  }

  const timeout = AbortSignal.timeout(timeoutMs);
  const combined = AbortSignal.any([signal, timeout]);

  return client.responses.create(
    {
      model,
      instructions: SYSTEM_PROMPT,
      input,
      stream: true,
    },
    { signal: combined },
  );
}

export { isAbortError } from "./abort.js";

export function logOpenAiFailure(error: unknown, conversationId: string | undefined): void {
  log.error("openai.request.failed", {
    conversationId: conversationId ?? "unknown",
    error,
  });
}
