<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <meta name="theme-color" content="#16233b">
        <link rel="icon" type="image/svg+xml" href="{{ Vite::asset('resources/js/assets/brand/Favicon.svg') }}">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        @inertia
    </body>
</html>
