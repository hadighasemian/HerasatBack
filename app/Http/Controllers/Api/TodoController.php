<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TodoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Todo::class);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'open', 'done'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $status = $data['status'] ?? 'all';

        $todos = Todo::query()
            ->where('user_id', $request->user()->id)
            ->when($status === 'open', fn ($q) => $q->whereNull('completed_at'))
            ->when($status === 'done', fn ($q) => $q->whereNotNull('completed_at'))
            ->orderByRaw('case when completed_at is null then 0 else 1 end')
            ->orderByRaw('case when due_date is null then 1 else 0 end')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($todos->items())->map(fn (Todo $t) => $this->json($t)),
            'meta' => [
                'current_page' => $todos->currentPage(),
                'last_page' => $todos->lastPage(),
                'total' => $todos->total(),
                'per_page' => $todos->perPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Todo::class);
        $data = $this->validated($request);
        $todo = new Todo;
        $todo->user_id = $request->user()->id;
        $todo->title = $data['title'];
        $todo->notes = $data['notes'] ?? null;
        $todo->due_date = $data['due_date'] ?? null;
        $todo->save();

        return response()->json(['todo' => $this->json($todo->fresh())], 201);
    }

    public function show(Request $request, Todo $todo): JsonResponse
    {
        $this->authorize('view', $todo);

        return response()->json(['todo' => $this->json($todo)]);
    }

    public function update(Request $request, Todo $todo): JsonResponse
    {
        $this->authorize('update', $todo);
        $data = $this->validated($request, requiredTitle: false);
        if (array_key_exists('title', $data)) {
            $todo->title = $data['title'];
        }
        if (array_key_exists('notes', $data)) {
            $todo->notes = $data['notes'];
        }
        if (array_key_exists('due_date', $data)) {
            $todo->due_date = $data['due_date'];
        }
        if (array_key_exists('is_done', $data)) {
            $todo->markDone((bool) $data['is_done']);
        }
        $todo->save();

        return response()->json(['todo' => $this->json($todo->fresh())]);
    }

    public function destroy(Todo $todo): JsonResponse
    {
        $this->authorize('delete', $todo);
        $todo->delete();

        return response()->json(['message' => 'کار حذف شد.']);
    }

    private function validated(Request $request, bool $requiredTitle = true): array
    {
        $title = $requiredTitle ? ['required', 'string', 'min:1', 'max:200'] : ['sometimes', 'required', 'string', 'min:1', 'max:200'];

        return $request->validate([
            'title' => $title,
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:1900-01-01', 'before:2100-01-01'],
            'is_done' => ['sometimes', 'boolean'],
        ]);
    }

    private function json(Todo $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'notes' => $t->notes,
            'due_date' => $t->due_date?->format('Y-m-d'),
            'is_done' => $t->isDone(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
