import { timingSafeEqual } from "node:crypto";
import type { IncomingMessage } from "node:http";

function safeEqual(left: string, right: string): boolean {
  const leftBuf = Buffer.from(left);
  const rightBuf = Buffer.from(right);
  if (leftBuf.length !== rightBuf.length) {
    return false;
  }
  return timingSafeEqual(leftBuf, rightBuf);
}

function bearerToken(header: string | undefined): string {
  if (!header) {
    return "";
  }
  const value = header.trim();
  const match = /^Bearer\s+(.+)$/i.exec(value);
  return (match?.[1] ?? value).trim();
}

export function extractPresentedSecret(req: IncomingMessage): string {
  const headerToken = req.headers["x-yfs-voice-token"];
  if (typeof headerToken === "string" && headerToken.trim()) {
    return headerToken.trim();
  }
  if (Array.isArray(headerToken) && headerToken[0]?.trim()) {
    return headerToken[0].trim();
  }
  return bearerToken(typeof req.headers.authorization === "string" ? req.headers.authorization : undefined);
}

export function isAuthorizedCustomLlm(req: IncomingMessage, expectedSecret: string): boolean {
  if (!expectedSecret) {
    return false;
  }
  const presented = extractPresentedSecret(req);
  if (!presented) {
    return false;
  }
  return safeEqual(presented, expectedSecret);
}
