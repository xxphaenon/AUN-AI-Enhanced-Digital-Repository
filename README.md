# AUN Digital Repository (IDR)

**AI-Enhanced Digital Repository for Senior Projects**, a Senior Design Project at the American University of Nigeria (School of Information Technology & Computing), November 2026.

A web platform where AUN students submit their Senior Design and Senior Research Projects, and anyone can search the published collection by meaning. A student signs a declaration when uploading; the system then compares the work with every earlier project, asks an AI model to confirm it is a senior project, and publishes it automatically if both checks pass, holding it for an administrator otherwise. A chat assistant answers questions from published documents and cites its sources.

| Folder | What it is |
|---|---|
| `idr-combined/` | Backend: Laravel 13 REST API (PHP 8.3), MongoDB, Redis queues, and the AI pipeline (Gemini embeddings and chat, Qdrant vector search). See its README for full set-up and API details. |
| `idr-frontend/` | Frontend: Next.js 16 / React 19 / TypeScript / Tailwind CSS 4 website, with a same-origin proxy to the API. |
| `start.bat` | Windows helper that starts the API, the queue worker and the website together for local use. |

## Running it locally (Windows)

You need PHP 8.3 with the MongoDB extension, Composer, MongoDB, Redis (or Memurai on Windows) and Node.js.

1. **Backend**, in `idr-combined/`:
   ```
   composer install
   copy .env.example .env
   php artisan key:generate
   php artisan migrate
   php artisan db:seed
   ```
   For the live AI features, add your own `GEMINI_API_KEY` and Qdrant settings to `.env` and run `php artisan idr:qdrant-setup`. Optionally load 20 sample theses with `php artisan idr:load-samples`.
2. **Frontend**, in `idr-frontend/`: `npm install` (only the first time).
3. Double-click **`start.bat`**, then open http://localhost:3000.

The seeded accounts `admin@aun.edu.ng` and `student@aun.edu.ng` use the password `Password123`.

## Tests

In `idr-combined/`, run `php artisan test` (233 test cases). The tests use built-in fake AI services, so no API keys are needed.

## Team

Almasi Victor Amos, Oluwapelumi Naseer Olayiwola and Magdalene Zakariya Jerry. Supervisor: Amir Muhammad Sa’ad, PhD.

Real settings files (`.env`) are not published. Never commit API keys.
