"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { getDocument, sendChat } from "../lib/api";

export interface ChatTurn { role: "user" | "model"; content: string; sources: { id: string; title: string }[] }

/**
 * One conversation with the repository assistant. The backend keeps the history
 * (keyed by sessionId) and answers only from approved documents.
 *
 * With documentId (the assistant panel on a document page) every answer comes
 * from that document only; moving to another document starts a new conversation.
 */
export function useChat(documentId: string | null = null) {
  const [turns, setTurns] = useState<ChatTurn[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const sessionId = useRef<string | null>(null);
  const titles = useRef(new Map<string, string>());

  const titleOf = useCallback(async (id: string) => {
    if (!titles.current.has(id)) {
      const title = await getDocument(id).then(d => d.title).catch(() => "Referenced document");
      titles.current.set(id, title);
    }
    return titles.current.get(id)!;
  }, []);

  const send = useCallback(async (text: string) => {
    const message = text.trim();
    if (!message || loading) return false;
    setLoading(true); setError("");
    setTurns(t => [...t, { role: "user", content: message, sources: [] }]);
    try {
      const reply = await sendChat(message, sessionId.current, documentId);
      sessionId.current = reply.sessionId;
      const sources = await Promise.all(reply.sources.map(async id => ({ id, title: await titleOf(id) })));
      setTurns(t => [...t, { role: "model", content: reply.content, sources }]);
      return true;
    } catch (e) {
      setTurns(t => t.slice(0, -1)); // nothing was saved; let the student try again
      setError(e instanceof Error ? e.message : "Sorry, something went wrong. Please try again.");
      return false;
    } finally {
      setLoading(false);
    }
  }, [loading, titleOf, documentId]);

  const reset = useCallback(() => { sessionId.current = null; setTurns([]); setError(""); }, []);

  // A different document is a different conversation.
  useEffect(() => { reset(); }, [documentId, reset]);

  return { turns, loading, error, send, reset };
}
