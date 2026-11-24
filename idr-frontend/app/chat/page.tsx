"use client";

import { useState } from "react";
import Link from "next/link";
import Icon from "../components/Icon";
import { useAuth } from "../components/AuthProvider";
import { useChat } from "../components/useChat";
import ChatMarkdown from "../components/ChatMarkdown";

export default function ChatPage() {
  const { user, loading: sessionLoading } = useAuth();
  const { turns, loading, error, send, reset } = useChat();
  const [message, setMessage] = useState("");

  return (
    <main className="chat-page">
      <Link href="/" className="detail-back">
        <Icon name="back" size={15} /> Back to repository
      </Link>

      <h1 className="text-3xl font-bold mb-2">Repository Assistant</h1>
      <p className="text-gray-600 mb-6">Ask questions about documents in the university repository. Answers come only from approved documents, with sources.</p>

      {sessionLoading ? <p role="status">Checking session…</p> : !user ? (
        <div className="chat-conversation">
          <div className="chat-empty-state">
            <strong>Sign in to chat</strong>
            <p>The assistant is available to students and staff with a university account.</p>
            <Link className="repo-button primary" href="/login?next=%2Fchat">Log In</Link>
          </div>
        </div>
      ) : (
        <>
          <div className="chat-conversation" aria-live="polite">
            {turns.length === 0 ? (
              <div className="chat-empty-state">
                <strong>Start a conversation</strong>
                <p>For example: “Which projects use machine learning for agriculture?”</p>
              </div>
            ) : (
              <div className="chat-thread">
                {turns.map((turn, i) => (
                  <div className={`chat-message ${turn.role === "user" ? "user" : "assistant"}`} key={i}>
                    <p className="chat-speaker">{turn.role === "user" ? "You" : "Assistant"}</p>
                    <div className="chat-bubble">{turn.role === "user" ? turn.content : <ChatMarkdown text={turn.content} sources={turn.sources} />}</div>
                    {turn.sources.length > 0 && (
                      <div className="mt-2">
                        <p className="chat-speaker">Sources</p>
                        {turn.sources.map((source, n) => (
                          <Link key={source.id} href={`/documents/${source.id}`} className="block mt-1 text-sm underline">[{n + 1}] {source.title}</Link>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
                {loading && <p className="chat-speaker" role="status">Assistant is thinking…</p>}
              </div>
            )}
          </div>

          {error && <p className="feedback-error" role="alert">{error}</p>}

          <form className="flex gap-2" onSubmit={async e => { e.preventDefault(); if (await send(message)) setMessage(""); }}>
            <input type="text" aria-label="Ask a question" placeholder="Ask a question..." value={message} maxLength={4000} onChange={e => setMessage(e.target.value)} className="border rounded-lg p-3 flex-1" />
            <button type="submit" disabled={loading || !message.trim()} className="button-primary">{loading ? "Sending..." : "Send"}</button>
            {turns.length > 0 && <button type="button" className="repo-button" disabled={loading} onClick={reset}>New chat</button>}
          </form>
        </>
      )}
    </main>
  );
}
