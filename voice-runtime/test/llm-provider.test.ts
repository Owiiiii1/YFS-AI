import assert from "node:assert/strict";
import { test } from "node:test";
import { SYSTEM_PROMPT } from "../src/config/system-prompt.js";
import { parseChatCompletionBody } from "../src/custom-llm/request.js";
import { writeSseData, writeSseDone } from "../src/custom-llm/sse.js";
import {
  buildGeminiGenerateRequest,
  extractGeminiTextParts,
  openaiMessagesToGemini,
} from "../src/llm/gemini-convert.js";
import { GeminiLlmProvider } from "../src/llm/gemini-provider.js";
import { createChatCompletionChunk } from "../src/llm/openai-chunks.js";
import { createLlmProvider, UnsupportedLlmProviderError } from "../src/llm/provider.js";
import { OpenAiLlmProvider } from "../src/llm/openai-provider.js";
import type { ServerResponse } from "node:http";

test("createLlmProvider selects gemini", () => {
  const provider = createLlmProvider({
    provider: "Gemini",
    model: "gemini-3.8-flash",
    apiKey: "AIza-test",
    timeoutMs: 1_000,
  });
  assert.equal(provider.providerName, "gemini");
  assert.ok(provider instanceof GeminiLlmProvider);
});

test("createLlmProvider selects openai", () => {
  const provider = createLlmProvider({
    provider: "openai",
    model: "gpt-test-runtime",
    apiKey: "sk-test",
    timeoutMs: 1_000,
  });
  assert.equal(provider.providerName, "openai");
  assert.ok(provider instanceof OpenAiLlmProvider);
});

test("createLlmProvider rejects unsupported providers", () => {
  assert.throws(
    () => createLlmProvider({
      provider: "anthropic",
      model: "claude-test",
      apiKey: "key",
      timeoutMs: 1_000,
    }),
    (error: unknown) => error instanceof UnsupportedLlmProviderError && error.provider === "anthropic",
  );
  assert.throws(
    () => createLlmProvider({
      provider: "",
      model: "x",
      apiKey: "key",
      timeoutMs: 1_000,
    }),
    UnsupportedLlmProviderError,
  );
});

test("Gemini conversion keeps YFS guardrail and ElevenLabs system prompt", () => {
  const parsed = parseChatCompletionBody(Buffer.from(JSON.stringify({
    model: "yfs-bot-runtime",
    messages: [
      { role: "system", content: "Agent prompt from ElevenLabs." },
      { role: "user", content: "Hello" },
      { role: "assistant", content: "Hi there" },
      { role: "user", content: "Reply with exactly OK" },
    ],
  })), 48);

  const converted = openaiMessagesToGemini(parsed.messages);
  assert.ok(converted.systemInstruction?.parts[0]?.text.includes(SYSTEM_PROMPT));
  assert.ok(converted.systemInstruction?.parts[0]?.text.includes("Agent prompt from ElevenLabs."));
  assert.deepEqual(converted.contents.map((item) => item.role), ["user", "model", "user"]);
  assert.equal(converted.contents[0]?.parts[0]?.text, "Hello");
  assert.equal(converted.contents[1]?.parts[0]?.text, "Hi there");
  assert.equal(converted.contents[2]?.parts[0]?.text, "Reply with exactly OK");
});

test("Gemini conversion maps OpenAI tools without inventing results", () => {
  const request = buildGeminiGenerateRequest({
    messages: [{ role: "user", content: "end please" }],
    tools: [{
      type: "function",
      function: {
        name: "end_call",
        description: "End the call",
        parameters: { type: "object", properties: {} },
      },
    }],
    toolChoice: "auto",
  });
  assert.equal(request.tools?.[0]?.functionDeclarations[0]?.name, "end_call");
  assert.equal(request.toolConfig?.functionCallingConfig.mode, "AUTO");
});

test("Gemini streamed text maps to OpenAI SSE chunks and [DONE]", async () => {
  const sse = [
    "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"O\"}]}}]}\r\n\r\n",
    "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"K\"}]},\"finishReason\":\"STOP\"}]}\r\n\r\n",
  ];
  const encoder = new TextEncoder();
  const fetchImpl: typeof fetch = async (input, init) => {
    const url = String(input);
    assert.equal(url.includes("AIza-test-not-real"), false);
    assert.match(url, /streamGenerateContent\?alt=sse$/);
    const headers = init?.headers as Record<string, string>;
    assert.equal(headers["x-goog-api-key"], "AIza-test-not-real");
    return new Response(
      new ReadableStream({
        start(controller) {
          controller.enqueue(encoder.encode(sse[0]));
          controller.enqueue(encoder.encode(sse[1]));
          controller.close();
        },
      }),
      { status: 200, headers: { "Content-Type": "text/event-stream" } },
    );
  };

  const provider = new GeminiLlmProvider("AIza-test-not-real", 5_000, fetchImpl);
  const chunks: unknown[] = [];
  const stream = await provider.streamChatCompletion({
    model: "gemini-3.8-flash",
    messages: [{ role: "user", content: "Reply with exactly OK" }],
  }, new AbortController().signal);

  for await (const chunk of stream) {
    chunks.push(chunk);
  }

  const serialized = chunks.map((chunk) => JSON.stringify(chunk)).join("\n");
  assert.match(serialized, /"object":"chat.completion.chunk"/);
  assert.match(serialized, /"content":"O"/);
  assert.match(serialized, /"content":"K"/);
  assert.match(serialized, /"finish_reason":"stop"/);
  assert.equal(serialized.includes("AIza-test-not-real"), false);

  const writes: string[] = [];
  const res = {
    writableEnded: false,
    writable: true,
    write(chunk: string) {
      writes.push(chunk);
      return true;
    },
    end() {
      this.writableEnded = true;
    },
  } as unknown as ServerResponse;

  for (const chunk of chunks) {
    writeSseData(res, chunk);
  }
  writeSseDone(res);
  const sseOut = writes.join("");
  assert.match(sseOut, /^data: /);
  assert.match(sseOut, /data: \[DONE\]/);
});

test("Gemini thought parts are skipped and function calls become OpenAI tool_calls", () => {
  const extracted = extractGeminiTextParts({
    candidates: [{
      content: {
        parts: [
          { thought: true, text: "secret reasoning" },
          { text: "OK" },
          { functionCall: { name: "end_call", args: {} } },
        ],
      },
      finishReason: "STOP",
    }],
  });
  assert.deepEqual(extracted.texts, ["OK"]);
  assert.equal(extracted.functionCalls[0]?.name, "end_call");
  assert.equal(extracted.finishReason, "stop");
});

test("OpenAI chunk helper never embeds secrets", () => {
  const chunk = createChatCompletionChunk("chatcmpl-x", "gemini-3.8-flash", { content: "OK" }, null);
  assert.equal(chunk.object, "chat.completion.chunk");
  assert.equal(JSON.stringify(chunk).includes("AIza"), false);
});
