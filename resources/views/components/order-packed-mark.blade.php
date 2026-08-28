@props(['order'])

@if ($order->isPacked())
    <span class="order-packed-mark is-done"
          data-bs-toggle="tooltip"
          data-bs-title="{{ $order->packedTooltip() }}"
          title="{{ $order->packedTooltip() }}">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>
        <span class="visually-hidden">Hazırlandı</span>
    </span>
@else
    <span class="order-packed-mark is-pending"
          data-bs-toggle="tooltip"
          data-bs-title="Hazırlanmadı"
          title="Hazırlanmadı">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        <span class="visually-hidden">Hazırlanmadı</span>
    </span>
@endif
