<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\KanbanCard;
use App\Models\KanbanColumn;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class KanbanController extends Controller
{
    public function index(): View
    {
        $userId = (int) Auth::id();
        $this->ensureDefaultColumns($userId);

        $columns = KanbanColumn::query()
            ->where('user_id', $userId)
            ->with(['cards' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return view('workspace.kanban', [
            'columns' => $columns,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Kanban'],
            ],
        ]);
    }

    public function storeColumn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $userId = (int) Auth::id();
        $max = (int) KanbanColumn::query()->where('user_id', $userId)->max('position');

        KanbanColumn::query()->create([
            'user_id' => $userId,
            'name' => $validated['name'],
            'color' => $validated['color'] ?: '#6c757d',
            'position' => $max + 1,
        ]);

        return back()->with('success', 'Kategori eklendi.');
    }

    public function updateColumn(Request $request, KanbanColumn $column): RedirectResponse
    {
        $this->authorizeColumn($column);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $column->update([
            'name' => $validated['name'],
            'color' => $validated['color'] ?: $column->color,
        ]);

        return back()->with('success', 'Kategori güncellendi.');
    }

    public function destroyColumn(KanbanColumn $column): RedirectResponse
    {
        $this->authorizeColumn($column);
        $column->delete();

        return back()->with('success', 'Kategori silindi.');
    }

    public function storeCard(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kanban_column_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
        ]);

        $column = KanbanColumn::query()->findOrFail($validated['kanban_column_id']);
        $this->authorizeColumn($column);

        $max = (int) KanbanCard::query()->where('kanban_column_id', $column->id)->max('position');

        KanbanCard::query()->create([
            'user_id' => Auth::id(),
            'kanban_column_id' => $column->id,
            'title' => $validated['title'],
            'body' => HtmlSanitizer::rich($validated['body'] ?? null),
            'position' => $max + 1,
        ]);

        return back()->with('success', 'Kart eklendi.');
    }

    public function updateCard(Request $request, KanbanCard $card): RedirectResponse
    {
        $this->authorizeCard($card);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
            'kanban_column_id' => ['nullable', 'integer'],
        ]);

        $data = [
            'title' => $validated['title'],
            'body' => HtmlSanitizer::rich($validated['body'] ?? null),
        ];

        if (! empty($validated['kanban_column_id'])) {
            $column = KanbanColumn::query()->findOrFail($validated['kanban_column_id']);
            $this->authorizeColumn($column);
            $data['kanban_column_id'] = $column->id;
        }

        $card->update($data);

        return back()->with('success', 'Kart güncellendi.');
    }

    public function destroyCard(KanbanCard $card): RedirectResponse
    {
        $this->authorizeCard($card);
        $card->delete();

        return back()->with('success', 'Kart silindi.');
    }

    public function moveCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'card_id' => ['required', 'integer'],
            'column_id' => ['required', 'integer'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer'],
        ]);

        $card = KanbanCard::query()->findOrFail($validated['card_id']);
        $this->authorizeCard($card);

        $column = KanbanColumn::query()->findOrFail($validated['column_id']);
        $this->authorizeColumn($column);

        DB::transaction(function () use ($card, $column, $validated): void {
            $card->update(['kanban_column_id' => $column->id]);

            foreach ($validated['ordered_ids'] as $index => $id) {
                KanbanCard::query()
                    ->where('user_id', Auth::id())
                    ->where('id', $id)
                    ->update([
                        'kanban_column_id' => $column->id,
                        'position' => $index + 1,
                    ]);
            }
        });

        return response()->json(['ok' => true]);
    }

    private function ensureDefaultColumns(int $userId): void
    {
        if (KanbanColumn::query()->where('user_id', $userId)->exists()) {
            return;
        }

        $defaults = [
            ['name' => 'Yapılacaklar', 'color' => '#6c757d'],
            ['name' => 'İşlemde', 'color' => '#0d6efd'],
            ['name' => 'Tamamlananlar', 'color' => '#198754'],
        ];

        foreach ($defaults as $index => $item) {
            KanbanColumn::query()->create([
                'user_id' => $userId,
                'name' => $item['name'],
                'color' => $item['color'],
                'position' => $index + 1,
            ]);
        }
    }

    private function authorizeColumn(KanbanColumn $column): void
    {
        abort_unless((int) $column->user_id === (int) Auth::id(), 403);
    }

    private function authorizeCard(KanbanCard $card): void
    {
        abort_unless((int) $card->user_id === (int) Auth::id(), 403);
    }
}
