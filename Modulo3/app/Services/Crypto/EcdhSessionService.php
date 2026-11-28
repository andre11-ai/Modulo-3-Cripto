<?php

namespace App\Services\Crypto;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EcdhSessionService
{
    private const HKDF_INFO = 'm3-payment-session-v1';
    private const SESSION_TTL_SECONDS = 300;

    public function createSession(string $buyerId, string $reservationId): array
    {
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];

        $opensslConf = env('OPENSSL_CONF');
        if ($opensslConf && file_exists($opensslConf)) {
            $config['config'] = $opensslConf;
        }

        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new Exception('Error al generar el par de llaves ECDH P-256 en OpenSSL.');
        }

        if (!openssl_pkey_export($res, $privateKeyPem, null, $config)) {
            throw new Exception('Error al exportar la llave privada efimera.');
        }

        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];
        $publicKeySpkiBase64 = $this->pemToSpkiBase64($publicKeyPem);

        $sessionId = 'ecdh_' . Str::ulid();
        $saltBase64 = base64_encode(random_bytes(32));

        Cache::put($this->cacheKey($sessionId), [
            'private_key_pem' => $privateKeyPem,
            'salt' => $saltBase64,
            'buyer_id' => $buyerId,
            'reservation_id' => $reservationId,
        ], now()->addSeconds(self::SESSION_TTL_SECONDS));

        Log::channel('crypto_tunnel')->info(
            "\n+----------------------------------------------------------------------+\n" .
            "| FASE 1: HANDSHAKE ECDH P-256 (SERVIDOR LARAVEL)                      |\n" .
            "+----------------------------------------------------------------------+\n" .
            "  ID de Sesion       : {$sessionId}\n" .
            "  Comprador / Reserva: {$buyerId} | {$reservationId}\n" .
            "  Llave Publica PubS : " . substr($publicKeySpkiBase64, 0, 44) . "...\n" .
            "  Salt Aleatorio 32B : {$saltBase64}\n" .
            "  Llave Privada PrivS: Guardada temporalmente en memoria (TTL 300s)\n" .
            "+----------------------------------------------------------------------+"
        );

        return [
            'sessionId' => $sessionId,
            'serverPublicKey' => $publicKeySpkiBase64,
            'salt' => $saltBase64,
            'curve' => 'P-256',
            'hkdfInfo' => self::HKDF_INFO,
            'expiresIn' => self::SESSION_TTL_SECONDS,
        ];
    }

    public function decryptOrderMetadata(
        string $sessionId,
        string $clientPublicKeySpkiBase64,
        string $ivBase64,
        string $ciphertextBase64,
        string $expectedBuyerId
    ): array {
        $session = Cache::get($this->cacheKey($sessionId));

        if (!$session) {
            throw new Exception('La sesion criptografica ECDH no existe o ya expiro.');
        }

        if ($session['buyer_id'] !== $expectedBuyerId) {
            throw new Exception('La sesion criptografica no pertenece al comprador autenticado.');
        }

        $this->destroySession($sessionId);

        $serverPrivateKey = openssl_pkey_get_private($session['private_key_pem']);
        $clientPublicKeyPem = $this->spkiBase64ToPem($clientPublicKeySpkiBase64);
        $clientPublicKey = openssl_pkey_get_public($clientPublicKeyPem);

        if ($serverPrivateKey === false || $clientPublicKey === false) {
            throw new Exception('Llaves ECDH invalidas para derivacion.');
        }

        $sharedSecret = openssl_pkey_derive($clientPublicKey, $serverPrivateKey, 32);
        if ($sharedSecret === false) {
            throw new Exception('Error al derivar el secreto compartido ECDH.');
        }

        $salt = base64_decode($session['salt'], true);
        $aesKey = hash_hkdf('sha256', $sharedSecret, 32, self::HKDF_INFO, $salt);

        $iv = base64_decode($ivBase64, true);
        $combined = base64_decode($ciphertextBase64, true);

        if ($iv === false || strlen($iv) !== 12 || $combined === false || strlen($combined) <= 16) {
            throw new Exception('Formato de IV o texto cifrado AES-GCM invalido.');
        }

        $tag = substr($combined, -16);
        $ciphertext = substr($combined, 0, -16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            Log::channel('crypto_tunnel')->error("[ALERTA AES-256-GCM] Fallo de integridad (Auth Tag invalido) en sesion {$sessionId}");
            throw new Exception('Fallo de autenticacion AES-256-GCM: payload alterado o llave incorrecta.');
        }

        $decoded = json_decode($plaintext, true);
        if (!is_array($decoded)) {
            throw new Exception('El payload descifrado no contiene un JSON valido.');
        }

        Log::channel('crypto_tunnel')->info(
            "\n+----------------------------------------------------------------------+\n" .
            "| FASE 2: DATOS CIFRADOS RECIBIDOS DEL NAVEGADOR (WEB CRYPTO API)      |\n" .
            "+----------------------------------------------------------------------+\n" .
            "  Llave Publica PubC : " . substr($clientPublicKeySpkiBase64, 0, 44) . "...\n" .
            "  Vector Inicial (IV): {$ivBase64} (12 bytes)\n" .
            "  Etiqueta Auth Tag  : " . base64_encode($tag) . " (16 bytes)\n" .
            "  Texto Cifrado      : " . substr($ciphertextBase64, 0, 44) . "...\n" .
            "+----------------------------------------------------------------------+\n" .
            "| FASE 3: DERIVACION HKDF Y DESCIFRADO AES-256-GCM (SERVIDOR LARAVEL)  |\n" .
            "+----------------------------------------------------------------------+\n" .
            "  Llave AES-256 HKDF : " . substr(bin2hex($aesKey), 0, 32) . "... (32 bytes)\n" .
            "  Integridad GCM     : VERIFICADA OK (Sin alteraciones en transito)\n" .
            "  Metadatos Planos   : {$plaintext}\n" .
            "  Seguridad PFS      : Llave privada efimera PrivS DESTRUIDA de memoria\n" .
            "+----------------------------------------------------------------------+"
        );

        return [
            'metadata' => $decoded,
            'reservation_id' => $session['reservation_id'],
        ];
    }

    public function destroySession(string $sessionId): void
    {
        Cache::forget($this->cacheKey($sessionId));
    }

    private function cacheKey(string $sessionId): string
    {
        return "m3:ecdh:session:{$sessionId}";
    }

    private function pemToSpkiBase64(string $pem): string
    {
        $clean = str_replace([
            '-----BEGIN PUBLIC KEY-----',
            '-----END PUBLIC KEY-----',
            "\r",
            "\n",
            ' ',
        ], '', $pem);

        return trim($clean);
    }

    private function spkiBase64ToPem(string $spkiBase64): string
    {
        $clean = preg_replace('/\s+/', '', $spkiBase64);
        $chunked = chunk_split($clean, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$chunked}-----END PUBLIC KEY-----\n";
    }
}
