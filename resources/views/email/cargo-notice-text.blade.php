Sayın {{ $customerName }},

{{ $storeHost }} üzerinden yapmış olduğunuz {{ $order->order_number }} numaralı siparişiniz kargoya verilmiştir.

Kargo firması: {{ $companyName }}
Takip numarası: {{ $shipment->tracking_number ?: '-' }}
Durum: {{ $statusText ?: 'Kargoda' }}
@if (filled($trackingUrl))
Takip bağlantısı: {{ $trackingUrl }}
@endif

Bizi tercih ettiğiniz için teşekkür ederiz.

{{ $brand['name'] ?? '' }}
