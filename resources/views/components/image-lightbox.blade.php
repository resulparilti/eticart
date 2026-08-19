<template x-teleport="body">
    <div class="eticart-lightbox"
         :class="{ 'is-open': lightboxIndex >= 0 }"
         x-cloak
         role="dialog"
         aria-modal="true"
         :aria-hidden="lightboxIndex >= 0 ? 'false' : 'true'"
         aria-label="Görsel önizleme"
         @keydown.escape.window="lightboxIndex >= 0 && closeLightbox()"
         @keydown.arrow-left.window="lightboxIndex >= 0 && prevLightbox()"
         @keydown.arrow-right.window="lightboxIndex >= 0 && nextLightbox()"
         @click.self="closeLightbox()">
        <button type="button"
                class="eticart-lightbox__close"
                @click="closeLightbox()"
                aria-label="Kapat">&times;</button>
        <button type="button"
                class="eticart-lightbox__nav eticart-lightbox__nav--prev"
                x-show="lightboxCount > 1"
                @click.stop="prevLightbox()"
                aria-label="Önceki görsel">&lsaquo;</button>
        <img class="eticart-lightbox__img"
             x-show="lightbox"
             :src="lightbox || ''"
             alt="Büyük görsel"
             @click.stop>
        <button type="button"
                class="eticart-lightbox__nav eticart-lightbox__nav--next"
                x-show="lightboxCount > 1"
                @click.stop="nextLightbox()"
                aria-label="Sonraki görsel">&rsaquo;</button>
        <div class="eticart-lightbox__counter"
             x-show="lightboxCount > 1 && lightboxIndex >= 0"
             x-text="(lightboxIndex + 1) + ' / ' + lightboxCount"></div>
    </div>
</template>
