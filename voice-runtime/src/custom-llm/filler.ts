import type { ServerResponse } from "node:http";
import { createChatCompletionChunk, createChatCompletionId } from "../llm/openai-chunks.js";
import { log } from "../logger.js";
import { writeSseData } from "./sse.js";
import type { VoiceFillerHint } from "../voice-tools/session-client.js";

export function normalizeFillerText(text: string): string {
  const trimmed = text.trimEnd();
  if (trimmed.endsWith("...")) {
    return `${trimmed} `;
  }
  return `${trimmed}... `;
}

export function selectFiller(
  fillers: Record<string, VoiceFillerHint> | undefined,
  toolName: string,
): VoiceFillerHint | null {
  const hint = fillers?.[toolName];
  if (!hint || hint.enabled === false || !hint.text.trim()) {
    return null;
  }
  return {
    ...hint,
    text: normalizeFillerText(hint.text),
  };
}

export function writeFillerSse(
  res: ServerResponse,
  model: string,
  filler: VoiceFillerHint,
  requestId: string,
): boolean {
  log.info("voice.filler.selected", {
    requestId,
    language: filler.language,
    category: filler.category,
    phrase_id: filler.phraseId,
  });
  return writeSseData(res, createChatCompletionChunk(
    createChatCompletionId(),
    model,
    { role: "assistant", content: filler.text },
  ));
}
