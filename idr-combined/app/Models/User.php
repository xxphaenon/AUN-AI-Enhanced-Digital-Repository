<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;
use MongoDB\Laravel\Relations\HasMany;

/**
 * Collection: users
 *
 * Two roles: "student" (everyone who registers) and "admin" (created with
 * `php artisan idr:make-admin` or promoted by another admin). The role is
 * never taken from registration input.
 *
 * @property string $id
 * @property string $name
 * @property string $email always stored lower-case
 * @property string $role "student" | "admin"
 * @property string|null $university_id student / staff ID number (optional)
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_STUDENT = 'student';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_STUDENT, self::ROLE_ADMIN];

    protected $connection = 'mongodb';

    protected $table = 'users';

    /** `role` is deliberately NOT fillable: it is only ever set with forceFill(). */
    protected $fillable = [
        'name',
        'email',
        'password',
        'university_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'role' => self::ROLE_STUDENT,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower(trim($value));
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'uploaded_by');
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'user_id');
    }

    /** Shape returned by /api/auth/* endpoints. */
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'universityId' => $this->university_id,
            'role' => $this->role,
            'createdAt' => Artifact::iso($this->created_at),
        ];
    }

    /** Short form embedded in admin listings (who uploaded what). */
    public function toSummaryArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'universityId' => $this->university_id,
        ];
    }
}
