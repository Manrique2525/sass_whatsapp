<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title inertia>{{ config('app.name', 'WhatsApp SaaS') }}</title>
        @if (request()->routeIs('landing'))
            @php
                $structuredData = json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'WebApplication',
                    'name' => config('app.name', 'WhatsApp SaaS'),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'description' => 'Automatiza conversaciones, organiza a tu equipo y convierte más clientes con un inbox compartido para WhatsApp Business.',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            @endphp
            <script type="application/ld+json">{!! $structuredData !!}</script>
        @endif
        @inertiaHead
        @if (! app()->runningUnitTests())
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
