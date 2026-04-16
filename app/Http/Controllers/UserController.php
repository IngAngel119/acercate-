<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $this->ensureCurrentUser($request, $user);

        return response()->json($user->load(['journalEntries', 'reflections']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureCurrentUser($request, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'record' => ['sometimes', 'integer', 'min:0'],
        ]);

        $user->update($validated);

        return response()->json($user->fresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureCurrentUser($request, $user);

        $user->currentAccessToken()?->delete();
        $user->delete();

        return response()->json(status: 204);
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureCurrentUser(Request $request, User $user): void
    {
        if ($request->user()?->id !== $user->id) {
            throw new AuthorizationException('You are not allowed to access this user.');
        }
    }
}