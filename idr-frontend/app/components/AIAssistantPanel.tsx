"use client";

import { useState } from "react";
import { usePathname } from "next/navigation";
import Link from "next/link";
import Icon from "./Icon";
import { useAuth } from "./AuthProvider";
import { useChat } from "./useChat";
import ChatMarkdown from "./ChatMarkdown";

export default function AIAssistantPanel() {
  const { user } = useAuth();
  // On a document page the assistant answers about that document only.
  const documentId = usePathname().match(/^\/documents\/([a-f0-9]{24})\/?$/i)?.[1] ?? null;
  const { turns, loading, error, send } = useChat(documentId);
  const [message, setMessage] = useState("");
  const last = [...turns].reverse().find(t => t.role === "model");
  const firstName = user?.fullName.split(" ")[0];

  return (
    <aside className="assistant-panel" aria-label="AI Assistant">
      <div className="assistant-header">
        <div className="assistant-title"><Icon name="sparkles" size={17} /> AI Assistant</div>
        <span className="beta-badge">BETA</span>
      </div>
      <div className="assistant-content">
        <h2 className="assistant-greeting">{firstName ? `Hi ${firstName}` : "Hi there"}</h2>
        <p className="assistant-intro">{documentId
          ? "Ask me anything about this document: its method, results, data or conclusions."
          : "I can help you search, understand, and work with university resources. What would you like to do?"}</p>
        {!user ? (
          <p className="assistant-intro"><Link className="assistant-source" href="/login">Log in</Link> to ask the assistant a question.</p>
        ) : (
          <>
            {(last || loading || error) && (
              <div className="assistant-response" aria-live="polite">
                {loading ? "Thinking…" : error || (last && <ChatMarkdown text={last.content} sources={last.sources} />)}
                {!loading && !error && last?.sources.map((source) => (
                  <Link className="assistant-source" href={`/documents/${source.id}`} key={source.id}>{source.title}</Link>
                ))}
              </div>
            )}
            <form className="assistant-form" onSubmit={async (event) => { event.preventDefault(); if (await send(message)) setMessage(""); }}>
              <div className="assistant-input-wrap">
                <input aria-label="Message the AI assistant" onChange={(event) => setMessage(event.target.value)} placeholder={documentId ? "Ask about this document..." : "Type your message..."} value={message} maxLength={4000} />
                <button aria-label="Send message" className="send-button" disabled={loading || !message.trim()} type="submit">
                  <Icon name="send" size={15} />
                </button>
              </div>
            </form>
          </>
        )}
      </div>
    </aside>
  );
}
