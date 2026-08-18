@props([
    'message' => 'Yükleniyor...',
])

<div {{ $attributes->merge(['class' => 'text-center py-5']) }} role="status" aria-live="polite">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">{{ $message }}</span>
    </div>
    <div class="mt-2 eticart-muted">{{ $message }}</div>
</div>
