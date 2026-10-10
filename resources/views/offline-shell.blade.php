<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <meta name="theme-color" content="#16233b">
        <link rel="icon" type="image/svg+xml" href="{{ Vite::asset('resources/js/assets/brand/Favicon.svg') }}">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="InnSYnc">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <title>InnSYnc</title>
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        {{-- No page and no data: the device puts the page it kept into this element before the application starts. --}}
        <div id="app" data-offline-shell></div>
    </body>
</html>
