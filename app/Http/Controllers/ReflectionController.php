<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\Reflection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        [$generatedContent, $generatedByAi] = $this->generateReflectionWithAi(
            userId: $request->user()->id,
            draftContent: $validated['content'],
            reflectionDate: $validated['reflection_date']
        );

        $reflection = $request->user()->reflections()->create([
            ...$validated,
            'content' => $generatedContent,
            'is_generated' => $generatedByAi,
        ]);

        return response()->json($reflection, 201);
    }

    /** @return array{string, bool} */
    private function generateReflectionWithAi(int $userId, string $draftContent, string $reflectionDate): array
    {
        $apiKey = config('services.openai.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return [$draftContent, false];
        }

        $model = config('services.openai.model', 'gpt-4.1-mini');
        $date = Carbon::parse($reflectionDate);
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);

        $entries = JournalEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('entry_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('entry_date')
            ->get();

        $entryLines = $entries->isEmpty()
            ? '- Sin entradas semanales registradas para este rango.'
            : $entries
                ->map(fn (JournalEntry $entry): string => sprintf('- [%s] %s', Carbon::parse($entry->entry_date)->toDateString(), trim($entry->content)))
                ->implode("\n");

        $systemPrompt = <<<'PROMPT'
Eres un coach emocional y redactas reflexiones personales en español.

Reglas:
- Mantén un tono cálido y práctico.
- Usa la nota del usuario como base principal, sin perder su intención original.
- Integra patrones observados de las entradas semanales si existen.
- No inventes datos.
- Devuelve solo texto plano, sin markdown.
PROMPT;

        $userPrompt = sprintf(
            "Fecha de reflexión: %s\nSemana analizada: %s a %s\n\nBorrador del usuario:\n%s\n\nEntradas de diario de la semana:\n%s",
            $date->toDateString(),
            $weekStart->toDateString(),
            $weekEnd->toDateString(),
            trim($draftContent),
            $entryLines
        );

        try {
            $response = Http::withToken($apiKey)
                ->timeout(25)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.7,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI reflection create failed', [
                    'status' => $response->status(),
                ]);

                return [$draftContent, false];
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            if ($content === '') {
                return [$draftContent, false];
            }

            return [$content, true];
        } catch (\Throwable $exception) {
            Log::warning('OpenAI reflection create exception', [
                'message' => $exception->getMessage(),
            ]);

            return [$draftContent, false];
        }
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