@props([
    'title' => 'Henüz veri yok',
    'message' => 'Bu alanda gösterilecek kayıt bulunamadı.',
    'icon' => 'bi-inbox',
])

<div {{ $attributes->merge(['class' => 'text-center py-5 eticart-card']) }}>
    <i class="bi {{ $icon }} display-5 eticart-muted"></i>
    <h2 class="h5 mt-3 mb-1">{{ $title }}</h2>
    <p class="eticart-muted mb-0">{{ $message }}</p>
    @isset($action)
        <div class="mt-3">{{ $action }}</div>
    @endisset
</div>
