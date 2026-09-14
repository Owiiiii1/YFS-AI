import assert from "node:assert/strict";
import { test } from "node:test";
import { buildGeminiGenerateRequest } from "../src/llm/gemini-convert.js";
import { geminiHttpErrorLogFields, summarizeGeminiErrorBody } from "../src/llm/gemini-error.js";
import { geminiSchemaContainsKey, openaiJsonSchemaToGemini } from "../src/llm/gemini-schema.js";
import { YFS_VOICE_TOOLS } from "../src/voice-tools/catalog.js";

test("Gemini schema conversion strips unsupported OpenAI JSON Schema keywords", () => {
  const converted = openaiJsonSchemaToGemini({
    $schema: "https://json-schema.org/draft/2020-12/schema",
    type: "object",
    additionalProperties: false,
    default: {},
    oneOf: [{ type: "object" }],
    anyOf: [{ type: "object" }],
    allOf: [{ type: "object" }],
    const: "blocked",
    $ref: "#/$defs/topic",
    $defs: { topic: { type: "string" } },
    definitions: { topic: { type: "string" } },
    properties: {
      topic: {
        type: "string",
        description: "Optional topic hint. Does not change the test-only result.",
        default: "ignore-me",
        additionalProperties: false,
      },
    },
    required: [],
  });

  assert.deepEqual(converted, {
    type: "object",
    properties: {
      topic: {
        type: "string",
        description: "Optional topic hint. Does not change the test-only result.",
      },
    },
  });

  for (const key of [
    "additionalProperties",
    "oneOf",
    "anyOf",
    "allOf",
    "const",
    "$schema",
    "$ref",
    "definitions",
    "$defs",
    "default",
  ]) {
    assert.equal(geminiSchemaContainsKey(converted, key), false, key);
  }
});

test("YFS test tool Gemini payload is a minimal object schema", () => {
  const request = buildGeminiGenerateRequest({
    messages: [{ role: "user", content: "What is the YFS test event?" }],
    tools: YFS_VOICE_TOOLS,
  });
  const parameters = request.tools?.[0]?.functionDeclarations[0]?.parameters;
  assert.deepEqual(parameters, {
    type: "object",
    properties: {
      topic: {
        type: "string",
        description: "Optional topic hint. Does not change the test-only result.",
      },
    },
  });
  assert.equal(geminiSchemaContainsKey(parameters, "additionalProperties"), false);
  assert.equal(geminiSchemaContainsKey(parameters, "required"), false);
});

test("Gemini HTTP error summary keeps code/status/message and redacts keys", () => {
  const summary = summarizeGeminiErrorBody(
    400,
    "gemini-3.8-flash",
    JSON.stringify({
      error: {
        code: 400,
        status: "INVALID_ARGUMENT",
        message: "Unknown name \"additionalProperties\" key=AIza-secret-value",
      },
    }),
  );
  assert.equal(summary.code, 400);
  assert.equal(summary.errorStatus, "INVALID_ARGUMENT");
  assert.match(summary.message ?? "", /additionalProperties/);
  assert.equal((summary.message ?? "").includes("AIza-secret-value"), false);
  const fields = geminiHttpErrorLogFields(summary);
  assert.equal(fields.status, 400);
  assert.equal(fields.model, "gemini-3.8-flash");
  assert.equal(JSON.stringify(fields).includes("AIza-secret-value"), false);
});

test("non-JSON Gemini error body is truncated without secrets", () => {
  const summary = summarizeGeminiErrorBody(400, "gemini-3.8-flash", `plain ${"x".repeat(500)} Bearer abc.def AIzaSyCtestkey`);
  assert.equal(summary.code, undefined);
  assert.ok((summary.bodyTruncated?.length ?? 0) <= 400);
  assert.equal(summary.bodyTruncated?.includes("AIzaSyCtestkey"), false);
  assert.equal(summary.bodyTruncated?.includes("Bearer abc.def"), false);
});
