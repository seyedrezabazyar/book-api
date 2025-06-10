<?php

namespace App\Services;

use App\Models\Config;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ApiClient
{
    public function __construct(private Config $config) {}

    public function request(string $url): Response
    {
        $generalSettings = $this->config->getGeneralSettings();

        return Http::timeout($this->config->timeout)
            ->retry(2, 1000)
            ->when(
                !($generalSettings['verify_ssl'] ?? true),
                fn($client) => $client->withoutVerifying()
            )
            ->get($url);
    }
}
