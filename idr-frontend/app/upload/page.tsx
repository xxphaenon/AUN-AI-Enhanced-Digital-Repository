"use client";
import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useAuth } from "../components/AuthProvider";
import MySubmissions from "../components/MySubmissions";
import UploadProgress from "../components/UploadProgress";
import { ApiError, DOCUMENT_TYPES, MAX_AUTHORS, UPLOAD_FIELD_MAP, declarationText, sameName, uploadDocument } from "../lib/api";

interface AuthorRow { name: string; studentId: string }

const MAX_MB = 20;

export default function UploadPage() {
  const { user, loading, error, refresh } = useAuth();
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<{ fraction: number; name: string; size: number } | null>(null);
  const [failure, setFailure] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [success, setSuccess] = useState<{ id: string; message: string; code: string | null } | null>(null);
  const [submitted, setSubmitted] = useState(0);
  const [agreed, setAgreed] = useState(false);
  const [signature, setSignature] = useState("");
  // Every member of the group. A student is listed first; an admin uploads for others, so starts blank.
  const [authors, setAuthors] = useState<AuthorRow[]>([]);
  const firstRow = (): AuthorRow[] => [user && user.role !== "admin" ? { name: user.fullName, studentId: user.universityId ?? "" } : { name: "", studentId: "" }];
  useEffect(() => { if (user && authors.length === 0) setAuthors(firstRow()); }, [user]); // eslint-disable-line react-hooks/exhaustive-deps
  const listed = authors.filter(a => a.name.trim());
  const group = listed.length > 1;
  const setAuthor = (i: number, field: keyof AuthorRow, value: string) => setAuthors(rows => rows.map((row, j) => j === i ? { ...row, [field]: value } : row));
  // Students sign the declaration; admins uploading on someone's behalf don't have to.
  const needsDeclaration = user?.role !== "admin";
  const signed = !needsDeclaration || (agreed && sameName(signature, user?.fullName ?? ""));

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!user || busy) return;
    const form = event.currentTarget;
    const data = new FormData(form);
    const value = (name: string) => String(data.get(name) || "").trim();
    for (const field of ["name", "course_code", "academic_year", "documentType"]) {
      if (!value(field)) { setFailure("Please complete every document field."); return; }
    }
    if (authors.some(a => a.name.trim().length < 2)) { setFailure("Enter a full name for each author, or remove the empty row."); return; }
    const file = data.get("file");
    if (!(file instanceof File) || file.size === 0 || !file.name.toLowerCase().endsWith(".pdf")) { setFailure("Select a non-empty PDF file."); return; }
    if (file.size > MAX_MB * 1024 * 1024) { setFailure(`The file is larger than ${MAX_MB} MB.`); return; }
    if (!signed) { setFailure("Tick the declaration and type your full name exactly as on your account."); return; }
    setBusy(true); setFailure(""); setFieldErrors({}); setSuccess(null);
    setProgress({ fraction: 0, name: file.name, size: file.size });
    try {
      // The backend takes the uploader from the session; nothing about the user is sent.
      const result = await uploadDocument({
        file, title: value("name"), authors: authors.map(a => ({ name: a.name.trim(), studentId: a.studentId.trim() || null })), courseCode: value("course_code"),
        academicYear: value("academic_year"), documentType: value("documentType"),
        declarationAccepted: needsDeclaration, declarationName: signature,
      }, fraction => setProgress(p => p && { ...p, fraction }));
      setSuccess({ id: result.id, message: result.message, code: result.submissionCode });
      setSubmitted(n => n + 1);
      form.reset();
      setAgreed(false);
      setSignature("");
      setAuthors(firstRow());
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const mapped: Record<string, string> = {};
        // "authors.1.name" stays as is: the error shows under that row.
        for (const [key, messages] of Object.entries(e.errors)) mapped[UPLOAD_FIELD_MAP[key] ?? key] = messages[0];
        setFieldErrors(mapped);
        setFailure("Please correct the highlighted fields.");
      } else {
        setFailure(e instanceof Error ? e.message : "Upload failed. Please retry.");
      }
    } finally { setBusy(false); setProgress(null); }
  }

  const fieldError = (name: string) => fieldErrors[name] && <span className="auth-field-error" id={`${name}-error`}>{fieldErrors[name]}</span>;
  const invalid = (name: string) => ({ "aria-invalid": Boolean(fieldErrors[name]), "aria-describedby": fieldErrors[name] ? `${name}-error` : undefined });

  return <div className="repository-page"><Link href="/">← Back to search</Link><header className="repository-heading"><p className="eyebrow">CONTRIBUTE TO THE REPOSITORY</p><h1>Upload Document</h1><p>Submit your Senior Design or Senior Research Project. It is published once processed; then hand in the printed submission certificate with your hard copy at the Dean’s office.</p></header>
    {loading ? <p role="status">Checking session…</p> : !user ? <section className="repository-card"><h2>Sign in to submit a document</h2><p>Your submission will be linked to your university account.</p><Link className="repo-button primary" href="/login?next=%2Fupload">Sign in to Upload</Link>{error && <p role="alert">{error} <button onClick={() => void refresh()}>Retry</button></p>}</section> : <form className="repository-card repo-form" onSubmit={submit} noValidate>
      <p>Submitting as <strong>{user.fullName}</strong> · {user.email}</p>
      <fieldset disabled={busy}>
        <label>Document title<input name="name" required maxLength={255} placeholder="e.g. Smart Irrigation Using IoT Sensors" {...invalid("name")} />{fieldError("name")}</label>
        <fieldset className="authors-box">
          <legend>Author{authors.length > 1 ? "s" : ""}</legend>
          <p className="authors-hint">Group project? Add every member. Student IDs are optional and only appear on the certificate.</p>
          {authors.map((author, i) => (
            <div className="author-row" key={i}>
              <label>{authors.length > 1 ? `Author ${i + 1}` : "Full name"}
                <input value={author.name} onChange={e => setAuthor(i, "name", e.target.value)} required maxLength={120} placeholder="e.g. Ada Obi" autoComplete="off" {...invalid(`authors.${i}.name`)} />
                {fieldError(`authors.${i}.name`)}
              </label>
              <label>Student ID <span className="optional">(optional)</span>
                <input value={author.studentId} onChange={e => setAuthor(i, "studentId", e.target.value.toUpperCase())} maxLength={12} placeholder="A00012345" autoComplete="off" {...invalid(`authors.${i}.studentId`)} />
                {fieldError(`authors.${i}.studentId`)}
              </label>
              {authors.length > 1 && <button className="author-remove" type="button" aria-label={`Remove author ${i + 1}`} onClick={() => setAuthors(rows => rows.filter((_, j) => j !== i))}>Remove</button>}
            </div>
          ))}
          {fieldError("authors")}
          {authors.length < MAX_AUTHORS && <button className="repo-button author-add" type="button" onClick={() => setAuthors(rows => [...rows, { name: "", studentId: "" }])}>+ Add author</button>}
        </fieldset>
        <div className="form-columns">
          <label>Course code<input name="course_code" required maxLength={40} placeholder="e.g. CSC 499" {...invalid("course_code")} />{fieldError("course_code")}</label>
          <label>Academic year<input name="academic_year" required placeholder="2025/2026" title="Use the format 2025/2026" {...invalid("academic_year")} />{fieldError("academic_year")}</label>
        </div>
        <label>Document type<select name="documentType" defaultValue="project" {...invalid("documentType")}>{DOCUMENT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}</select>{fieldError("documentType")}</label>
        <label>Select file<input type="file" name="file" required accept=".pdf,application/pdf" {...invalid("file")} />{fieldError("file")}</label>
        <p>PDF only, up to {MAX_MB} MB. {user.role === "admin" ? "As an administrator, your upload is published straight away." : "Your project is published once it has been processed. Then print your submission certificate and hand it in with your hard copy at the Dean's office."}</p>
        {needsDeclaration && <fieldset className="declaration-box" aria-describedby="declaration-text">
          <legend>Declaration</legend>
          <p id="declaration-text">{declarationText(user.fullName, group)}</p>
          <label className="declaration-check"><input type="checkbox" name="declarationAccepted" checked={agreed} onChange={e => setAgreed(e.target.checked)} {...invalid("declarationAccepted")} /> <span>I agree to this declaration</span></label>
          {fieldError("declarationAccepted")}
          <label>Type your full name to sign<input name="declarationName" autoComplete="off" value={signature} onChange={e => setSignature(e.target.value)} placeholder={user.fullName} {...invalid("declarationName")} /></label>
          {fieldError("declarationName")}
          {signature && !sameName(signature, user.fullName) && !fieldErrors.declarationName && <span className="auth-field-error">Type your name exactly as on your account: {user.fullName}</span>}
        </fieldset>}
        {progress && <UploadProgress fraction={progress.fraction} fileName={progress.name} fileSize={progress.size} />}
        <button className="repo-button primary" type="submit" disabled={!signed || busy}>{busy ? "Submitting…" : user.role === "admin" ? "Upload" : "Sign and submit"}</button>
      </fieldset>
      {failure && <p className="feedback-error" role="alert">{failure}</p>}
      {success && <div className="feedback-success" role="status"><p>{success.message}</p>{success.code && <p>Your submission code is <strong className="submission-code">{success.code}</strong>.</p>}<p>{success.code && <><Link className="repo-button primary" href={`/documents/${success.id}/certificate`}>Print submission certificate</Link>{" "}</>}<Link href={`/documents/${success.id}`}>View your submission →</Link></p></div>}
    </form>}
    {user && <MySubmissions refreshKey={submitted} />}
  </div>;
}
