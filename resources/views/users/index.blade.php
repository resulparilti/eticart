@extends('layouts.app')

@section('title', 'Kullanıcılar')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Kullanıcılar</h1>
            <p class="eticart-muted mb-0">Rol ve yetki yönetimi.</p>
        </div>
        @if (\App\Support\PermissionCatalog::allows(auth()->user(), 'users.create'))
            <a href="{{ route('users.create') }}" class="btn btn-primary">Yeni Kullanıcı</a>
        @endif
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('users.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Ara</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Ad veya e-posta">
            </div>
            <div class="col-md-3">
                <label class="form-label">Rol</label>
                <select name="role" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Durum</label>
                <select name="status" class="form-select">
                    <option value="">Tümü</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Aktif</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Pasif</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($users->isEmpty())
        <x-empty-state title="Kullanıcı yok" message="Yeni kullanıcı ekleyerek başlayın." icon="bi-people" />
    @else
        <x-table :headers="['Ad', 'E-posta', 'Rol', 'Durum', 'Oluşturulma', 'İşlem']">
            @foreach ($users as $user)
                <tr>
                    <td class="fw-semibold">{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        @forelse ($user->roles as $role)
                            <x-badge type="info">{{ $role->name }}</x-badge>
                        @empty
                            <span class="eticart-muted">-</span>
                        @endforelse
                    </td>
                    <td>
                        <x-badge type="{{ $user->is_active ? 'success' : 'danger' }}">
                            {{ $user->is_active ? 'Aktif' : 'Pasif' }}
                        </x-badge>
                    </td>
                    <td>{{ optional($user->created_at)->format('d.m.Y') }}</td>
                    <td class="text-nowrap">
                        @if (\App\Support\PermissionCatalog::allows(auth()->user(), 'users.update'))
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary">Düzenle</a>
                        @endif
                        @if (\App\Support\PermissionCatalog::allows(auth()->user(), 'users.update'))
                        @if ($user->is_active)
                            <form method="POST" action="{{ route('users.deactivate', $user) }}" class="d-inline" data-confirm="Kullanıcı pasifleştirilsin mi?">
                                @csrf
                                <button class="btn btn-sm btn-outline-warning" type="submit" @disabled($user->id === auth()->id())>Pasifleştir</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('users.activate', $user) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-success" type="submit">Aktifleştir</button>
                            </form>
                        @endif
                        @endif
                        @if (\App\Support\PermissionCatalog::allows(auth()->user(), 'users.delete'))
                        <form method="POST" action="{{ route('users.destroy', $user) }}" class="d-inline" data-confirm="Kullanıcı silinsin mi? İşlem kayıtları silinmez.">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit" @disabled($user->id === auth()->id())>Sil</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="mt-3">
            {{ $users->links() }}
        </div>
    @endif
@endsection
