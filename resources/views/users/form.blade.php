@extends('layouts.app')

@section('title', $mode === 'create' ? 'Yeni Kullanıcı' : 'Kullanıcı Düzenle')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ $mode === 'create' ? 'Yeni Kullanıcı' : 'Kullanıcı Düzenle' }}</h1>
            <p class="eticart-muted mb-0">Rol ve izin ataması yapın.</p>
        </div>
        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="eticart-card p-3">
                <form method="POST" action="{{ $mode === 'create' ? route('users.store') : route('users.update', $user) }}">
                    @csrf
                    @if ($mode === 'edit')
                        @method('PUT')
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Ad Soyad</label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">E-posta</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Şifre {{ $mode === 'edit' ? '(opsiyonel)' : '' }}</label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" @required($mode === 'create')>
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Şifre Tekrar</label>
                            <input type="password" name="password_confirmation" class="form-control" @required($mode === 'create')>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rol</label>
                        <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                            @foreach ($roles as $role)
                                <option value="{{ $role->name }}" @selected(old('role', $selectedRole) === $role->name)>{{ ucfirst($role->name) }}</option>
                            @endforeach
                        </select>
                        @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Sayfa ve işlem yetkileri</label>
                        <p class="form-text mt-0">Rol varsayılan paket getirir; aşağıdan sayfa bazında listeleme / ekleme / düzenleme / silme işaretleyin.</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Sayfa</th>
                                        @foreach (\App\Support\PermissionCatalog::ACTIONS as $action => $actionLabel)
                                            <th class="text-center">{{ $actionLabel }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($permissionModules as $module => $meta)
                                        <tr>
                                            <td class="fw-semibold">{{ $meta['label'] }}</td>
                                            @foreach (\App\Support\PermissionCatalog::ACTIONS as $action => $actionLabel)
                                                @php $permName = $module.'.'.$action; @endphp
                                                <td class="text-center">
                                                    @if (in_array($action, $meta['actions'], true))
                                                        <input class="form-check-input" type="checkbox"
                                                               name="permissions[]" value="{{ $permName }}"
                                                               id="perm_{{ $module }}_{{ $action }}"
                                                               @checked(in_array($permName, old('permissions', $selectedPermissions), true))>
                                                    @else
                                                        <span class="eticart-muted">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $user->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Aktif</label>
                    </div>

                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </form>
            </div>
        </div>

        @if ($mode === 'edit')
            <div class="col-lg-5">
                <div class="eticart-card p-3">
                    <h2 class="h6 mb-3">Şifre Sıfırla</h2>
                    <form method="POST" action="{{ route('users.reset-password', $user) }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Yeni Şifre</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Yeni Şifre Tekrar</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Şifreyi Sıfırla</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
