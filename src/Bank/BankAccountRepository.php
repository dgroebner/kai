<?php
namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Security\TokenEncryptionService;

class BankAccountRepository
{
    private \PDO $pdo;
	private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
		$this->logger = new Logger(14);
    }

    /**
     * Lädt alle Bankkonten (ohne die verschlüsselten Credentials).
     */
    public function getAllAccounts(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, account_name, account_type, iban, current_balance 
            FROM bank_accounts 
            ORDER BY id ASC
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Lädt ein Bankkonto nach Typ.
     */
    public function getAccountByType(string $type): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, account_name, account_type, iban, current_balance 
            FROM bank_accounts 
            WHERE account_type = :type AND is_active = 1 
            LIMIT 1
        ");
        $stmt->execute([':type' => $type]);
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Lädt ein Bankkonto nach IBAN.
     */
    public function getAccountByIban(string $iban): ?array
    {
        $normalizedIban = str_replace(' ', '', strtoupper($iban));
        $stmt = $this->pdo->prepare("
            SELECT id, account_name, account_type, iban, current_balance 
            FROM bank_accounts 
            WHERE REPLACE(iban, ' ', '') = :iban AND is_active = 1 
            LIMIT 1
        ");
        $stmt->execute([':iban' => $normalizedIban]);
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Speichert die verschlüsselten OAuth-Tokens für ein bestimmtes Konto.
     */
    public function saveApiTokens(int $accountId, array $tokens, TokenEncryptionService $encryptionService): bool
    {
        $encryptedBase64 = $encryptionService->encryptTokens($tokens);

        $stmt = $this->pdo->prepare("
            UPDATE bank_accounts 
            SET api_credentials = :api_credentials 
            WHERE id = :id
        ");

        return $stmt->execute([
            ':api_credentials' => $encryptedBase64,
            ':id'              => $accountId
        ]);
    }

    /**
     * Lädt und entschlüsselt die OAuth-Tokens für ein bestimmtes Konto.
     * Gibt das Token-Array zurück oder null, falls keine Tokens existieren 
     * oder die Entschlüsselung fehlschlägt.
     */
    public function getApiTokens(int $accountId, TokenEncryptionService $encryptionService): ?array
    {
        $stmt = $this->pdo->prepare("SELECT api_credentials FROM bank_accounts WHERE id = :id");
        $stmt->execute([':id' => $accountId]);
        $encryptedBase64 = $stmt->fetchColumn();

        if (empty($encryptedBase64)) {
            return null;
        }

        return $encryptionService->decryptTokens($encryptedBase64);
    }

    /**
     * Aktualisiert den aktuellen Kontostand (Saldo).
     */
    public function updateBalance(int $accountId, float $balance): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE bank_accounts 
            SET current_balance = :balance, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        return $stmt->execute([
            ':balance' => $balance,
            ':id'      => $accountId
        ]);
    }
	
	/**
     * Prüft, ob gültige und nicht abgelaufene Tokens für das Konto existieren.
     * Im Test-Szenario läuft ein Token nach 10 Minuten (600 Sekunden) ab.
     */
    public function areTokensValid(int $accountId, TokenEncryptionService $encryptionService): bool
    {
        $tokens = $this->getApiTokens($accountId, $encryptionService);
        
        if (!$tokens || !isset($tokens['expires_in']) || !isset($tokens['created_at'])) {
            return false;
        }

        $expiresIn = (int)$tokens['expires_in'];
		$createdAt = (int)$tokens['created_at'];
        $maxAge = 600; // Erlaubte Rest-Gültigkeit
		
		$expirationTime = $createdAt + $expiresIn;
		$currentTime = time();
		$remainingTime = $expirationTime - $currentTime;
		
		$this->logger->debug("BankAccountRepository.areTokensValid: Remaining time $remainingTime seconds.", ['remaining' => $remainingTime]);

        // Prüfen, ob das Zeitfenster überschritten wurde
        return $remainingTime > $maxAge;
    }
	
	/**
	 * Liefert den Zeitpunkt der letzten Aktualisierung eines Kontos (updated_at)
	 * als DATETIME-String oder null, falls noch nie aktualisiert wurde.
	 */
	public function getUpdatedAt(int $accountId): ?string
	{
		$stmt = $this->pdo->prepare("SELECT updated_at FROM bank_accounts WHERE id = :id");
		$stmt->execute([':id' => $accountId]);
		$result = $stmt->fetchColumn();

		return $result !== false && $result !== null ? (string)$result : null;
	}

	public function getLastSyncDate($accountId) {
		$stmt = $this->pdo->prepare("SELECT updated_at FROM bank_accounts WHERE id = ?");
		$stmt->execute([$accountId]);
		$result = $stmt->fetchColumn();
		
		// Falls noch nie synchronisiert, nimm ein Standarddatum oder null
		return $result ? date('Y-m-d', strtotime($result)) : '2026-01-01';
	}
}