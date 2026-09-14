import { log } from "../logger.js";
import { isAbortError } from "./abort.js";
import { buildGeminiGenerateRequest, extractGeminiTextParts, geminiModelPath } from "./gemini-convert.js";
import { geminiHttpErrorLogFields, summarizeGeminiErrorBody } from "./gemini-error.js";
import { createChatCompletionChunk, createChatCompletionId } from "./openai-chunks.js";
import type { ChatStreamParams, LlmProvider } from "./types.js";

export class GeminiUpstreamError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "GeminiUpstreamError";
  }
}

type FetchLike = typeof fetch;

function parseSseBuffer(buffer: string): { events: unknown[]; rest: string } {
  const events: unknown[] = [];
  const normalized = buffer.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  const parts = normalized.split("\n\n");
  const rest = parts.pop() ?? "";
  for (const block of parts) {
    const dataLines = block
      .split("\n")
      .map((line) => line.trimEnd())
      .filter((line) => line.startsWith("data:"))
      .map((line) => line.slice(5).trim());
    if (dataLines.length === 0) {
      continue;
    }
    const payload = dataLines.join("");
    if (!payload || payload === "[DONE]") {
      continue;
    }
    try {
      events.push(JSON.parse(payload) as unknown);
    } catch {
      log.warn("gemini.sse.malformed", { bytes: payload.length });
    }
  }
  return { events, rest };
}

async function* iterateGeminiSse(
  body: ReadableStream<Uint8Array>,
  signal: AbortSignal,
): AsyncGenerator<unknown> {
  const reader = body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";

  try {
    while (!signal.aborted) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const parsed = parseSseBuffer(buffer);
      buffer = parsed.rest;
      for (const event of parsed.events) {
        yield event;
      }
    }
    if (buffer.trim()) {
      const parsed = parseSseBuffer(`${buffer}\n\n`);
      for (const event of parsed.events) {
        yield event;
      }
    }
  } finally {
    try {
      reader.releaseLock();
    } catch {
      // ignore
    }
  }
}

export class GeminiLlmProvider implements LlmProvider {
  readonly providerName = "gemini";

  constructor(
    private readonly apiKey: string,
    private readonly timeoutMs: number,
    private readonly fetchImpl: FetchLike = fetch,
  ) {}

  async streamChatCompletion(params: ChatStreamParams, signal: AbortSignal): Promise<AsyncIterable<unknown>> {
    const model = geminiModelPath(params.model);
    const timeout = AbortSignal.timeout(this.timeoutMs);
    const combined = AbortSignal.any([signal, timeout]);
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:streamGenerateContent?alt=sse`;
    const request = buildGeminiGenerateRequest(params);

    const response = await this.fetchImpl(url, {
      method: "POST",
      headers: {
        Accept: "text/event-stream",
        "Content-Type": "application/json",
        "x-goog-api-key": this.apiKey,
      },
      body: JSON.stringify(request),
      signal: combined,
    });

    if (!response.ok) {
      const raw = await response.text().catch(() => "");
      const summary = summarizeGeminiErrorBody(response.status, model, raw);
      log.warn("gemini.http_error", geminiHttpErrorLogFields(summary));
      throw new GeminiUpstreamError(
        summary.message ? `Gemini HTTP ${response.status}: ${summary.message}` : `Gemini HTTP ${response.status}`,
      );
    }
    if (!response.body) {
      throw new GeminiUpstreamError("Gemini response body is empty");
    }

    const id = createChatCompletionId();
    const body = response.body;

    return (async function* geminiOpenAiChunks() {
      let sentRole = false;
      let toolCallIndex = 0;
      let sawFinish = false;

      try {
        for await (const event of iterateGeminiSse(body, combined)) {
          if (combined.aborted) {
            break;
          }
          const extracted = extractGeminiTextParts(event);
          for (const text of extracted.texts) {
            const delta: Record<string, unknown> = sentRole ? { content: text } : { role: "assistant", content: text };
            sentRole = true;
            yield createChatCompletionChunk(id, params.model, delta);
          }
          for (const call of extracted.functionCalls) {
            yield createChatCompletionChunk(id, params.model, {
              tool_calls: [{
                index: toolCallIndex,
                id: `call_${id}_${toolCallIndex}`,
                type: "function",
                function: {
                  name: call.name,
                  arguments: JSON.stringify(call.args ?? {}),
                },
                ...(call.thoughtSignature ? { thought_signature: call.thoughtSignature } : {}),
              }],
            });
            toolCallIndex += 1;
          }
          if (extracted.finishReason) {
            sawFinish = true;
            yield createChatCompletionChunk(id, params.model, {}, extracted.finishReason);
          }
        }
        if (!sawFinish && !combined.aborted) {
          yield createChatCompletionChunk(id, params.model, {}, "stop");
        }
      } catch (error) {
        if (isAbortError(error) || combined.aborted) {
          throw error;
        }
        log.error("gemini.stream.failed", { error, model });
        throw error;
      }
    })();
  }
}
