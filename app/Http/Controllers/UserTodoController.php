<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserTodo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserTodoController extends Controller
{
    public function index(): View
    {
        $todos = UserTodo::query()
            ->where('user_id', Auth::id())
            ->orderBy('is_done')
            ->orderBy('position')
            ->orderByDesc('id')
            ->get();

        return view('workspace.todos', [
            'todos' => $todos,
            'pendingCount' => $todos->where('is_done', false)->count(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Yapılacaklar'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $max = (int) UserTodo::query()->where('user_id', Auth::id())->max('position');

        UserTodo::query()->create([
            'user_id' => Auth::id(),
            'title' => $validated['title'],
            'is_done' => false,
            'position' => $max + 1,
        ]);

        return back()->with('success', 'Görev eklendi.');
    }

    public function update(Request $request, UserTodo $todo): RedirectResponse
    {
        $this->authorizeOwner($todo);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $todo->update(['title' => $validated['title']]);

        return back()->with('success', 'Görev güncellendi.');
    }

    public function toggle(UserTodo $todo): RedirectResponse
    {
        $this->authorizeOwner($todo);
        $todo->update(['is_done' => ! $todo->is_done]);

        return back()->with('success', $todo->is_done ? 'Görev tamamlandı.' : 'Görev yeniden açıldı.');
    }

    public function destroy(UserTodo $todo): RedirectResponse
    {
        $this->authorizeOwner($todo);
        $todo->delete();

        return back()->with('success', 'Görev silindi.');
    }

    private function authorizeOwner(UserTodo $todo): void
    {
        abort_unless((int) $todo->user_id === (int) Auth::id(), 403);
    }
}
