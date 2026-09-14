import assert from "node:assert/strict";
import { after, test } from "node:test";
import type { InfraConfig } from "../src/config/env.js";
import type { LaravelVoiceConfig } from "../src/laravel/config-client.js";
import type { VoiceConfigSource, VoiceRuntimeSnapshot } from "../src/laravel/config-store.js";
import type { ChatStreamParams, LlmProvider } from "../src/llm/types.js";
import { createHttpServer } from "../src/server/http.js";
import { YFS_TEST_TOOL_NAME } from "../src/voice-tools/catalog.js";
import type { ExecuteVoiceTool } from "../src/voice-tools/laravel-client.js";
import type { FetchSessionTurn } from "../src/voice-tools/session-client.js";

const SECRET = "test-voice-llm-secret";
const OPENAI_KEY = "sk-test-openai-not-real";
const GEMINI_KEY = "AIza-test-gemini-not-real";

function infra(overrides: Partial<InfraConfig> = {}): InfraConfig {
  return {
    host: "127.0.0.1",
    port: 0,
    openaiTimeoutMs: 5_000,
    speechEngineId: "",
    wsPath: "/ws",
    laravelInternalBaseUrl: "http://127.0.0.1:9",
    voiceRuntimeInternalToken: "internal-token",
    voiceLlmSharedSecret: SECRET,
    configTtlMs: 60_000,
    maxLlmBodyBytes: 4_096,
    maxLlmMessages: 8,
    ...overrides,
  };
}

function providers(overrides: Partial<LaravelVoiceConfig["llm"]> = {}): LaravelVoiceConfig {
  return {
    llm: {
      provider: "openai",
      model: "gpt-test-runtime",
      apiKey: OPENAI_KEY,
      ...overrides,
    },
    elevenlabs: { apiKey: "el-test" },
  };
}

function snapshotFor(config: LaravelVoiceConfig | null, state: VoiceRuntimeSnapshot["laravel_config"] = "loaded"): VoiceRuntimeSnapshot {
  return {
    laravel_config: state,
    laravel_config_fetched_at: state === "missing" ? null : "2026-09-14T00:00:00.000Z",
    laravel_config_loaded_at: state === "loaded" ? "2026-09-14T00:00:00.000Z" : null,
    provider: config?.llm.provider ?? null,
    model: config?.llm.model ?? null,
    llm_key: config?.llm.apiKey && config.llm.model ? "configured" : "missing",
    elevenlabs_key: config?.elevenlabs.apiKey ? "configured" : "missing",
    speech_engine: "waiting",
  };
}

function storeFor(config: LaravelVoiceConfig | null, state: VoiceRuntimeSnapshot["laravel_config"] = "loaded"): VoiceConfigSource {
  return {
    async get() {
      return config;
    },
    snapshot(): VoiceRuntimeSnapshot {
      return snapshotFor(config, state);
    },
  };
}

type Started = {
  base: string;
  close: () => Promise<void>;
};

async function listen(server: ReturnType<typeof createHttpServer>): Promise<Started> {
  await new Promise<void>((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => {
      server.off("error", reject);
      resolve();
    });
  });
  const address = server.address();
  if (!address || typeof address === "string") {
    throw new Error("no listen address");
  }
  return {
    base: `http://127.0.0.1:${address.port}`,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}

const closers: Array<() => Promise<void>> = [];

after(async () => {
  await Promise.all(closers.map((close) => close()));
});

function mockProvider(
  stream: AsyncIterable<unknown> | ((signal: AbortSignal) => AsyncIterable<unknown>),
  providerName = "openai",
): LlmProvider {
  return {
    providerName,
    async streamChatCompletion(_params: ChatStreamParams, signal: AbortSignal) {
      if (typeof stream === "function") {
        return stream(signal);
      }
      return stream;
    },
  };
}

function defaultStream(): AsyncIterable<unknown> {
  return (async function* mock() {
    yield {
      id: "chatcmpl-test",
      object: "chat.completion.chunk",
      created: 1,
      model: "gpt-test-runtime",
      choices: [{ index: 0, delta: { content: "Hello" }, finish_reason: null }],
    };
    yield {
      id: "chatcmpl-test",
      object: "chat.completion.chunk",
      created: 1,
      model: "gpt-test-runtime",
      choices: [{ index: 0, delta: {}, finish_reason: "stop" }],
    };
  })();
}

async function start(options: {
  config?: LaravelVoiceConfig | null;
  state?: VoiceRuntimeSnapshot["laravel_config"];
  infra?: Partial<InfraConfig>;
  stream?: AsyncIterable<unknown> | ((signal: AbortSignal) => AsyncIterable<unknown>);
  createProvider?: (config: LaravelVoiceConfig["llm"], timeoutMs: number) => LlmProvider;
  executeVoiceTool?: ExecuteVoiceTool;
  fetchSessionTurn?: FetchSessionTurn;
  useRealProviderFactory?: boolean;
} = {}): Promise<Started> {
  const config = options.config === undefined ? providers() : options.config;
  const handlerOptions = options.useRealProviderFactory
    ? { executeVoiceTool: options.executeVoiceTool, fetchSessionTurn: options.fetchSessionTurn }
    : {
        createProvider: options.createProvider ?? ((llm) => mockProvider(
          options.stream ?? defaultStream(),
          llm.provider || "openai",
        )),
        executeVoiceTool: options.executeVoiceTool,
        fetchSessionTurn: options.fetchSessionTurn,
      };
  const server = createHttpServer(
    storeFor(config, options.state),
    infra(options.infra),
    handlerOptions,
  );
  const started = await listen(server);
  closers.push(started.close);
  return started;
}

function chatBody(overrides: Record<string, unknown> = {}): string {
  return JSON.stringify({
    model: "yfs-bot-runtime",
    stream: true,
    messages: [
      { role: "system", content: "Agent prompt from ElevenLabs." },
      { role: "user", content: "Hello" },
    ],
    ...overrides,
  });
}

async function post(
  base: string,
  init: {
    auth?: string | null;
    extraHeaders?: Record<string, string>;
    body?: string;
  } = {},
): Promise<Response> {
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    ...init.extraHeaders,
  };
  if (init.auth !== null) {
    headers.Authorization = init.auth ?? `Bearer ${SECRET}`;
  }
  return fetch(`${base}/v1/chat/completions`, {
    method: "POST",
    headers,
    body: init.body ?? chatBody(),
  });
}

test("health remains 200 and reports custom_llm ready", async () => {
  const { base } = await start();
  const response = await fetch(`${base}/health`);
  const body = await response.json() as Record<string, unknown>;
  assert.equal(response.status, 200);
  assert.equal(body.status, "ok");
  assert.equal(body.service, "yfs-voice-runtime");
  assert.equal(body.process, "running");
  assert.equal(body.custom_llm, "ready");
  assert.equal(body.provider, "openai");
  assert.equal(body.model, "gpt-test-runtime");
  assert.equal(body.llm_key, "configured");
  assert.equal(body.elevenlabs_key, "configured");
  assert.ok(!JSON.stringify(body).includes(OPENAI_KEY));
});

test("health reports gemini provider from latest config snapshot", async () => {
  const { base } = await start({
    config: providers({ provider: "gemini", model: "gemini-3.8-flash", apiKey: GEMINI_KEY }),
  });
  const response = await fetch(`${base}/health`);
  const body = await response.json() as Record<string, unknown>;
  assert.equal(body.provider, "gemini");
  assert.equal(body.model, "gemini-3.8-flash");
  assert.equal(body.laravel_config, "loaded");
  assert.equal(typeof body.laravel_config_fetched_at, "string");
  assert.ok(!JSON.stringify(body).includes(GEMINI_KEY));
});

test("no auth → 401", async () => {
  const { base } = await start();
  const response = await post(base, { auth: null });
  assert.equal(response.status, 401);
});

test("wrong auth → 401", async () => {
  const { base } = await start();
  const response = await post(base, { auth: "Bearer wrong-secret" });
  assert.equal(response.status, 401);
});

test("malformed JSON → 400", async () => {
  const { base } = await start();
  const response = await post(base, { body: "{not-json" });
  assert.equal(response.status, 400);
});

test("Laravel config unavailable → 503", async () => {
  const { base } = await start({ config: null, state: "error" });
  const response = await post(base);
  assert.equal(response.status, 503);
  const body = await response.json() as { error?: { type?: string } };
  assert.equal(body.error?.type, "config_unavailable");
});

test("unsupported provider → 400 and process stays up", async () => {
  const { base } = await start({
    config: providers({ provider: "anthropic", model: "claude-test" }),
    useRealProviderFactory: true,
  });
  const response = await post(base);
  assert.equal(response.status, 400);
  const body = await response.json() as { error?: { type?: string; provider?: string } };
  assert.equal(body.error?.type, "unsupported_provider");
  assert.equal(body.error?.provider, "anthropic");
  const health = await fetch(`${base}/health`);
  assert.equal(health.status, 200);
});

test("provider selection uses Laravel gemini config, not ElevenLabs model label", async () => {
  let seen: { provider?: string; model?: string } = {};
  const { base } = await start({
    config: providers({ provider: "gemini", model: "gemini-3.8-flash", apiKey: GEMINI_KEY }),
    createProvider: (llm) => {
      seen = { provider: llm.provider, model: llm.model };
      return mockProvider(defaultStream(), "gemini");
    },
  });
  const response = await post(base, { body: chatBody({ model: "yfs-bot-runtime" }) });
  assert.equal(response.status, 200);
  assert.equal(seen.provider, "gemini");
  assert.equal(seen.model, "gemini-3.8-flash");
});

test("provider selection uses Laravel openai config", async () => {
  let seen: { provider?: string; model?: string } = {};
  const { base } = await start({
    createProvider: (llm) => {
      seen = { provider: llm.provider, model: llm.model };
      return mockProvider(defaultStream(), "openai");
    },
  });
  const response = await post(base);
  assert.equal(response.status, 200);
  assert.equal(seen.provider, "openai");
  assert.equal(seen.model, "gpt-test-runtime");
});

test("stream=true → SSE with OpenAI-compatible deltas", async () => {
  const { base } = await start();
  const response = await post(base);
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type") ?? "", /text\/event-stream/);
  assert.equal(response.headers.get("x-accel-buffering"), "no");
  const text = await response.text();
  assert.match(text, /^data: /);
  assert.match(text, /"content":"Hello"/);
  assert.match(text, /data: \[DONE\]\s*$/);
});

test("non-stream request still returns SSE for ElevenLabs compatibility", async () => {
  const { base } = await start();
  const response = await post(base, { body: chatBody({ stream: false }) });
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type") ?? "", /text\/event-stream/);
  const text = await response.text();
  assert.match(text, /data: \[DONE\]/);
});

test("multiple messages/context are preserved and ElevenLabs model is ignored", async () => {
  let seen: { model?: string; roles?: string[]; contents?: string[] } = {};

  const server = createHttpServer(
    storeFor(providers()),
    infra(),
    {
      createProvider: () => ({
        providerName: "openai",
        async streamChatCompletion(params) {
          seen = {
            model: params.model,
            roles: params.messages.map((message) => message.role),
            contents: params.messages.map((message) => typeof message.content === "string" ? message.content : ""),
          };
          return (async function* () {
            yield {
              id: "chatcmpl-test",
              object: "chat.completion.chunk",
              created: 1,
              model: params.model,
              choices: [{ index: 0, delta: { content: "ok" }, finish_reason: "stop" }],
            };
          })();
        },
      }),
    },
  );
  const started = await listen(server);
  closers.push(started.close);

  const response = await post(started.base, {
    body: chatBody({
      model: "should-not-win",
      messages: [
        { role: "system", content: "Agent prompt from ElevenLabs." },
        { role: "user", content: "First" },
        { role: "assistant", content: "Ack" },
        { role: "user", content: "Second" },
      ],
    }),
  });
  assert.equal(response.status, 200);
  assert.equal(seen.model, "gpt-test-runtime");
  assert.deepEqual(seen.roles, ["system", "system", "user", "assistant", "user"]);
  assert.ok(seen.contents?.includes("Agent prompt from ElevenLabs."));
  assert.ok(seen.contents?.includes("Second"));
});

test("disconnect aborts upstream", async () => {
  let aborted = false;
  const { base } = await start({
    stream: (signal) => {
      signal.addEventListener("abort", () => {
        aborted = true;
      });
      return (async function* () {
        yield {
          id: "chatcmpl-test",
          object: "chat.completion.chunk",
          created: 1,
          model: "gpt-test-runtime",
          choices: [{ index: 0, delta: { content: "Hi" }, finish_reason: null }],
        };
        await new Promise<void>((resolve, reject) => {
          const timer = setTimeout(() => reject(new Error("upstream was not aborted")), 2_000);
          signal.addEventListener("abort", () => {
            clearTimeout(timer);
            resolve();
          });
        });
      })();
    },
  });

  const controller = new AbortController();
  const response = await fetch(`${base}/v1/chat/completions`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${SECRET}`,
      "Content-Type": "application/json",
    },
    body: chatBody(),
    signal: controller.signal,
  });
  assert.equal(response.status, 200);
  const reader = response.body?.getReader();
  assert.ok(reader);
  await reader.read();
  controller.abort();
  await new Promise((resolve) => setTimeout(resolve, 150));
  assert.equal(aborted, true);
});

test("no secrets in logs", async () => {
  const lines: string[] = [];
  const original = console.log;
  console.log = (...args: unknown[]) => {
    lines.push(args.map((value) => String(value)).join(" "));
  };
  try {
    const { base } = await start({
      config: providers({ provider: "gemini", model: "gemini-3.8-flash", apiKey: GEMINI_KEY }),
    });
    await post(base);
    await new Promise((resolve) => setTimeout(resolve, 50));
  } finally {
    console.log = original;
  }
  const joined = lines.join("\n");
  assert.equal(joined.includes(SECRET), false);
  assert.equal(joined.includes(OPENAI_KEY), false);
  assert.equal(joined.includes(GEMINI_KEY), false);
  assert.equal(joined.includes("Authorization"), false);
  assert.match(joined, /custom-llm\.stream\.started|custom-llm\.stream\.completed/);
});

function geminiFunctionCallStream(): AsyncIterable<unknown> {
  return (async function* mock() {
    yield {
      id: "chatcmpl-tool",
      object: "chat.completion.chunk",
      created: 1,
      model: "gemini-3.8-flash",
      choices: [{
        index: 0,
        delta: {
          tool_calls: [{
            index: 0,
            id: "call_yfs_test_1",
            type: "function",
            function: { name: YFS_TEST_TOOL_NAME, arguments: "{}" },
          }],
        },
        finish_reason: null,
      }],
    };
    yield {
      id: "chatcmpl-tool",
      object: "chat.completion.chunk",
      created: 1,
      model: "gemini-3.8-flash",
      choices: [{ index: 0, delta: {}, finish_reason: "stop" }],
    };
  })();
}

function finalTextStream(text: string): AsyncIterable<unknown> {
  return (async function* mock() {
    yield {
      id: "chatcmpl-final",
      object: "chat.completion.chunk",
      created: 2,
      model: "gemini-3.8-flash",
      choices: [{ index: 0, delta: { role: "assistant", content: text }, finish_reason: null }],
    };
    yield {
      id: "chatcmpl-final",
      object: "chat.completion.chunk",
      created: 2,
      model: "gemini-3.8-flash",
      choices: [{ index: 0, delta: {}, finish_reason: "stop" }],
    };
  })();
}

test("Gemini functionCall is executed by Laravel and final text is streamed without exposing YFS tool_calls", async () => {
  let rounds = 0;
  let secondMessages: Array<{ role?: string; name?: string; tool_call_id?: string; content?: unknown }> = [];
  let firstTools: string[] = [];
  const executed: Array<{ name: string; args: Record<string, unknown> }> = [];

  const { base } = await start({
    config: providers({ provider: "gemini", model: "gemini-3.8-flash", apiKey: GEMINI_KEY }),
    executeVoiceTool: async (name, args) => {
      executed.push({ name, args });
      return {
        source: "yfs_ai_test",
        status: "ok",
        message: "Voice tool calling is working",
        event_name: "YFS Test Event",
        availability: "test-only",
      };
    },
    createProvider: () => ({
      providerName: "gemini",
      async streamChatCompletion(params) {
        rounds += 1;
        if (rounds === 1) {
          firstTools = (params.tools ?? []).map((tool) => tool.type === "function" ? tool.function.name : "");
          return geminiFunctionCallStream();
        }
        secondMessages = params.messages.map((message) => ({
          role: message.role,
          name: "name" in message && typeof message.name === "string" ? message.name : undefined,
          tool_call_id: "tool_call_id" in message && typeof message.tool_call_id === "string" ? message.tool_call_id : undefined,
          content: "content" in message ? message.content : undefined,
        }));
        return finalTextStream("The YFS test event is test-only. Voice tool calling is working.");
      },
    }),
  });

  const response = await post(base, {
    body: chatBody({
      messages: [
        { role: "system", content: "Agent prompt from ElevenLabs." },
        { role: "user", content: "What is the YFS test event?" },
      ],
    }),
  });
  const text = await response.text();
  assert.equal(response.status, 200);
  assert.equal(rounds, 2);
  assert.equal(executed.length, 1);
  assert.equal(executed[0]?.name, YFS_TEST_TOOL_NAME);
  assert.ok(firstTools.includes(YFS_TEST_TOOL_NAME));
  assert.ok(secondMessages.some((message) => message.role === "tool" && message.name === YFS_TEST_TOOL_NAME));
  assert.ok(secondMessages.some((message) => {
    return message.role === "tool" && typeof message.content === "string" && message.content.includes("YFS Test Event");
  }));
  assert.match(text, /Voice tool calling is working/);
  assert.match(text, /data: \[DONE\]/);
  assert.equal(text.includes(`"name":"${YFS_TEST_TOOL_NAME}"`), false);
  assert.equal(text.includes(GEMINI_KEY), false);
});

test("tool error is fed back to Gemini and does not crash the process", async () => {
  let rounds = 0;
  let sawErrorResult = false;
  const { base } = await start({
    executeVoiceTool: async () => {
      throw new Error("secret-orchestrator-token-must-not-leak");
    },
    createProvider: () => ({
      providerName: "gemini",
      async streamChatCompletion(params) {
        rounds += 1;
        if (rounds === 1) {
          return geminiFunctionCallStream();
        }
        const tool = params.messages.find((message) => message.role === "tool");
        const content = tool && "content" in tool ? String(tool.content) : "";
        sawErrorResult = content.includes("tool_failed") && !content.includes("secret-orchestrator-token-must-not-leak");
        return finalTextStream("I could not look that up right now.");
      },
    }),
  });

  const response = await post(base);
  const text = await response.text();
  assert.equal(response.status, 200);
  assert.equal(rounds, 2);
  assert.equal(sawErrorResult, true);
  assert.match(text, /I could not look that up right now/);
  assert.equal(text.includes("secret-orchestrator-token-must-not-leak"), false);
  const health = await fetch(`${base}/health`);
  assert.equal(health.status, 200);
});

test("disconnect aborts during Laravel tool execution", async () => {
  let aborted = false;
  const { base } = await start({
    executeVoiceTool: async (_name, _args, _requestId, signal) => {
      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error("tool was not aborted")), 2_000);
        signal.addEventListener("abort", () => {
          aborted = true;
          clearTimeout(timer);
          const error = new Error("aborted");
          error.name = "AbortError";
          reject(error);
        });
      });
      return {};
    },
    createProvider: () => ({
      providerName: "gemini",
      async streamChatCompletion() {
        return geminiFunctionCallStream();
      },
    }),
  });

  const controller = new AbortController();
  const response = await fetch(`${base}/v1/chat/completions`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${SECRET}`,
      "Content-Type": "application/json",
    },
    body: chatBody(),
    signal: controller.signal,
  });
  assert.equal(response.status, 200);
  await new Promise((resolve) => setTimeout(resolve, 40));
  controller.abort();
  await new Promise((resolve) => setTimeout(resolve, 150));
  assert.equal(aborted, true);
});

test("ElevenLabs native tools are still forwarded and not executed by Laravel", async () => {
  let executed = 0;
  const { base } = await start({
    executeVoiceTool: async () => {
      executed += 1;
      return {};
    },
    stream: (async function* () {
      yield {
        id: "chatcmpl-end",
        object: "chat.completion.chunk",
        created: 1,
        model: "gpt-test-runtime",
        choices: [{
          index: 0,
          delta: {
            tool_calls: [{
              index: 0,
              id: "call_end_1",
              type: "function",
              function: { name: "end_call", arguments: "{}" },
            }],
          },
          finish_reason: null,
        }],
      };
      yield {
        id: "chatcmpl-end",
        object: "chat.completion.chunk",
        created: 1,
        model: "gpt-test-runtime",
        choices: [{ index: 0, delta: {}, finish_reason: "tool_calls" }],
      };
    })(),
  });

  const response = await post(base);
  const text = await response.text();
  assert.equal(response.status, 200);
  assert.equal(executed, 0);
  assert.match(text, /"name":"end_call"/);
  assert.match(text, /data: \[DONE\]/);
});

test("fast path injects session prompt and does not call YFS tools", async () => {
  let seenTools: string[] = [];
  let seenPrompt = "";
  let executed = 0;
  const { base } = await start({
    fetchSessionTurn: async () => ({
      sessionId: "sess-fast",
      activeTopic: "test_event",
      responsePath: "fast",
      systemPrompt: "SESSION yfs_context: event_name=YFS Test Event; message=Voice session context is working.",
      allowedTools: [],
      sectionNames: ["global", "session", "topic:test_event"],
      promptChars: 80,
      detectedLanguage: "en",
      fillers: {},
    }),
    executeVoiceTool: async () => {
      executed += 1;
      return {};
    },
    createProvider: () => ({
      providerName: "gemini",
      async streamChatCompletion(params) {
        seenTools = (params.tools ?? []).map((tool) => tool.type === "function" ? tool.function.name : "");
        seenPrompt = typeof params.messages[0]?.content === "string" ? params.messages[0].content : "";
        return defaultStream();
      },
    }),
  });

  const response = await post(base, {
    body: chatBody({
      messages: [
        { role: "system", content: "Agent prompt from ElevenLabs." },
        { role: "user", content: "What is the YFS test event?" },
      ],
    }),
  });
  const text = await response.text();
  assert.equal(response.status, 200);
  assert.equal(executed, 0);
  assert.equal(seenTools.includes(YFS_TEST_TOOL_NAME), false);
  assert.match(seenPrompt, /YFS Test Event/);
  assert.match(text, /Hello/);
  assert.match(text, /data: \[DONE\]/);
  assert.equal(text.includes("... "), false);
});

test("tool path writes ElevenLabs buffer-word filler then continues the same SSE stream", async () => {
  let rounds = 0;
  const { base } = await start({
    fetchSessionTurn: async () => ({
      sessionId: "sess-tool",
      activeTopic: "test_status",
      responsePath: "tool",
      systemPrompt: "Call get_current_yfs_test_context for latest test status.",
      allowedTools: [YFS_TEST_TOOL_NAME],
      sectionNames: ["global", "session", "topic:test_status"],
      promptChars: 40,
      detectedLanguage: "en",
      fillers: {
        [YFS_TEST_TOOL_NAME]: {
          enabled: true,
          language: "en",
          category: "lookup",
          phraseId: "en_lookup_1",
          text: "One moment, let me check... ",
        },
      },
    }),
    executeVoiceTool: async () => ({
      source: "yfs_ai_test",
      status: "ok",
      message: "Voice tool calling is working",
    }),
    createProvider: () => ({
      providerName: "gemini",
      async streamChatCompletion() {
        rounds += 1;
        if (rounds === 1) {
          return geminiFunctionCallStream();
        }
        return finalTextStream("The latest YFS test status is test-only.");
      },
    }),
  });

  const response = await post(base, {
    body: chatBody({
      messages: [
        { role: "system", content: "Agent prompt from ElevenLabs." },
        { role: "user", content: "Check the latest YFS test status." },
      ],
    }),
  });
  const text = await response.text();
  assert.equal(response.status, 200);
  assert.equal(rounds, 2);
  assert.match(text, /One moment, let me check\.\.\. /);
  assert.match(text, /The latest YFS test status is test-only/);
  assert.match(text, /data: \[DONE\]/);
  const doneIndex = text.lastIndexOf("data: [DONE]");
  const fillerIndex = text.indexOf("One moment, let me check");
  const finalIndex = text.indexOf("The latest YFS test status");
  assert.ok(fillerIndex >= 0 && fillerIndex < finalIndex);
  assert.ok(finalIndex < doneIndex);
  assert.equal((text.match(/data: \[DONE\]/g) ?? []).length, 1);
});
