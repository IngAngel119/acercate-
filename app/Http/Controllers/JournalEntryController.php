<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->journalEntries()->latest('entry_date')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'entry_date' => ['required', 'date'],
        ]);

        $journalEntry = $request->user()->journalEntries()->create($validated);
        // Add +1 to user's record when a new journal entry is created
        $request->user()->increment('record');
        // If the user's entry is not continued from the previous day, reset the record to 1
        $yesterday = now()->subDay()->toDateString();
        if ($request->user()->journalEntries()->whereDate('entry_date', $yesterday)->doesntExist()) {
            $request->user()->update(['record' => 1]);
        }
        return response()->json($journalEntry, 201);
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->ensureOwnership($request, $journalEntry);

        return response()->json($journalEntry);
    }

    public function update(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->ensureOwnership($request, $journalEntry);

        $validated = $request->validate([
            'content' => ['sometimes', 'string'],
            'entry_date' => ['sometimes', 'date'],
        ]);

        $journalEntry->update($validated);

        return response()->json($journalEntry->fresh());
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->ensureOwnership($request, $journalEntry);

        $journalEntry->delete();

        return response()->json(status: 204);
    }

    private function ensureOwnership(Request $request, JournalEntry $journalEntry): void
    {
        if ($journalEntry->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException();
        }
    }
}