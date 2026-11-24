<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     *
     * Creates a STUDENT account (only @aun.edu.ng emails) and logs it in.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = new User([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'), // hashed by the model cast
            'university_id' => $request->validated('universityId'),
        ]);
        $user->forceFill(['role' => User::ROLE_STUDENT])->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Account created.',
            'data' => $this->tokenPayload($user, 'web'),
        ], 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Always run one hash check so the response time does not reveal
        // whether the email exists.
        $hash = $user?->password ?? '$2y$12$0000000000000000000000.000000000000000000000000000000';
        $passwordOk = Hash::check($request->validated('password'), $hash);

        if ($user === null || ! $passwordOk) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged in.',
            'data' => $this->tokenPayload($user, $request->validated('deviceName', 'web')),
        ]);
    }

    /**
     * POST /api/auth/logout  — revokes the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['status' => 'success', 'message' => 'Logged out.']);
    }

    /**
     * GET /api/auth/me  — who am I? (lets the frontend restore a session)
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['user' => $request->user()->toApiArray()],
        ]);
    }

    private function tokenPayload(User $user, string $deviceName): array
    {
        $expiresAt = now()->addMinutes(config('idr.auth.token_ttl_minutes'));
        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return [
            'user' => $user->toApiArray(),
            'token' => $token->plainTextToken,
            'tokenType' => 'Bearer',
            'expiresAt' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
