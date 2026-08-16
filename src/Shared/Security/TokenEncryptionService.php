<?php
namespace Kai\Tools\Shared\Security;

class TokenEncryptionService
{
    private string $key;

    public function __construct(string $base64Key)
    {
        // Schlüssel aus der .env decodieren
        $decodedKey = base64_decode($base64Key);
        
        if (strlen($decodedKey) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \Exception("Ungültige Schlüssellänge für XChaCha20-Poly1305.");
        }
        
        $this->key = $decodedKey;
    }

    /**
     * Verschlüsselt ein Array von Tokens zu einem Base64-String zur Speicherung in der DB.
     */
    public function encryptTokens(array $tokens): string
    {
        $jsonPayload = json_encode($tokens, JSON_UNESCAPED_SLASHES);
        
        // Zufälligen Initialisierungsvektor (Nonce) für jeden Speichervorgang generieren
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        
        // Verschlüsseln (Payload, Additional Data, Nonce, Key)
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $jsonPayload,
            '', 
            $nonce,
            $this->key
        );
        
        // Nonce und Ciphertext zusammenfügen und als Base64 für die Datenbank zurückgeben
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Entschlüsselt den Base64-String aus der DB zurück in das Token-Array.
     */
    public function decryptTokens(string $encryptedBase64): ?array
    {
        $decoded = base64_decode($encryptedBase64);
        
        if ($decoded === false) {
            return null; // Ungültiges Base64
        }
        
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        
        if (strlen($decoded) < $nonceLength) {
            return null; // Daten zu kurz, Manipulation oder Fehler
        }
        
        $nonce = substr($decoded, 0, $nonceLength);
        $ciphertext = substr($decoded, $nonceLength);
        
        // Entschlüsseln
        $decryptedJson = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            '',
            $nonce,
            $this->key
        );
        
        if ($decryptedJson === false) {
            return null; // Entschlüsselung fehlgeschlagen (Falscher Key oder manipuliert)
        }
        
        return json_decode($decryptedJson, true);
    }
}