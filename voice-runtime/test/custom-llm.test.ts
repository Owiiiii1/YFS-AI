import assert from "node:assert/strict";
import { after, test } from "node:test";
import type { InfraConfig } from "../src/config/env.js";
import type { LaravelVoiceConfig } from "../src/laravel/config-client.js";
import type { VoiceConfigSource, VoiceRuntimeSnapshot } from "../src/laravel/config-store.js";
import type { ChatStreamParams, LlmProvider } from "../src/llm/types.js";
import { createHttpServer } from "../src/server/http.js";

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
  useRealProviderFactory?: boolean;
} = {}): Promise<Started> {
  const config = options.config === undefined ? providers() : options.config;
  const server = createHttpServer(
    storeFor(config, options.state),
    infra(options.infra),
    options.useRealProviderFactory
      ? {}
      : {
          createProvider: options.createProvider ?? ((llm) => mockProvider(
            options.stream ?? defaultStream(),
            llm.provider || "openai",
          )),
        },
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
