@extends('layouts.app')

@section('title', 'Queue Durumu')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Queue Durumu</h1>
            <p class="eticart-muted mb-0">Bağlantı: <code>{{ $connection }}</code></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('admin.queue.process-now') }}">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Kuyruğu şimdi işle</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.clear-pending') }}"
                  onsubmit="return confirm('Bekleyen TÜM kuyruk kayıtları silinsin mi? Cron sync artık kuyruğa değil doğrudan çalışmalı.');">
                @csrf
                <button class="btn btn-outline-danger btn-sm" type="submit">Bekleyenleri temizle</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.dispatch-test') }}">
                @csrf
                <button class="btn btn-outline-primary btn-sm" type="submit">Test Sync (anında)</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.retry-all') }}" data-confirm="Tüm failed joblar yeniden denensin mi?">
                @csrf
                <button class="btn btn-outline-success btn-sm" type="submit">Tümünü Retry</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.flush') }}" data-confirm="Failed joblar silinsin mi?">
                @csrf
                <button class="btn btn-outline-danger btn-sm" type="submit">Failed Temizle</button>
            </form>
        </div>
    </div>

    @if ($pending > 0)
        <div class="alert alert-warning">
            <strong>{{ number_format($pending) }} bekleyen iş</strong> kuyrukta duruyor.
            <code>attempts = 0</code> ise worker hiç dokunmamış demektir (eski cron: işi kuyruğa atıp <code>queue:work</code> FAIL).
            Güncel kodda sync job’lar <code>dispatchSync</code> ile anında çalışır; bu listeyi <strong>Bekleyenleri temizle</strong> ile silebilirsiniz.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Bekleyen</div>
                <div class="fs-3 fw-semibold">{{ number_format($pending) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Başarısız</div>
                <div class="fs-3 fw-semibold text-danger">{{ number_format($failed) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Paylaşımlı hosting</div>
                <div class="small mt-1">Cron → <code>eticart:process-queue</code></div>
                <div class="small">Sync → <code>dispatchSync</code> (kuyruğa düşmez)</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Bekleyen Joblar</h2>
                @if ($recentJobs->isEmpty())
                    <p class="eticart-muted mb-0">Kuyruk boş.</p>
                @else
                    <x-table :headers="['ID', 'Job', 'Queue', 'Attempts', 'Oluşturulma']">
                        @foreach ($recentJobs as $job)
                            <tr>
                                <td>{{ $job->id }}</td>
                                <td class="small">{{ class_basename($job->display_name) }}</td>
                                <td>{{ $job->queue }}</td>
                                <td>{{ $job->attempts }}</td>
                                <td>{{ $job->created_at }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        </div>
        <div class="col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h6 mb-3">Başarısız Joblar</h2>
                @if ($failedJobs->isEmpty())
                    <p class="eticart-muted mb-0">Failed job yok.</p>
                @else
                    <x-table :headers="['Job', 'Hata', 'Zaman', '']">
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td class="small">{{ class_basename($job->display_name) }}</td>
                                <td class="small text-danger">{{ $job->exception }}</td>
                                <td class="text-nowrap small">{{ $job->failed_at }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.queue.retry', $job->uuid) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Retry</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        </div>
    </div>
@endsection
