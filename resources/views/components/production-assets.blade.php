@php
    $manifestPath = public_path('build/manifest.json');
    $entries = ['resources/css/app.css', 'resources/js/app.js'];
@endphp
@if (is_readable($manifestPath))
    @php
        $manifest = json_decode((string) file_get_contents($manifestPath), true) ?? [];
    @endphp
    @foreach ($entries as $entry)
        @if (! empty($manifest[$entry]['file']))
            @php $file = $manifest[$entry]['file']; @endphp
            @if (str_ends_with($file, '.css'))
                <link rel="stylesheet" href="{{ asset('build/'.$file) }}">
            @elseif (str_ends_with($file, '.js'))
                <script type="module" src="{{ asset('build/'.$file) }}"></script>
            @endif
        @endif
    @endforeach
@else
    {{-- Vite build yoksa login/panel en azından açılsın --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" crossorigin="anonymous">
@endif
