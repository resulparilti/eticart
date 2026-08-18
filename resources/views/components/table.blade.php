@props([
    'headers' => [],
    'emptyMessage' => 'Kayıt bulunamadı.',
])

<div class="table-responsive eticart-card">
    <table {{ $attributes->merge(['class' => 'table table-hover mb-0 align-middle']) }}>
        @if (!empty($headers))
            <thead class="table-light">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
