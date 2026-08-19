<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNote;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserNoteController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->string('tab')->toString() === 'archive' ? 'archive' : 'notes';

        $query = UserNote::query()->where('user_id', Auth::id());

        if ($tab === 'archive') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $notes = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        return view('workspace.notes', [
            'notes' => $notes,
            'tab' => $tab,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Notlar'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
        ]);

        UserNote::query()->create([
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'body' => HtmlSanitizer::rich($validated['body'] ?? null),
        ]);

        return redirect()
            ->route('notes.index')
            ->with('success', 'Not eklendi.');
    }

    public function update(Request $request, UserNote $note): RedirectResponse
    {
        $this->authorizeOwner($note);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
        ]);

        $note->update([
            'title' => $validated['title'],
            'body' => HtmlSanitizer::rich($validated['body'] ?? null),
        ]);

        return back()->with('success', 'Not güncellendi.');
    }

    public function archive(UserNote $note): RedirectResponse
    {
        $this->authorizeOwner($note);
        $note->update(['archived_at' => now()]);

        return redirect()
            ->route('notes.index')
            ->with('success', 'Not arşivlendi.');
    }

    public function restore(UserNote $note): RedirectResponse
    {
        $this->authorizeOwner($note);
        $note->update(['archived_at' => null]);

        return redirect()
            ->route('notes.index', ['tab' => 'archive'])
            ->with('success', 'Not arşivden çıkarıldı.');
    }

    public function destroy(UserNote $note): RedirectResponse
    {
        $this->authorizeOwner($note);
        $note->delete();

        return back()->with('success', 'Not silindi.');
    }

    private function authorizeOwner(UserNote $note): void
    {
        abort_unless((int) $note->user_id === (int) Auth::id(), 403);
    }
}
