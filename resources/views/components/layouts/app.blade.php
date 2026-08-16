<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Worktrack' }}</title>
    {{-- Colours the browser chrome on mobile to match the app header, so the status
         bar does not sit as a white stripe above a navy bar. --}}
    <meta name="theme-color" content="#162660">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-canvas text-ink antialiased">
    {{ $slot }}
</body>
</html>
