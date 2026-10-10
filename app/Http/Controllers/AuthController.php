<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Username + password session auth for the SPA. Every response carries a
 * fresh CSRF token because logging in or out regenerates the session.
 */
class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return $this->sessionResponse($request);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return $this->sessionResponse($request, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('name', 'password'), remember: true)) {
            throw ValidationException::withMessages(['name' => 'Those credentials do not match our records.']);
        }

        $request->session()->regenerate();

        return $this->sessionResponse($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->sessionResponse($request);
    }

    private function sessionResponse(Request $request, int $status = 200): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user ? UserResource::make($user) : null,
            'active_match_id' => $user?->activeMatch()?->id,
            'csrf_token' => $request->session()->token(),
        ], $status);
    }
}
