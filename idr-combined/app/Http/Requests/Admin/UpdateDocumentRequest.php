<?php

namespace App\Http\Requests\Admin;

use App\Enums\DocumentType;
use App\Models\Artifact;
use App\Rules\AcademicYear;
use App\Rules\CourseCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH|PUT /api/admin/documents/{artifactId}
 *   { "title"?, "courseCode"?, "academicYear"?, "author"?, "authors"?, "documentType"? }
 *
 * author (one string) is split into names; authors ([{name, studentId?}]) replaces the list.
 *
 * Send only the fields being corrected; at least one is required.
 */
class UpdateDocumentRequest extends FormRequest
{
    private const FIELDS = ['title', 'courseCode', 'academicYear', 'author', 'authors', 'documentType'];

    public function authorize(): bool
    {
        return true; // role:admin middleware guards the route
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (self::FIELDS as $field) {
            if ($field !== 'authors' && is_string($this->input($field))) {
                $trimmed[$field] = trim(preg_replace('/\s+/', ' ', $this->input($field)));
            }
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'courseCode' => ['sometimes', 'required', 'string', new CourseCode],
            'academicYear' => ['sometimes', 'required', 'string', new AcademicYear],
            'author' => ['sometimes', 'nullable', 'string', 'min:2', 'max:255'],
            'authors' => ['sometimes', 'array', 'max:'.Artifact::MAX_AUTHORS],
            'authors.*' => ['array'],
            'authors.*.name' => ['required', 'string', 'min:2', 'max:120', 'distinct:ignore_case'],
            'authors.*.studentId' => ['nullable', 'string', 'regex:/^[A-Za-z]?\d{5,10}$/'],
            'documentType' => ['sometimes', 'nullable', Rule::enum(DocumentType::class)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('title', 'Send at least one of: '.implode(', ', self::FIELDS).'.');
                }
            },
        ];
    }

    /** Validated changes, as MongoDB field => value. */
    public function changes(): array
    {
        $data = $this->validated();
        $changes = [];

        if (array_key_exists('title', $data)) {
            $changes['title'] = $data['title'];
        }
        if (array_key_exists('courseCode', $data)) {
            $changes['course_code'] = Artifact::normalizeCourseCode($data['courseCode']);
        }
        if (array_key_exists('academicYear', $data)) {
            $changes['academic_year'] = Artifact::normalizeAcademicYear($data['academicYear']);
        }
        if (array_key_exists('author', $data)) {
            $changes['author'] = $data['author'] ?: null;
        }
        if (array_key_exists('authors', $data)) {
            $changes['authors'] = Artifact::cleanAuthors($data['authors']); // after `author`, so the list wins
        }
        if (array_key_exists('documentType', $data)) {
            $changes['document_type'] = $data['documentType'] ?: null;
        }

        return $changes;
    }
}
