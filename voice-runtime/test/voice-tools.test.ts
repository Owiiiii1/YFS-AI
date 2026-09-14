import assert from "node:assert/strict";
import { test } from "node:test";
import {
  accumulateToolCalls,
  inspectOpenAiChunk,
  parseToolArguments,
  toToolResultMessage,
} from "../src/custom-llm/chunks.js";
import { extractGeminiTextParts } from "../src/llm/gemini-convert.js";
import {
  isYfsServerTool,
  mergeChatTools,
  YFS_TEST_TOOL_NAME,
  YFS_VOICE_TOOLS,
} from "../src/voice-tools/catalog.js";
import { executeLaravelVoiceTool, VoiceToolClientError } from "../src/voice-tools/laravel-client.js";
import type { InfraConfig } from "../src/config/env.js";

test("Gemini functionCall is parsed into OpenAI tool_calls shape", () => {
  const extracted = extractGeminiTextParts({
    candidates: [{
      content: {
        parts: [
          { functionCall: { name: YFS_TEST_TOOL_NAME, args: { topic: "phone" } } },
        ],
      },
      finishReason: "STOP",
    }],
  });
  assert.equal(extracted.functionCalls[0]?.name, YFS_TEST_TOOL_NAME);
  assert.deepEqual(extracted.functionCalls[0]?.args, { topic: "phone" });

  const inspected = inspectOpenAiChunk({
    choices: [{
      delta: {
        tool_calls: [{
          index: 0,
          id: "call_1",
          type: "function",
          function: { name: YFS_TEST_TOOL_NAME, arguments: "{\"topic\":\"phone\"}" },
        }],
      },
      finish_reason: null,
    }],
  });
  const acc = new Map();
  accumulateToolCalls(acc, inspected.toolCalls);
  assert.equal(acc.get(0)?.name, YFS_TEST_TOOL_NAME);
  assert.deepEqual(parseToolArguments(acc.get(0)?.arguments ?? ""), { topic: "phone" });
});

test("YFS test tool is merged into the LLM request even if ElevenLabs omitted it", () => {
  const merged = mergeChatTools([{
    type: "function",
    function: { name: "end_call", description: "End the call", parameters: { type: "object" } },
  }], YFS_VOICE_TOOLS);
  const names = (merged ?? []).map((tool) => tool.type === "function" ? tool.function.name : "");
  assert.ok(names.includes("end_call"));
  assert.ok(names.includes(YFS_TEST_TOOL_NAME));
  assert.equal(isYfsServerTool(YFS_TEST_TOOL_NAME), true);
  assert.equal(isYfsServerTool("end_call"), false);
});

test("Laravel tool client posts execute payload and never logs the token", async () => {
  const lines: string[] = [];
  const original = console.log;
  console.log = (...args: unknown[]) => {
    lines.push(args.map((value) => String(value)).join(" "));
  };

  const infra: InfraConfig = {
    host: "127.0.0.1",
    port: 0,
    openaiTimeoutMs: 1_000,
    speechEngineId: "",
    wsPath: "/ws",
    laravelInternalBaseUrl: "http://voice-orchestrator.test",
    voiceRuntimeInternalToken: "internal-secret-token",
    voiceLlmSharedSecret: "llm-secret",
    configTtlMs: 1_000,
    maxLlmBodyBytes: 1024,
    maxLlmMessages: 8,
  };

  let seenUrl = "";
  let seenAuth = "";
  let seenBody: { tool?: string; arguments?: Record<string, unknown> } = {};

  try {
    const result = await executeLaravelVoiceTool(
      infra,
      YFS_TEST_TOOL_NAME,
      { topic: "unit" },
      "req-1",
      new AbortController().signal,
      async (input, init) => {
        seenUrl = String(input);
        const headers = init?.headers as Record<string, string>;
        seenAuth = headers.Authorization;
        seenBody = JSON.parse(String(init?.body)) as typeof seenBody;
        return Response.json({
          ok: true,
          tool: YFS_TEST_TOOL_NAME,
          result: { source: "yfs_ai_test", status: "ok" },
        });
      },
    );
    assert.deepEqual(result, { source: "yfs_ai_test", status: "ok" });
  } finally {
    console.log = original;
  }

  assert.equal(seenUrl, "http://voice-orchestrator.test/api/internal/voice/tools/execute");
  assert.equal(seenAuth, "Bearer internal-secret-token");
  assert.equal(seenBody.tool, YFS_TEST_TOOL_NAME);
  const joined = lines.join("\n");
  assert.equal(joined.includes("internal-secret-token"), false);
  assert.equal(joined.includes("Authorization"), false);
  assert.match(joined, /voice\.tool\.requested/);
  assert.match(joined, /voice\.tool\.completed/);
});

test("Laravel tool client maps unknown tool without crashing", async () => {
  const infra: InfraConfig = {
    host: "127.0.0.1",
    port: 0,
    openaiTimeoutMs: 1_000,
    speechEngineId: "",
    wsPath: "/ws",
    laravelInternalBaseUrl: "http://voice-orchestrator.test",
    voiceRuntimeInternalToken: "internal-secret-token",
    voiceLlmSharedSecret: "llm-secret",
    configTtlMs: 1_000,
    maxLlmBodyBytes: 1024,
    maxLlmMessages: 8,
  };

  await assert.rejects(
    () => executeLaravelVoiceTool(
      infra,
      "missing_tool",
      {},
      "req-2",
      new AbortController().signal,
      async () => Response.json({
        ok: false,
        error: { type: "unknown_tool", message: "Unknown voice tool" },
      }, { status: 404 }),
    ),
    (error: unknown) => error instanceof VoiceToolClientError && error.type === "unknown_tool" && error.status === 404,
  );
});

test("tool result message uses the function name for Gemini functionResponse", () => {
  const message = toToolResultMessage(
    { id: "call_1", name: YFS_TEST_TOOL_NAME, arguments: "{}" },
    { source: "yfs_ai_test" },
  );
  assert.equal(message.role, "tool");
  assert.equal("name" in message ? message.name : "", YFS_TEST_TOOL_NAME);
  assert.equal("tool_call_id" in message ? message.tool_call_id : "", "call_1");
});
