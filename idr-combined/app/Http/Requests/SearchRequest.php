<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/search?q=how+does+neural+networking+work&limit=5&type=research
 *
 * type (optional): project (Senior Design Project) | research (Senior Research Project) | all
 */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge(['q' => trim(preg_replace('/\s+/', ' ', $this->query('q')))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('idr.search.max_limit')],
            'type' => ['sometimes', 'nullable', Rule::in([...DocumentType::values(), 'all'])],
        ];
    }

    public function searchQuery(): string
    {
        return (string) $this->input('q');
    }

    public function documentType(): ?DocumentType
    {
        $type = $this->input('type');

        return $type === null || $type === 'all' ? null : DocumentType::from($type);
    }

    public function limit(): int
    {
        return (int) $this->input('limit', config('idr.search.default_limit'));
    }
}
