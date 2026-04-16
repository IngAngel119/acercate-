<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Quote::query()->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'author' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:2048'],
        ]);

        $quote = Quote::create($validated);

        return response()->json($quote, 201);
    }

    public function show(Quote $quote): JsonResponse
    {
        return response()->json($quote);
    }

    public function update(Request $request, Quote $quote): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['sometimes', 'string'],
            'author' => ['sometimes', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:2048'],
        ]);

        $quote->update($validated);

        return response()->json($quote->fresh());
    }

    public function destroy(Quote $quote): JsonResponse
    {
        $quote->delete();

        return response()->json(status: 204);
    }
}