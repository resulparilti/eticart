@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'alert alert-success']) }} role="status">
        {{ $status }}
    </div>
@endif
