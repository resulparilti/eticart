@props([
    'url' => null,
    'alt' => 'Ürün görseli',
])

@if (filled($url))
    <img src="{{ \App\Support\ShopifyMetafieldFormatter::cdnWidth((string) $url, 96) }}"
         alt="{{ $alt }}"
         class="eticart-product-thumb"
         loading="lazy"
         decoding="async"
         width="44"
         height="44">
@else
    <span class="eticart-product-thumb eticart-product-thumb--empty" title="Görsel yok" aria-hidden="true">
        <i class="bi bi-image"></i>
    </span>
@endif
