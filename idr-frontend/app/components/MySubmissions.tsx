"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { HARD_COPY_TEXT, documentTypeLabel, myDocuments, type DocumentDetails } from "../lib/api";

/** The signed-in user's own uploads, in every review state. */
export default function MySubmissions({ refreshKey }: { refreshKey: number }) {
  const [docs, setDocs] = useState<DocumentDetails[] | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    let active = true;
    myDocuments()
      .then(d => { if (active) { setDocs(d); setError(""); } })
      .catch(e => { if (active) setError(e instanceof Error ? e.message : "Could not load your submissions."); });
    return () => { active = false; };
  }, [refreshKey]);
  return <section className="repository-card">
    <h2>Your submissions</h2>
    {error ? <p role="alert" className="feedback-error">{error}</p>
      : docs === null ? <p role="status">Loading…</p>
      : docs.length === 0 ? <p>You haven’t submitted anything yet.</p>
      : <div className="table-scroll"><table className="admin-table"><thead><tr><th scope="col">Document</th><th scope="col">Submitted</th><th scope="col">Status</th></tr></thead><tbody>
          {docs.map(d => <tr key={d.id}>
            <td><Link href={`/documents/${d.id}`}><strong>{d.title}</strong></Link><small>{[d.courseCode, d.academicYear, documentTypeLabel(d.documentType)].filter(Boolean).join(" · ")}</small>{d.reviewStatus === "rejected" && d.rejectionReason && <small className="danger-text">Reason: {d.rejectionReason}</small>}</td>
            <td>{d.createdAt ? new Date(d.createdAt).toLocaleDateString("en-GB") : "—"}</td>
            <td>{d.reviewStatus && <span className={`status-badge ${d.reviewStatus}`}>{d.reviewStatus}</span>}{d.submissionCode && d.hardCopy && d.hardCopy.status !== "not_required" && <small className={d.hardCopy.status === "overdue" ? "danger-text" : undefined}>{HARD_COPY_TEXT[d.hardCopy.status]}{d.hardCopy.status !== "received" && <> · <Link href={`/documents/${d.id}/certificate`}>Print certificate</Link></>}</small>}</td>
          </tr>)}
        </tbody></table></div>}
  </section>;
}
