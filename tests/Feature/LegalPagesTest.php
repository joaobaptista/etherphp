<?php

it('serves the public privacy policy with the Google API Limited Use disclosure', function (): void {
    $this->get('/privacidade')
        ->assertOk()
        ->assertSee('Política de Privacidade')
        ->assertSee('photospicker.mediaitems.readonly')
        ->assertSee('apenas os itens que você seleciona', false)
        ->assertSee('requisitos de Uso Limitado', false)
        ->assertSee('https://developers.google.com/terms/api-services-user-data-policy', false)
        ->assertSee('myaccount.google.com/permissions', false);

    $this->get('/termos')->assertOk()->assertSee('Termos de Uso')->assertSee('Direito de arrependimento');
    $this->get('/privacy')->assertRedirect('/privacidade');
    $this->get('/terms')->assertRedirect('/termos');
});

it('flags missing legal data instead of inventing it', function (): void {
    config(['ethernity.legal.entity_name' => '', 'ethernity.legal.entity_document' => '', 'ethernity.legal.privacy_email' => '']);

    $this->get('/privacidade')
        ->assertOk()
        ->assertSee('[a definir: nome do responsável]', false)
        ->assertSee('[a definir: CPF/CNPJ]', false)
        ->assertSee('[a definir: e-mail de privacidade]', false);
});

it('shows the configured legal data', function (): void {
    config([
        'ethernity.legal.entity_name' => 'Empresa Exemplo Ltda',
        'ethernity.legal.entity_document' => '00.000.000/0001-00',
        'ethernity.legal.privacy_email' => 'privacidade@example.com',
    ]);

    $this->get('/privacidade')
        ->assertOk()
        ->assertSee('Empresa Exemplo Ltda')
        ->assertSee('00.000.000/0001-00')
        ->assertSee('mailto:privacidade@example.com', false)
        ->assertDontSee('a definir', false);
});
