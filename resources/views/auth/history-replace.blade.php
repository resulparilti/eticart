<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Yönlendiriliyor</title>
</head>
<body>
    <p>Önceki sayfaya dönülüyor… <a href="{{ $url }}">Devam et</a></p>
    <script>
        location.replace(@json($url));
    </script>
</body>
</html>
