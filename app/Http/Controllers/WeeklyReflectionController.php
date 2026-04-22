<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Models\Reflection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeeklyReflectionController extends Controller
{
    // ─── POST /api/reflections/weekly/generate ───────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_start_date' => ['nullable', 'date'],
            'image' => ['nullable', 'string', 'max:2048'],
        ]);

        [$weekStart, $weekEnd] = $this->resolveWeekRange($validated['week_start_date'] ?? null);

        $entries = $this->weekEntries($request->user()->id, $weekStart, $weekEnd);

        if ($entries->isEmpty()) {
            return response()->json([
                'message' => 'No journal entries were found for that week.',
            ], 422);
        }

        // Check if a reflection already exists for this week
        $existingReflection = Reflection::query()
            ->where('user_id', $request->user()->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->where('week_end_date', $weekEnd->toDateString())
            ->first();

        $content = $this->buildReflectionContentWithAi($entries, $weekStart, $weekEnd);

        // Only update if it was created today, otherwise create a new one
        if ($existingReflection && $existingReflection->created_at->toDateString() === today()->toDateString()) {
            $existingReflection->update([
                'content' => $content,
                'image' => $validated['image'] ?? null,
                'reflection_date' => $weekEnd->toDateString(),
                'is_generated' => true,
            ]);
            $reflection = $existingReflection;
            $statusCode = 200;
        } else {
            $reflection = Reflection::create([
                'user_id' => $request->user()->id,
                'week_start_date' => $weekStart->toDateString(),
                'week_end_date' => $weekEnd->toDateString(),
                'content' => $content,
                'image' => $validated['image'] ?? null,
                'reflection_date' => $weekEnd->toDateString(),
                'is_generated' => true,
            ]);
            $statusCode = 201;
        }

        return response()->json($reflection, $statusCode);
    }

    // ─── GET /api/reflections/weekly/current ─────────────────────────────────

    public function current(Request $request): JsonResponse
    {
        [$weekStart, $weekEnd] = $this->resolveWeekRange(null);

        $reflection = Reflection::query()
            ->where('user_id', $request->user()->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->where('week_end_date', $weekEnd->toDateString())
            ->first();

        if (! $reflection) {
            return response()->json([
                'message' => 'No reflection generated for the current week yet.',
                'week_start_date' => $weekStart->toDateString(),
                'week_end_date' => $weekEnd->toDateString(),
            ], 404);
        }

        return response()->json($reflection);
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    /** @return array{Carbon, Carbon} */
    private function resolveWeekRange(?string $startDate): array
    {
        $weekStart = $startDate
            ? Carbon::parse($startDate)->startOfWeek(Carbon::MONDAY)
            : now()->startOfWeek(Carbon::MONDAY);

        return [$weekStart, $weekStart->copy()->endOfWeek(Carbon::SUNDAY)];
    }

    private function weekEntries(int $userId, Carbon $weekStart, Carbon $weekEnd): Collection
    {
        return JournalEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('entry_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('entry_date')
            ->get();
    }

    private function buildReflectionContentWithAi(Collection $entries, Carbon $weekStart, Carbon $weekEnd): string
    {
        $apiKey = config('services.openai.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $this->buildReflectionContent($entries, $weekStart, $weekEnd);
        }

        $model = config('services.openai.model', 'gpt-4.1-mini');
        $entryLines = $entries
            ->map(fn (JournalEntry $entry): string => sprintf('- [%s] %s', Carbon::parse($entry->entry_date)->toDateString(), trim($entry->content)))
            ->implode("\n");

        $systemPrompt = <<<'PROMPT'
Eres un coach emocional y escribes reflexiones semanales personalizadas basadas en entradas de diario.

Reglas:
- Escribe entre 130 y 220 palabras.
- Tono cálido, empático y concreto.
- Usa exactamente estas 4 secciones en este orden y con estos títulos:
1) Resumen de la semana
2) Patrones observados
3) Fortalezas y aprendizajes
4) Intención para la próxima semana
- No inventes hechos que no estén en las entradas.
- Si faltan detalles, dilo con suavidad sin suponer.
- Devuelve solo texto plano en español.
PROMPT;

        $userPrompt = sprintf(
            "Semana: %s a %s\n\nEntradas del usuario:\n%s",
            $weekStart->toDateString(),
            $weekEnd->toDateString(),
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
                Log::warning('OpenAI reflection generation failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return $this->buildReflectionContent($entries, $weekStart, $weekEnd);
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            if ($content === '') {
                return $this->buildReflectionContent($entries, $weekStart, $weekEnd);
            }

            return $content;
        } catch (\Throwable $exception) {
            Log::warning('OpenAI reflection generation exception', [
                'message' => $exception->getMessage(),
            ]);

            return $this->buildReflectionContent($entries, $weekStart, $weekEnd);
        }
    }

    private function buildReflectionContent(Collection $entries, Carbon $weekStart, Carbon $weekEnd): string
    {
        $allText = $entries->pluck('content')->implode(' ');

        $analysis = $this->analyzeText($allText);
        $daysCovered = $entries
            ->pluck('entry_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->count();

        /** @var JournalEntry $longestEntry */
        $longestEntry = $entries->sortByDesc(fn (JournalEntry $e) => mb_strlen($e->content))->first();

        $wordCount = str_word_count(strip_tags($allText));
        $avgWords = (int) round($wordCount / $entries->count());

        $lines = [
            sprintf(
                '📅 Weekly reflection — %s to %s',
                $weekStart->format('M j'),
                $weekEnd->format('M j, Y')
            ),
            '',
            sprintf(
                'You wrote %d %s across %d %s this week, averaging %d words per entry.',
                $entries->count(),
                $entries->count() === 1 ? 'entry' : 'entries',
                $daysCovered,
                $daysCovered === 1 ? 'day' : 'days',
                $avgWords
            ),
        ];

        // Themes block
        if ($analysis['themes']) {
            $lines[] = '';
            $lines[] = '🔎 Main themes: '.$analysis['themes'].'.';
        }

        // Emotional tone block
        $lines[] = '🧭 Emotional tone: '.$analysis['tone'].'.';

        // Mood arc
        if ($analysis['mood_arc'] !== 'stable') {
            $lines[] = '📈 Mood arc: '.$analysis['mood_arc'].'.';
        }

        // Outstanding day
        if ($longestEntry instanceof JournalEntry) {
            $lines[] = '';
            $lines[] = sprintf(
                '✍️  Your most detailed entry was on %s — it may be worth revisiting.',
                Carbon::parse($longestEntry->entry_date)->format('l, M j')
            );
        }

        // Streak note
        if ($daysCovered >= 5) {
            $lines[] = '🔥 Great consistency — you wrote 5 or more days this week.';
        } elseif ($daysCovered >= 3) {
            $lines[] = '👍 Solid week — you checked in on '.$daysCovered.' different days.';
        }

        // Forward-looking prompt
        $lines[] = '';
        $lines[] = $this->buildNextWeekPrompt($analysis['tone'], $analysis['themes']);

        return implode("\n", $lines);
    }

    /** @return array{themes: string, tone: string, mood_arc: string} */
    private function analyzeText(string $text): array
    {
        return [
            'themes' => $this->extractThemes($text),
            'tone' => $this->detectTone($text),
            'mood_arc' => $this->detectMoodArc($text),
        ];
    }

    private function extractThemes(string $text): string
    {
        $stopWords = [
            'about', 'after', 'again', 'also', 'been', 'before', 'being', 'because', 'could', 'daily', 'from',
            'have', 'into', 'just', 'like', 'made', 'more', 'much', 'only', 'over', 'really', 'some', 'that',
            'their', 'them', 'then', 'there', 'these', 'they', 'this', 'through', 'very', 'were', 'what',
            'when', 'with', 'would', 'your', 'today', 'yesterday', 'felt', 'feel', 'think', 'thinking',
            'went', 'want', 'needed', 'need', 'week', 'said', 'told', 'still', 'didn', 'wasn', 'isn',
            'into', 'come', 'came', 'done', 'make', 'knew', 'know', 'take', 'took', 'even', 'back',
            'keep', 'kept', 'tell', 'well', 'last', 'next', 'time', 'long', 'each', 'kind', 'help',
        ];

        preg_match_all('/[a-zA-Z]{4,}/', mb_strtolower($text), $matches);

        $frequencies = collect($matches[0])
            ->reject(fn (string $word) => in_array($word, $stopWords, true))
            ->countBy()
            ->sortDesc()
            ->take(3)
            ->keys()
            ->values();

        return $frequencies->isNotEmpty()
            ? $frequencies->implode(', ')
            : 'self-awareness, routine, and personal growth';
    }

    private function detectTone(string $text): string
    {
        $positiveWords = [
            'calm', 'grateful', 'gratitude', 'happy', 'happiness', 'hopeful', 'hope', 'joy', 'joyful',
            'proud', 'pride', 'peace', 'peaceful', 'good', 'better', 'great', 'excited', 'love', 'loved',
            'enthusiastic', 'motivated', 'inspired', 'content', 'satisfied', 'energized', 'alive', 'light',
        ];
        $negativeWords = [
            'anxious', 'anxiety', 'angry', 'anger', 'sad', 'sadness', 'stressed', 'stress', 'tired',
            'worried', 'worry', 'bad', 'overwhelmed', 'lonely', 'upset', 'frustrated', 'frustration',
            'exhausted', 'lost', 'empty', 'difficult', 'hard', 'heavy', 'down', 'drained', 'hopeless',
        ];

        $positive = $this->countMatches($text, $positiveWords);
        $negative = $this->countMatches($text, $negativeWords);
        $total = $positive + $negative;

        if ($total === 0) {
            return 'balanced and reflective';
        }

        $ratio = $positive / $total;

        if ($ratio >= 0.65) {
            return 'mostly positive and grounded';
        }

        if ($ratio <= 0.35) {
            return 'challenging, but emotionally honest';
        }

        return 'balanced and reflective';
    }

    private function detectMoodArc(string $text): string
    {
        // Split the full text in thirds and compare tone of each segment.
        $len = mb_strlen($text);
        if ($len < 60) {
            return 'stable';
        }

        $third = (int) ($len / 3);
        $parts = [
            mb_substr($text, 0, $third),
            mb_substr($text, $third, $third),
            mb_substr($text, $third * 2),
        ];

        $scores = array_map(function (string $part): int {
            $pos = $this->countMatches($part, ['calm', 'grateful', 'happy', 'hopeful', 'joy', 'proud', 'peace', 'good', 'better', 'great', 'excited', 'light', 'energized']);
            $neg = $this->countMatches($part, ['anxious', 'angry', 'sad', 'stressed', 'tired', 'worried', 'bad', 'overwhelmed', 'upset', 'frustrated', 'heavy', 'drained']);

            return $pos - $neg;
        }, $parts);

        [$first, , $last] = $scores;

        if ($last > $first + 1) {
            return 'improving as the week progressed';
        }

        if ($first > $last + 1) {
            return 'started strongly but grew heavier toward the end';
        }

        return 'stable';
    }

    private function buildNextWeekPrompt(string $tone, string $themes): string
    {
        if (str_contains($tone, 'positive')) {
            return '💡 Next week: keep momentum around '.$themes.' and notice which habits helped you feel steady.';
        }

        if (str_contains($tone, 'challenging')) {
            return '💡 Next week: give yourself extra room to slow down and pay attention to what supports you around '.$themes.'.';
        }

        return '💡 Next week: keep observing how '.$themes.' continue to shape your routine and mood.';
    }

    private function countMatches(string $text, array $words): int
    {
        $lower = mb_strtolower($text);

        return collect($words)->sum(fn (string $word) => substr_count($lower, $word));
    }
}
