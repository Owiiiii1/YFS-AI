import type { ServerResponse } from "node:http";

export function writeSseHeaders(res: ServerResponse): void {
  res.writeHead(200, {
    "Content-Type": "text/event-stream; charset=utf-8",
    "Cache-Control": "no-cache, no-transform",
    Connection: "keep-alive",
    "X-Accel-Buffering": "no",
  });
  if (typeof res.flushHeaders === "function") {
    res.flushHeaders();
  }
}

export function writeSseData(res: ServerResponse, payload: unknown): boolean {
  if (res.writableEnded || !res.writable) {
    return false;
  }
  return res.write(`data: ${JSON.stringify(payload)}\n\n`);
}

export function writeSseDone(res: ServerResponse): void {
  if (res.writableEnded || !res.writable) {
    return;
  }
  res.write("data: [DONE]\n\n");
  res.end();
}
