<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EtiCart kuruldu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5" style="max-width: 640px;">
        @if (!empty($alreadyConnected))
            <div class="alert alert-success">
                <strong>{{ $shop }}</strong> mağazası EtiCart’a zaten bağlı.
            </div>
            <p>Kurulumu yeniden yapmanıza gerek yok. Panele gidip sipariş ve ürün senkronunu kullanabilirsiniz.</p>
        @else
            <div class="alert alert-success">
                <strong>{{ $shop }}</strong> mağazası EtiCart’a bağlandı.
            </div>
            <p class="text-secondary small mb-3">Kapsam: <code>{{ $scope ?: '—' }}</code></p>
            <p>Access token ayarlara kaydedildi. Artık sipariş, müşteri ve ürün senkronu bu token ile çalışır.</p>
        @endif
        <a class="btn btn-primary" href="{{ $panelUrl }}">EtiCart paneline git</a>
        @if (! empty($shop))
            <form method="GET" action="{{ route('shopify.install') }}" class="d-inline ms-2">
                <input type="hidden" name="shop" value="{{ $shop }}">
                <button type="submit" class="btn btn-outline-primary">Shopify’ı yeniden bağla</button>
            </form>
        @endif
    </div>
</body>
</html>
