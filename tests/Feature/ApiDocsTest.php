<?php

it('keeps the API docs closed outside the local environment by default', function (): void {
    config(['ethernity.security.api_docs_public' => false]);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

it('serves the OpenAPI document generated from the Laravel routes when enabled', function (): void {
    config(['ethernity.security.api_docs_public' => true]);

    $response = $this->getJson('/docs/api.json')->assertOk();

    expect($response->json('info.title'))->toBe('Ethernity API')
        ->and($response->json('components.securitySchemes.http.scheme'))->toBe('bearer')
        ->and(array_keys($response->json('paths')))->toContain('/memories', '/capsules/{id}', '/subscriptions/webhook', '/integrations/google-photos/sessions/{sessionId}/import')
        ->and($response->json('paths./login.post.security'))->toBe([])
        ->and($response->json('paths./memories.get.summary'))->toBe('Listar memórias')
        ->and(array_keys($response->json('paths./vault/media/upload.post.requestBody.content')))->toBe(['multipart/form-data']);

    $this->withoutVite()->get('/docs/api')->assertOk()->assertSee('Ethernity API')->assertSee('id="swagger-ui"', false);
});

it('keeps the Node-era documentation addresses working', function (): void {
    $this->get('/docs')->assertRedirect('/docs/api');
    $this->get('/openapi.json')->assertRedirect('/docs/api.json');
});
