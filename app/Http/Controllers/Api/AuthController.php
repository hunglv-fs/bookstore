<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->registerCustomer($request->validated());

        return $this->tokenResponse($user, $request->validated('device_name') ?? 'api', Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attemptLogin($request->validated('email'), $request->validated('password'));

        return $this->tokenResponse($user, $request->validated('device_name') ?? 'api');
    }

    public function logout(Request $request): Response
    {
        // Revoke only the token used for this request; other devices stay signed in.
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['role', 'customer']));
    }

    private function tokenResponse(User $user, string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'data' => [
                'token' => $this->auth->issueToken($user, $deviceName),
                'token_type' => 'Bearer',
                'user' => new UserResource($user->load(['role', 'customer'])),
            ],
        ], $status);
    }
}
