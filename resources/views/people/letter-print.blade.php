<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $letter->title }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #334155; }
        body { margin: 0; background: #f5f0ff; }
        main { max-width: 44rem; margin: 2rem auto; padding: clamp(1.25rem, 5vw, 3rem); background: white; border-top: .25rem solid #7c46f1; }
        h1 { font-size: 1.65rem; line-height: 1.25; color: #391e73; margin: 0; }
        .details { margin: .5rem 0 1.5rem; font-size: .85rem; }
        .letter { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.65; }
        .print-help { max-width: 44rem; margin: 1.25rem auto; padding: 0 1.25rem; }
        @page { margin: 18mm; }
        @media print {
            body { background: white; }
            main { margin: 0; padding: 0; border: 0; max-width: none; }
            header { break-inside: avoid; }
            .print-help { display: none; }
        }
    </style>
</head>
<body>
    <p class="print-help">Use your browser's Print command to print this letter or save it as a PDF.</p>
    <main>
        <header>
            <h1>{{ $letter->title }}</h1>
            <p class="details">Issued {{ substr($letter->created_at, 0, 10) }} &middot; Template version {{ $letter->version }}</p>
        </header>
        <div class="letter" dir="auto">{{ $body }}</div>
    </main>
</body>
</html>
