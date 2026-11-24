"use client";
import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "./AuthProvider";
import DocumentPreview from "./DocumentPreview";
import { ApiError, filenameFrom, request } from "../lib/api";
export default function PaperViewer({ id, filename }: { id: string; filename?: string | null }) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const login = `/login?next=${encodeURIComponent(`/documents/${id}`)}`;
  async function download() {
    setBusy(true); setError("");
    try {
      const response = await request(`/documents/${encodeURIComponent(id)}/download`, { headers: { Accept: "application/pdf" } });
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a"); anchor.href = url; anchor.download = filenameFrom(response, filename || `document-${id}.pdf`); anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) { if (e instanceof ApiError && e.status === 401) router.push(login); else setError(e instanceof Error ? e.message : "Download failed."); }
    finally { setBusy(false); }
  }
  return <section className="repository-card"><div className="section-toolbar"><div><h2>Document preview</h2><p>Preview this paper before downloading.</p></div>{loading ? <span>Checking session…</span> : user ? <button className="repo-button primary" disabled={busy} onClick={download}>{busy ? "Downloading…" : "Download"}</button> : <Link className="repo-button primary" href={login}>Sign in to Download</Link>}</div>{error && <p role="alert" className="feedback-error">{error}</p>}<DocumentPreview id={id} /></section>;
}
