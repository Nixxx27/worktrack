<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Worktrack' }}</title>
    {{-- Colours the browser chrome on mobile to match the app header, so the status
         bar does not sit as a white stripe above a navy bar. --}}
    <meta name="theme-color" content="#162660">
    {{-- The icon is the clipboard head, not the whole mascot: the full figure is unreadable
         below about 64px, and a tab strip renders this at 16. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" type="image/png" href="{{ asset('favicon-96.png') }}" sizes="96x96">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-canvas text-ink antialiased">
    {{ $slot }}
</body>
</html>
