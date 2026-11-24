<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Exceptions\DuplicateDocumentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Artifact;
use App\Models\User;
use App\Rules\AcademicYear;
use App\Rules\CourseCode;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MongoDB\BSON\Regex;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoints for students and guests. Admin-only actions live in
 * Api\Admin\AdminDocumentController.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * GET /api/documents?courseCode=&academicYear=&q=&page=&perPage=
     *
     * Public catalogue: APPROVED documents only, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'courseCode' => ['nullable', 'string', new CourseCode],
            'academicYear' => ['nullable', 'string', new AcademicYear],
            'documentType' => ['nullable', Rule::enum(DocumentType::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Artifact::approved()
            ->ofType($filters['documentType'] ?? null)
            ->when($filters['courseCode'] ?? null, fn ($q, $v) => $q->where('course_code', Artifact::normalizeCourseCode($v)))
            ->when($filters['academicYear'] ?? null, fn ($q, $v) => $q->where('academic_year', Artifact::normalizeAcademicYear($v)))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $rx = new Regex(preg_quote(trim($v)), 'i');
                $q->where(fn ($w) => $w->where('title', 'regex', $rx)
                    ->orWhere('course_code', 'regex', $rx)
                    ->orWhere('author', 'regex', $rx));
            })
            ->orderBy('created_at', 'desc');

        return response()->json($this->paginated(
            $query,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['perPage'] ?? 20),
            fn (Artifact $a) => $a->toPublicArray(),
        ));
    }

    /**
     * GET /api/documents/mine  (logged in)
     *
     * The caller's own submissions in every state, with review outcome.
     */
    public function mine(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Artifact::where('uploaded_by', (string) $request->user()->id)->orderBy('created_at', 'desc');

        return response()->json($this->paginated(
            $query,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['perPage'] ?? 20),
            fn (Artifact $a) => $a->toOwnerArray(),
        ));
    }

    /**
     * POST /api/documents/upload  (logged in, multipart/form-data)
     *
     * Save the file -> create the record (student: pending, admin: approved)
     * -> queue AI ingestion -> 202. Never waits on the AI pipeline.
     */
    public function upload(UploadDocumentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Contract compatibility: a client may still send uploaderId, but it
        // can only ever be the logged-in user.
        $claimed = $request->validated('uploaderId');
        abort_if($claimed !== null && $claimed !== (string) $user->id, 403, 'uploaderId must be your own user id.');

        try {
            $artifact = $this->documents->submit($user, $request->file('file'), $request->metadata(), $request->declaration());
        } catch (DuplicateDocumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'This exact file has already been submitted.',
                'data' => $e->existing->isVisibleTo($user)
                    ? ['artifactId' => (string) $e->existing->id, 'reviewStatus' => $e->existing->status->value]
                    : null,
            ], 409);
        }

        abort_unless(
            $this->documents->queueIngestion($artifact),
            503,
            'The document was saved but could not be queued for processing. An admin can reprocess it later.'
        );

        $certificate = ' Print your submission certificate and hand it in with your hard copy at the Dean\'s office.';

        return response()->json([
            'status' => 'success',
            'message' => match (true) {
                $artifact->isApproved() => 'File uploaded and queued for processing.',
                $artifact->hasDeclaration() && config('idr.review.auto_approve_submissions') => 'Submitted. It will be published as soon as it has been processed.'.$certificate,
                $artifact->hasDeclaration() => 'Submitted for review.'.$certificate,
                default => 'File submitted for review. It will be visible to others once an admin approves it.',
            },
            'data' => [
                'artifactId' => (string) $artifact->id,
                'processingStatus' => $artifact->processing_status->value,
                'reviewStatus' => $artifact->status->value,
                'submissionCode' => $artifact->submission_code,
            ],
        ], 202);
    }

    /**
     * GET /api/documents/{artifactId}
     *
     * Approved: anyone. Pending/rejected: only the uploader or an admin
     * (everyone else gets 404, so its existence isn't revealed).
     */
    public function show(Request $request, string $artifactId): JsonResponse
    {
        $user = $request->user('sanctum');
        $artifact = $this->findVisible($artifactId, $user);

        return response()->json(['status' => 'success', 'data' => $artifact->toArrayFor($user)]);
    }

    /**
     * GET /api/documents/{artifactId}/status  (logged in: uploader or admin)
     *
     * Polled by the frontend until processingStatus is EMBEDDED or FAILED.
     */
    public function status(Request $request, string $artifactId): JsonResponse
    {
        $user = $request->user();
        $artifact = Artifact::find($artifactId);

        abort_if($artifact === null || ! ($artifact->isOwnedBy($user) || $user->isAdmin()), 404, 'Document not found.');

        return response()->json(['status' => 'success', 'data' => $artifact->toStatusPayload()]);
    }

    /**
     * GET /api/documents/{artifactId}/certificate  (logged in: uploader or admin)
     *
     * The details printed on the submission certificate the student hands in
     * at the Dean's office. Only declared submissions have one.
     */
    public function certificate(Request $request, string $artifactId): JsonResponse
    {
        $user = $request->user();
        $artifact = Artifact::find($artifactId);

        abort_if($artifact === null || ! ($artifact->isOwnedBy($user) || $user->isAdmin()), 404, 'Document not found.');
        abort_unless($artifact->hasDeclaration(), 404, 'This document was not submitted with a declaration, so it has no certificate.');

        return response()->json(['status' => 'success', 'data' => $artifact->toCertificateArray()]);
    }

    /**
     * GET /api/documents/{artifactId}/preview
     *
     * Shows the PDF inline (Content-Disposition: inline). Open to everyone
     * for APPROVED files, so guests can read before signing in. The
     * uploader can also preview their own pending file by sending their token.
     * Admins reviewing submissions use /api/admin/documents/{id}/preview.
     */
    public function preview(Request $request, string $artifactId): StreamedResponse
    {
        $artifact = $this->findVisible($artifactId, $request->user('sanctum'));

        return $this->streamFile($artifact, 'inline');
    }

    /**
     * GET /api/documents/{artifactId}/download  (logged in)
     *
     * Content-Disposition: attachment. Requires a valid, unexpired token:
     * guests get 401 and the frontend shows "Sign in to Download".
     */
    public function download(Request $request, string $artifactId): StreamedResponse
    {
        $artifact = $this->findVisible($artifactId, $request->user());

        return $this->streamFile($artifact, 'attachment');
    }

    private function findVisible(string $artifactId, ?User $user): Artifact
    {
        $artifact = Artifact::find($artifactId);

        abort_if($artifact === null || ! $artifact->isVisibleTo($user), 404, 'Document not found.');

        return $artifact;
    }
}
