@php
    $pagerId = $pagerId ?? 'productPager';
@endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 {{ $class ?? '' }}">
    <form method="GET" action="{{ route('products.index') }}" class="d-flex align-items-center gap-2" id="{{ $pagerId }}">
        <input type="hidden" name="tab" value="{{ $tab ?? 'all' }}">
        @foreach (($listQuery ?? []) as $name => $value)
            @if ($name !== 'per_page')
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <label class="small eticart-muted mb-0" for="{{ $pagerId }}Select">Sayfa başı</label>
        <select name="per_page" id="{{ $pagerId }}Select" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
            @foreach ($perPageOptions ?? [10, 20, 50, 100] as $option)
                <option value="{{ $option }}" @selected((int) ($perPage ?? 20) === (int) $option)>{{ $option }}</option>
            @endforeach
        </select>
    </form>
    {{ $products->links() }}
</div>
