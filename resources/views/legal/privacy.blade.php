@extends('legal.layout')

@section('title', 'Política de Privacidade')
@section('description', 'Como o Ethernity DMs coleta, usa, protege e exclui seus dados, incluindo os dados recebidos do Google.')

@section('content')
    <h1>Política de Privacidade</h1>
    <p class="updated">Última atualização: {{ \Illuminate\Support\Carbon::parse($updatedAt)->format('d/m/Y') }}</p>

    <p>
        Esta política explica como o <strong>{{ $appName }}</strong> ({{ $appHost }}) trata os seus dados pessoais.
        O responsável pelo tratamento é <x-legal-field :value="$entityName" label="nome do responsável" />@if ($entityDocument || ! $entityName) (<x-legal-field :value="$entityDocument" label="CPF/CNPJ" />)@endif.
        Para qualquer assunto de privacidade, escreva para
        @if ($privacyEmail)<a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>@else<x-legal-field :value="null" label="e-mail de privacidade" />@endif.
    </p>

    <h2>1. Dados que coletamos</h2>
    <ul>
        <li><strong>Conta:</strong> nome, e-mail, telefone (opcional), foto de perfil e senha. A senha é guardada apenas em forma de <em>hash</em>, nunca em texto.</li>
        <li><strong>Login com Google:</strong> se você entrar com o Google, recebemos seu nome, e-mail e foto de perfil.</li>
        <li><strong>Conteúdo que você cria:</strong> cápsulas, cartas e mensagens, fotos, áudios, vídeos, histórias das fotos e datas de entrega.</li>
        <li><strong>Pessoas que você indica:</strong> nome, e-mail, telefone, parentesco e foto de destinatários e guardiões que você cadastrar. Ao cadastrá-los, você declara ter base legítima para informar esses dados.</li>
        <li><strong>Google Fotos:</strong> somente as fotos que você escolher no seletor do Google, além das credenciais de acesso da conexão (veja a seção 3).</li>
        <li><strong>Pagamentos:</strong> processados pelo <strong>Mercado Pago</strong>. Não recebemos nem guardamos os dados do seu cartão; recebemos apenas o resultado do pagamento (situação, valor e identificador).</li>
        <li><strong>Dados técnicos de segurança:</strong> como o endereço IP, usado para limitar tentativas de login e proteger as contas.</li>
    </ul>

    <h2>2. Como usamos os dados</h2>
    <ul>
        <li>Prestar o serviço: guardar suas memórias, montar as cápsulas e entregá-las às pessoas e nas datas que você definir.</li>
        <li>Autenticar seu acesso e manter sua conta segura.</li>
        <li>Processar a assinatura dos planos pagos.</li>
        <li>Cumprir obrigações legais.</li>
    </ul>
    <p>Não vendemos seus dados, não os usamos para publicidade e não os compartilhamos para fins de marketing.</p>

    <h2 id="google">3. Dados recebidos do Google</h2>
    <h3>3.1 O que acessamos</h3>
    <ul>
        <li><strong>Login com Google</strong> (escopos <code>openid</code>, <code>email</code> e <code>profile</code>): seu nome, e-mail, foto e um identificador da conta, para criar e acessar sua conta.</li>
        <li><strong>Google Fotos</strong> (escopo <code>photospicker.mediaitems.readonly</code>, da Google Photos Picker API): <strong>apenas os itens que você seleciona</strong> na tela do próprio Google. Não temos acesso ao restante da sua biblioteca, aos seus álbuns nem a fotos que você não escolher.</li>
    </ul>
    <h3>3.2 Como usamos</h3>
    <p>
        Usamos os dados do Google somente para identificar sua conta e para importar as fotos que você escolheu
        para o seu cofre no {{ $appName }}, vinculadas à cápsula que você indicar. Vídeos selecionados no seletor não são importados
        nem armazenados. Não usamos esses dados para nenhuma outra finalidade.
    </p>
    <h3>3.3 Como guardamos e protegemos</h3>
    <ul>
        <li>As mídias importadas são guardadas <strong>cifradas (AES-256-GCM)</strong> no nosso armazenamento.</li>
        <li>As credenciais de acesso da conexão com o Google Fotos são guardadas <strong>cifradas</strong> e usadas apenas para criar as sessões de seleção que você solicitar.</li>
    </ul>
    <h3>3.4 Com quem compartilhamos</h3>
    <p>
        Não compartilhamos dados recebidos do Google com terceiros. As exceções são: o provedor de infraestrutura que armazena os
        dados (sempre cifrados) em nosso nome; as pessoas para quem <strong>você</strong> decidir entregar uma cápsula, que receberão as
        mídias que você incluir nela; e quando a lei exigir.
    </p>
    <h3>3.5 O que nunca fazemos com dados do Google</h3>
    <ul>
        <li>Não vendemos nem transferimos esses dados para plataformas de publicidade, corretores de dados ou revendedores de informação.</li>
        <li>Não usamos esses dados para exibir anúncios, nem para avaliar crédito ou para concessão de empréstimos.</li>
        <li>Não usamos esses dados para desenvolver, melhorar ou treinar modelos de inteligência artificial ou de aprendizado de máquina generalizados.</li>
        <li>Nenhuma pessoa da nossa equipe lê esses dados, exceto com o seu consentimento expresso, quando necessário para segurança (por exemplo, investigar abuso), para cumprir a lei, ou de forma agregada e anonimizada para operar o serviço.</li>
    </ul>
    <div class="box">
        O uso e a transferência, para qualquer outro aplicativo, de informações recebidas das APIs do Google pelo {{ $appName }}
        seguirão a <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener">Política de Dados do Usuário dos Serviços de API do Google</a>,
        incluindo os requisitos de Uso Limitado (<em>Limited Use</em>).
    </div>
    <h3>3.6 Como desconectar</h3>
    <p>
        Você pode desconectar o Google Fotos a qualquer momento no próprio app. Ao desconectar, revogamos o acesso junto ao Google e
        apagamos as credenciais guardadas. Você também pode remover o acesso em
        <a href="https://myaccount.google.com/permissions" rel="noopener">myaccount.google.com/permissions</a>.
        As fotos já importadas continuam no seu cofre até que você as exclua.
    </p>

    <h2>4. Processamento no seu aparelho</h2>
    <p>
        Os recursos de inteligência artificial para sugerir textos e a identificação de rostos para organizar fotos funcionam no seu
        próprio navegador. As imagens e textos usados nesses recursos não são enviados aos nossos servidores para esse fim.
    </p>

    <h2>5. Segurança</h2>
    <ul>
        <li>Toda a comunicação usa conexão cifrada (HTTPS).</li>
        <li>Fotos, áudios e vídeos são guardados cifrados (AES-256-GCM).</li>
        <li>Quando você cria a senha do cofre, títulos e conteúdos são cifrados no seu aparelho antes de serem enviados; sem essa senha, nem nós conseguimos lê-los.</li>
        <li>Ao excluir um arquivo, ele é sobrescrito antes de ser removido do disco.</li>
    </ul>

    <h2>6. Armazenamento no navegador</h2>
    <p>
        Usamos o armazenamento local do navegador e cookies técnicos de sessão apenas para manter seu acesso e suas
        preferências (como idioma e tema). Não usamos cookies de rastreamento, de publicidade nem ferramentas de análise de terceiros.
    </p>

    <h2>7. Retenção e exclusão</h2>
    <p>
        Guardamos seus dados enquanto sua conta estiver ativa. Você pode excluir cápsulas, mídias e mensagens a qualquer momento
        no app; a exclusão é definitiva. Para excluir sua conta e todos os dados associados, escreva para
        @if ($privacyEmail)<a href="mailto:{{ $privacyEmail }}">{{ $privacyEmail }}</a>@else<x-legal-field :value="null" label="e-mail de privacidade" />@endif.
        Dados que a lei obrigue a manter (por exemplo, registros de pagamento) são guardados apenas pelo prazo legal.
    </p>

    <h2>8. Seus direitos (LGPD)</h2>
    <p>
        Nos termos da Lei Geral de Proteção de Dados (Lei nº 13.709/2018), você pode pedir a confirmação e o acesso aos seus dados,
        a correção, a anonimização ou a exclusão, a portabilidade, informações sobre compartilhamento e a revogação do consentimento.
        Atenderemos os pedidos nos prazos previstos na LGPD. Você também pode apresentar reclamação à Autoridade Nacional de Proteção de Dados (ANPD).
    </p>

    <h2>9. Crianças e adolescentes</h2>
    <p>
        O {{ $appName }} é destinado a pessoas maiores de idade. Dados de crianças e adolescentes que você informar (por exemplo, como
        destinatários de uma cápsula) são tratados sob sua responsabilidade e sempre no melhor interesse deles.
    </p>

    <h2>10. Alterações</h2>
    <p>
        Podemos atualizar esta política. Mudanças relevantes serão comunicadas no app ou por e-mail, e a data de atualização no topo
        desta página será alterada.
    </p>
@endsection
