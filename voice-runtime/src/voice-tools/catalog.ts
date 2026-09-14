import type { ChatCompletionFunctionTool, ChatCompletionTool } from "openai/resources/chat/completions";

export const YFS_TEST_TOOL_NAME = "get_current_yfs_test_context";

/**
 * Server-side YFS tools executed by Laravel Voice Orchestrator.
 * ElevenLabs must not run these itself.
 */
export const YFS_VOICE_TOOLS: ChatCompletionFunctionTool[] = [
  {
    type: "function",
    function: {
      name: YFS_TEST_TOOL_NAME,
      description:
        "Returns test-only read-only Voice Consultant context from YFS. "
        + "Not production show, customer, or CRM data. "
        + "Use when the caller asks for the latest YFS test status or another fact that is not already in session context. "
        + "Do not use when the YFS Test Event is already preloaded in session context.",
      parameters: {
        type: "object",
        properties: {
          topic: {
            type: "string",
            description: "Optional topic hint. Does not change the test-only result.",
          },
        },
        additionalProperties: false,
      },
    },
  },
];

export const YFS_SERVER_TOOL_NAMES = new Set(YFS_VOICE_TOOLS.map((tool) => tool.function.name));

export function isYfsServerTool(name: string): boolean {
  return YFS_SERVER_TOOL_NAMES.has(name);
}

export function yfsToolsAllowed(allowed: string[] | null): ChatCompletionFunctionTool[] {
  if (allowed === null) {
    return YFS_VOICE_TOOLS;
  }
  return YFS_VOICE_TOOLS.filter((tool) => allowed.includes(tool.function.name));
}

export function mergeChatTools(
  incoming: ChatCompletionTool[] | undefined,
  yfsTools: ChatCompletionTool[],
): ChatCompletionTool[] | undefined {
  const byName = new Map<string, ChatCompletionTool>();
  for (const tool of [...(incoming ?? []), ...yfsTools]) {
    if (tool?.type === "function" && tool.function?.name) {
      byName.set(tool.function.name, tool);
    }
  }
  if (byName.size === 0) {
    return undefined;
  }
  return [...byName.values()];
}
