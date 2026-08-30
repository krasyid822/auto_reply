<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MetaOAuth
{
    use ResolvesInstagramAccountTrait;

    /**
     * URL untuk memulai "Connect with Facebook" (dialog OAuth).
     */
    public function authorizeUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('instagram.client_id'),
            'redirect_uri' => config('instagram.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(',', config('instagram.scopes')),
        ]);

        return 'https://www.facebook.com/'.config('instagram.api_version').'/dialog/oauth?'.$params;
    }

    /**
     * Tukar authorization code -> short-lived token.
     */
    public function exchangeCode(string $code): string
    {
        $data = $this->get('/oauth/access_token', [
            'client_id' => config('instagram.client_id'),
            'client_secret' => config('instagram.client_secret'),
            'redirect_uri' => config('instagram.redirect_uri'),
            'code' => $code,
        ]);

        return (string) ($data['access_token'] ?? throw new InstagramApiException('OAuth code tidak ditukarkan.'));
    }

    /**
     * Perpanjang short-lived -> long-lived (60 hari).
     *
     * @return array{token: string, expires_at: ?\DateTimeInterface}
     */
    public function longLived(string $shortToken): array
    {
        $data = $this->get('/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('instagram.client_id'),
            'client_secret' => config('instagram.client_secret'),
            'fb_exchange_token' => $shortToken,
        ]);

        $token = (string) ($data['access_token'] ?? throw new InstagramApiException('Gagal memperpanjang token.'));
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : null;

        return [
            'token' => $token,
            'expires_at' => $expiresIn !== null ? now()->addSeconds($expiresIn) : null,
        ];
    }

    protected function get(string $path, array $query = []): array
    {
        $base = rtrim((string) config('instagram.api_base'), '/').'/'.config('instagram.api_version');

        $response = Http::baseUrl($base)
            ->timeout(30)
            ->get($path, $query);

        return $this->parse($response);
    }

    protected function parse(Response $response): array
    {
        $json = $response->json();

        if (isset($json['error'])) {
            $err = $json['error'];
            throw new InstagramApiException(
                (string) ($err['message'] ?? 'Kesalahan OAuth'),
                (int) ($err['code'] ?? 0),
                isset($err['error_subcode']) ? (int) $err['error_subcode'] : null,
            );
        }

        return is_array($json) ? $json : [];
    }
}
