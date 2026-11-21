<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #334155; font-size: 11pt; }
        h1 { color: #391e73; font-size: 19pt; margin: 0 0 6pt; }
        .details { font-size: 9pt; margin: 0 0 18pt; }
        .letter { line-height: 1.6; }
    </style>
</head>
<body>
    <h1>{{ $letter->title }}</h1>
    <p class="details">Issued {{ substr($letter->created_at, 0, 10) }} &middot; Template version {{ $letter->version }}</p>
    <div class="letter">{!! nl2br(e($body)) !!}</div>
    @if ($signer)
        <p>Electronically signed in DevDuo HRM by {{ $signer }} on {{ $letter->signed_at }}.</p>
    @endif
</body>
</html>
