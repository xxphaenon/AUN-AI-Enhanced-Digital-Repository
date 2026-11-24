<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MongoDB\BSON\Regex;

/**
 * /api/admin/users: see accounts and promote/demote between student and admin.
 */
class AdminUserController extends Controller
{
    /** GET /api/admin/users?role=&q=&page=&perPage= */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(User::ROLES)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['q'] ?? null, function ($q, $text) {
                $rx = new Regex(preg_quote(trim($text)), 'i');
                $q->where(fn ($w) => $w->where('name', 'regex', $rx)->orWhere('email', 'regex', $rx));
            })
            ->orderBy('created_at', 'desc');

        return response()->json($this->paginated(
            $query,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['perPage'] ?? 20),
            fn (User $u) => $u->toApiArray(),
        ));
    }

    /**
     * PATCH /api/admin/users/{userId}/role   { "role": "admin" | "student" }
     *
     * Admins cannot change their own role, so the last admin can't lock
     * everyone out by accident.
     */
    public function updateRole(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);

        $user = User::find($userId);
        abort_if($user === null, 404, 'User not found.');
        abort_if((string) $user->id === (string) $request->user()->id, 403, 'You cannot change your own role.');

        $user->forceFill(['role' => $data['role']])->save();

        return response()->json([
            'status' => 'success',
            'message' => "Role updated to {$data['role']}.",
            'data' => $user->toApiArray(),
        ]);
    }
}
