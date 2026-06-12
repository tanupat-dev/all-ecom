<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS — {{ config('app.name') }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; background: #f4f4f5; }
        .pos-wrap { max-width: 960px; margin: 0 auto; padding: 1rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: .5rem .75rem; border-bottom: 1px solid #e4e4e7; text-align: left; }
        input, select, button { padding: .5rem .65rem; font-size: 1rem; }
        button { cursor: pointer; }
        .error { color: #b91c1c; }
        .total { font-size: 1.5rem; font-weight: 700; }
    </style>
</head>
<body>
<div class="pos-wrap">
    {{ $slot }}
</div>
@livewireScripts
</body>
</html>
