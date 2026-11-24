<?php

namespace App\Http\Requests\Admin;

use App\Enums\DocumentType;
use App\Enums\ReviewStatus;
use App\Rules\AcademicYear;
use App\Rules\CourseCode;
use App\Rules\ObjectId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/documents?status=&uploadedBy=&uploadedFrom=&uploadedTo=
 *                         &filename=&courseCode=&academicYear=&q=&sort=&page=&perPage=
 *
 * Every filter is optional and they combine with AND.
 *   status        pending | approved | rejected
 *   uploadedBy    uploader's user id
 *   uploadedFrom  YYYY-MM-DD, inclusive (a day in Africa/Lagos time)
 *   uploadedTo    YYYY-MM-DD, inclusive
 *   filename      EXACT original file name, e.g. "CSC401-2024.pdf" (case-insensitive)
 *   courseCode    exact, e.g. "CSC 401"
 *   academicYear  exact, e.g. "2024/2025"
 *   documentType  project (Senior Design Project) | research (Senior Research Project)
 *   hardCopy      awaiting | overdue | received  (declared submissions' printed certificate)
 *   view          recent (published automatically in the last idr.review.recent_days days)
 *                 | flagged (held back by the AI check, waiting for an admin)
 *   q             quick search: part of the title, file name, course code or author
 *   sort          newest (default) | oldest | title
 */
class AdminDocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:admin middleware guards the route
    }

    protected function prepareForValidation(): void
    {
        foreach (['status', 'filename', 'q', 'courseCode', 'academicYear', 'uploadedBy', 'sort', 'hardCopy', 'view'] as $field) {
            if (is_string($this->query($field))) {
                $this->merge([$field => trim($this->query($field))]);
            }
        }

        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtolower($this->input('status'))]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(ReviewStatus::values())],
            'uploadedBy' => ['nullable', 'string', new ObjectId],
            'uploadedFrom' => ['nullable', 'date_format:Y-m-d'],
            'uploadedTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:uploadedFrom'],
            'filename' => ['nullable', 'string', 'max:255'],
            'courseCode' => ['nullable', 'string', new CourseCode],
            'academicYear' => ['nullable', 'string', new AcademicYear],
            'documentType' => ['nullable', Rule::enum(DocumentType::class)],
            'hardCopy' => ['nullable', Rule::in(['awaiting', 'overdue', 'received'])],
            'view' => ['nullable', Rule::in(['recent', 'flagged'])],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'title'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:'.config('idr.admin.max_per_page')],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('perPage') ?? config('idr.admin.default_per_page'));
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
