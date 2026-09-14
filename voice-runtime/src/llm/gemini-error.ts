const MAX_MESSAGE = 800;
const MAX_BODY = 400;

export type GeminiHttpErrorSummary = {
  status: number;
  model: string;
  code?: number | string;
  errorStatus?: string;
  message?: string;
  bodyTruncated?: string;
};

function redactSecrets(value: string): string {
  return value
    .replace(/AIza[0-9A-Za-z_-]+/g, "[redacted]")
    .replace(/Bearer\s+\S+/gi, "Bearer [redacted]");
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return null;
  }
  return value as Record<string, unknown>;
}

export function summarizeGeminiErrorBody(status: number, model: string, raw: string): GeminiHttpErrorSummary {
  const summary: GeminiHttpErrorSummary = { status, model };
  const trimmed = raw.trim();
  if (!trimmed) {
    return summary;
  }

  try {
    const parsed = JSON.parse(trimmed) as unknown;
    const root = asRecord(parsed);
    const error = asRecord(root?.error);
    if (error) {
      if (typeof error.code === "number" || typeof error.code === "string") {
        summary.code = error.code;
      }
      if (typeof error.status === "string") {
        summary.errorStatus = error.status;
      }
      if (typeof error.message === "string" && error.message) {
        summary.message = redactSecrets(error.message).slice(0, MAX_MESSAGE);
      }
      return summary;
    }
  } catch {
    // not JSON — fall through to truncated body
  }

  summary.bodyTruncated = redactSecrets(trimmed).slice(0, MAX_BODY);
  return summary;
}

export function geminiHttpErrorLogFields(summary: GeminiHttpErrorSummary): Record<string, unknown> {
  const fields: Record<string, unknown> = {
    status: summary.status,
    model: summary.model,
  };
  if (summary.code !== undefined) {
    fields.code = summary.code;
  }
  if (summary.errorStatus) {
    fields.errorStatus = summary.errorStatus;
  }
  if (summary.message) {
    fields.message = summary.message;
  }
  if (summary.bodyTruncated) {
    fields.bodyTruncated = summary.bodyTruncated;
  }
  return fields;
}
