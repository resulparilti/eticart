@props([
    'fields' => [],
])

@if ($fields !== [])
    <div {{ $attributes->class('eticart-card p-3') }}>
        <h2 class="h6 mb-3">Shopify meta alanları</h2>
        <div class="d-flex flex-column gap-3">
            @foreach ($fields as $field)
                <div>
                    <div class="small eticart-muted mb-1">{{ $field['label'] ?? $field['key'] ?? 'Meta' }}</div>
                    @if (($field['kind'] ?? '') === 'richtext')
                        <div class="eticart-metafield-rich small">{!! $field['html'] !!}</div>
                    @elseif (($field['kind'] ?? '') === 'products')
                        <div class="eticart-related-products">
                            @foreach ($field['products'] ?? [] as $related)
                                @php
                                    $href = ! empty($related['local_id'])
                                        ? route('products.show', $related['local_id'])
                                        : (! empty($related['mirror_id']) ? route('products.shopify-mirror.show', $related['mirror_id']) : null);
                                    $image = $related['image'] ?? null;
                                @endphp
                                @if ($href)
                                    <a href="{{ $href }}" class="eticart-related-product">
                                        @if ($image)
                                            <img src="{{ \App\Support\ShopifyMetafieldFormatter::cdnWidth((string) $image, 96) }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <span class="eticart-related-product__placeholder"><i class="bi bi-box"></i></span>
                                        @endif
                                        <span>{{ $related['title'] }}</span>
                                    </a>
                                @else
                                    <div class="eticart-related-product eticart-related-product--static">
                                        @if ($image)
                                            <img src="{{ \App\Support\ShopifyMetafieldFormatter::cdnWidth((string) $image, 96) }}" alt="" loading="lazy" decoding="async">
                                        @else
                                            <span class="eticart-related-product__placeholder"><i class="bi bi-box"></i></span>
                                        @endif
                                        <span>{{ $related['title'] ?? ('#'.$related['id']) }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="small">{{ $field['text'] ?: '—' }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
