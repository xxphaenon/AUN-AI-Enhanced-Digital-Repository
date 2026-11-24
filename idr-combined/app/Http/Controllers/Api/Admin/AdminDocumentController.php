<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminDocumentIndexRequest;
use App\Http\Requests\Admin\UpdateDocumentRequest;
use App\Http\Requests\Admin\UpdateDocumentStatusRequest;
use App\Models\Artifact;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use MongoDB\BSON\Regex;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything under /api/admin/documents. Routes are wrapped in
 * auth:sanctum + role:admin, so every method here can assume an admin.
 */
class AdminDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * GET /api/admin/documents
     *
     * Direct MongoDB query for the admin dashboard table. Filters: see
     * AdminDocumentIndexRequest. meta.counts gives the number of documents
     * per status under the same (non-status) filters, for tab badges.
     */
    public function index(AdminDocumentIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $base = $this->filteredQuery($filters);

        $counts = [];
        foreach (ReviewStatus::values() as $status) {
            $counts[$status] = (clone $base)->where('status', $status)->count();
        }

        $query = (clone $base)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with('uploader');

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'title' => $query->orderBy('title', 'asc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        return response()->json($this->paginated(
            $query,
            $request->page(),
            $request->perPage(),
            fn (Artifact $a) => $a->toAdminArray(),
            ['counts' => $counts],
        ));
    }

    /**
     * GET /api/admin/documents/pending
     *
     * The review queue: pending submissions, OLDEST first (first come,
     * first reviewed). Same filters as index().
     */
    public function pending(AdminDocumentIndexRequest $request): JsonResponse
    {
        $query = $this->filteredQuery($request->validated())
            ->where('status', ReviewStatus::Pending->value)
            ->with('uploader')
            ->orderBy('created_at', 'asc');

        return response()->json($this->paginated(
            $query,
            $request->page(),
            $request->perPage(),
            fn (Artifact $a) => $a->toAdminArray(),
        ));
    }

    /** GET /api/admin/documents/{artifactId} */
    public function show(string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);
        $artifact->load('uploader');

        return response()->json(['status' => 'success', 'data' => $artifact->toAdminArray()]);
    }

    /**
     * GET /api/admin/documents/by-code/{code}
     *
     * The Dean's office types the code printed on a student's certificate.
     * Spaces, dashes and letter case don't matter.
     */
    public function findByCode(string $code): JsonResponse
    {
        $artifact = Artifact::where('submission_code', Artifact::normalizeSubmissionCode($code))->first();

        abort_if($artifact === null, 404, 'No submission has this code. Check the code on the certificate.');

        return response()->json(['status' => 'success', 'data' => $artifact->load('uploader')->toAdminArray()]);
    }

    /**
     * POST /api/admin/documents/{artifactId}/hard-copy
     *
     * Record that the printed certificate and hard copy were handed in.
     */
    public function markHardCopyReceived(Request $request, string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);

        abort_unless($artifact->hasDeclaration(), 409, 'This document was not submitted with a declaration, so no hard copy is expected.');

        if ($artifact->hard_copy_received_at === null) {
            $this->documents->markHardCopyReceived($artifact, $request->user());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Hard copy recorded as received.',
            'data' => $artifact->load('uploader')->toAdminArray(),
        ]);
    }

    /** DELETE /api/admin/documents/{artifactId}/hard-copy: undo a confirmation made by mistake. */
    public function clearHardCopy(string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);
        $this->documents->clearHardCopy($artifact);

        return response()->json([
            'status' => 'success',
            'message' => 'Hard copy confirmation removed.',
            'data' => $artifact->load('uploader')->toAdminArray(),
        ]);
    }

    /**
     * GET /api/admin/documents/{artifactId}/preview
     *
     * Streams ANY file (pending, approved, rejected) inline so the admin can
     * read it before deciding. Inline + no-store means the browser shows it
     * rather than saving it, and nothing is cached or made public.
     */
    public function preview(string $artifactId): StreamedResponse
    {
        return $this->streamFile($this->findOrFail($artifactId), 'inline');
    }

    /**
     * PATCH /api/admin/documents/{artifactId}/status
     *   { "status": "approved" | "rejected", "reason"? }
     */
    public function updateStatus(UpdateDocumentStatusRequest $request, string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);
        $status = ReviewStatus::from($request->validated('status'));

        $vectorSync = $this->documents->review($artifact, $request->user(), $status, $request->validated('reason'));

        return response()->json([
            'status' => 'success',
            'message' => $status === ReviewStatus::Approved ? 'Document approved.' : 'Document rejected.',
            'data' => $artifact->load('uploader')->toAdminArray(),
            'vectorSync' => $vectorSync,
        ]);
    }

    /**
     * PATCH|PUT /api/admin/documents/{artifactId}
     *   { "title"?, "courseCode"?, "academicYear"?, "author"? }
     *
     * Updates MongoDB and patches the Qdrant payload without re-embedding.
     */
    public function update(UpdateDocumentRequest $request, string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);

        $vectorSync = $this->documents->updateMetadata($artifact, $request->changes());

        return response()->json([
            'status' => 'success',
            'message' => $vectorSync === 'unchanged' ? 'Nothing changed.' : 'Document updated.',
            'data' => $artifact->load('uploader')->toAdminArray(),
            'vectorSync' => $vectorSync,
        ]);
    }

    /**
     * DELETE /api/admin/documents/{artifactId}
     *
     * MongoDB record -> physical file -> Qdrant vectors. See
     * DocumentService::delete() for what each step reports.
     */
    public function destroy(string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);
        $steps = $this->documents->delete($artifact);

        $message = match (true) {
            $steps['file'] === 'failed' => 'Document deleted, but its file could not be removed from storage (logged for clean-up).',
            $steps['vectors'] === 'queued' => 'Document deleted. Its search vectors will be removed as soon as the AI service is reachable.',
            default => 'Document permanently deleted.',
        };

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => ['artifactId' => $artifactId, 'steps' => $steps],
        ]);
    }

    /**
     * POST /api/admin/documents/{artifactId}/reprocess
     *
     * Re-run AI ingestion, e.g. after a FAILED attempt.
     */
    public function reprocess(string $artifactId): JsonResponse
    {
        $artifact = $this->findOrFail($artifactId);

        abort_if($artifact->processing_status === ProcessingStatus::Pending, 409, 'This document is already waiting to be processed.');

        abort_unless($this->documents->reprocess($artifact), 503, 'Could not queue the document for processing. Is the queue (Redis) running?');

        return response()->json([
            'status' => 'success',
            'message' => 'Document queued for processing.',
            'data' => $artifact->toStatusPayload(),
        ], 202);
    }

    private function findOrFail(string $artifactId): Artifact
    {
        $artifact = Artifact::find($artifactId);

        abort_if($artifact === null, 404, 'Document not found.');

        return $artifact;
    }

    /** All filters except `status` (so status counts can reuse it). */
    private function filteredQuery(array $filters)
    {
        $tz = config('idr.timezone');

        return Artifact::query()
            ->when($filters['uploadedBy'] ?? null, fn ($q, $id) => $q->where('uploaded_by', $id))
            ->when($filters['uploadedFrom'] ?? null, fn ($q, $day) => $q->where(
                'created_at', '>=', Carbon::createFromFormat('Y-m-d', $day, $tz)->startOfDay()->utc()
            ))
            ->when($filters['uploadedTo'] ?? null, fn ($q, $day) => $q->where(
                'created_at', '<=', Carbon::createFromFormat('Y-m-d', $day, $tz)->endOfDay()->utc()
            ))
            // Exact file name, ignoring letter case.
            ->when($filters['filename'] ?? null, fn ($q, $name) => $q->where(
                'original_filename', 'regex', new Regex('^'.preg_quote($name).'$', 'i')
            ))
            ->when($filters['courseCode'] ?? null, fn ($q, $code) => $q->where('course_code', Artifact::normalizeCourseCode($code)))
            ->when($filters['academicYear'] ?? null, fn ($q, $year) => $q->where('academic_year', Artifact::normalizeAcademicYear($year)))
            ->when($filters['documentType'] ?? null, fn ($q, $type) => $q->ofType($type))
            ->when($filters['hardCopy'] ?? null, fn ($q, $state) => $q->hardCopy($state))
            ->when($filters['view'] ?? null, fn ($q, $view) => $q->adminView($view))
            ->when($filters['q'] ?? null, function ($q, $text) {
                $rx = new Regex(preg_quote($text), 'i');
                $q->where(fn ($w) => $w->where('title', 'regex', $rx)
                    ->orWhere('original_filename', 'regex', $rx)
                    ->orWhere('course_code', 'regex', $rx)
                    ->orWhere('author', 'regex', $rx));
            });
    }
}
