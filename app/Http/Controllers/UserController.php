<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List users.
     */
    public function index(Request $request): View
    {
        $query = User::query()->with('roles')->latest();

        if ($request->filled('role')) {
            $role = $request->string('role')->toString();
            $query->role($role);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }

        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        return view('users.index', [
            'users' => $query->paginate(20)->withQueryString(),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'filters' => $request->only(['role', 'status', 'q']),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Kullanıcılar'],
            ],
        ]);
    }

    /**
     * Create user form.
     */
    public function create(): View
    {
        return view('users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::query()->orderBy('name')->get(),
            'permissionModules' => PermissionCatalog::modules(),
            'selectedRole' => 'viewer',
            'selectedPermissions' => [],
            'mode' => 'create',
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Kullanıcılar', 'url' => route('users.index')],
                ['label' => 'Yeni Kullanıcı'],
            ],
        ]);
    }

    /**
     * Store a new user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedData($request, null);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_active' => $request->boolean('is_active', true),
            'email_verified_at' => now(),
        ]);

        $user->syncRoles([$validated['role']]);
        foreach ($validated['permissions'] ?? [] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->syncPermissions($validated['permissions'] ?? []);

        return redirect()
            ->route('users.index')
            ->with('success', 'Kullanıcı oluşturuldu.');
    }

    /**
     * Edit user form.
     */
    public function edit(User $user): View
    {
        $user->load(['roles', 'permissions']);

        return view('users.form', [
            'user' => $user,
            'roles' => Role::query()->orderBy('name')->get(),
            'permissionModules' => PermissionCatalog::modules(),
            'selectedRole' => $user->roles->first()?->name ?? 'viewer',
            'selectedPermissions' => $user->getAllPermissions()->pluck('name')->all(),
            'lastLoginAt' => $this->lastLoginAt($user),
            'mode' => 'edit',
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Kullanıcılar', 'url' => route('users.index')],
                ['label' => $user->name],
            ],
        ]);
    }

    /**
     * Ajax: activity logs for the edited user (date + content filters).
     */
    public function logs(Request $request, User $user): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = ActivityLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at');

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($builder) use ($q) {
                $builder->where('description', 'like', '%'.$q.'%')
                    ->orWhere('action', 'like', '%'.$q.'%')
                    ->orWhere('path', 'like', '%'.$q.'%');
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $logs = $query->paginate(15)->withQueryString();
        $actions = [
            'view' => 'Listeleme',
            'create' => 'Ekleme',
            'update' => 'Düzenleme',
            'delete' => 'Silme',
            'prepare' => 'Hazırlama',
            'login' => 'Giriş',
            'logout' => 'Çıkış',
        ];

        return response()->json([
            'ok' => true,
            'last_login_at' => $this->lastLoginAt($user),
            'logs' => $logs->getCollection()->map(static function (ActivityLog $log) use ($actions): array {
                return [
                    'id' => $log->id,
                    'created_at' => $log->created_at?->format('d.m.Y H:i:s'),
                    'action' => $log->action,
                    'action_label' => $actions[$log->action] ?? $log->action,
                    'description' => $log->description,
                ];
            })->values()->all(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    /**
     * Update user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validatedData($request, $user);

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $request->boolean('is_active'),
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $user->update($payload);
        $user->syncRoles([$validated['role']]);
        foreach ($validated['permissions'] ?? [] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->syncPermissions($validated['permissions'] ?? []);

        return redirect()
            ->route('users.index')
            ->with('success', 'Kullanıcı güncellendi.');
    }

    /**
     * Deactivate user.
     */
    public function deactivate(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Kendi hesabınızı pasifleştiremezsiniz.');
        }

        $user->update(['is_active' => false]);

        return back()->with('success', 'Kullanıcı pasifleştirildi.');
    }

    /**
     * Activate user.
     */
    public function activate(User $user): RedirectResponse
    {
        $user->update(['is_active' => true]);

        return back()->with('success', 'Kullanıcı aktifleştirildi.');
    }

    /**
     * Reset user password.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => $validated['password'],
        ]);

        return back()->with('success', 'Şifre sıfırlandı.');
    }

    /**
     * Soft-delete user; activity logs keep name/email snapshots.
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Kendi hesabınızı silemezsiniz.');
        }

        if ($user->hasRole('admin') && User::role('admin')->count() <= 1) {
            return back()->with('error', 'Son yönetici silinemez.');
        }

        $user->update([
            'is_active' => false,
            'email' => sprintf('deleted.%d.%s', $user->id, $user->email),
        ]);
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', 'Kullanıcı silindi. İşlem kayıtları ad soyad ile duruyor.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id)->whereNull('deleted_at'),
            ],
            'password' => [
                $user ? 'nullable' : 'required',
                'confirmed',
                Password::defaults(),
            ],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::allPermissionNames())],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function lastLoginAt(User $user): ?string
    {
        $login = ActivityLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'login')
            ->latest('created_at')
            ->first();

        return $login?->created_at?->format('d.m.Y H:i:s');
    }
}
