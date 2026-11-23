<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $config->get('ui.title') ?? config('app.name').' API' }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @vite('resources/js/docs/swagger.js')
</head>
<body>
    <header class="docs-banner">
        <h1>{{ $config->get('ui.title') ?? config('app.name').' API' }}</h1>
        <nav>
            <a href="{{ url('/docs/api.json') }}" target="_blank" rel="noopener">OpenAPI (JSON)</a>
            <a href="{{ url('/') }}" target="_blank" rel="noopener">Abrir o app</a>
        </nav>
    </header>
    <div id="swagger-ui"></div>
    <script type="application/json" id="openapi-spec">@json($spec)</script>
</body>
</html>
