@extends('legal.layout')

@section('title', 'Sobre o '.$appName)
@section('description', 'O Ethernity DMs guarda memórias em um cofre cifrado e as entrega às pessoas que você ama, na data que você escolher.')

@section('content')
    <h1>{{ $appName }} — Legado Digital</h1>
    <p class="updated">Memórias guardadas com segurança e entregues a quem você ama, no momento certo.</p>

    <p>
        O {{ $appName }} é um cofre digital para guardar fotos, cartas, áudios e vídeos e organizá-los em <strong>cápsulas do
        tempo</strong>. Cada cápsula é entregue às pessoas que você escolher: em uma data especial (um aniversário, um
        casamento) ou depois do seu falecimento, com a ajuda de <strong>guardiões</strong> de confiança.
    </p>

    <h2>O que você pode fazer</h2>
    <ul>
        <li>Criar cápsulas para filhos, netos, amigos e familiares.</li>
        <li>Escrever cartas e gravar mensagens de voz e vídeo.</li>
        <li>Montar álbuns de fotos e contar a história de cada momento.</li>
        <li>Agendar a entrega para uma data futura ou indicar guardiões para a entrega póstuma.</li>
    </ul>

    <h2 id="google">Como usamos o Google</h2>
    <ul>
        <li><strong>Entrar com o Google:</strong> usamos seu nome, e-mail e foto apenas para criar e acessar sua conta.</li>
        <li><strong>Google Fotos (opcional):</strong> você escolhe as fotos na tela do próprio Google, e importamos <strong>apenas as
            fotos que você selecionar</strong> para o seu cofre. Não acessamos o restante da sua biblioteca.</li>
        <li>As fotos importadas e as credenciais de acesso são guardadas cifradas. Você pode desconectar a qualquer momento, e o acesso é revogado também no Google.</li>
        <li>Não vendemos seus dados, não os usamos para publicidade e não os usamos para treinar modelos de inteligência artificial.</li>
    </ul>
    <p>Os detalhes estão na <a href="{{ route('legal.privacy') }}">Política de Privacidade</a> e nos <a href="{{ route('legal.terms') }}">Termos de Uso</a>.</p>

    <h2>Segurança</h2>
    <p>
        Fotos, áudios e vídeos são guardados cifrados (AES-256-GCM), e a comunicação usa conexão segura. Com a senha do cofre,
        o conteúdo é cifrado no seu próprio aparelho antes de ser enviado.
    </p>

    <p style="margin-top: 32px;">
        <a href="/" style="display: inline-block; background: var(--primary); color: #fff; text-decoration: none; font-weight: 700; padding: 12px 22px; border-radius: 999px;">Acessar o {{ $appName }}</a>
    </p>
@endsection
