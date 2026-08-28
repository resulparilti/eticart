@extends('layouts.app')

@section('title', 'İşlem kayıtları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">İşlem kayıtları</h1>
            <p class="eticart-muted mb-0">Kullanıcı silinse bile ad soyad ile kayıtlar kalır ve filtrelenebilir.</p>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Ara</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Ad soyad, e-posta, işlem">
            </div>
            <div class="col-md-2">
                <label class="form-label">İşlem</label>
                <select name="action" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($actions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['action'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Modül</label>
                <select name="module" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($modules as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['module'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Bitiş</label>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-primary w-100" type="submit">Filtre</button>
            </div>
        </form>
    </div>

    @if ($logs->isEmpty())
        <x-empty-state title="Kayıt yok" message="Henüz işlem kaydı oluşmadı." icon="bi-journal-text" />
    @else
        <div class="eticart-card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Kullanıcı</th>
                            <th>İşlem</th>
                            <th>Açıklama</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="text-nowrap">{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $log->actorLabel() }}</div>
                                    <div class="small eticart-muted">{{ $log->user_email }}</div>
                                </td>
                                <td><x-badge type="info">{{ $actions[$log->action] ?? $log->action }}</x-badge></td>
                                <td>
                                    {{ $log->description }}
                                    @if (is_array($log->properties['checked_labels'] ?? null) && $log->properties['checked_labels'] !== [])
                                        <div class="small eticart-muted mt-1">{{ implode(' · ', $log->properties['checked_labels']) }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
@endsection
