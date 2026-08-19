Sayın {{ $customerName }},

{{ $storeHost }} üzerinden yapmış olduğunuz {{ $order->order_number }} numaralı siparişiniz için oluşturulan faturanızı aşağıdaki bağlantıdan indirebilirsiniz.
@if (filled($invoiceUrl))

{{ $invoiceUrl }}
@endif

Bizi tercih ettiğiniz için teşekkür ederiz.

{{ $brand['name'] ?? '' }}
