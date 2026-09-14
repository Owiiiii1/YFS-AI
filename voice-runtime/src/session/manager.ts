export type SessionRecord = {
  conversationId: string;
  connectedAt: number;
  lastEventAt: number;
};

export class SessionManager {
  private readonly sessions = new Map<string, SessionRecord>();

  add(conversationId: string): SessionRecord {
    const now = Date.now();
    const record: SessionRecord = {
      conversationId,
      connectedAt: now,
      lastEventAt: now,
    };
    this.sessions.set(conversationId, record);
    return record;
  }

  touch(conversationId: string | undefined): void {
    if (!conversationId) {
      return;
    }
    const existing = this.sessions.get(conversationId);
    if (existing) {
      existing.lastEventAt = Date.now();
    }
  }

  remove(conversationId: string | undefined): SessionRecord | undefined {
    if (!conversationId) {
      return undefined;
    }
    const existing = this.sessions.get(conversationId);
    this.sessions.delete(conversationId);
    return existing;
  }

  has(conversationId: string): boolean {
    return this.sessions.has(conversationId);
  }

  count(): number {
    return this.sessions.size;
  }
}
