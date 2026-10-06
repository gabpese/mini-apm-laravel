<?php

it('serves the public demo page', function () {
    $response = $this->get('/demo')->assertOk();

    expect($response->baseResponse->getFile()->getContent())
        ->toContain('mini-apm browser demo')
        ->toContain('/demo/mini-apm.js');
});

it('serves the JavaScript client the demo page imports', function () {
    $response = $this->get('/demo/mini-apm.js')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('javascript')
        ->and($response->baseResponse->getFile()->getContent())->toContain('export class MiniApm');
});

it('lets browsers on another origin call the ingestion API', function () {
    $this->call('OPTIONS', '/api/v1/events', server: [
        'HTTP_ORIGIN' => 'https://some-app.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
    ])
        ->assertSuccessful()
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

describe('public demo key', function () {
    it('opens the demo with the key filled in when one is configured', function () {
        config(['services.demo.api_key' => 'apm_publicdemokey0123456789']);

        $this->get('/demo')->assertRedirect('/demo?key=apm_publicdemokey0123456789');
    });

    it('does not redirect when the visitor already brought a key', function () {
        config(['services.demo.api_key' => 'apm_publicdemokey0123456789']);

        $this->get('/demo?key=apm_mine')->assertOk();
    });

    it('does not redirect when no demo key is configured', function () {
        config(['services.demo.api_key' => null]);

        $this->get('/demo')->assertOk();
    });
});
