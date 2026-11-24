<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewStatus;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults to an APPROVED, not-yet-embedded document uploaded by a fresh
 * admin. Chain ->pending(), ->rejected(), ->embedded(), ->uploadedBy($user).
 *
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    protected $model = Artifact::class;

    public function definition(): array
    {
        return [
            'title' => Str::title(fake()->words(4, true)),
            'author' => fake()->name(),
            'course_code' => 'CSC '.fake()->numberBetween(100, 499),
            'academic_year' => '2024/2025',
            'document_type' => fake()->randomElement(DocumentType::cases()),
            'uploaded_by' => fn () => (string) User::factory()->admin()->create()->id,
            'status' => ReviewStatus::Approved,
            'reviewed_by' => fn (array $attributes) => $attributes['uploaded_by'],
            'reviewed_at' => now(),
            'rejection_reason' => null,
            'storage_disk' => 'local',
            'file_path' => 'artifacts/'.Str::uuid().'.pdf',
            'file_hash' => hash('sha256', Str::random(40)),
            'original_filename' => 'project.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4096,
            'processing_status' => ProcessingStatus::Pending,
            'chunks_processed' => 0,
        ];
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn () => ['uploaded_by' => (string) $user->id]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ReviewStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    public function rejected(string $reason = 'Wrong course code'): static
    {
        return $this->state(fn () => [
            'status' => ReviewStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }

    public function embedded(int $chunks = 10): static
    {
        return $this->state(fn () => [
            'processing_status' => ProcessingStatus::Embedded,
            'chunks_processed' => $chunks,
            'embedded_at' => now(),
        ]);
    }

    /** A student submission made with the declaration (has a submission code). */
    public function declared(?User $student = null): static
    {
        return $this->state(fn (array $attributes) => [
            'submission_code' => 'IDR-2026-'.strtoupper(Str::random(6)),
            'declared_name' => $student?->name ?? fake()->name(),
            'declared_university_id' => $student?->university_id ?? 'A000'.fake()->numberBetween(10000, 99999),
            'declaration_text' => 'I hereby confirm that the attached work is my own.',
            'declared_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Gemini API error'): static
    {
        return $this->state(fn () => [
            'processing_status' => ProcessingStatus::Failed,
            'processing_error' => $reason,
        ]);
    }
}
