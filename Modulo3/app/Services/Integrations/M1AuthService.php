<?php

namespace App\Services\Integrations;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class M1AuthService
{
    public function validateBuyerToken(?string $authHeader): array
    {
        if (config('modulo3.use_mocks')) {
            return [
                'sub' => 'usr_102938',
                'sessionId' => 'ses_445566',
                'role' => 'BUYER',
                'exp' => now()->addHour()->timestamp,
            ];
        }

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            throw new Exception('Token JWT no proporcionado o formato inválido.');
        }

        $jwt = trim(substr($authHeader, 7));

        $jwks = Cache::remember('m1_jwks_keys', 300, function () {
            $response = Http::timeout(5)->get(config('modulo3.services.m1_jwks_url'));
            if ($response->failed()) {
                throw new Exception('No se pudieron obtener las llaves públicas (JWKS) de M1.');
            }
            return $response->json();
        });

        $decoded = (array) JWT::decode($jwt, JWK::parseKeySet($jwks));

        if (($decoded['role'] ?? null) !== 'BUYER') {
            throw new Exception('El usuario no tiene rol BUYER autorizado.');
        }

        return $decoded;
    }
}
