@extends('layouts.app')

@section('title', 'Notlar')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2" x-data>
        <div>
            <h1 class="h3 mb-1">Notlar</h1>
            <p class="eticart-muted mb-0">Kişisel not defteriniz.</p>
        </div>
        @if ($tab === 'notes')
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#noteCreateModal">
                <i class="bi bi-plus-lg me-1"></i> Not ekle
            </button>
        @endif
    </div>

    <ul class="nav nav-pills mb-3 gap-2">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'notes' ? 'active' : '' }}" href="{{ route('notes.index') }}">Notlar</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'archive' ? 'active' : '' }}" href="{{ route('notes.index', ['tab' => 'archive']) }}">Arşiv</a>
        </li>
    </ul>

    @if ($notes->isEmpty())
        <x-empty-state
            title="{{ $tab === 'archive' ? 'Arşiv boş' : 'Not yok' }}"
            message="{{ $tab === 'archive' ? 'Arşivlenmiş not bulunmuyor.' : 'Yeni bir not ekleyerek başlayın.' }}"
            icon="bi-journal-text"
        />
    @else
        <div class="d-flex flex-column gap-2">
            @foreach ($notes as $note)
                <div class="eticart-workspace-card">
                    <div class="flex-grow-1 min-w-0">
                        <button
                            type="button"
                            class="btn btn-link text-decoration-none text-start p-0 fw-semibold text-truncate w-100"
                            data-bs-toggle="modal"
                            data-bs-target="#noteEditModal{{ $note->id }}"
                        >
                            {{ $note->title }}
                        </button>
                        @if ($note->body)
                            <div class="small eticart-muted text-truncate mt-1">{!! \Illuminate\Support\Str::limit(strip_tags($note->body), 90) !!}</div>
                        @endif
                    </div>
                    <div class="text-end ms-3 flex-shrink-0">
                        <div class="small eticart-muted text-nowrap">{{ optional($note->updated_at)->format('d.m.Y H:i') }}</div>
                        <div class="d-flex gap-1 justify-content-end mt-1">
                            @if ($tab === 'notes')
                                <form method="POST" action="{{ route('notes.archive', $note) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Arşivle"><i class="bi bi-archive"></i></button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('notes.restore', $note) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Geri al"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('notes.destroy', $note) }}" onsubmit="return confirm('Not silinsin mi?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="noteEditModal{{ $note->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <form method="POST" action="{{ route('notes.update', $note) }}" class="modal-content">
                            @csrf
                            @method('PUT')
                            <div class="modal-header">
                                <h5 class="modal-title">Notu düzenle</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Başlık</label>
                                    <input type="text" name="title" class="form-control" value="{{ $note->title }}" required maxlength="255">
                                </div>
                                <label class="form-label">İçerik</label>
                                <x-rich-editor name="body" :value="$note->body" height="220px" />
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                                <button type="submit" class="btn btn-primary">Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-3">{{ $notes->links() }}</div>
    @endif

    <div class="modal fade" id="noteCreateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="{{ route('notes.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Yeni not</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Başlık</label>
                        <input type="text" name="title" class="form-control" required maxlength="255">
                    </div>
                    <label class="form-label">İçerik</label>
                    <x-rich-editor name="body" value="" height="220px" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
@endsection
