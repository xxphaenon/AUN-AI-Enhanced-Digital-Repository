"use client";
import { useEffect, useRef, type ReactNode } from "react";
export default function Modal({ title, children, onClose, busy = false }: { title: string; children: ReactNode; onClose: () => void; busy?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => { const dialog = ref.current!; dialog.showModal(); return () => dialog.close(); }, []);
  return <dialog ref={ref} className="repo-modal" aria-labelledby="modal-title" onCancel={event => { event.preventDefault(); if (!busy) onClose(); }}><div className="section-toolbar"><h2 id="modal-title">{title}</h2><button className="repo-button" disabled={busy} onClick={onClose} aria-label="Close dialog">Close</button></div>{children}</dialog>;
}
