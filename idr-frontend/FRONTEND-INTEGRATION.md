# Frontend ↔ backend integration

This Next.js app talks to the Laravel API in `idr-combined` (auth, documents, review workflow, AI search and chat).

## Running it

1. Start the backend (in `idr-combined`): `php artisan serve` and `php artisan queue:work redis --queue=ingestion,default --timeout=600`.
2. Start the frontend (here): `npm install` once, then `npm run dev`, and open http://localhost:3000.

No `.env.local` is needed when Laravel runs on `http://127.0.0.1:8000`. If it runs elsewhere, set `IDR_API_URL` (server-side only, never `NEXT_PUBLIC_`) in `.env.local`, e.g. `IDR_API_URL=http://127.0.0.1:8080`.

Test accounts (from `php artisan db:seed`, password `Password123`): `admin@aun.edu.ng` (admin) and `student@aun.edu.ng` (student). Admins are sent to `/admin` after signing in.

## How requests flow

```
browser ──► /api/backend/<path>   (app/api/backend/[...path]/route.ts, same origin)
              └─► ${IDR_API_URL}/api/<path>   (Laravel)
```

- Laravel issues a Sanctum token at login/registration. The proxy stores it in an **HttpOnly cookie** (`idr_session`, `SameSite=Lax`, path `/api/backend`), strips it from the JSON, and adds `Authorization: Bearer …` to every later call. Page JavaScript never sees the token.
- Logout, or any 401 from Laravel, clears the cookie. Writes from another site are refused (`Origin` / `Sec-Fetch-Site` check).
- Because the browser only talks to Next.js, Laravel needs no CORS settings for the frontend. Laravel trusts the client IP the proxy forwards from `127.0.0.1` (see `TRUSTED_PROXIES` in `bootstrap/app.php`), so rate limits apply per student.
- Keep `NEXT_PUBLIC_API_BASE_URL` unset (default `/api/backend`): pointing the browser straight at Laravel would bypass the cookie.

## Where things are

| File | Role |
| --- | --- |
| `app/api/backend/[...path]/route.ts` | the proxy described above |
| `app/lib/api.ts` | one typed function per backend call; translates backend field names (`title`, `courseCode`, `reviewStatus`, `{status, data, meta}` envelopes) into the shapes the components use |
| `app/components/AuthProvider.tsx` | session state from `GET /auth/me` |
| `app/components/useChat.ts` | chat state shared by `/chat` and the side assistant panel |

## Screens and the endpoints they use

| Screen | Endpoints |
| --- | --- |
| Log in / Sign up | `POST /auth/login`, `POST /auth/register` (`name`, `email`, `universityId`, `password`, `password_confirmation`), `GET /auth/me`, `POST /auth/logout` |
| Home search | `GET /search?q=&type=&limit=`: AI search over approved documents; `type` = project (Senior Design Project) or research (Senior Research Project) |
| Upload (+ “Your submissions”) | `POST /documents/upload` (multipart: `file`, `title`, `author`, `courseCode`, `academicYear`, `documentType`, and for students `declarationAccepted`, `declarationName`), `GET /documents/mine` |
| Submission certificate (`/documents/{id}/certificate`, printable) | `GET /documents/{id}/certificate` (uploader/admin) |
| Document page | `GET /documents/{id}`, `GET /documents/{id}/status` (uploader/admin), `GET /documents/{id}/preview`, `GET /documents/{id}/download` (signed in) |
| Chat + assistant panel | `POST /chat/message` (`message`, `sessionId?`), signed in; sources link to `/documents/{id}` |
| Admin | `GET /admin/documents?status=&q=`, `PATCH /admin/documents/{id}/status` (`status`, `reason?`), `PATCH /admin/documents/{id}`, `DELETE /admin/documents/{id}`, `POST /admin/documents/{id}/reprocess`, `GET /admin/documents/{id}/preview`, `GET /admin/search?q=`; Dean’s office hard copy check: `GET /admin/documents/by-code/{code}`, `POST`/`DELETE /admin/documents/{id}/hard-copy`, list filter `hardCopy=awaiting|overdue|received` |

Validation errors (422) come back per field and are shown under the matching input. Everything the UI hides is also enforced by the backend: students get 403 on admin routes, and unapproved documents are 404 to everyone except their uploader and admins.

`/admin?preview=1` (development only) still shows the admin table with sample data, without a backend.
