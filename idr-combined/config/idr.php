<?php

use App\Contracts\ChatResponder;
use App\Contracts\DocumentIngestor;
use App\Contracts\RetrievalService;
use App\Contracts\SimilarityChecker;
use App\Contracts\SubmissionScreener;
use App\Contracts\VectorIndex;
use App\Services\Ai\GeminiChatResponder;
use App\Services\Ai\GeminiDocumentIngestor;
use App\Services\Ai\QdrantRetrievalService;
use App\Services\Ai\GeminiSubmissionScreener;
use App\Services\Ai\QdrantSimilarityChecker;
use App\Services\Ai\QdrantVectorIndex;

/*
|--------------------------------------------------------------------------
| IDR (AI-Enhanced Digital Repository) settings
|--------------------------------------------------------------------------
|
| Everything the Member 3 backend layer needs to know that is specific to
| this project. Member 2's Gemini/Qdrant settings live in config/services.php.
|
*/

return [

    /*
    | Which implementation of the AI contracts to bind (App\Contracts\*).
    |
    |  - "fake": deterministic stand-ins (App\Services\Fake\*). Lets the
    |            frontend integrate end-to-end before the AI pipeline is ready.
    |  - "live": Member 2's real Gemini + Qdrant implementations, registered
    |            in the "live" map below. Any contract left out keeps its fake.
    */
    'ai_driver' => env('IDR_AI_DRIVER', 'fake'),

    'ai_bindings' => [
        'live' => [
            DocumentIngestor::class => GeminiDocumentIngestor::class,
            RetrievalService::class => QdrantRetrievalService::class,
            ChatResponder::class => GeminiChatResponder::class,
            VectorIndex::class => QdrantVectorIndex::class,
            SubmissionScreener::class => GeminiSubmissionScreener::class,
            SimilarityChecker::class => QdrantSimilarityChecker::class,
        ],
    ],

    'auth' => [
        // Accounts can only be registered with an address at exactly this domain
        // (no subdomains, no look-alikes). Compared case-insensitively.
        'allowed_email_domain' => env('IDR_ALLOWED_EMAIL_DOMAIN', 'aun.edu.ng'),

        // How long a login token stays valid. 10080 minutes = 7 days.
        'token_ttl_minutes' => (int) env('IDR_TOKEN_TTL_MINUTES', 10080),

        // Failed logins allowed per minute for one email + IP before a 429.
        'login_attempts_per_minute' => (int) env('IDR_LOGIN_ATTEMPTS_PER_MINUTE', 5),
    ],

    'storage' => [
        // Any disk from config/filesystems.php: "local" for dev, "s3" for prod.
        // The "local" disk lives in storage/app/private and is NOT web-accessible:
        // files only leave through the preview and download endpoints.
        'disk' => env('IDR_STORAGE_DISK', 'local'),
        'directory' => 'artifacts',
    ],

    'upload' => [
        // Kilobytes (Laravel's "max" rule unit). 20 MB default.
        'max_size_kb' => (int) env('IDR_UPLOAD_MAX_KB', 20480),
        'mimes' => ['pdf'],
        'mimetypes' => ['application/pdf'],
    ],

    'review' => [
        // Students' submissions always start as "pending". Uploads by an admin
        // are trusted and go straight to "approved" unless this is false.
        'auto_approve_admin_uploads' => (bool) env('IDR_AUTO_APPROVE_ADMIN_UPLOADS', true),

        // Students' submissions carry a signed declaration. When this is true they
        // are published automatically as soon as AI processing succeeds; the hard
        // copy handed in at the Dean's office is the human check that follows.
        // Files that fail processing stay "pending" for an admin.
        'auto_approve_submissions' => (bool) env('IDR_AUTO_APPROVE_SUBMISSIONS', true),

        // The admin page's "Recently published" view covers this many days.
        'recent_days' => (int) env('IDR_RECENT_DAYS', 7),
    ],

    'similarity' => [
        // Compare each declared submission with every other project in Qdrant
        // before it can be published automatically.
        'enabled' => (bool) env('IDR_SIMILARITY_CHECK', true),
        // Cosine score at which two ~500-word sections count as a close match
        // (copied or lightly reworded). Same-topic projects score well below.
        'section_score' => (float) env('IDR_SIMILARITY_SECTION_SCORE', 0.92),
        // Share of the submission's sections matching ONE earlier project at
        // which it is held for an admin (0.3 = 30%).
        'flag_overlap' => (float) env('IDR_SIMILARITY_FLAG_OVERLAP', 0.3),
        // Nearest sections fetched per section.
        'neighbours' => (int) env('IDR_SIMILARITY_NEIGHBOURS', 5),
    ],

    'screening' => [
        // Before auto-publishing, Gemini checks that the file looks like a senior
        // design / research project of the declared type. Unsure → stays pending.
        'enabled' => (bool) env('IDR_AI_SCREENING', true),
        // How much of the PDF's opening text the check reads.
        'excerpt_chars' => (int) env('IDR_AI_SCREENING_CHARS', 6000),
    ],

    'submission' => [
        // Days a student has to hand in the printed certificate and hard copy
        // before the submission shows as "overdue" on the admin page.
        'hard_copy_days' => (int) env('IDR_HARD_COPY_DAYS', 14),

        // The statement students accept on upload. :name is replaced with the
        // student's name; the final text is stored with the submission, so later
        // edits here never change what someone already signed.
        'declaration' => 'I, :name, hereby confirm that the attached work is my own original senior project, '
            .'that the details I have entered are correct, and that I am submitting it to the American University '
            .'of Nigeria Digital Repository. I understand that it will be published once processed, that I must hand '
            .'in a printed copy of this certificate with my hard copy at the Dean\'s office, and that the submission '
            .'may be removed if any of this is found to be untrue.',
        // Used when the submission lists more than one author (a group project).
        'declaration_group' => 'I, :name, on behalf of all the authors listed, hereby confirm that the attached work is our own '
            .'original senior project, that the details entered are correct, and that we are submitting it to the American '
            .'University of Nigeria Digital Repository. We understand that it will be published once processed, that a printed '
            .'copy of this certificate must be handed in with our hard copy at the Dean\'s office, and that the submission may '
            .'be removed if any of this is found to be untrue.',
    ],

    // Date filters ("uploaded on 2026-10-03") are read in this timezone, so a
    // day means a day in Nigeria. Stored timestamps are always UTC.
    'timezone' => env('IDR_TIMEZONE', 'Africa/Lagos'),

    'admin' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    'queue' => [
        'ingestion' => env('IDR_INGESTION_QUEUE', 'ingestion'),
        // Retries for keeping Qdrant in step with MongoDB (deletes, metadata).
        'vector_sync' => env('IDR_VECTOR_SYNC_QUEUE', 'default'),
    ],

    // Seconds a search or chat request may run (PHP's default is 30). The Gemini
    // and Qdrant timeouts in config/services.php stay below this.
    'ai_request_seconds' => (int) env('IDR_AI_REQUEST_SECONDS', 120),

    'search' => [
        'default_limit' => 5,
        'max_limit' => 20,
        // How many raw chunks to ask the retriever for per requested document,
        // so that grouping chunks by document still yields "limit" documents.
        'chunk_overfetch' => 4,
        'max_snippets_per_result' => 3,
        // Drop documents whose best chunk scores below this (raw cosine
        // similarity). Measured on the 20 sample theses with Gemini embeddings:
        // unrelated queries ("football tactics") top out around 0.57.
        'min_score' => (float) env('IDR_SEARCH_MIN_SCORE', 0.58),
        // How raw similarity becomes the "% match" shown to users (App\Support\MatchScore):
        // [raw score, percent] points, straight lines in between. Fitted to real
        // searches: the right paper scores ~0.65-0.78 raw (shown ~75-97%), loosely
        // related ones ~0.60-0.63 (shown ~20-55%).
        'match_scale' => [
            [0.58, 0],
            [0.60, 20],
            [0.65, 75],
            [0.70, 92],
            [0.78, 98],
            [0.85, 100],
        ],
    ],

    'chat' => [
        // Number of previous messages sent to the model as conversational memory.
        'history_window' => (int) env('IDR_CHAT_HISTORY_WINDOW', 20),
        // Number of retrieved chunks used to ground each answer.
        'context_chunks' => 6,
        // Chunks used when the question is asked on a document page (that document only).
        'document_context_chunks' => 8,
        'max_message_length' => 4000,
    ],

];
