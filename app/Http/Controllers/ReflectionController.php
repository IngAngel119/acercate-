<?php

namespace App\Http\Controllers;

use App\Models\Reflection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReflectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->reflections()->latest('reflection_date')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'reflection_date' => ['required', 'date'],
        ]);

        $reflection = $request->user()->reflections()->create([
            ...$validated,
            'is_generated' => false,
        ]);

        return response()->json($reflection, 201);
    }

    public function show(Request $request, Reflection $reflection): JsonResponse
    {
        $this->ensureOwnership($request, $reflection);

        return response()->json($reflection);
    }

    public function update(Request $request, Reflection $reflection): JsonResponse
    {
        $this->ensureOwnership($request, $reflection);

        $validated = $request->validate([
            'content' => ['sometimes', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'reflection_date' => ['sometimes', 'date'],
        ]);

        $reflection->update($validated);

        return response()->json($reflection->fresh());
    }

    public function destroy(Request $request, Reflection $reflection): JsonResponse
    {
        $this->ensureOwnership($request, $reflection);

        $reflection->delete();

        return response()->json(status: 204);
    }

    private function ensureOwnership(Request $request, Reflection $reflection): void
    {
        if ($reflection->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException();
        }
    }
}