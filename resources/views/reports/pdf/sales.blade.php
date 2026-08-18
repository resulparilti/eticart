<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Satış Raporu</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #666; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .summary { margin: 12px 0; }
        .summary span { display: inline-block; margin-right: 18px; }
    </style>
</head>
<body>
    <h1>Satış Raporu</h1>
    <div class="muted">{{ $dateFrom }} — {{ $dateTo }}</div>

    <div class="summary">
        <span>Sipariş: <strong>{{ $report['summary']['orders'] }}</strong></span>
        <span>Ciro: <strong>{{ number_format($report['summary']['revenue'], 2) }} TL</strong></span>
        <span>Ort.: <strong>{{ number_format($report['summary']['avg_order'], 2) }} TL</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Sipariş</th>
                <th>Ciro</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['daily'] as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['orders'] }}</td>
                    <td>{{ number_format($row['revenue'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
