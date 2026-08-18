@extends('layouts.app')

@section('title', 'Yapılacaklar')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Yapılacaklar</h1>
            <p class="eticart-muted mb-0">Kişisel görev listeniz. {{ $pendingCount }} bekleyen görev.</p>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="POST" action="{{ route('todos.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-9">
                <label class="form-label">Yeni görev</label>
                <input type="text" name="title" class="form-control" placeholder="Örn: Kargo etiketlerini yazdır" required maxlength="255">
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100" type="submit">Ekle</button>
            </div>
        </form>
    </div>

    @if ($todos->isEmpty())
        <x-empty-state title="Görev yok" message="İlk görevinizi ekleyerek başlayın." icon="bi-check2-square" />
    @else
        <div class="d-flex flex-column gap-2">
            @foreach ($todos as $todo)
                <div class="eticart-workspace-card {{ $todo->is_done ? 'is-done' : '' }}">
                    <form method="POST" action="{{ route('todos.toggle', $todo) }}" class="me-2">
                        @csrf
                        <button type="submit" class="btn btn-sm {{ $todo->is_done ? 'btn-success' : 'btn-outline-secondary' }}" title="Durumu değiştir">
                            <i class="bi {{ $todo->is_done ? 'bi-check-lg' : 'bi-circle' }}"></i>
                        </button>
                    </form>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate {{ $todo->is_done ? 'text-decoration-line-through eticart-muted' : '' }}">{{ $todo->title }}</div>
                        <div class="small eticart-muted">{{ optional($todo->updated_at)->format('d.m.Y H:i') }}</div>
                    </div>
                    <form method="POST" action="{{ route('todos.destroy', $todo) }}" onsubmit="return confirm('Görev silinsin mi?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
@endsection
