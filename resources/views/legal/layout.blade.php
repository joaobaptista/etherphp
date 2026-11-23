<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title') — {{ $appName }}</title>
    <meta name="description" content="@yield('description')" />
    <link rel="icon" type="image/x-icon" href="/favicon.ico" />
    <style>
        :root {
            --bg: #FAF7F2; --surface: #FFFFFF; --text: #2D1917; --muted: #7E6C66;
            --primary: #F05A47; --border: #ECE5DC; --todo-bg: #FFF3CD; --todo-text: #7A5A00;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.65; font-size: 16px;
        }
        header { border-bottom: 1px solid var(--border); background: var(--surface); }
        .wrap { max-width: 820px; margin: 0 auto; padding: 0 20px; }
        header .wrap { display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .brand { font-weight: 800; color: var(--text); text-decoration: none; font-size: 18px; }
        .brand span { color: var(--primary); }
        nav a { color: var(--muted); text-decoration: none; margin-left: 18px; font-size: 14px; font-weight: 600; }
        nav a[aria-current="page"] { color: var(--primary); }
        main { padding: 40px 0 64px; }
        article { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 40px; }
        h1 { font-size: 30px; line-height: 1.2; margin: 0 0 6px; }
        .updated { color: var(--muted); font-size: 14px; margin: 0 0 28px; }
        h2 { font-size: 20px; margin: 34px 0 10px; }
        h3 { font-size: 16px; margin: 22px 0 6px; }
        p, li { color: var(--text); }
        ul { padding-left: 22px; }
        li { margin: 4px 0; }
        a { color: var(--primary); }
        .box { border-left: 4px solid var(--primary); background: #FDF3F1; padding: 14px 18px; border-radius: 8px; }
        mark.todo { background: var(--todo-bg); color: var(--todo-text); padding: 1px 6px; border-radius: 4px; font-weight: 600; }
        footer { color: var(--muted); font-size: 13px; text-align: center; padding: 0 0 40px; }
        @media (max-width: 640px) { article { padding: 24px 18px; } h1 { font-size: 24px; } nav a { margin-left: 12px; } }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <a class="brand" href="/">Ethernity <span>DMs</span></a>
            <nav aria-label="Documentos legais">
                <a href="{{ route('legal.about') }}" @if (request()->routeIs('legal.about')) aria-current="page" @endif>Sobre</a>
                <a href="{{ route('legal.privacy') }}" @if (request()->routeIs('legal.privacy')) aria-current="page" @endif>Privacidade</a>
                <a href="{{ route('legal.terms') }}" @if (request()->routeIs('legal.terms')) aria-current="page" @endif>Termos de Uso</a>
            </nav>
        </div>
    </header>
    <main>
        <div class="wrap">
            <article>
                @yield('content')
            </article>
        </div>
    </main>
    <footer>
        <div class="wrap">
            © {{ date('Y') }} <x-legal-field :value="$entityName" label="nome do responsável" /> · {{ $appName }} ·
            <a href="/">Acessar o app</a>
        </div>
    </footer>
</body>
</html>
