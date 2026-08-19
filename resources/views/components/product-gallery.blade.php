@props([
    'images' => [],
    'alt' => 'Ürün görseli',
    'empty' => 'Görsel yok.',
])

@php
    $images = array_values(array_filter(array_map('strval', $images)));
    $main = $images[0] ?? null;
    $rest = array_slice($images, 1);
@endphp

@if ($main === null)
    <p class="eticart-muted mb-0">{{ $empty }}</p>
@else
    <button type="button"
            class="eticart-gallery__main"
            data-full-url="{{ $main }}"
            @click="openLightbox($event.currentTarget.getAttribute('data-full-url'))"
            title="Büyüt">
        <img src="{{ \App\Support\ShopifyMetafieldFormatter::cdnWidth($main, 480) }}"
             alt="{{ $alt }}"
             loading="eager"
             decoding="async">
    </button>
    @if ($rest !== [])
        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2" @click="showThumbs = true" x-show="!showThumbs">
            Diğer görselleri gör ({{ count($rest) }})
        </button>
        <template x-if="showThumbs">
            <div class="eticart-gallery__slider">
                <button type="button"
                        class="eticart-gallery__slider-btn"
                        @click="scrollThumbs(-1)"
                        aria-label="Önceki görseller">&lsaquo;</button>
                <div class="eticart-gallery__thumbs" x-ref="thumbTrack">
                    @foreach ($rest as $url)
                        <button type="button"
                                class="eticart-gallery__thumb"
                                data-full-url="{{ $url }}"
                                @click="openLightbox($event.currentTarget.getAttribute('data-full-url'))"
                                title="Büyüt">
                            <img src="{{ \App\Support\ShopifyMetafieldFormatter::cdnWidth($url, 120) }}"
                                 alt=""
                                 loading="lazy"
                                 decoding="async"
                                 width="72"
                                 height="72">
                        </button>
                    @endforeach
                </div>
                <button type="button"
                        class="eticart-gallery__slider-btn"
                        @click="scrollThumbs(1)"
                        aria-label="Sonraki görseller">&rsaquo;</button>
            </div>
        </template>
    @endif
@endif
