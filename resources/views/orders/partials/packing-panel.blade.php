@php
    $detected = \App\Support\OrderPackingChecklist::detectGiftBox($order);
    $giftBox = $order->packing_checklist
        ? (bool) $order->packing_gift_box
        : (bool) $detected['required'];
    $giftSize = $order->packing_gift_box_size ?: $detected['size'];
    $checklist = is_array($order->packing_checklist) ? $order->packing_checklist : [];
    $initial = [];
    foreach (\App\Support\OrderPackingChecklist::keys() as $key) {
        $initial[$key] = ! empty($checklist[$key]);
    }
    $canPack = \App\Support\PermissionCatalog::allows(auth()->user(), 'orders.prepare')
        && ! in_array((string) $order->fulfillment_status, ['cancelled', 'refunded'], true)
        && ! $order->isPackingClaimedByOther(auth()->user());
    $packed = $order->isPacked();
    $claimedByOther = $order->isPackingClaimedByOther(auth()->user());
@endphp

<div class="eticart-card p-3 h-100"
     x-data="eticartOrderPacking({
        giftBox: {{ $giftBox ? 'true' : 'false' }},
        giftSize: @js($giftSize),
        items: @js($initial),
        labels: @js(\App\Support\OrderPackingChecklist::labels()),
        always: @js(\App\Support\OrderPackingChecklist::alwaysRequired()),
        giftKeys: @js(\App\Support\OrderPackingChecklist::giftKeys()),
        packed: {{ $packed ? 'true' : 'false' }},
        canPack: {{ $canPack ? 'true' : 'false' }},
        saveUrl: @js(route('orders.packing.checklist', $order)),
        statusUrl: @js(route('orders.packing.status', $order))
     })">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Sipariş hazırlama</h2>
            <p class="eticart-muted small mb-0">Yapılan maddeleri işaretleyin. Liste bitince siparişi hazırlandı olarak kapatabilirsiniz.</p>
        </div>
        @if ($packed)
            <span class="badge text-bg-success">{{ $order->packedTooltip() }}</span>
        @elseif ($claimedByOther)
            <span class="badge text-bg-warning">{{ $order->packingStarterName() }} hazırlıyor</span>
        @endif
    </div>

    @if ($claimedByOther)
        <div class="alert alert-warning py-2">{{ \App\Services\OrderPackingService::LOCKED_MESSAGE }}</div>
    @endif

    @if (auth()->user()?->hasRole('admin') && ($packed || $order->hasPackingProgress()))
        <form method="POST" action="{{ route('orders.packing.reset', $order) }}" class="mb-3"
              data-confirm="Hazırlama kaydı silinsin mi? Yüklenen görsel de kaldırılır.">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-warning">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Hazırlamayı sıfırla
            </button>
        </form>
    @endif

    <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" id="packingGiftBox" x-model="giftBox"
               @change="onGiftToggle()" :disabled="packed || !canPack">
        <label class="form-check-label" for="packingGiftBox">Bu siparişte hediye kutusu var</label>
    </div>

    <div class="mb-3" x-show="giftBox" x-cloak>
        <label class="form-label">Hediye kutusu boyutu</label>
        <select class="form-select" x-model="giftSize" @change="persist()" :disabled="packed || !canPack" style="max-width: 240px;">
            <option value="">Seçiniz</option>
            <option value="Küçük">Küçük</option>
            <option value="Orta">Orta</option>
            <option value="Büyük">Büyük</option>
        </select>
        <div class="form-text" x-show="!giftSize" x-cloak>Tamamlamak için hediye kutusu boyutunu seçin.</div>
    </div>

    <div class="list-group mb-3">
        <template x-for="key in visibleKeys()" :key="key">
            <label class="list-group-item d-flex gap-2 align-items-start">
                <input class="form-check-input mt-1" type="checkbox"
                       :checked="!!items[key]"
                       @change="toggle(key, $event.target.checked)"
                       :disabled="packed || !canPack">
                <span x-text="labels[key]"></span>
            </label>
        </template>
    </div>

    <div class="small eticart-muted mb-2" x-show="saving" x-cloak>Kaydediliyor…</div>

    @if ($order->packingPhotoUrl())
        <div class="mb-3">
            <div class="form-label">Hazırlama fotoğrafı</div>
            <a href="{{ $order->packingPhotoUrl() }}" target="_blank" rel="noopener">
                <img src="{{ $order->packingPhotoUrl() }}" alt="Hazırlanan sipariş" class="img-fluid rounded border" style="max-height: 240px;">
            </a>
        </div>
    @endif

    <div x-show="canPack && !packed && allDone()" x-cloak>
        <form method="POST" action="{{ route('orders.packing.complete', $order) }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="gift_box" :value="giftBox ? 1 : 0">
            <input type="hidden" name="gift_box_size" :value="giftSize || ''">
            <template x-for="key in Object.keys(labels)" :key="'h-'+key">
                <input type="hidden" :name="'checklist['+key+']'" :value="items[key] ? 1 : 0">
            </template>
            <div class="mb-3">
                <label class="form-label">Fotoğraf (isteğe bağlı)</label>
                <input type="file" name="photo" class="form-control" accept="image/*" capture="environment">
                <div class="form-text">Kameradan çekilen son hali yükleyebilirsiniz. Yüklemeden de tamamlayabilirsiniz.</div>
            </div>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check2-circle me-1"></i> Hazırlamayı tamamla
            </button>
        </form>
    </div>
</div>
