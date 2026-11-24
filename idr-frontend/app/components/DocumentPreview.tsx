"use client";
import { useEffect, useState } from "react";
import { request } from "../lib/api";
export default function DocumentPreview({ id, admin = false }: { id: string; admin?: boolean }) {
  const [url, setUrl] = useState("");
  const [error, setError] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    let objectUrl = "";
    request(`/${admin ? "admin/" : ""}documents/${encodeURIComponent(id)}/preview`, { signal: controller.signal, headers: { Accept: "application/pdf" } })
      .then(async response => {
        const blob = await response.blob();
        if (controller.signal.aborted) return;
        if (blob.type !== "application/pdf") throw new Error("Preview is unavailable for this file format. A PDF preview is required.");
        objectUrl = URL.createObjectURL(blob); setUrl(objectUrl);
      }).catch(e => { if (!controller.signal.aborted) setError(e.message); });
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [id, admin]);
  if (error) return <p role="alert" className="feedback-error">{error}</p>;
  if (!url) return <p role="status">Loading document preview…</p>;
  return <iframe className="paper-preview" title="Document preview" src={url} />;
}
