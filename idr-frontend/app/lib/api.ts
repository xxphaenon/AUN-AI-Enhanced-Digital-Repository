/**
 * Talks to the Laravel backend (idr-combined) through the same-origin proxy in
 * app/api/backend/[...path]/route.ts. Keep the base at /api/backend: the login
 * cookie only exists there.
 *
 * Every backend call has a typed function below, and the backend's field names
 * (title, courseCode, reviewStatus…) are translated here, in one place, into
 * the shapes the components use.
 */
const base = (process.env.NEXT_PUBLIC_API_BASE_URL || "/api/backend").replace(/\/$/, "");
export const apiUrl = (path: string) => `${base}${path}`;

export class ApiError extends Error {
  constructor(message: string, public status: number, public errors: Record<string, string[]> = {}) {
    super(message);
  }
  /** First message for a field from a 422 response, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

export async function request(path: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  if (!headers.has("Accept")) headers.set("Accept", "application/json");
  if (init.body && !(init.body instanceof FormData)) headers.set("Content-Type", "application/json");
  let response: Response;
  try {
    response = await fetch(apiUrl(path), { ...init, headers, credentials: "same-origin", cache: "no-store" });
  } catch (e) {
    if (e instanceof DOMException && e.name === "AbortError") throw e;
    throw new ApiError("Cannot reach the repository. Check your connection and try again.", 0);
  }
  if (!response.ok) throw failure(response.status, await response.json().catch(() => null));
  return response;
}

/** The ApiError for a failed response (shared by fetch and the upload request). */
function failure(status: number, data: Json | null): ApiError {
  if (status === 401 && typeof window !== "undefined") window.dispatchEvent(new Event("session-expired"));
  const fallback =
    status === 401 ? "Your session has expired. Please sign in again."
    : status === 403 ? "You do not have permission to perform this action."
    : status === 404 ? "This document could not be found."
    : status === 413 ? "The file is too large for the server to accept."
    : status === 429 ? "Too many attempts. Please wait a minute and try again."
    : "The repository service is unavailable. Please try again.";
  return new ApiError(data?.message || fallback, status, data?.errors ?? {});
}

/**
 * POST a form and report how much of it has been sent (0–1). fetch() cannot
 * report upload progress, so this uses XMLHttpRequest; errors match request().
 */
function postWithProgress(path: string, form: FormData, onProgress?: (fraction: number) => void): Promise<Json> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", apiUrl(path));
    xhr.setRequestHeader("Accept", "application/json");
    xhr.responseType = "text";
    xhr.upload.onprogress = e => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
    xhr.upload.onload = () => onProgress?.(1);
    xhr.onload = () => {
      let data: Json | null = null;
      try { data = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch { /* not JSON */ }
      if (xhr.status >= 200 && xhr.status < 300) resolve(data ?? {});
      else reject(failure(xhr.status, data));
    };
    xhr.onerror = () => reject(new ApiError("Cannot reach the repository. Check your connection and try again.", 0));
    xhr.onabort = () => reject(new DOMException("Upload cancelled", "AbortError"));
    xhr.send(form);
  });
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await request(path, init);
  return response.status === 204 ? (undefined as T) : response.json();
}

export function safeReturnPath(value: string | null, fallback = "/") {
  return value && value.startsWith("/") && !value.startsWith("//") && !/[\\\r\n]/.test(value) ? value : fallback;
}

/* ── Shared shapes ─────────────────────────────────────────────── */

export type Role = "student" | "admin";
export type ReviewStatus = "pending" | "approved" | "rejected";
export type ProcessingStatus = "PENDING" | "EMBEDDED" | "FAILED";

export const DOCUMENT_TYPES = [
  { value: "project", label: "Senior Design Project" },
  { value: "research", label: "Senior Research Project" },
] as const;
export type DocumentType = (typeof DOCUMENT_TYPES)[number]["value"];
export const documentTypeLabel = (value?: string | null) => DOCUMENT_TYPES.find(t => t.value === value)?.label;

/** The printed certificate + hard copy the student hands in at the Dean's office. */
export type HardCopyStatus = "not_required" | "awaiting" | "overdue" | "received";
export interface HardCopy { status: HardCopyStatus; dueBy: string | null; receivedAt: string | null; receivedBy?: string | null }
export const HARD_COPY_TEXT: Record<HardCopyStatus, string> = {
  not_required: "Not required",
  awaiting: "Hard copy awaited",
  overdue: "Hard copy overdue",
  received: "Hard copy received",
};

/** The statement students accept on upload (the backend stores its own copy of the exact text). Group projects get the "on behalf of all the authors" wording. */
export const declarationText = (name: string, group = false) => group
  ? `I, ${name}, on behalf of all the authors listed, hereby confirm that the attached work is our own original senior project, that the details entered are correct, and that we are submitting it to the American University of Nigeria Digital Repository. We understand that it will be published once processed, that a printed copy of this certificate must be handed in with our hard copy at the Dean's office, and that the submission may be removed if any of this is found to be untrue.`
  : `I, ${name}, hereby confirm that the attached work is my own original senior project, that the details I have entered are correct, and that I am submitting it to the American University of Nigeria Digital Repository. I understand that it will be published once processed, that I must hand in a printed copy of this certificate with my hard copy at the Dean's office, and that the submission may be removed if any of this is found to be untrue.`;

/** "  ada   OBI " and "Ada Obi" are the same signature (same rule as the backend). */
export const sameName = (a: string, b: string) => {
  const norm = (s: string) => s.trim().replace(/\s+/g, " ").toLowerCase();
  return norm(a) !== "" && norm(a) === norm(b);
};

/** One author of a document. Student IDs are only returned to the uploader, admins and on the certificate. */
export interface Author { name: string; studentId?: string | null }

/** Most authors a document can list (same limit as the backend). */
export const MAX_AUTHORS = 10;

/** ["Ada Obi", "Musa Bello", "Grace Eze"] → "Ada Obi, Musa Bello & Grace Eze" */
export const formatAuthors = (authors: Author[]) => {
  const names = authors.map(a => a.name).filter(Boolean);
  return names.length < 2 ? (names[0] ?? "") : `${names.slice(0, -1).join(", ")} & ${names[names.length - 1]}`;
};


export interface User { id: string; fullName: string; email: string; role: Role; universityId?: string | null }

/** One row of the admin table. */
export interface Paper {
  id: string;
  name: string;
  author: string | null;
  authors: Author[];
  course_code: string;
  academic_year: string;
  documentType: DocumentType | null;
  status: ReviewStatus;
  uploaded_by: string;
  uploaderEmail: string | null;
  created_at: string;
  processingStatus: ProcessingStatus;
  processingError: string | null;
  rejectionReason: string | null;
  submissionCode: string | null;
  hardCopy: HardCopy | null;
  autoApproved: boolean;
  /** The AI check before auto-publishing: accept | review | error. */
  aiScreening: { verdict: "accept" | "review" | "error"; reason: string | null; checkedAt: string | null } | null;
  /** Overlap with earlier projects (score = share 0–1 of sections closely matching one project). */
  similarity: Similarity | null;
}

export interface SimilarityMatch { artifactId: string; title: string | null; academicYear: string | null; overlap: number; matchedSections: number }
export interface Similarity { score: number | null; flagged: boolean; matches: SimilarityMatch[]; error: string | null; checkedAt: string | null }

/** A document as GET /documents/{id} returns it (fields depend on who asks). */
export interface DocumentDetails {
  id: string;
  title: string;
  author: string | null;
  authors: Author[];
  courseCode: string | null;
  academicYear: string | null;
  documentType: DocumentType | null;
  originalFilename: string | null;
  sizeBytes: number;
  createdAt: string | null;
  /** Only for the uploader and admins. */
  reviewStatus?: ReviewStatus;
  rejectionReason?: string | null;
  processingStatus?: ProcessingStatus;
  submissionCode?: string | null;
  hardCopy?: HardCopy | null;
}

export interface Certificate {
  id: string;
  submissionCode: string;
  title: string;
  author: string | null;
  authors: Author[];
  courseCode: string | null;
  academicYear: string | null;
  documentTypeLabel: string | null;
  originalFilename: string | null;
  fileHash: string | null;
  submittedAt: string | null;
  declaration: { name: string; universityId: string | null; text: string; acceptedAt: string | null };
  reviewStatus: ReviewStatus;
  hardCopy: HardCopy;
}

export interface SearchResult {
  artifactId: string;
  title: string;
  author: string | null;
  courseCode: string | null;
  academicYear: string | null;
  documentType: DocumentType | null;
  documentTypeLabel: string | null;
  /** Raw cosine similarity, 0-1. */
  relevanceScore: number;
  /** What to show as "% match": the raw score rescaled by the backend. */
  matchPercent: number;
  relevantSnippets: string[];
  reviewStatus?: ReviewStatus;
}

/* ── Backend → frontend translation ────────────────────────────── */

type Json = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

/** Older records (and the search API) only have the single author string. */
const toAuthors = (d: Json): Author[] => Array.isArray(d.authors) ? d.authors : d.author ? [{ name: d.author }] : [];

const toUser = (u: Json): User => ({ id: u.id, fullName: u.name, email: u.email, role: u.role, universityId: u.universityId ?? null });

const toPaper = (d: Json): Paper => ({
  id: d.artifactId,
  name: d.title,
  author: d.author ?? null,
  authors: toAuthors(d),
  course_code: d.courseCode ?? "",
  academic_year: d.academicYear ?? "",
  documentType: d.documentType ?? null,
  status: d.reviewStatus,
  uploaded_by: d.uploader?.name ?? d.uploadedBy ?? "Unknown",
  uploaderEmail: d.uploader?.email ?? null,
  created_at: d.createdAt ?? "",
  processingStatus: d.processingStatus,
  processingError: d.processingError ?? null,
  rejectionReason: d.rejectionReason ?? null,
  submissionCode: d.submissionCode ?? null,
  hardCopy: d.hardCopy ?? null,
  autoApproved: Boolean(d.autoApproved),
  aiScreening: d.aiScreening ?? null,
  similarity: d.similarity ?? null,
});

const toDetails = (d: Json): DocumentDetails => ({
  id: d.artifactId,
  title: d.title,
  author: d.author ?? null,
  authors: toAuthors(d),
  courseCode: d.courseCode ?? null,
  academicYear: d.academicYear ?? null,
  documentType: d.documentType ?? null,
  originalFilename: d.originalFilename ?? null,
  sizeBytes: d.sizeBytes ?? 0,
  createdAt: d.createdAt ?? null,
  reviewStatus: d.reviewStatus,
  rejectionReason: d.rejectionReason,
  processingStatus: d.processingStatus,
  submissionCode: d.submissionCode ?? null,
  hardCopy: d.hardCopy ?? null,
});

const post = (body: unknown, method = "POST"): RequestInit => ({ method, body: JSON.stringify(body) });

/* ── Accounts ──────────────────────────────────────────────────── */

export async function getMe(): Promise<User> {
  return toUser((await api<Json>("/auth/me")).data.user);
}

export async function login(email: string, password: string): Promise<User> {
  return toUser((await api<Json>("/auth/login", post({ email, password }))).data.user);
}

export async function register(input: { fullName: string; email: string; universityId: string; password: string; confirmPassword: string }): Promise<User> {
  const body = { name: input.fullName, email: input.email, universityId: input.universityId, password: input.password, password_confirmation: input.confirmPassword };
  return toUser((await api<Json>("/auth/register", post(body))).data.user);
}

export async function logout(): Promise<void> {
  await api("/auth/logout", { method: "POST" });
}

/** Backend validation field → form field name. */
export const AUTH_FIELD_MAP: Record<string, string> = { name: "fullName", email: "email", password: "password", password_confirmation: "confirmPassword", universityId: "universityId" };

/* ── Documents ─────────────────────────────────────────────────── */

/** onProgress gets the share of the file sent so far (0–1); at 1 the server is still saving and checking it. */
export async function uploadDocument(input: { file: File; title: string; authors: Author[]; courseCode: string; academicYear: string; documentType: string; declarationAccepted?: boolean; declarationName?: string }, onProgress?: (fraction: number) => void) {
  const form = new FormData();
  form.set("file", input.file);
  form.set("title", input.title);
  input.authors.forEach((author, i) => {
    form.set(`authors[${i}][name]`, author.name);
    if (author.studentId) form.set(`authors[${i}][studentId]`, author.studentId);
  });
  form.set("courseCode", input.courseCode);
  form.set("academicYear", input.academicYear);
  form.set("documentType", input.documentType);
  if (input.declarationAccepted) {
    form.set("declarationAccepted", "1");
    form.set("declarationName", input.declarationName ?? "");
  }
  const body = await postWithProgress("/documents/upload", form, onProgress);
  return { id: body.data.artifactId as string, reviewStatus: body.data.reviewStatus as ReviewStatus, message: body.message as string, submissionCode: (body.data.submissionCode ?? null) as string | null };
}

/** Backend upload field → form field name. */
export const UPLOAD_FIELD_MAP: Record<string, string> = { file: "file", title: "name", author: "authors", courseCode: "course_code", academicYear: "academic_year", documentType: "documentType", declarationAccepted: "declarationAccepted", declarationName: "declarationName" };

export async function getDocument(id: string): Promise<DocumentDetails> {
  return toDetails((await api<Json>(`/documents/${encodeURIComponent(id)}`)).data);
}

export async function getProcessingStatus(id: string) {
  const d = (await api<Json>(`/documents/${encodeURIComponent(id)}/status`)).data;
  return { processingStatus: d.processingStatus as ProcessingStatus, chunksProcessed: d.chunksProcessed as number, reviewStatus: d.reviewStatus as ReviewStatus, error: d.error as string | undefined, rejectionReason: d.rejectionReason as string | undefined };
}

export async function getCertificate(id: string): Promise<Certificate> {
  const d = (await api<Json>(`/documents/${encodeURIComponent(id)}/certificate`)).data;
  return {
    id: d.artifactId, submissionCode: d.submissionCode, title: d.title, author: d.author ?? null, authors: toAuthors(d),
    courseCode: d.courseCode ?? null, academicYear: d.academicYear ?? null, documentTypeLabel: d.documentTypeLabel ?? null,
    originalFilename: d.originalFilename ?? null, fileHash: d.fileHash ?? null, submittedAt: d.submittedAt ?? null,
    declaration: { name: d.declaration?.name ?? "", universityId: d.declaration?.universityId ?? null, text: d.declaration?.text ?? "", acceptedAt: d.declaration?.acceptedAt ?? null },
    reviewStatus: d.reviewStatus, hardCopy: d.hardCopy,
  };
}

export async function myDocuments(): Promise<DocumentDetails[]> {
  return (await api<Json>("/documents/mine?perPage=50")).data.map(toDetails);
}

/** File name from a Content-Disposition header, e.g. attachment; filename="x.pdf". */
export function filenameFrom(response: Response, fallback: string) {
  const header = response.headers.get("content-disposition") || "";
  const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
  if (star) return decodeURIComponent(star[1]);
  return /filename="?([^";]+)"?/i.exec(header)?.[1] || fallback;
}

/* ── Search & chat ─────────────────────────────────────────────── */

export async function search(query: string, type = "all", limit = 10, admin = false): Promise<SearchResult[]> {
  const params = new URLSearchParams({ q: query.trim(), limit: String(limit) });
  if (type && type !== "all") params.set("type", type);
  return (await api<Json>(`${admin ? "/admin" : ""}/search?${params}`)).results;
}

export interface ChatReply { sessionId: string; content: string; timestamp: string; sources: string[] }

/** documentId: asked from a document page, so the answer comes from that document only. */
export async function sendChat(message: string, sessionId?: string | null, documentId?: string | null): Promise<ChatReply> {
  const body = await api<Json>("/chat/message", post({ message, ...(sessionId ? { sessionId } : {}), ...(documentId ? { documentId } : {}) }));
  return { sessionId: body.sessionId, content: body.message.content, timestamp: body.message.timestamp, sources: body.sourcesReferenced ?? [] };
}

/* ── Admin ─────────────────────────────────────────────────────── */

/** Status filter values that select by hard copy instead of review status. */
export const HARD_COPY_FILTERS = { "hardcopy-awaiting": "awaiting", "hardcopy-overdue": "overdue", "hardcopy-received": "received" } as const;
/** Status filter values for the admin views: published automatically lately, or held back by the AI check. */
export const ADMIN_VIEWS = { "view-recent": "recent", "view-flagged": "flagged" } as const;

export async function listAdminDocuments(filters: { status: string; q: string }, signal?: AbortSignal) {
  const params = new URLSearchParams({ perPage: "100", sort: "newest" });
  const hardCopy = HARD_COPY_FILTERS[filters.status as keyof typeof HARD_COPY_FILTERS];
  const view = ADMIN_VIEWS[filters.status as keyof typeof ADMIN_VIEWS];
  if (view) params.set("view", view);
  else if (hardCopy) params.set("hardCopy", hardCopy);
  else if (filters.status && filters.status !== "all") params.set("status", filters.status);
  if (filters.q) params.set("q", filters.q);
  const body = await api<Json>(`/admin/documents?${params}`, { signal });
  return {
    papers: (body.data as Json[]).map(toPaper),
    total: body.meta.total as number,
    counts: body.meta.counts as Record<ReviewStatus, number>,
  };
}

export async function setReviewStatus(id: string, status: "approved" | "rejected", reason?: string) {
  return toPaper((await api<Json>(`/admin/documents/${encodeURIComponent(id)}/status`, post(reason ? { status, reason } : { status }, "PATCH"))).data);
}

export async function updateDocument(id: string, changes: { title: string; author: string; courseCode: string; academicYear: string; documentType: string }) {
  const body = { ...changes, author: changes.author || null, documentType: changes.documentType || null };
  return toPaper((await api<Json>(`/admin/documents/${encodeURIComponent(id)}`, post(body, "PATCH"))).data);
}

export async function deleteDocument(id: string) {
  await api(`/admin/documents/${encodeURIComponent(id)}`, { method: "DELETE" });
}

/** The Dean's office types the code printed on the student's certificate. */
export async function findByCode(code: string) {
  const clean = code.trim().replace(/\s+/g, "");
  return toPaper((await api<Json>(`/admin/documents/by-code/${encodeURIComponent(clean)}`)).data);
}

export async function setHardCopyReceived(id: string, received: boolean) {
  return toPaper((await api<Json>(`/admin/documents/${encodeURIComponent(id)}/hard-copy`, { method: received ? "POST" : "DELETE" })).data);
}

export async function reprocessDocument(id: string) {
  await api(`/admin/documents/${encodeURIComponent(id)}/reprocess`, { method: "POST" });
}
