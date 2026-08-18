Merhaba {{ $customerName }},

{{ $order->order_number }} numaralı siparişiniz kargoya teslim edildi.
@if ($hasAttachment ?? false)
Fatura belgesi bu e-postanın ekinde yer alır.
@else
Fatura belgesi e-posta ekinde değildir. Aşağıdaki bağlantıdan indirebilirsiniz.
@endif

Kargo: {{ $companyName }}
Takip kodu: {{ $shipment->tracking_number ?: '-' }}
Durum: {{ $statusText ?: 'Kargoda' }}
@if (filled($trackingUrl))
Takip: {{ $trackingUrl }}
@endif
@if (filled($invoiceUrl))
Fatura: {{ $invoiceUrl }}
@endif
@if (filled($brand['site_url'] ?? null))
Mağaza: {{ $brand['site_url'] }}
@endif
@if (filled($brand['account_url'] ?? null))
Hesabım: {{ $brand['account_url'] }}
@endif
Teşekkürler,
{{ $brand['name'] ?? '' }}
