# IDR Backend: AI-Enhanced Digital Repository for Senior Projects

The Laravel API for the repository. This folder is **Member 3's layer (Backend & Data Infrastructure)**:
accounts and login, roles, the MongoDB schema, file storage, the review workflow (pending → approved/rejected),
secure preview and download, the admin endpoints, Redis queues, and chat history.

This is the **combined** project: the backend above plus the AI pipeline (Gemini embeddings + Qdrant vector
search), which plugs in through four small interfaces in `app/Contracts/` and lives in `app/Services/Ai/`.
`IDR_AI_DRIVER=live` uses the real AI; `IDR_AI_DRIVER=fake` switches back to built-in stand-ins (tests use these).

| Layer | Tech |
|---|---|
| Framework | Laravel 13, PHP 8.3+ |
| Auth | Laravel Sanctum bearer tokens, stored in MongoDB |
| Database | MongoDB via `mongodb/laravel-mongodb` 5.11+ |
| Queues | Redis + Laravel Horizon (`ingestion` and `default` queues) |
| Files | Laravel Storage, private disk (`storage/app/private`); never publicly reachable |

---

## 1. Rules the backend enforces

| Rule | Where |
|---|---|
| Accounts can only be created with an address at exactly **@aun.edu.ng** (no subdomains, no look-alikes like `aun.edu.ng.evil.com`; case-insensitive) | `App\Rules\AllowedEmailDomain`, `RegisterRequest` |
| Everyone who registers is a **student**. A `role` sent at sign-up is ignored | `AuthController::register` |
| Admins are created with `php artisan idr:make-admin`, or promoted by another admin | `MakeAdmin` command, `AdminUserController` |
| Passwords: at least 8 characters, letters and numbers, stored hashed (bcrypt) | `RegisterRequest`, `User` cast |
| Login is throttled: 5 attempts a minute per email + IP | `AppServiceProvider` |
| Tokens expire after 7 days; logout revokes the token; only a SHA-256 hash of each token is stored | `config/idr.php`, Sanctum |
| A student's upload starts **pending**; an admin's upload is approved straight away | `DocumentService::submit` |
| Students and guests only ever see **approved** files: listing, search, chat, preview and download. This is re-checked in MongoDB even if the vector store returns something else | `Artifact::isVisibleTo`, `SearchService`, `ChatService` |
| Unapproved files return **404**, not 403, so their existence isn't revealed | `DocumentController::findVisible` |
| **Download** needs a valid token; **preview** of approved files is open to guests | routes, `DocumentController` |
| Files are only PDFs (the content is checked, not just the extension), at most 20 MB | `UploadDocumentRequest` |
| The exact same file can't be submitted twice while pending or approved (SHA-256 check) | `DocumentService::submit` |
| Every admin route returns 401 for guests and 403 for students. Roles are re-read on each request, so a demotion takes effect immediately | `EnsureUserHasRole` |

## 2. Endpoints

Logged-in requests send `Authorization: Bearer <token>`, using the token from register or login.

### Accounts

| Method | Path | Who | What |
|---|---|---|---|
| POST | `/api/auth/register` | anyone | `name, email, password, password_confirmation, universityId?` → 201 `{ user, token, tokenType, expiresAt }` |
| POST | `/api/auth/login` | anyone | `email, password` → `{ user, token, tokenType, expiresAt }` |
| POST | `/api/auth/logout` | logged in | revokes the current token |
| GET | `/api/auth/me` | logged in | `{ user }`, so the frontend can restore a session on reload |

### Documents (students and guests)

| Method | Path | Who | What |
|---|---|---|---|
| GET | `/api/documents` | anyone | approved catalogue, newest first. Filters: `courseCode`, `academicYear`, `documentType`, `q`, `page`, `perPage` |
| GET | `/api/documents/{id}` | anyone* | one document's details |
| GET | `/api/documents/{id}/preview` | anyone* | streams the PDF **inline** for viewing |
| GET | `/api/documents/{id}/download` | logged in* | streams the PDF as an **attachment**. Guests get 401, so the UI shows "Sign in to Download" |
| POST | `/api/documents/upload` | logged in | multipart: `file, title, courseCode, academicYear, author?, documentType?`, plus for students `declarationAccepted=1` and `declarationName` (their account name) → 202 with `submissionCode` |
| GET | `/api/documents/{id}/certificate` | uploader / admin | details for the printable submission certificate (declared submissions only) |
| GET | `/api/documents/mine` | logged in | my submissions in every state, with `reviewStatus` and `rejectionReason` |
| GET | `/api/documents/{id}/status` | uploader / admin | `processingStatus`, `chunksProcessed`, `reviewStatus` (+ `error` / `rejectionReason`) |
| GET | `/api/search?q=&limit=&type=` | anyone | semantic search over approved files; `type` limits it to one document type |
| POST | `/api/chat/message` | logged in | `{ message, sessionId? }`; only approved files are cited |
| GET | `/api/chat/sessions` | logged in | my conversations |
| GET | `/api/chat/sessions/{id}` | logged in | one conversation's messages |

\* Approved files: anyone. Pending or rejected files: only the uploader or an admin; everyone else gets 404.

### Admin (role `admin` only)

| Method | Path | What |
|---|---|---|
| GET | `/api/admin/documents` | dashboard table. Filters: `status`, `uploadedBy`, `uploadedFrom`, `uploadedTo` (YYYY-MM-DD, Lagos time), `filename` (exact, case-insensitive), `courseCode`, `academicYear`, `documentType`, `hardCopy` (`awaiting`/`overdue`/`received`), `view` (`recent`/`flagged`), `q` (quick search), `sort` (`newest`/`oldest`/`title`), `page`, `perPage`. `meta.counts` gives `{pending, approved, rejected}` for tab badges |
| GET | `/api/admin/documents/pending` | the review queue, oldest first |
| GET | `/api/admin/documents/{id}` | full details, including uploader name and email |
| GET | `/api/admin/documents/{id}/preview` | streams **any** file inline (pending, approved or rejected) so it can be read before deciding |
| PATCH | `/api/admin/documents/{id}/status` | `{ status: "approved" \| "rejected", reason? }`; the reason is shown to the student |
| PATCH / PUT | `/api/admin/documents/{id}` | edit `title`, `courseCode`, `academicYear`, `author`, `documentType` (send only what changes). Updates Qdrant without re-embedding |
| DELETE | `/api/admin/documents/{id}` | permanent, 3 steps: MongoDB record → file in storage → Qdrant vectors |
| POST | `/api/admin/documents/{id}/reprocess` | re-run AI ingestion (e.g. after FAILED) |
| GET | `/api/admin/documents/by-code/{code}` | find a submission from the code on its printed certificate (case, spaces and dashes ignored) |
| POST / DELETE | `/api/admin/documents/{id}/hard-copy` | record (or undo) that the certificate and hard copy were handed in at the Dean's office |
| GET | `/api/admin/search?q=` | semantic search with `admin_mode`: includes pending and rejected files, each tagged with `reviewStatus` |
| GET | `/api/admin/users?role=&q=` | list accounts |
| PATCH | `/api/admin/users/{id}/role` | `{ role: "admin" \| "student" }` (not your own) |

### Response shapes

```jsonc
// single item
{ "status": "success", "data": { ... } }
// lists
{ "status": "success", "data": [ ... ], "meta": { "page": 1, "perPage": 20, "total": 57, "lastPage": 3 } }
// errors (errors{} only on 422)
{ "status": "error", "message": "…", "errors": { "field": ["…"] } }
```

A document as an admin sees it (students see a subset; guests see the first block only):

```jsonc
{
  "artifactId": "6ac1…", "title": "Compilers Final Exam", "author": null,
  "courseCode": "CSC 401", "academicYear": "2024/2025",
  "originalFilename": "CSC401-Exam-2025.pdf", "sizeBytes": 183402, "createdAt": "2026-10-03T19:20:23Z",
  "links": { "preview": ".../api/documents/6ac1…/preview", "download": ".../download", "adminPreview": ".../api/admin/documents/6ac1…/preview" },
  // uploader + admin
  "reviewStatus": "pending", "rejectionReason": null, "reviewedAt": null,
  "processingStatus": "EMBEDDED", "chunksProcessed": 35,
  // admin only
  "uploadedBy": "66f0…02", "uploader": { "id": "66f0…02", "name": "Test Student", "email": "student@aun.edu.ng" },
  "reviewedBy": null, "filePath": "artifacts/2026/10/<uuid>.pdf", "storageDisk": "local",
  "mimeType": "application/pdf", "fileHash": "<sha256>", "processingError": null, "processingAttempts": 1,
  "embeddedAt": "…", "updatedAt": "…"
}
```

| Code | When |
|---|---|
| 401 | No token, or an invalid, expired or revoked one |
| 403 | Logged in but not allowed (student on an admin route; `uploaderId`/`userId` that isn't yours) |
| 404 | Doesn't exist, or you're not allowed to know it exists |
| 409 | Duplicate file upload; reprocessing a file that's already queued |
| 422 | Validation failed |
| 429 | Rate limited |
| 502 | The AI tier failed (search/chat); nothing is saved |
| 503 | File saved, but the queue (Redis) is down; the file is marked FAILED and can be reprocessed |

### Submission declaration and hard copy

1. A student ticks the declaration and types their full name (it must match their account) when uploading. The backend stores the exact statement, their name, Student ID and the time, and gives the submission a code such as `IDR-2026-7KQ4MX`.
2. The student prints the certificate (`/documents/{id}/certificate` in the frontend) and hands it in with the hard copy at the Dean's office.
3. With `IDR_AUTO_APPROVE_SUBMISSIONS=true` (default) the submission is published as soon as AI processing succeeds **and** the AI check (`App\Contracts\SubmissionScreener`, Gemini in live mode) says it clearly looks like a senior design or research project of the declared type. If the check is unsure, gives an unclear answer or can't run, the file stays pending with the reason shown to admins ("Flagged by AI check"). Files that fail processing also stay pending. Turn the check off with `IDR_AI_SCREENING=false`.
   Before that, the **similarity check** (`App\Contracts\SimilarityChecker`, Qdrant in live mode) compares each ~500-word section of the submission with the sections of every other project already stored for search. A section counts as a close match at a cosine score of `IDR_SIMILARITY_SECTION_SCORE` (default 0.92: copied or lightly reworded text; same-topic projects score lower). If `IDR_SIMILARITY_FLAG_OVERLAP` (default 30%) or more of the submission's sections closely match ONE earlier project, it stays pending and appears under "Flagged by AI check" with that project linked; lower overlaps are shown to admins as a similarity score. It only compares against this repository (not the internet), and a flag is a prompt for a human, not proof of plagiarism. Turn it off with `IDR_SIMILARITY_CHECK=false`.
   Admins can spot-check everything published automatically in the last `IDR_RECENT_DAYS` days (default 7) with the "Recently published" view (`GET /api/admin/documents?view=recent`); `view=flagged` lists what the AI check held back.
4. The Dean's office looks up the code on the admin page and marks the hard copy as received. After `IDR_HARD_COPY_DAYS` (default 14) without one, it shows as overdue; admins can still reject at any time.

## 3. MongoDB schema

**users**: `name`, `email` (unique, lower-case), `password` (bcrypt), `role` (`student` | `admin`), `university_id` (optional student/staff ID), timestamps.

**artifacts**

| Field | Notes |
|---|---|
| `title`, `author`, `course_code`, `academic_year`, `document_type` | editable by admins (`course_code` stored as `"CSC 401"`, `academic_year` as `"2024/2025"`, `document_type` is `project` (Senior Design Project) or `research` (Senior Research Project)) |
| `status` | `pending` \| `approved` \| `rejected` (API name: `reviewStatus`) |
| `uploaded_by` | user id of the submitter |
| `reviewed_by`, `reviewed_at`, `rejection_reason` | set when an admin decides |
| `file_path`, `storage_disk` | where the PDF physically is (private disk) |
| `original_filename`, `mime_type`, `size_bytes`, `file_hash` | `file_hash` = SHA-256, used for the duplicate check |
| `processing_status`, `chunks_processed`, `processing_error`, `processing_attempts`, `embedded_at` | AI pipeline state (`PENDING` \| `EMBEDDED` \| `FAILED`) |

Indexes: `status+created_at`, `uploaded_by+created_at`, `original_filename`, `course_code`, `file_hash`, unique `file_path`.

**personal_access_tokens**: Sanctum tokens (hash only), with `expires_at` and `last_used_at`.
**chat_sessions**: as before, keyed by `user_id`.

The migration `2026_10_03_000002_update_artifacts_for_review_workflow` upgrades existing data in place:
`uploader_id → uploaded_by`, `storage_path → file_path`, and existing files are marked `approved`, since
admins uploaded them.

## 4. Setup (Windows, no Docker)

You need MongoDB Community Server, Memurai (Redis for Windows), and PHP 8.3+ with the `mongodb` extension.
Section 7 has the first-time install steps.

**Upgrading from the previous version** (you already ran it once):

```
composer update laravel/sanctum
php artisan migrate
php artisan db:seed
```

`composer update laravel/sanctum` installs the one new package, Sanctum. `migrate` adds the tokens collection and
upgrades existing documents. `db:seed` updates the test accounts to the new @aun.edu.ng addresses.

**Turning on the real AI (one time, in this combined project):**

```
composer install
php artisan idr:qdrant-setup
php artisan idr:reindex
```

`.env` needs `IDR_AI_DRIVER=live`, `GEMINI_API_KEY`, `QDRANT_URL` (Qdrant Cloud: no port, so it uses 443) and
`QDRANT_API_KEY`. `idr:reindex` only queues the work; the queue worker below does the embedding.

**Every time you work on it**, in two terminals:

```
php artisan serve
php artisan queue:work redis --queue=ingestion,default --timeout=600
```

**Test accounts** (password `Password123` for both):

| Role | Email | id |
|---|---|---|
| admin | `admin@aun.edu.ng` | `66f000000000000000000001` |
| student | `student@aun.edu.ng` | `66f000000000000000000002` |

The seed data creates only these two accounts. **Sample documents:** `php artisan idr:load-samples` loads 20
fictional senior projects and research papers from `database/samples` (approved, queued for embedding; keep the
queue worker running). Add `--fresh` to permanently delete every existing document first (it asks to confirm;
accounts and chats are kept).

**Make a real admin:** `php artisan idr:make-admin someone@aun.edu.ng --name="Dr. Bello"`. It asks for a password,
or promotes the account if it already exists.

**Tests:** `php artisan test` runs 165 tests against a separate database, `idr_testing`.

**Postman:** import `IDR-Backend.postman_collection.json`. Run *Login as admin* and *Login as student* first;
they store the tokens for every other request.

## 5. Notes for teammates

### Frontend (Next.js, `idr-frontend`)

The frontend is connected: it calls this API through its own same-origin proxy (`app/api/backend/[...path]/route.ts`),
which keeps the login token in an HttpOnly cookie and adds `Authorization: Bearer …` for each request. See
`FRONTEND-INTEGRATION.md` in that folder. Because every request then arrives from `127.0.0.1`, `bootstrap/app.php`
trusts the `X-Forwarded-For` header from `TRUSTED_PROXIES` (default `127.0.0.1,::1`) so rate limits stay per user.
Calling the API directly (Postman, mobile app) works exactly as below.

- **Session:** call register or login, keep `data.token`, and send it as `Authorization: Bearer <token>` on every
  request. Call `GET /api/auth/me` on page load to restore the session. A **401** anywhere means the token is
  missing or expired, so send the user to login.
- **Register errors:** domain errors come back as 422 with the message
  `"Only @aun.edu.ng email addresses can be used to create an account."` under `errors.email`.
- **Uploads:** the backend takes the uploader from the token. `courseCode` and `academicYear` are required;
  `documentType` is `project` (Senior Design Project) or `research` (Senior Research Project). Older documents with a removed type were cleared by the `2026_11_16_000001_limit_document_types` migration; run `php artisan idr:sync-vectors` after migrating.
- **Admin table:** `GET /api/admin/documents?status=pending&q=…&page=…`; the tab badges are `meta.counts`.
  Approve or reject with `PATCH …/status`, edit with `PATCH …/{id}`, delete with `DELETE …/{id}`.

### AI pipeline (Gemini + Qdrant), `app/Services/Ai/`

| Class | Implements | What it does |
|---|---|---|
| `GeminiDocumentIngestor` | `DocumentIngestor` | PDF → text (smalot/pdfparser) → 500-word chunks, 100-word overlap → Gemini embeddings (batched) → Qdrant. Deletes the file's old points first, so retries never duplicate |
| `QdrantRetrievalService` | `RetrievalService` | embeds the query, searches Qdrant. Students/guests: filter `is_approved: true`. Admin mode: no filter |
| `QdrantVectorIndex` | `VectorIndex` | `deleteArtifact` (all points of a file) and `updateArtifactMetadata` (Qdrant *set payload*, no re-embedding; keeps `is_approved` consistent with `status`) |
| `GeminiChatResponder` | `ChatResponder` | answers only from the retrieved approved chunks, numbers them `[1] [2]…`, and reports the documents it actually cited |
| `GeminiClient`, `QdrantClient` | | the only classes that call the two APIs (REST via Laravel's HTTP client) |

Every Qdrant point carries `Artifact::vectorMetadata()` (`artifactId`, `title`, `author`, `course_code`,
`academic_year`, `status`, `is_approved`) plus `chunkIndex` and `text`. Settings are in `config/services.php`
(`gemini`, `qdrant`, `chunking`).

**Commands**

| Command | When |
|---|---|
| `php artisan idr:qdrant-setup` | once: creates the collection (3072-dim, cosine, vector `content`) and the payload indexes the filters need. `--fresh` deletes and recreates it |
| `php artisan idr:reindex` | after switching to live, or changing the embedding model: queues every document for embedding. `--failed` for FAILED ones only |
| `php artisan idr:sync-vectors` | safety net: re-sends status/title/course from MongoDB to Qdrant. `--prune` also removes vectors of deleted files |

## 6. Where things are

```
app/
  Contracts/                 ← the AI interfaces (implemented in Services/Ai, faked in Services/Fake)
    DocumentIngestor.php       ingest(Artifact): int
    RetrievalService.php       retrieve(query, limit, artifactIds[], adminMode)
    ChatResponder.php          respond(message, history[], context[])
    VectorIndex.php            deleteArtifact(id), updateArtifactMetadata(id, metadata)
  Console/Commands/          MakeAdmin, QdrantSetup, ReindexArtifacts, SyncVectorMetadata
  Enums/                     ProcessingStatus (PENDING|EMBEDDED|FAILED), ReviewStatus (pending|approved|rejected)
  Http/Controllers/Api/      AuthController, DocumentController, SearchController, ChatController
  Http/Controllers/Api/Admin AdminDocumentController, AdminUserController
  Http/Middleware/EnsureUserHasRole.php     role:admin
  Http/Requests/             validation for every endpoint (Auth/, Admin/)
  Jobs/                      ProcessArtifactIngestion, SyncArtifactVectorMetadata, DeleteArtifactVectors
  Models/                    User, Artifact, ChatSession, PersonalAccessToken
  Rules/                     AllowedEmailDomain, CourseCode, AcademicYear, ObjectId
  Services/                  DocumentService (lifecycle), VectorSync (Qdrant sync + retries),
                             SearchService, ChatService, Fake/,
                             Ai/ (GeminiClient, QdrantClient, the four live implementations)
config/idr.php               project settings (email domain, token lifetime, auto-approve, limits…)
config/sanctum.php           pure bearer-token mode
routes/api.php               every route, grouped public / logged in / admin
tests/                       165 tests: Auth/, Admin/, documents, search, chat, ingestion, rules
```

## 7. First-time install (Windows, no Docker)

1. Install **MongoDB Community Server** (MSI, "Install as a Service") and **Memurai Developer**.
2. Add the PHP MongoDB extension: download `php_mongodb.dll` for your PHP version (x64, TS or NTS as shown by
   `php -i | findstr "Thread"`) from https://github.com/mongodb/mongo-php-driver/releases, put it in PHP's `ext`
   folder, and add `extension=mongodb` to `php.ini`. Check it with `php -m | findstr mongodb`.
3. In `php.ini`, set `upload_max_filesize=25M` and `post_max_size=30M`, and enable `fileinfo`, `openssl`,
   `mbstring`, `curl` and `zip`.
4. In this folder:
   ```
   copy .env.example .env
   composer install
   php artisan key:generate
   php artisan migrate
   php artisan db:seed
   ```

`.env` is already set up for this: Redis goes through Predis (no PHP Redis extension needed) and sessions are
stored in files. Horizon's Linux-only requirements are declared as present in `composer.json` so it installs on
Windows; only `php artisan horizon` itself won't run there, so use `queue:work`. Docker users can follow
`docker-compose.yml` instead.

## 8. Known limits and next steps

- **Email ownership isn't verified.** The domain rule guarantees an @aun.edu.ng *address*, but not that the
  person owns that mailbox. Adding Laravel's email verification (a link sent by email) closes this; it needs an
  SMTP account (AUN's mail server, or Mailtrap while developing).
- **Password reset** isn't built yet. It also needs email.
- **Preview vs download:** an inline preview can still be saved by a determined user from the browser's PDF
  viewer. The download restriction is a deliberate UX gate (as the spec describes), not DRM.
