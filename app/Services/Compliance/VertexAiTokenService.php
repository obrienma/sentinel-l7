<?php

namespace App\Services\Compliance;

use Google\Auth\Credentials\ServiceAccountCredentials;

class VertexAiTokenService
{
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    public function fetchAccessToken(): string
    {
        $credentials = new ServiceAccountCredentials(self::SCOPE, config('services.vertexai.credentials_path'));

        $token = $credentials->fetchAuthToken()['access_token'] ?? null;

        if ($token === null) {
            throw new \RuntimeException('VertexAiTokenService: failed to mint OAuth2 access token');
        }

        return $token;
    }
}
