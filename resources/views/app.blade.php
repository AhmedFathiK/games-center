<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <!-- Shared app font set (Space Grotesk / Inter / JetBrains Mono),
         used by AuthLayout, Index, Rooms/Mine, and the settings pages.
         Previously each of those five components carried its own
         identical @import in a scoped <style> block, firing duplicate
         requests on every navigation between them. Centralized here as
         a single <link> so it's fetched once and discovered early by
         the browser's preloader, instead of waiting on each component's
         CSS to parse. Mafia's own fonts (Special Elite / IBM Plex) stay
         scoped to Rooms/Show.vue since they're genuinely only needed
         there — see that file's own note about eventually gating them
         per game slug once a second game exists to gate against. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap"
        rel="stylesheet"
    />

    @routes
    @vite(['resources/js/app.ts'])
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>