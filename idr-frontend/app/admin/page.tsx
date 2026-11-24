"use client";
import Link from "next/link";
import { use, useEffect, useState, type FormEvent } from "react";
import { useAuth } from "../components/AuthProvider";
import Modal from "../components/Modal";
import DocumentPreview from "../components/DocumentPreview";
import {
  ADMIN_VIEWS, DOCUMENT_TYPES, HARD_COPY_FILTERS, HARD_COPY_TEXT, deleteDocument, documentTypeLabel, findByCode, listAdminDocuments, reprocessDocument, search, setHardCopyReceived, setReviewStatus, updateDocument,
  type Paper, type ReviewStatus, type SearchResult,
} from "../lib/api";

type Action = { kind: "view" | "edit" | "delete" | "reject"; paper: Paper };
const sample = (p: Partial<Paper> & Pick<Paper, "id" | "name" | "course_code" | "status" | "uploaded_by" | "created_at">): Paper =>
  ({ author: null, authors: [], academic_year: "2025/2026", documentType: "project", uploaderEmail: null, processingStatus: "EMBEDDED", processingError: null, rejectionReason: null, submissionCode: null, hardCopy: null, autoApproved: false, aiScreening: null, similarity: null, ...p });
const samplePapers: Paper[] = [
  sample({ id: "demo-1", name: "Smart Campus Parking Monitor.pdf", course_code: "SEN 499", status: "pending", uploaded_by: "Sample Student A", created_at: "2026-10-05T09:00:00Z" }),
  sample({ id: "demo-2", name: "Mobile Learning Adoption Survey.pdf", course_code: "CSC 498", status: "pending", uploaded_by: "Sample Student B", created_at: "2026-10-04T14:30:00Z", documentType: "research", aiScreening: { verdict: "review", reason: "This looks like lecture notes, not a research report.", checkedAt: "2026-10-04T14:32:00Z" } }),
  sample({ id: "demo-3", name: "Crop Disease Detection with CNNs.pdf", course_code: "CSC 499", status: "approved", uploaded_by: "Sample Student C", created_at: "2026-10-03T11:00:00Z", documentType: "project", submissionCode: "IDR-2026-7KQ4MX", autoApproved: true, similarity: { score: 0.08, flagged: false, matches: [{ artifactId: "demo-1", title: "Smart Campus Parking Monitor", academicYear: "2024/2025", overlap: 0.08, matchedSections: 1 }], error: null, checkedAt: "2026-10-03T11:02:00Z" }, hardCopy: { status: "awaiting", dueBy: "2026-10-17T11:00:00Z", receivedAt: null } }),
  sample({ id: "demo-4", name: "Solar Microgrid Feasibility Study.pdf", course_code: "EEE 498", status: "rejected", uploaded_by: "Sample Student D", created_at: "2026-10-02T10:00:00Z", documentType: "research" }),
];
export default function AdminPage({ searchParams }: { searchParams: Promise<{ preview?: string }> }) {
  const params = use(searchParams);
  const preview = process.env.NODE_ENV === "development" && params.preview === "1";
  const { user, loading, error: sessionError, refresh } = useAuth();
  if (preview) return <AdminTable preview />;
  if (loading) return <div className="repository-page" role="status">Checking administrator access…</div>;
  if (!user) return <div className="repository-page repository-card"><h1>Administrator sign-in required</h1><p>Sign in with an administrator account to review submissions.</p><Link className="repo-button primary" href="/login?next=%2Fadmin">Sign in</Link>{sessionError && <p role="alert">{sessionError} <button onClick={() => void refresh()}>Retry</button></p>}</div>;
  if (user.role !== "admin") return <div className="repository-page repository-card"><h1>Administrator access only</h1><p>Your student account can search resources and submit documents for review.</p><Link href="/">Return to repository</Link></div>;
  return <><HardCopyCheck /><AdminTable /><AdminAiSearch /></>;
}
function AdminTable({ preview = false }: { preview?: boolean }) {
  const [remotePapers, setPapers] = useState<Paper[]>([]);
  const [total, setTotal] = useState(0);
  const [counts, setCounts] = useState<Partial<Record<ReviewStatus, number>>>({});
  const [demoPapers, setDemoPapers] = useState<Paper[]>(samplePapers);
  const [status, setStatus] = useState(preview ? "all" : "pending");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(!preview);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [action, setAction] = useState<Action | null>(null);
  const [actionError, setActionError] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const hardCopyFilter = HARD_COPY_FILTERS[status as keyof typeof HARD_COPY_FILTERS];
  const viewFilter = ADMIN_VIEWS[status as keyof typeof ADMIN_VIEWS];
  const matchesStatus = (paper: Paper) =>
    viewFilter === "recent" ? paper.autoApproved && paper.status === "approved"
    : viewFilter === "flagged" ? paper.status === "pending" && (paper.aiScreening?.verdict === "review" || paper.aiScreening?.verdict === "error" || Boolean(paper.similarity?.flagged))
    : hardCopyFilter ? paper.hardCopy?.status === hardCopyFilter
    : status === "all" || paper.status === status;
  const papers = preview ? demoPapers.filter(paper =>
    matchesStatus(paper) &&
    `${paper.name} ${paper.course_code}`.toLowerCase().includes(query.toLowerCase())
  ) : remotePapers;
  useEffect(() => {
    if (preview) return;
    const controller = new AbortController();
    listAdminDocuments({ status, q: query }, controller.signal)
      .then(data => { if (!controller.signal.aborted) { setPapers(data.papers); setTotal(data.total); setCounts(data.counts); setError(""); } })
      .catch(e => { if (!controller.signal.aborted) { setPapers([]); setError(e.message); } })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [status, query, revision, preview]);
  function reload() { setLoading(!preview); setRevision(value => value + 1); }
  function open(kind: Action["kind"], paper: Paper) { setAction({ kind, paper }); setActionError(""); setConfirmation(""); }
  /** Runs one admin action against the backend (or the sample data in preview mode). */
  async function run(paper: Paper, done: string, live: () => Promise<unknown>, demo: (p: Paper) => Paper | null) {
    if (busy) return;
    if (preview) {
      setDemoPapers(current => current.flatMap(item => item.id !== paper.id ? [item] : (demo(item) ? [demo(item)!] : [])));
      setAction(null);
      setNotice(`Sample document “${paper.name}” was ${done}. Reload the page to reset the preview.`);
      return;
    }
    setBusy(true); setActionError(""); setError(""); setNotice("");
    try {
      await live();
      setAction(null); setNotice(`“${paper.name}” was ${done}.`); reload();
    } catch (e) { const message = e instanceof Error ? e.message : "The action failed. Please retry."; if (action) setActionError(message); else setError(message); }
    finally { setBusy(false); }
  }
  const approve = (paper: Paper) => run(paper, "approved", () => setReviewStatus(paper.id, "approved"), p => ({ ...p, status: "approved" }));
  function reject(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!action) return;
    const reason = String(new FormData(event.currentTarget).get("reason") || "").trim();
    void run(action.paper, "rejected", () => setReviewStatus(action.paper.id, "rejected", reason || undefined), p => ({ ...p, status: "rejected", rejectionReason: reason || null }));
  }
  function edit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!action) return;
    const data = new FormData(event.currentTarget);
    const value = (name: string) => String(data.get(name) || "").trim();
    const changes = { title: value("name"), author: value("author"), courseCode: value("course_code"), academicYear: value("academic_year"), documentType: value("documentType") };
    if (!changes.title || !changes.courseCode || !changes.academicYear) { setActionError("Title, course code and academic year are required."); return; }
    void run(action.paper, "updated", () => updateDocument(action.paper.id, changes),
      p => ({ ...p, name: changes.title, author: changes.author || null, course_code: changes.courseCode, academic_year: changes.academicYear, documentType: (changes.documentType || null) as Paper["documentType"] }));
  }
  const reprocess = (paper: Paper) => run(paper, "queued for AI processing again", () => reprocessDocument(paper.id), p => ({ ...p, processingStatus: "PENDING" }));
  const label = (value: string, text: string) => counts[value as ReviewStatus] === undefined ? text : `${text} (${counts[value as ReviewStatus]})`;
  return <div className="repository-page admin-page"><header className="repository-heading"><p className="eyebrow">REPOSITORY ADMINISTRATION</p><h1>Review submissions</h1>{preview && <p className="feedback-success" role="status">UI preview · Sample data only. Changes stay in this page and reset when you reload.</p>}<p>Preview documents, manage approvals, and keep university resources accurate.</p></header><section className="repository-card">
    <div className="section-toolbar"><div><h2>Submissions</h2><p>Review each paper before making it available to students.</p></div><button className="repo-button" disabled={busy || loading} onClick={reload}>Refresh</button></div>
    <form className="admin-filters" onSubmit={event => { event.preventDefault(); if (query === search.trim()) reload(); else { setLoading(!preview); setQuery(search.trim()); } }}><label>Search submissions<input placeholder="Search by title, filename, course code or author…" value={search} onChange={e => setSearch(e.target.value)} disabled={busy} /></label><label>Status<select value={status} disabled={busy} onChange={e => { setLoading(!preview); setStatus(e.target.value); }}><option value="pending">{label("pending", "Pending")}</option><option value="approved">{label("approved", "Approved")}</option><option value="rejected">{label("rejected", "Rejected")}</option><option value="all">All statuses</option><option value="view-recent">Recently published (automatic)</option><option value="view-flagged">Flagged by AI check</option><option value="hardcopy-awaiting">Hard copy awaited</option><option value="hardcopy-overdue">Hard copy overdue</option><option value="hardcopy-received">Hard copy received</option></select></label><button className="repo-button primary" disabled={busy} type="submit">Search</button></form>
    {notice && <p role="status" className="feedback-success">{notice}</p>}{error && <p role="alert" className="feedback-error">{error} <button className="repo-button" disabled={busy || loading} onClick={reload}>Retry</button></p>}
    <div aria-live="polite">{loading ? <div className="empty-state">Loading submissions…</div> : papers.length === 0 ? <div className="empty-state"><strong>{error ? "Submissions could not be loaded" : "No submissions found"}</strong>{error ? "Retry when the repository service is available." : "Try another search or status filter."}</div> : <div className="table-scroll"><table className="admin-table"><caption>{!preview && total > papers.length ? `Showing the newest ${papers.length} of ${total}` : `${papers.length} submissions`} · {status === "all" ? "All statuses" : viewFilter === "recent" ? "Recently published (automatic)" : viewFilter === "flagged" ? "Flagged by AI check" : hardCopyFilter ? HARD_COPY_TEXT[hardCopyFilter] : status}</caption><thead><tr><th scope="col">Document</th><th scope="col">Uploaded by</th><th scope="col">Upload date</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>{papers.map(paper => <tr key={paper.id}><td><strong>{paper.name}</strong><small>{[paper.course_code, paper.academic_year, documentTypeLabel(paper.documentType)].filter(Boolean).join(" · ")}</small>{paper.processingStatus === "FAILED" && <small className="danger-text">AI processing failed{paper.processingError ? `: ${paper.processingError}` : ""}</small>}{paper.processingStatus === "PENDING" && <small>Being prepared for AI search…</small>}</td><td>{paper.uploaded_by}{paper.uploaderEmail && <small>{paper.uploaderEmail}</small>}</td><td>{Number.isNaN(Date.parse(paper.created_at)) ? "—" : new Date(paper.created_at).toLocaleDateString("en-GB")}</td><td><span className={`status-badge ${paper.status}`}>{paper.status}</span>{paper.autoApproved && paper.status === "approved" && <small>Published automatically</small>}{paper.status === "pending" && paper.aiScreening && paper.aiScreening.verdict !== "accept" && <small className="danger-text">AI check: {paper.aiScreening.verdict === "error" ? "could not run" : "needs review"}{paper.aiScreening.reason ? ` (${paper.aiScreening.reason})` : ""}</small>}<SimilarityNote similarity={paper.similarity} />{paper.hardCopy && paper.hardCopy.status !== "not_required" && <small className={paper.hardCopy.status === "overdue" ? "danger-text" : undefined}>{HARD_COPY_TEXT[paper.hardCopy.status]}</small>}</td><td><div className="row-actions"><button disabled={busy} onClick={() => open("view", paper)} aria-label={`View ${paper.name}`}>View</button><button disabled={busy || paper.status === "approved"} onClick={() => void approve(paper)} aria-label={`Approve ${paper.name}`}>Approve</button><button disabled={busy || paper.status === "rejected"} onClick={() => open("reject", paper)} aria-label={`Reject ${paper.name}`}>Reject</button><button disabled={busy} onClick={() => open("edit", paper)} aria-label={`Edit ${paper.name}`}>Edit</button>{paper.processingStatus === "FAILED" && <button disabled={busy} onClick={() => void reprocess(paper)} aria-label={`Reprocess ${paper.name}`}>Reprocess</button>}<button className="danger-text" disabled={busy} onClick={() => open("delete", paper)} aria-label={`Delete ${paper.name}`}>Delete</button></div></td></tr>)}</tbody></table></div>}</div>
  </section>
  {action && <Modal title={action.kind === "view" ? action.paper.name : action.kind === "edit" ? "Edit document details" : action.kind === "reject" ? "Reject submission?" : "Permanently delete document?"} busy={busy} onClose={() => setAction(null)}>
    {action.kind === "view" && (preview ? <article className="repository-card"><p className="eyebrow">SAMPLE DOCUMENT</p><h2>{action.paper.name}</h2><p>{action.paper.course_code} · {action.paper.academic_year}</p><hr /><h3>Practice questions</h3><ol><li>Define the key concepts introduced in this course.</li><li>Explain how these concepts apply to a practical example.</li><li>Compare two approaches and discuss their strengths.</li></ol><p>This placeholder illustrates the preview dialog. Live submissions display their PDF here.</p></article> : <DocumentPreview key={action.paper.id} id={action.paper.id} admin />)}
    {action.kind === "reject" && <form className="repo-form" onSubmit={reject}><fieldset disabled={busy}><p><strong>{action.paper.name}</strong> will be hidden from students and from AI search. The uploader sees the reason below.</p><label>Reason (optional)<input name="reason" maxLength={500} placeholder="e.g. The scan is unreadable. Please upload a clearer copy." /></label><div className="modal-actions"><button className="repo-button" type="button" onClick={() => setAction(null)}>Cancel</button><button className="repo-button danger" type="submit">{busy ? "Rejecting…" : "Reject submission"}</button></div></fieldset></form>}
    {action.kind === "edit" && <form className="repo-form" onSubmit={edit}><fieldset disabled={busy}><label>Document title<input name="name" defaultValue={action.paper.name} required maxLength={255} /></label><label>Author<input name="author" defaultValue={action.paper.author ?? ""} maxLength={255} /></label><div className="form-columns"><label>Course code<input name="course_code" defaultValue={action.paper.course_code} required maxLength={40} /></label><label>Academic year<input name="academic_year" defaultValue={action.paper.academic_year} required pattern="[0-9]{4}/[0-9]{4}" title="Use the format 2025/2026" /></label></div><label>Document type<select name="documentType" defaultValue={action.paper.documentType ?? ""}><option value="">Not specified</option>{DOCUMENT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}</select></label><p>Changes update AI search straight away, without re-processing the file.</p><div className="modal-actions"><button className="repo-button" type="button" onClick={() => setAction(null)}>Cancel</button><button className="repo-button primary" type="submit">{busy ? "Saving…" : "Save corrections"}</button></div></fieldset></form>}
    {action.kind === "delete" && <div className="repo-form"><p>This permanently removes <strong>{action.paper.name}</strong>, its stored file, and its search entry. This action cannot be undone.</p><label>Type the full document name to confirm<input autoComplete="off" value={confirmation} disabled={busy} onChange={e => setConfirmation(e.target.value)} /></label><div className="modal-actions"><button className="repo-button" disabled={busy} onClick={() => setAction(null)}>Cancel</button><button className="repo-button danger" disabled={busy || confirmation !== action.paper.name} onClick={() => void run(action.paper, "deleted", () => deleteDocument(action.paper.id), () => null)}>{busy ? "Deleting…" : "Permanently delete"}</button></div></div>}
    {actionError && <p role="alert" className="feedback-error">{actionError}</p>}
  </Modal>}
  </div>;
}
/** "Similar to …" line under a submission's status, linking to the closest earlier project. */
function SimilarityNote({ similarity }: { similarity: Paper["similarity"] }) {
  if (!similarity) return null;
  if (similarity.error) return <small className="danger-text">Similarity check could not run</small>;
  const top = similarity.matches[0];
  if (!top) return <small>No similar projects found</small>;
  const percent = Math.round(top.overlap * 100);
  return <small className={similarity.flagged ? "danger-text" : undefined}>
    {similarity.flagged ? "Too similar: " : "Similarity: "}{percent}% of sections match <Link href={`/documents/${top.artifactId}`}>{top.title ?? "an earlier project"}</Link>{top.academicYear ? ` (${top.academicYear})` : ""}
  </small>;
}

/**
 * The Dean's office desk: type the code printed on a student's certificate,
 * check the details against the hard copy, and record it as received.
 */
function HardCopyCheck() {
  const [code, setCode] = useState("");
  const [paper, setPaper] = useState<Paper | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  async function lookUp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (code.trim().length < 4) { setError("Type the code printed on the certificate, e.g. IDR-2026-7KQ4MX."); return; }
    setBusy(true); setError(""); setNotice(""); setPaper(null);
    try { setPaper(await findByCode(code)); }
    catch (e) { setError(e instanceof Error ? e.message : "The code could not be checked."); }
    finally { setBusy(false); }
  }
  async function mark(received: boolean) {
    if (!paper) return;
    setBusy(true); setError("");
    try {
      const updated = await setHardCopyReceived(paper.id, received);
      setPaper(updated);
      setNotice(received ? `Hard copy of “${updated.name}” recorded as received.` : "Confirmation removed.");
    } catch (e) { setError(e instanceof Error ? e.message : "The hard copy could not be recorded."); }
    finally { setBusy(false); }
  }
  return <div className="repository-page" style={{ paddingBottom: 0 }}><section className="repository-card">
    <div className="section-toolbar"><div><h2>Hard copy check (Dean’s office)</h2><p>Enter the submission code from the student’s printed certificate, compare the details with the hard copy, then record it as received.</p></div></div>
    <form className="admin-filters" style={{ gridTemplateColumns: "minmax(180px, 1fr) auto" }} onSubmit={lookUp}><label>Submission code<input value={code} onChange={e => setCode(e.target.value)} placeholder="e.g. IDR-2026-7KQ4MX" autoComplete="off" disabled={busy} /></label><button className="repo-button primary" disabled={busy} type="submit">{busy && !paper ? "Checking…" : "Check code"}</button></form>
    {error && <p role="alert" className="feedback-error">{error}</p>}
    {notice && <p role="status" className="feedback-success">{notice}</p>}
    {paper && <div className="table-scroll"><table className="admin-table"><thead><tr><th scope="col">Document</th><th scope="col">Submitted by</th><th scope="col">Status</th><th scope="col">Hard copy</th></tr></thead><tbody><tr>
      <td><Link href={`/documents/${paper.id}`}><strong>{paper.name}</strong></Link><small>{[paper.submissionCode, paper.course_code, paper.academic_year, documentTypeLabel(paper.documentType)].filter(Boolean).join(" · ")}</small></td>
      <td>{paper.uploaded_by}{paper.uploaderEmail && <small>{paper.uploaderEmail}</small>}</td>
      <td><span className={`status-badge ${paper.status}`}>{paper.status}</span></td>
      <td>{paper.hardCopy && <small className={paper.hardCopy.status === "overdue" ? "danger-text" : undefined}>{HARD_COPY_TEXT[paper.hardCopy.status]}{paper.hardCopy.receivedAt && ` on ${new Date(paper.hardCopy.receivedAt).toLocaleDateString("en-GB")}`}</small>}<div className="row-actions">{paper.hardCopy?.status === "received"
        ? <button type="button" disabled={busy} onClick={() => void mark(false)}>Undo</button>
        : <button type="button" disabled={busy} onClick={() => void mark(true)}>Mark hard copy received</button>}</div></td>
    </tr></tbody></table></div>}
  </section></div>;
}

/** Semantic search over EVERY submission (pending, approved, rejected), for admins only. */
function AdminAiSearch() {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (query.trim().length < 2) { setError("Type at least 2 characters."); return; }
    setLoading(true); setError("");
    try { setResults(await search(query, "all", 10, true)); }
    catch (e) { setResults(null); setError(e instanceof Error ? e.message : "Search failed."); }
    finally { setLoading(false); }
  }
  return <div className="repository-page" style={{ paddingTop: 0 }}><section className="repository-card">
    <div className="section-toolbar"><div><h2>AI search across all submissions</h2><p>Finds documents by meaning, including pending and rejected ones (students only ever see approved files).</p></div></div>
    <form className="admin-filters" style={{ gridTemplateColumns: "minmax(180px, 1fr) auto" }} onSubmit={submit}><label>Ask in plain words<input value={query} onChange={e => setQuery(e.target.value)} placeholder="e.g. projects about crop disease detection" disabled={loading} /></label><button className="repo-button primary" disabled={loading} type="submit">{loading ? "Searching…" : "Search"}</button></form>
    {error && <p role="alert" className="feedback-error">{error}</p>}
    {results && (results.length === 0 ? <div className="empty-state">No matching submissions.</div> : <div className="table-scroll"><table className="admin-table"><thead><tr><th scope="col">Document</th><th scope="col">Match</th><th scope="col">Status</th><th scope="col">Excerpt</th></tr></thead><tbody>{results.map(r => <tr key={r.artifactId}><td><Link href={`/documents/${r.artifactId}`}><strong>{r.title}</strong></Link><small>{[r.courseCode, r.academicYear, r.documentTypeLabel].filter(Boolean).join(" · ")}</small></td><td>{r.matchPercent ?? Math.round(r.relevanceScore * 100)}%<small title="Raw similarity from the vector search">raw {r.relevanceScore.toFixed(2)}</small></td><td>{r.reviewStatus && <span className={`status-badge ${r.reviewStatus}`}>{r.reviewStatus}</span>}</td><td><small>{(r.relevantSnippets[0] ?? "").slice(0, 220)}{(r.relevantSnippets[0]?.length ?? 0) > 220 ? "…" : ""}</small></td></tr>)}</tbody></table></div>)}
  </section></div>;
}
