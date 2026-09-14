const KEEP_KEYS = new Set([
  "type",
  "format",
  "description",
  "nullable",
  "enum",
  "properties",
  "required",
  "items",
  "minItems",
  "maxItems",
  "minLength",
  "maxLength",
  "minimum",
  "maximum",
  "pattern",
]);

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === "object" && !Array.isArray(value);
}

function sanitizeType(raw: unknown, out: Record<string, unknown>): void {
  if (typeof raw === "string" && raw) {
    out.type = raw;
    return;
  }
  if (!Array.isArray(raw)) {
    return;
  }
  const types = raw.filter((item): item is string => typeof item === "string" && item.length > 0);
  if (types.includes("null")) {
    out.nullable = true;
  }
  const concrete = types.filter((item) => item !== "null");
  if (concrete.length > 0) {
    out.type = concrete[0];
  }
}

/**
 * OpenAI / JSON Schema → Gemini FunctionDeclaration.parameters.
 * Gemini Schema is a protobuf JSON object and rejects unknown fields
 * such as additionalProperties.
 */
export function openaiJsonSchemaToGemini(schema: unknown): Record<string, unknown> | undefined {
  if (!isPlainObject(schema)) {
    return undefined;
  }

  const out: Record<string, unknown> = {};
  sanitizeType(schema.type, out);

  if (typeof schema.description === "string" && schema.description) {
    out.description = schema.description;
  }
  if (typeof schema.format === "string" && schema.format) {
    out.format = schema.format;
  }
  if (typeof schema.nullable === "boolean") {
    out.nullable = schema.nullable;
  }
  if (typeof schema.pattern === "string" && schema.pattern) {
    out.pattern = schema.pattern;
  }

  if (Array.isArray(schema.enum)) {
    const values = schema.enum.filter((value) => (
      typeof value === "string" || typeof value === "number" || typeof value === "boolean"
    ));
    if (values.length > 0) {
      out.enum = values;
    }
  }

  if (isPlainObject(schema.properties)) {
    const properties: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(schema.properties)) {
      const child = openaiJsonSchemaToGemini(value);
      if (child) {
        properties[key] = child;
      }
    }
    out.properties = properties;
  }

  if (schema.items !== undefined) {
    const items = openaiJsonSchemaToGemini(schema.items);
    if (items) {
      out.items = items;
    }
  }

  if (Array.isArray(schema.required)) {
    const required = schema.required.filter((name): name is string => typeof name === "string" && name.length > 0);
    if (required.length > 0) {
      out.required = required;
    }
  }

  for (const key of ["minItems", "maxItems", "minLength", "maxLength", "minimum", "maximum"] as const) {
    if (typeof schema[key] === "number" && Number.isFinite(schema[key])) {
      out[key] = schema[key];
    }
  }

  if (Object.keys(out).length === 0) {
    return { type: "object" };
  }

  for (const key of Object.keys(out)) {
    if (!KEEP_KEYS.has(key)) {
      delete out[key];
    }
  }

  return out;
}

export function geminiSchemaContainsKey(schema: unknown, key: string): boolean {
  if (Array.isArray(schema)) {
    return schema.some((item) => geminiSchemaContainsKey(item, key));
  }
  if (!isPlainObject(schema)) {
    return false;
  }
  if (Object.prototype.hasOwnProperty.call(schema, key)) {
    return true;
  }
  return Object.values(schema).some((value) => geminiSchemaContainsKey(value, key));
}
