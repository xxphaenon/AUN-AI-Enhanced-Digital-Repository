"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { useAuth } from "../../components/AuthProvider";
import PaperViewer from "../../components/PaperViewer";
import { ApiError, documentTypeLabel, formatAuthors, getDocument, type DocumentDetails } from "../../lib/api";
import Status from "./Status";

export default function DocumentView({ id }: { id: string }) {
  const { user, loading: sessionLoading } = useAuth();
  const [doc, setDoc] = useState<DocumentDetails | null>(null);
  const [error, setError] = useState("");
  const [notFound, setNotFound] = useState(false);

  // Re-fetch when the session changes: an unapproved file only shows up for its uploader or an admin.
  useEffect(() => {
    if (sessionLoading) return;
    let active = true;
    getDocument(id)
      .then(d => { if (active) { setDoc(d); setError(""); setNotFound(false); } })
      .catch(e => {
        if (!active) return;
        if (e instanceof ApiError && e.status === 404) setNotFound(true);
        else setError(e instanceof Error ? e.message : "This document could not be loaded.");
      });
    return () => { active = false; };
  }, [id, user?.id, sessionLoading]);

  if (notFound) {
    return <div className="repository-page"><Link href="/">← Back to search</Link><section className="repository-card"><h1>Document not found</h1><p>It may have been removed, or it is still waiting for review.{!user && " If you uploaded it, sign in to see its status."}</p>{!user && <Link className="repo-button primary" href={`/login?next=${encodeURIComponent(`/documents/${id}`)}`}>Sign in</Link>}</section></div>;
  }
  if (error) return <div className="repository-page"><Link href="/">← Back to search</Link><p role="alert" className="feedback-error">{error}</p></div>;
  if (!doc) return <div className="repository-page" role="status">Loading document…</div>;

  const facts: [string, string | null][] = [
    [doc.authors.length > 1 ? "Authors" : "Author", formatAuthors(doc.authors) || doc.author],
    ["Course", doc.courseCode],
    ["Academic year", doc.academicYear],
    ["Document type", documentTypeLabel(doc.documentType) ?? null],
    ["Added", doc.createdAt ? new Date(doc.createdAt).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : null],
  ];

  return <div className="repository-page">
    <Link href="/">← Back to search</Link>
    <header className="repository-heading"><p className="eyebrow">REPOSITORY DOCUMENT</p><h1>{doc.title}</h1></header>
    <section className="repository-card">
      <dl className="form-columns">
        {facts.filter(([, value]) => value).map(([label, value]) => <div key={label}><dt className="eyebrow">{label.toUpperCase()}</dt><dd>{value}</dd></div>)}
      </dl>
      {doc.reviewStatus && <Status id={doc.id} initial={doc} />}
      {doc.submissionCode && <p style={{ marginTop: 16 }}>Submission code <strong className="submission-code">{doc.submissionCode}</strong> · <Link href={`/documents/${doc.id}/certificate`}>Print submission certificate</Link></p>}
    </section>
    <PaperViewer id={doc.id} filename={doc.originalFilename} />
  </div>;
}
