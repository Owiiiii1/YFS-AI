import { config as loadDotenv } from "dotenv";
import { resolve } from "node:path";
import type { ServerResponse } from "node:http";
import { loadInfraConfig } from "./config/env.js";
import { SYSTEM_PROMPT } from "./config/system-prompt.js";
import { streamWithServerTools } from "./custom-llm/tool-loop.js";
import { fetchLaravelVoiceConfig } from "./laravel/config-client.js";
import { buildGeminiGenerateRequest, geminiModelPath } from "./llm/gemini-convert.js";
import { geminiHttpErrorLogFields, summarizeGeminiErrorBody } from "./llm/gemini-error.js";
import { GeminiLlmProvider } from "./llm/gemini-provider.js";
import { YFS_TEST_TOOL_NAME, YFS_VOICE_TOOLS } from "./voice-tools/catalog.js";
import { createLaravelExecuteVoiceTool } from "./voice-tools/laravel-client.js";

loadDotenv({ path: resolve(process.cwd(), ".env"), quiet: true });

const infra = loadInfraConfig();
const laravel = await fetchLaravelVoiceConfig(infra);
if (laravel.llm.provider.trim().toLowerCase() !== "gemini" || !laravel.llm.apiKey || !laravel.llm.model) {
  throw new Error("Laravel bot_runtime is not gemini");
}

const model = geminiModelPath(laravel.llm.model);
const request = buildGeminiGenerateRequest({
  messages: [
    { role: "system", content: SYSTEM_PROMPT },
    { role: "user", content: "What is the YFS test event?" },
  ],
  tools: YFS_VOICE_TOOLS,
  toolChoice: "auto",
});

const generateUrl = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent`;
const generateResponse = await fetch(generateUrl, {
  method: "POST",
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
    "x-goog-api-key": laravel.llm.apiKey,
  },
  body: JSON.stringify(request),
  signal: AbortSignal.timeout(20_000),
});

const generateRaw = await generateResponse.text();
if (!generateResponse.ok) {
  const summary = summarizeGeminiErrorBody(generateResponse.status, model, generateRaw);
  console.log(JSON.stringify({
    event: "gemini.tools.probe.error",
    ...geminiHttpErrorLogFields(summary),
  }));
  process.exit(2);
}

let functionName = "";
try {
  const parsed = JSON.parse(generateRaw) as {
    candidates?: Array<{ content?: { parts?: Array<{ functionCall?: { name?: string } }> } }>;
  };
  functionName = parsed.candidates?.[0]?.content?.parts?.find((part) => part.functionCall?.name)?.functionCall?.name ?? "";
} catch {
  functionName = "";
}

if (functionName !== YFS_TEST_TOOL_NAME) {
  console.log(JSON.stringify({
    event: "gemini.tools.probe.unexpected",
    status: generateResponse.status,
    model,
    functionName: functionName || "none",
  }));
  process.exit(3);
}

console.log(JSON.stringify({
  event: "gemini.tools.probe.ok",
  status: generateResponse.status,
  model,
  functionName,
}));

const chunks: string[] = [];
const res = {
  writableEnded: false,
  writable: true,
  write(chunk: string) {
    chunks.push(chunk);
    return true;
  },
} as unknown as ServerResponse;

const llm = new GeminiLlmProvider(laravel.llm.apiKey, infra.openaiTimeoutMs);
const loopLogs: string[] = [];
const originalLog = console.log;
console.log = (...args: unknown[]) => {
  const line = args.map((value) => String(value)).join(" ");
  loopLogs.push(line);
  originalLog(line);
};

let loop;
try {
  loop = await streamWithServerTools({
    llm,
    executeVoiceTool: createLaravelExecuteVoiceTool(infra),
    requestId: "smoke-gemini-tools",
    res,
    signal: new AbortController().signal,
    model: laravel.llm.model,
    messages: [
      { role: "system", content: SYSTEM_PROMPT },
      { role: "user", content: "What is the YFS test event?" },
    ],
    tools: YFS_VOICE_TOOLS,
    toolChoice: "auto",
  });
} finally {
  console.log = originalLog;
}

const sse = chunks.join("");
const requested = loopLogs.some((line) => line.includes("voice.tool.requested") && line.includes(YFS_TEST_TOOL_NAME));
const completed = loopLogs.some((line) => line.includes("voice.tool.completed") && line.includes(YFS_TEST_TOOL_NAME));
const leakedKey = sse.includes(laravel.llm.apiKey) || loopLogs.join("\n").includes(laravel.llm.apiKey);

console.log(JSON.stringify({
  event: "gemini.tools.loop.ok",
  rounds: loop?.rounds ?? 0,
  status: loop?.status ?? "none",
  requested,
  completed,
  hasFinalText: /"content":"/.test(sse),
  leakedKey,
}));

if (!loop || loop.rounds < 2 || !requested || !completed || leakedKey) {
  process.exit(4);
}
