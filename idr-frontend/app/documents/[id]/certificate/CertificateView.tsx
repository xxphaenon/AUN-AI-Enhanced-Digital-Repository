"use client";
import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";
import { useAuth } from "../../../components/AuthProvider";
import { ApiError, HARD_COPY_TEXT, getCertificate, type Certificate } from "../../../lib/api";

const date = (iso: string | null, withTime = false) =>
  iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "long", year: "numeric", ...(withTime ? { hour: "2-digit", minute: "2-digit" } : {}) }) : "—";

/**
 * The submission certificate. The student prints it (or saves it as PDF), signs
 * it and hands it in with the hard copy at the Dean's office, where staff look
 * up the code on the admin page and mark the hard copy as received.
 */
export default function CertificateView({ id }: { id: string }) {
  const { user, loading: sessionLoading } = useAuth();
  const [cert, setCert] = useState<Certificate | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    if (sessionLoading || !user) return;
    let active = true;
    getCertificate(id)
      .then(c => { if (active) { setCert(c); setError(""); } })
      .catch(e => {
        if (!active) return;
        setError(e instanceof ApiError && e.status === 404 ? "There is no certificate for this document. Only submissions signed with the declaration have one." : e instanceof Error ? e.message : "The certificate could not be loaded.");
      });
    return () => { active = false; };
  }, [id, user, sessionLoading]);

  if (sessionLoading) return <div className="repository-page" role="status">Checking session…</div>;
  if (!user) return <div className="repository-page"><section className="repository-card"><h1>Sign in to see your certificate</h1><Link className="repo-button primary" href={`/login?next=${encodeURIComponent(`/documents/${id}/certificate`)}`}>Sign in</Link></section></div>;
  if (error) return <div className="repository-page"><Link href="/upload">← Back to your submissions</Link><p role="alert" className="feedback-error">{error}</p></div>;
  if (!cert) return <div className="repository-page" role="status">Loading certificate…</div>;

  const group = cert.authors.length > 1;
  const rows: [string, string | null][] = [
    ["Project title", cert.title],
    ["Project type", cert.documentTypeLabel],
    ...(group ? [] : [["Author", cert.authors[0]?.name ?? cert.author] as [string, string | null]]),
    ["Course", cert.courseCode],
    ["Academic year", cert.academicYear],
    [group ? "Submitted by" : "Student name", cert.declaration.name],
    ["Student ID", cert.declaration.universityId],
    ["File submitted", cert.originalFilename],
    ["Submitted on", date(cert.submittedAt, true)],
  ];

  return <div className="certificate-page">
    <div className="certificate-actions no-print">
      <Link href={`/documents/${cert.id}`}>← Back to the document</Link>
      <span className={`status-badge ${cert.hardCopy.status === "received" ? "approved" : cert.hardCopy.status === "overdue" ? "rejected" : "pending"}`}>{HARD_COPY_TEXT[cert.hardCopy.status]}</span>
      {cert.hardCopy.status !== "received" && cert.hardCopy.dueBy && <span>Hand in by {date(cert.hardCopy.dueBy)}</span>}
      <button className="repo-button primary" type="button" onClick={() => window.print()}>Print or save as PDF</button>
    </div>

    <article className="certificate" aria-labelledby="certificate-title">
      <header className="certificate-header">
        {/* The full AUN logo (crest and name), in its version for white paper. */}
        <Image src="/aun-logo-light.png" alt="American University of Nigeria" width={1023} height={354} className="certificate-logo" priority />
        <div>
          <p className="certificate-sub">AUN Digital Repository</p>
          <h1 id="certificate-title">Senior Project Submission Certificate</h1>
        </div>
      </header>

      <div className="certificate-code">
        <span>Submission code</span>
        <strong>{cert.submissionCode}</strong>
      </div>

      <table className="certificate-table">
        <tbody>
          {rows.map(([label, value]) => <tr key={label}><th scope="row">{label}</th><td>{value || "—"}</td></tr>)}
        </tbody>
      </table>

      {group && <section className="certificate-group">
        <h2>Group members</h2>
        <table className="certificate-table certificate-members">
          <thead><tr><th scope="col">#</th><th scope="col">Name</th><th scope="col">Student ID</th><th scope="col">Signature</th></tr></thead>
          <tbody>
            {cert.authors.map((author, i) => <tr key={author.name}><td>{i + 1}</td><td>{author.name}</td><td>{author.studentId || "—"}</td><td><span className="certificate-line" /></td></tr>)}
          </tbody>
        </table>
      </section>}

      <section className="certificate-declaration">
        <h2>Declaration</h2>
        <p>{cert.declaration.text}</p>
        <p className="certificate-meta">Accepted electronically by {cert.declaration.name} on {date(cert.declaration.acceptedAt, true)}.</p>
      </section>

      <div className="certificate-signatures">
        <div><span className="certificate-line" /><p>{group ? "Signature of the student who submitted, and date" : "Student’s signature and date"}</p></div>
        <div><span className="certificate-line" /><p>Received by (Dean’s office), date and stamp</p></div>
      </div>

      <footer className="certificate-footer">
        <p>Hand this certificate in with the hard copy of your project at the Dean’s office. Staff confirm it by entering the submission code on the repository’s admin page.</p>
        {cert.fileHash && <p className="certificate-meta">File fingerprint (SHA-256): {cert.fileHash.slice(0, 16)}…{cert.fileHash.slice(-8)}</p>}
      </footer>
    </article>
  </div>;
}
