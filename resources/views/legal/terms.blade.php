@extends('legal.layout')

@section('title', 'Termos de Uso')
@section('description', 'Regras de uso do Ethernity DMs: conta, conteúdo, planos, pagamentos e cancelamento.')

@section('content')
    <h1>Termos de Uso</h1>
    <p class="updated">Última atualização: {{ \Illuminate\Support\Carbon::parse($updatedAt)->format('d/m/Y') }}</p>

    <p>
        Estes termos regem o uso do <strong>{{ $appName }}</strong> ({{ $appHost }}), oferecido por
        <x-legal-field :value="$entityName" label="nome do responsável" />@if ($entityDocument || ! $entityName) (<x-legal-field :value="$entityDocument" label="CPF/CNPJ" />)@endif.
        Ao criar uma conta ou usar o serviço, você concorda com estes termos e com a
        <a href="{{ route('legal.privacy') }}">Política de Privacidade</a>.
    </p>

    <h2>1. O serviço</h2>
    <p>
        O {{ $appName }} permite guardar memórias (fotos, vídeos, áudios e cartas) em um cofre cifrado e organizá-las em cápsulas
        para entrega a pessoas escolhidas por você, em datas definidas ou após o seu falecimento, com o apoio de guardiões.
    </p>

    <h2>2. Sua conta</h2>
    <ul>
        <li>Você é responsável pelas informações da conta e por manter sua senha em sigilo.</li>
        <li><strong>Senha do cofre:</strong> se você criar uma senha do cofre, guarde também a chave de recuperação. Sem elas, o conteúdo cifrado no seu aparelho não pode ser recuperado, nem por nós.</li>
        <li>Avise-nos imediatamente se perceber uso não autorizado da sua conta.</li>
    </ul>

    <h2>3. Seu conteúdo</h2>
    <ul>
        <li>O conteúdo que você envia continua sendo seu. Você nos concede apenas a permissão necessária para armazená-lo, processá-lo e entregá-lo conforme as suas instruções.</li>
        <li>Você declara ter os direitos sobre o conteúdo que envia e autorização para informar dados de terceiros (destinatários e guardiões).</li>
        <li>Não é permitido usar o serviço para conteúdo ilegal, que viole direitos de terceiros ou que envolva exploração de crianças e adolescentes.</li>
    </ul>

    <h2>4. Integração com o Google Fotos</h2>
    <p>
        A conexão com o Google Fotos é opcional e acessa apenas os itens que você selecionar no seletor do Google. O uso desses dados
        segue a seção 3 da <a href="{{ route('legal.privacy') }}">Política de Privacidade</a>. Você pode desconectar a qualquer momento.
    </p>

    <h2>5. Planos e pagamentos</h2>
    <ul>
        <li>Os preços e limites de cada plano são exibidos no app no momento da contratação.</li>
        <li><strong>Cartão:</strong> a assinatura é renovada automaticamente a cada mês ou a cada ano, conforme o ciclo escolhido, e cobrada pelo Mercado Pago até você cancelar.</li>
        <li><strong>PIX:</strong> paga um único período, sem renovação automática.</li>
        <li><strong>Cancelamento:</strong> você pode cancelar a renovação a qualquer momento no app. O plano continua ativo até o fim do período já pago e depois volta ao plano gratuito.</li>
        <li><strong>Direito de arrependimento:</strong> você pode desistir da contratação em até 7 dias, nos termos do art. 49 do Código de Defesa do Consumidor.</li>
    </ul>

    <h2>6. Disponibilidade</h2>
    <p>
        Trabalhamos para manter o serviço disponível e seguro, mas ele pode passar por interrupções para manutenção ou por causas
        fora do nosso controle. Recomendamos manter cópias próprias dos arquivos mais importantes.
    </p>

    <h2>7. Encerramento</h2>
    <p>
        Você pode encerrar sua conta a qualquer momento (veja a seção 7 da Política de Privacidade). Podemos suspender contas que
        violem estes termos, avisando sempre que possível.
    </p>

    <h2>8. Alterações</h2>
    <p>Podemos atualizar estes termos. Mudanças relevantes serão comunicadas com antecedência no app ou por e-mail.</p>

    <h2>9. Lei aplicável e contato</h2>
    <p>
        Estes termos seguem as leis do Brasil. Fica eleito o foro do domicílio do consumidor. Dúvidas:
        @if ($privacyEmail)<a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>@else<x-legal-field :value="null" label="e-mail de contato" />@endif.
    </p>
@endsection
