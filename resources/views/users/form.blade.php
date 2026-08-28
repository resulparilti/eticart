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
                                <option value="{{ $role->name }}" @selected(old('role', $selectedRole) === $role->name)>{{ \App\Support\PermissionCatalog::roleLabel($role->name) }}</option>
                            @endforeach
                        </select>
                        @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Üretim personeli rolü yalnızca ürün görüntüleme, sipariş görüntüleme ve sipariş hazırlama yetkisi verir.</div>
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

    @if ($mode === 'edit')
        <div class="eticart-card p-3 mt-3"
             data-logs-url="{{ route('users.logs', $user) }}"
             x-data="eticartUserLogs({
                url: $el.dataset.logsUrl,
                lastLoginAt: @js($lastLoginAt ?? null),
                from: @js(now()->toDateString()),
                to: @js(now()->toDateString())
             })">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="h6 mb-1">İşlem kayıtları</h2>
                    <p class="small eticart-muted mb-0">
                        Son giriş:
                        <strong x-text="lastLoginAt || 'Henüz giriş kaydı yok'">{{ $lastLoginAt ?? 'Henüz giriş kaydı yok' }}</strong>
                    </p>
                </div>
                <span class="small eticart-muted" x-show="pagination.total > 0" x-cloak>
                    <span x-text="pagination.from"></span>–<span x-text="pagination.to"></span>
                    / <span x-text="pagination.total"></span>
                </span>
            </div>

            <div class="row g-2 align-items-end mb-3">
                <div class="col-12 col-md-5">
                    <label class="form-label">İçerik ara</label>
                    <input type="search" class="form-control" placeholder="Açıklama veya işlem" x-model="q" @input="scheduleFetch()">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Başlangıç</label>
                    <input type="date" class="form-control" x-model="from" @change="applyDates()">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Bitiş</label>
                    <input type="date" class="form-control" x-model="to" @change="applyDates()">
                </div>
            </div>

            <div class="small eticart-muted mb-2" x-show="loading" x-cloak>Yükleniyor…</div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>İşlem</th>
                            <th>Açıklama</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="log in logs" :key="log.id">
                            <tr>
                                <td class="text-nowrap" x-text="log.created_at"></td>
                                <td><span class="badge text-bg-info" x-text="log.action_label"></span></td>
                                <td x-text="log.description"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="small eticart-muted mt-3" x-show="!loading && logs.length === 0" x-cloak>
                Bu filtrelere uyan kayıt yok.
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3" x-show="pagination.last_page > 1" x-cloak>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="go(page - 1)" :disabled="page <= 1">Önceki</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="go(page + 1)" :disabled="page >= pagination.last_page">Sonraki</button>
            </div>
        </div>
    @endif
@endsection
