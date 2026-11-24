<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use App\Models\Artifact;
use App\Rules\AcademicYear;
use App\Rules\CourseCode;
use App\Rules\ObjectId;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/documents/upload  (multipart/form-data, logged-in users)
 *   file, title, courseCode, academicYear, authors[]?, author?, documentType?, uploaderId?
 *
 *   authors[i][name], authors[i][studentId]?: one entry per group member (up to 10).
 *   author: a single string, still accepted from older clients and split into names.
 *   documentType: project (Senior Design Project) | research (Senior Research Project)
 *   declarationAccepted, declarationName   required for students: they tick the
 *     declaration and type their full name exactly as on their account. Admins
 *     uploading on someone's behalf may leave both out.
 *
 * The uploader is ALWAYS the logged-in user. `uploaderId` is still accepted
 * for compatibility with the original contract, but it must be the caller's
 * own id (checked in the controller), otherwise the request is refused.
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['title', 'author', 'courseCode', 'academicYear', 'declarationName'] as $field) {
            if (is_string($this->input($field))) {
                $trimmed[$field] = trim(preg_replace('/\s+/', ' ', $this->input($field)));
            }
        }

        if (is_array($this->input('authors'))) {
            $trimmed['authors'] = array_values(array_map(fn ($a) => is_array($a) ? [
                'name' => is_string($a['name'] ?? null) ? trim(preg_replace('/\s+/', ' ', $a['name'])) : ($a['name'] ?? null),
                'studentId' => is_string($a['studentId'] ?? null) && trim($a['studentId']) !== '' ? strtoupper(trim($a['studentId'])) : null,
            ] : $a, $this->input('authors')));
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        $student = ! ($this->user()?->isAdmin() ?? false);

        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', config('idr.upload.mimes')),
                'mimetypes:'.implode(',', config('idr.upload.mimetypes')),
                'max:'.config('idr.upload.max_size_kb'),
            ],
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'courseCode' => ['required', 'string', new CourseCode],
            'academicYear' => ['required', 'string', new AcademicYear],
            'author' => ['nullable', 'string', 'min:2', 'max:255'],
            'authors' => ['nullable', 'array', 'min:1', 'max:'.Artifact::MAX_AUTHORS],
            'authors.*' => ['array'],
            'authors.*.name' => ['required', 'string', 'min:2', 'max:120', 'distinct:ignore_case'],
            'authors.*.studentId' => ['nullable', 'string', 'regex:/^[A-Z]?\d{5,10}$/'],
            'documentType' => ['nullable', Rule::enum(DocumentType::class)],
            'uploaderId' => ['nullable', 'string', new ObjectId],
            'declarationAccepted' => $student ? ['accepted'] : ['nullable', 'boolean'],
            'declarationName' => $student
                ? ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                    if (self::comparableName((string) $value) !== self::comparableName((string) $this->user()?->name)) {
                        $fail('Type your full name exactly as it appears on your account: '.$this->user()?->name.'.');
                    }
                }]
                : ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The declaration to store, or null when an admin uploads without one.
     *
     * @return array{name: string, university_id: ?string}|null
     */
    public function declaration(): ?array
    {
        if (! $this->boolean('declarationAccepted')) {
            return null;
        }

        return [
            'name' => (string) $this->user()->name,
            'university_id' => $this->user()->university_id,
        ];
    }

    /** "  ada   OBI " and "Ada Obi" are the same signature. */
    private static function comparableName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Only PDF files can be uploaded.',
            'file.mimetypes' => 'Only PDF files can be uploaded.',
            'documentType.enum' => 'Choose a document type from the list.',
            'declarationAccepted.accepted' => 'Tick the declaration to confirm this is your own work.',
            'declarationName.required' => 'Type your full name to sign the declaration.',
            'authors.max' => 'A document can list at most '.Artifact::MAX_AUTHORS.' authors.',
            'authors.*.name.required' => 'Enter a name for each author, or remove the empty row.',
            'authors.*.name.min' => 'Each author name needs at least 2 characters.',
            'authors.*.name.distinct' => 'This author is listed twice.',
            'authors.*.studentId.regex' => 'Student IDs look like A00021457.',
            'file.max' => 'The file may not be larger than '.round(config('idr.upload.max_size_kb') / 1024).' MB.',
            // PHP itself turned the file away, almost always because it is over
            // upload_max_filesize in php.ini (PHP's default is only 2M).
            'file.uploaded' => 'The server could not receive this file. It is probably larger than the server allows ('
                .ini_get('upload_max_filesize').'). Try a smaller PDF or ask the repository admin to raise the limit.',
        ];
    }

    /** @return array{title: string, authors: list<array{name: string, studentId: ?string}>, course_code: string, academic_year: string, document_type: ?string} */
    public function metadata(): array
    {
        return [
            'title' => $this->validated('title'),
            // Setting `authors` also sets the display string `author`.
            'authors' => $this->validated('authors') !== null
                ? Artifact::cleanAuthors($this->validated('authors'))
                : Artifact::parseAuthors($this->validated('author')),
            'course_code' => Artifact::normalizeCourseCode($this->validated('courseCode')),
            'academic_year' => Artifact::normalizeAcademicYear($this->validated('academicYear')),
            'document_type' => $this->validated('documentType') ?: null,
        ];
    }
}
