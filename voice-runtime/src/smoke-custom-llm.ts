import { config as loadDotenv } from "dotenv";
import { resolve } from "node:path";
import { loadInfraConfig } from "./config/env.js";

loadDotenv({ path: resolve(process.cwd(), ".env"), quiet: true });

const config = loadInfraConfig();
if (!config.voiceLlmSharedSecret) {
  throw new Error("VOICE_LLM_SHARED_SECRET is empty");
}

const startedAt = Date.now();
const firstByteAt = { current: 0 };
const controller = new AbortController();

const response = await fetch(`http://${config.host}:${config.port}/v1/chat/completions`, {
  method: "POST",
  headers: {
    Authorization: `Bearer ${config.voiceLlmSharedSecret}`,
    "Content-Type": "application/json",
    Accept: "text/event-stream",
  },
  body: JSON.stringify({
    model: "yfs-bot-runtime",
    stream: true,
    messages: [
      { role: "system", content: "You are a short connectivity probe. Reply with one word." },
      { role: "user", content: "ping" },
    ],
  }),
  signal: AbortSignal.timeout(config.openaiTimeoutMs),
});

if (response.status !== 200) {
  throw new Error(`custom llm status ${response.status}`);
}

const contentType = response.headers.get("content-type") ?? "";
if (!contentType.includes("text/event-stream")) {
  throw new Error(`unexpected content-type: ${contentType}`);
}

const reader = response.body?.getReader();
if (!reader) {
  throw new Error("missing response body");
}

const decoder = new TextDecoder();
let buffer = "";
let chunkCount = 0;
let sawDone = false;

while (true) {
  const { done, value } = await reader.read();
  if (done) {
    break;
  }
  if (!firstByteAt.current) {
    firstByteAt.current = Date.now();
  }
  buffer += decoder.decode(value, { stream: true });
  chunkCount += 1;
  if (buffer.includes("data: [DONE]")) {
    sawDone = true;
    controller.abort();
    break;
  }
}

const totalMs = Date.now() - startedAt;
const firstMs = firstByteAt.current ? firstByteAt.current - startedAt : null;

if (!sawDone) {
  throw new Error("stream ended without [DONE]");
}

console.log(JSON.stringify({
  event: "custom_llm.smoke.ok",
  status: response.status,
  contentType,
  firstByteMs: firstMs,
  totalMs,
  readChunks: chunkCount,
  earlyDelta: firstMs !== null && firstMs < totalMs,
}));
