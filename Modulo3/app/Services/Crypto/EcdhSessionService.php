<?php

namespace App\Services\Crypto;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EcdhSessionService
{
    private const HKDF_INFO = 'm3-payment-session-v1';
    private const SESSION_TTL_SECONDS = 300; // 5 minutos

    /**
     * Fase 1: Genera par efímero ECDH P-256 en el servidor y guarda la llave privada en caché.
     */
    public function createSession(string $buyerId, string $reservationId): array
    {
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1', // Equivalente a P-256 / secp256r1
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
            throw new Exception('Error al exportar la llave privada efímera.');
        }

        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];

        // Convertimos PEM a SPKI Base64 puro para que Web Crypto API lo importe directo
        $publicKeySpkiBase64 = $this->pemToSpkiBase64($publicKeyPem);

        $sessionId = 'ecdh_' . Str::ulid();
        $saltBase64 = base64_encode(random_bytes(32));

        Cache::put($this->cacheKey($sessionId), [
            'private_key_pem' => $privateKeyPem,
            'salt' => $saltBase64,
            'buyer_id' => $buyerId,
            'reservation_id' => $reservationId,
        ], now()->addSeconds(self::SESSION_TTL_SECONDS));

        return [
            'sessionId' => $sessionId,
            'serverPublicKey' => $publicKeySpkiBase64,
            'salt' => $saltBase64,
            'curve' => 'P-256',
            'hkdfInfo' => self::HKDF_INFO,
            'expiresIn' => self::SESSION_TTL_SECONDS,
        ];
    }

    /**
     * Fase 3: Deriva el secreto compartido ECDH + HKDF-SHA256, descifra AES-256-GCM
     * y destruye la llave privada efímera de la memoria/caché.
     */
    public function decryptOrderMetadata(
        string $sessionId,
        string $clientPublicKeySpkiBase64,
        string $ivBase64,
        string $ciphertextBase64,
        string $expectedBuyerId
    ): array {
        $session = Cache::get($this->cacheKey($sessionId));

        if (!$session) {
            throw new Exception('La sesión criptográfica ECDH no existe o ya expiró.');
        }

        if ($session['buyer_id'] !== $expectedBuyerId) {
            throw new Exception('La sesión criptográfica no pertenece al comprador autenticado.');
        }

        // Destruimos la llave efímera inmediatamente (un solo uso / Perfect Forward Secrecy)
        $this->destroySession($sessionId);

        $serverPrivateKey = openssl_pkey_get_private($session['private_key_pem']);
        $clientPublicKeyPem = $this->spkiBase64ToPem($clientPublicKeySpkiBase64);
        $clientPublicKey = openssl_pkey_get_public($clientPublicKeyPem);

        if ($serverPrivateKey === false || $clientPublicKey === false) {
            throw new Exception('Llaves ECDH inválidas para derivación.');
        }

        // 1. Secreto compartido ECDH (32 bytes para P-256)
        $sharedSecret = openssl_pkey_derive($clientPublicKey, $serverPrivateKey, 32);
        if ($sharedSecret === false) {
            throw new Exception('Error al derivar el secreto compartido ECDH.');
        }

        // 2. Derivación HKDF-SHA256 -> Llave AES-256 (32 bytes)
        $salt = base64_decode($session['salt'], true);
        $aesKey = hash_hkdf('sha256', $sharedSecret, 32, self::HKDF_INFO, $salt);

        // 3. Descifrado autenticado AES-256-GCM
        $iv = base64_decode($ivBase64, true);
        $combined = base64_decode($ciphertextBase64, true);

        if ($iv === false || strlen($iv) !== 12 || $combined === false || strlen($combined) <= 16) {
            throw new Exception('Formato de IV o texto cifrado AES-GCM inválido.');
        }

        // Web Crypto concatena el Auth Tag de 16 bytes al final del ciphertext
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
            throw new Exception('Fallo de autenticación AES-256-GCM: payload alterado o llave incorrecta.');
        }

        $decoded = json_decode($plaintext, true);
        if (!is_array($decoded)) {
            throw new Exception('El payload descifrado no contiene un JSON válido.');
        }

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
