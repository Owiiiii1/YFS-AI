import { config as loadDotenv } from "dotenv";
import { resolve } from "node:path";
import { loadInfraConfig } from "./config/env.js";
import { LaravelConfigStore } from "./laravel/config-store.js";
import { log } from "./logger.js";
import { createHttpServer } from "./server/http.js";
import { SessionManager } from "./session/manager.js";
import { attachSpeechEngine } from "./speech/engine.js";

loadDotenv({ path: resolve(process.cwd(), ".env"), quiet: true });

const infra = loadInfraConfig();
const store = new LaravelConfigStore(infra);
const sessions = new SessionManager();
const server = createHttpServer(store, infra);

process.on("uncaughtException", (error) => {
  log.error("runtime.uncaught", { error });
});

process.on("unhandledRejection", (error) => {
  log.error("runtime.unhandled_rejection", { error });
});

await store.get({ refresh: true });

let attachment: Awaited<ReturnType<typeof attachSpeechEngine>> = null;
try {
  attachment = await attachSpeechEngine(server, infra, store, sessions);
} catch (error) {
  log.error("speech-engine.attach.failed", { error });
}

await new Promise<void>((resolveListen, reject) => {
  server.once("error", reject);
  server.listen(infra.port, infra.host, () => {
    server.off("error", reject);
    resolveListen();
  });
});

log.info("runtime.listening", {
  host: infra.host,
  port: infra.port,
  health: `http://${infra.host}:${infra.port}/health`,
  wsPath: infra.wsPath,
  speechEngineAttached: attachment !== null,
  ...store.snapshot(),
});

async function shutdown(signal: string): Promise<void> {
  log.info("runtime.shutdown", { signal });
  try {
    await attachment?.close();
  } catch (error) {
    log.error("speech-engine.close.failed", { error });
  }

  await new Promise<void>((resolveClose) => {
    server.close(() => resolveClose());
    setTimeout(resolveClose, 3_000).unref();
  });
  process.exit(0);
}

process.on("SIGTERM", () => {
  void shutdown("SIGTERM");
});
process.on("SIGINT", () => {
  void shutdown("SIGINT");
});
