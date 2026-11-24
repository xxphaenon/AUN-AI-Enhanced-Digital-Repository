<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development data with FIXED ids, so the frontend (Member 1) and the
 * Postman collection can rely on them:
 *
 *   admin@aun.edu.ng    / Password123   (admin)
 *   student@aun.edu.ng  / Password123   (student)
 *
 * Documents are not seeded. Load the 20 sample theses with
 *
 *   php artisan db:seed                      (safe to re-run)
 *   php artisan idr:load-samples             (needs the queue worker running)
 */
class DatabaseSeeder extends Seeder
{
    public const ADMIN_ID = '66f000000000000000000001';

    public const STUDENT_ID = '66f000000000000000000002';

    public const DEV_PASSWORD = 'Password123';

    public function run(): void
    {
        $admin = $this->upsertById(User::class, self::ADMIN_ID, [
            'name' => 'Repository Admin', 'email' => 'admin@aun.edu.ng', 'password' => self::DEV_PASSWORD, 'role' => User::ROLE_ADMIN,
        ]);

        $student = $this->upsertById(User::class, self::STUDENT_ID, [
            'name' => 'Test Student', 'email' => 'student@aun.edu.ng', 'password' => self::DEV_PASSWORD, 'role' => User::ROLE_STUDENT,
        ]);

        $this->command?->info('Seeded accounts (password for both: '.self::DEV_PASSWORD.'):');
        $this->command?->table(['role', 'id', 'email'], [
            ['admin', (string) $admin->id, $admin->email],
            ['student', (string) $student->id, $student->email],
        ]);
    }

    /**
     * Create with a fixed _id, or update the existing document (without
     * touching _id, which MongoDB treats as immutable). Re-running is safe.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function upsertById(string $model, string $id, array $attributes)
    {
        $record = $model::find($id) ?? (new $model)->forceFill(['id' => $id]);
        $record->forceFill($attributes)->save();

        return $record;
    }
}
