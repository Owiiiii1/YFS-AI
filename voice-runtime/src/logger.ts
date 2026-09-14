type Level = "debug" | "info" | "warn" | "error";

const RANK: Record<Level, number> = {
  debug: 10,
  info: 20,
  warn: 30,
  error: 40,
};

const SECRET_KEY = /api[_-]?key|authorization|token|secret|password|credential|thought[_-]?signature/i;

function currentLevel(): Level {
  const raw = (process.env.VOICE_LOG_LEVEL ?? "info").toLowerCase();
  if (raw === "debug" || raw === "info" || raw === "warn" || raw === "error") {
    return raw;
  }
  return "info";
}

function redact(value: unknown): unknown {
  if (value == null) {
    return value;
  }
  if (typeof value === "string") {
    return value.length > 240 ? `${value.slice(0, 240)}…` : value;
  }
  if (value instanceof Error) {
    return { name: value.name, message: value.message };
  }
  if (Array.isArray(value)) {
    return value.slice(0, 8).map(redact);
  }
  if (typeof value === "object") {
    const out: Record<string, unknown> = {};
    for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
      out[key] = SECRET_KEY.test(key) ? "[redacted]" : redact(nested);
    }
    return out;
  }
  return value;
}

function write(level: Level, event: string, fields: Record<string, unknown> = {}): void {
  if (RANK[level] < RANK[currentLevel()]) {
    return;
  }
  const line = {
    ts: new Date().toISOString(),
    service: "yfs-voice-runtime",
    level,
    event,
    ...Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, redact(value)])),
  };
  const sink = level === "error" ? console.error : level === "warn" ? console.warn : console.log;
  sink(JSON.stringify(line));
}

export const log = {
  debug: (event: string, fields?: Record<string, unknown>) => write("debug", event, fields),
  info: (event: string, fields?: Record<string, unknown>) => write("info", event, fields),
  warn: (event: string, fields?: Record<string, unknown>) => write("warn", event, fields),
  error: (event: string, fields?: Record<string, unknown>) => write("error", event, fields),
};
