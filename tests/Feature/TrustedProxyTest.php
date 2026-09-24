<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_hosted_requests_use_https_and_the_client_ip_without_trusting_forwarded_hosts(): void
    {
        config(['trustedproxy.proxies' => '*']);
        Route::get('/proxy-check', fn (Request $request): array => [
            'url' => $request->url(),
            'ip' => $request->ip(),
        ]);

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '203.0.113.5',
            'X-Forwarded-Host' => 'untrusted.example',
        ])->get('http://bank.example/proxy-check');

        $response->assertExactJson([
            'url' => 'https://bank.example/proxy-check',
            'ip' => '203.0.113.5',
        ]);
    }

    public function test_direct_requests_ignore_forwarded_headers_when_no_proxy_is_configured(): void
    {
        config(['trustedproxy.proxies' => null]);
        Route::get('/proxy-check', fn (Request $request): array => [
            'url' => $request->url(),
            'ip' => $request->ip(),
        ]);

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.5',
        ])->get('http://bank.example/proxy-check');

        $response->assertExactJson([
            'url' => 'http://bank.example/proxy-check',
            'ip' => '127.0.0.1',
        ]);
    }
}
