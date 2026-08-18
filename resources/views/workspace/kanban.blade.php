@extends('layouts.app')

@section('title', 'Kanban')

@section('content')
    <div
        class="eticart-kanban"
        x-data="eticartKanban(@js([
            'moveUrl' => route('kanban.cards.move'),
        ]))"
        x-init="init()"
    >
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1">Kanban</h1>
                <p class="eticart-muted mb-0">Kartları sürükleyerek kategoriler arasında taşıyın.</p>
            </div>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#kanbanColumnModal">
                <i class="bi bi-plus-lg me-1"></i> Kategori ekle
            </button>
        </div>

        <div class="eticart-kanban__board">
            @foreach ($columns as $column)
                <div class="eticart-kanban__column eticart-card"
                     data-column-id="{{ $column->id }}"
                     @dragover.prevent
                     @drop.prevent="onDrop($event, {{ $column->id }})">
                    <div class="eticart-kanban__column-head">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <span class="eticart-kanban__dot" style="background: {{ $column->color }}"></span>
                            <strong class="text-truncate">{{ $column->name }}</strong>
                            <span class="badge text-bg-light">{{ $column->cards->count() }}</span>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-link text-muted p-0" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#kanbanColumnEdit{{ $column->id }}">Düzenle</button>
                                </li>
                                <li>
                                    <form method="POST" action="{{ route('kanban.columns.destroy', $column) }}" onsubmit="return confirm('Kategori ve kartları silinsin mi?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="dropdown-item text-danger">Sil</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="eticart-kanban__cards" data-column-list="{{ $column->id }}">
                        @foreach ($column->cards as $card)
                            <div
                                class="eticart-kanban__card"
                                draggable="true"
                                data-card-id="{{ $card->id }}"
                                @dragstart="onDragStart($event, {{ $card->id }}, {{ $column->id }})"
                                @dragend="onDragEnd()"
                            >
                                <button type="button" class="eticart-kanban__card-title" data-bs-toggle="modal" data-bs-target="#kanbanCardEdit{{ $card->id }}">
                                    {{ $card->title }}
                                </button>
                                @if ($card->body)
                                    <div class="small eticart-muted text-truncate">{{ \Illuminate\Support\Str::limit(strip_tags($card->body), 70) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2" data-bs-toggle="modal" data-bs-target="#kanbanCardCreate{{ $column->id }}">
                        <i class="bi bi-plus-lg"></i> Kart ekle
                    </button>
                </div>
            @endforeach
        </div>
    </div>

    @foreach ($columns as $column)
        <div class="modal fade" id="kanbanCardCreate{{ $column->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <form method="POST" action="{{ route('kanban.cards.store') }}" class="modal-content">
                    @csrf
                    <input type="hidden" name="kanban_column_id" value="{{ $column->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Kart ekle — {{ $column->name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Başlık</label>
                            <input type="text" name="title" class="form-control" required maxlength="255">
                        </div>
                        <label class="form-label">İçerik</label>
                        <x-rich-editor name="body" value="" height="180px" />
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" class="btn btn-primary">Ekle</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="kanbanColumnEdit{{ $column->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('kanban.columns.update', $column) }}" class="modal-content">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title">Kategori düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Ad</label>
                            <input type="text" name="name" class="form-control" value="{{ $column->name }}" required maxlength="80">
                        </div>
                        <div>
                            <label class="form-label">Renk</label>
                            <input type="color" name="color" class="form-control form-control-color" value="{{ $column->color }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>

        @foreach ($column->cards as $card)
            <div class="modal fade" id="kanbanCardEdit{{ $card->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <form method="POST" action="{{ route('kanban.cards.update', $card) }}" class="modal-content">
                        @csrf
                        @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title">Kartı düzenle</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Başlık</label>
                                <input type="text" name="title" class="form-control" value="{{ $card->title }}" required maxlength="255">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kategori</label>
                                <select name="kanban_column_id" class="form-select">
                                    @foreach ($columns as $opt)
                                        <option value="{{ $opt->id }}" @selected($opt->id === $column->id)>{{ $opt->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="form-label">İçerik</label>
                            <x-rich-editor name="body" :value="$card->body" height="180px" />
                        </div>
                        <div class="modal-footer justify-content-between">
                            <button form="kanbanCardDelete{{ $card->id }}" class="btn btn-outline-danger" onclick="return confirm('Kart silinsin mi?');">Sil</button>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                                <button type="submit" class="btn btn-primary">Kaydet</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <form id="kanbanCardDelete{{ $card->id }}" method="POST" action="{{ route('kanban.cards.destroy', $card) }}" class="d-none">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    @endforeach

    <div class="modal fade" id="kanbanColumnModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('kanban.columns.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Yeni kategori</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ad</label>
                        <input type="text" name="name" class="form-control" placeholder="Örn: Beklemede" required maxlength="80">
                    </div>
                    <div>
                        <label class="form-label">Renk</label>
                        <input type="color" name="color" class="form-control form-control-color" value="#6c757d">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary">Ekle</button>
                </div>
            </form>
        </div>
    </div>
@endsection
