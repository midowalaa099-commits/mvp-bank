<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            try {
                $user = User::create([
                    ...$request->safe()->only(['name', 'email']),
                    'password' => Hash::make($request->validated('password')),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
            }

            return $this->tokenResponse($request, $user, 201);
        });
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = (new Timebox)->call(function () use ($credentials): User {
            $user = User::query()->where('email', $credentials['email'])->first();

            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid email or password.');
            }

            return $user;
        }, 200_000);

        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($credentials['password']);
            $user->save();
        }

        return $this->tokenResponse($request, $user);
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response()->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function tokenResponse(Request $request, User $user, int $status = 200): JsonResponse
    {
        $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken('api', ['*'], $expiresAt);

        return response()->json([
            'data' => [
                'user' => (new UserResource($user))->resolve($request),
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
