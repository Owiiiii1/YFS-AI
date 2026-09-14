import { config as loadDotenv } from "dotenv";
import { resolve } from "node:path";
import { WebSocket } from "ws";
import { loadInfraConfig } from "./config/env.js";

loadDotenv({ path: resolve(process.cwd(), ".env"), quiet: true });

const config = loadInfraConfig();
const base = `http://${config.host}:${config.port}`;

const health = await fetch(`${base}/health`);
const healthBody = await health.json() as {
  status?: string;
  service?: string;
  laravel_config?: string;
  openai?: string;
  elevenlabs?: string;
  custom_llm?: string;
  speech_engine?: string;
};

if (health.status !== 200 || healthBody.status !== "ok" || healthBody.service !== "yfs-voice-runtime") {
  throw new Error(`health failed: ${health.status}`);
}

console.log(JSON.stringify({
  event: "health.ok",
  status: health.status,
  laravel_config: healthBody.laravel_config ?? null,
  openai: healthBody.openai ?? null,
  elevenlabs: healthBody.elevenlabs ?? null,
  custom_llm: healthBody.custom_llm ?? null,
  speech_engine: healthBody.speech_engine ?? null,
}));

const wsProbe = await fetch(`http://${config.host}:${config.port}${config.wsPath}`);
console.log(JSON.stringify({
  event: "ws.http_probe",
  path: config.wsPath,
  status: wsProbe.status,
}));

const wsUrl = `ws://${config.host}:${config.port}${config.wsPath}`;
const result = await new Promise<{ event: string; code?: number }>((resolvePromise) => {
  const socket = new WebSocket(wsUrl);
  const timer = setTimeout(() => {
    socket.close();
    resolvePromise({ event: "ws.timeout" });
  }, 3_000);

  socket.addEventListener("open", () => {
    clearTimeout(timer);
    socket.close();
    resolvePromise({ event: "ws.open" });
  });
  socket.addEventListener("error", () => {
    clearTimeout(timer);
    resolvePromise({ event: "ws.error" });
  });
  socket.addEventListener("close", (event) => {
    clearTimeout(timer);
    resolvePromise({ event: "ws.close", code: event.code });
  });
});

console.log(JSON.stringify({
  event: "ws.smoke",
  url: wsUrl,
  result: result.event,
  code: result.code ?? null,
}));
