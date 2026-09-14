<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InstagramGraphClient
{
    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $token, string $path, array $query = [], int $timeout = 25): Response
    {
        $url = $this->url($path);

        return Http::timeout($timeout)
            ->acceptJson()
            ->withToken($token)
            ->get($url, $query);
    }

    public function url(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((string) config('services.meta.instagram_graph_base_url', 'https://graph.instagram.com'), '/');
        $version = trim((string) config('services.meta.graph_api_version', 'v25.0'), '/');

        return $base.'/'.$version.'/'.ltrim($path, '/');
    }
}
