<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\AiTagClassifier;
use Kai\Tools\Bank\BankAccountRepository;
use Kai\Tools\Bank\BankContractRepository;
use Kai\Tools\Bank\BankGiroService;
use Kai\Tools\Bank\BankTagRepository;
use Kai\Tools\Bank\BankTagService;
use Kai\Tools\Bank\BankTransactionRepository;
use Kai\Tools\Bank\ComdirectClient;
use Kai\Tools\Bank\ContractAssignmentService;
use Kai\Tools\Bank\CreditCardRepository;
use Kai\Tools\Bank\RuleMatcher;
use Kai\Tools\Bank\StatementMatcher;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Shared\Security\Sanitizer;
use Kai\Tools\Shared\Security\TokenEncryptionService;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check (AGENTS.md)
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}

// 4. CSRF-Token-Check (AGENTS.md)
Auth::requireCsrfToken($data);

$action = $data['action'] ?? '';

/**
 * Ermittelt die ID des aktiven Girokontos oder bricht mit einer Fehlerantwort ab.
 */
$requireCheckingAccountId = static function (BankAccountRepository $repository): int {
    $account = $repository->getAccountByType('checking');
    if ($account === null) {
        Auth::sendJsonError(404, 'Kein Girokonto gefunden.');
    }

    return (int)$account['id'];
};

try {

    // Startet den Login- und photoTAN-Push Validierungs-Flow
    if ($action === 'start_auth_flow') {
        $accessId = trim((string)($data['access_id'] ?? ''));
        $pin = trim((string)($data['pin'] ?? ''));

        if ($accessId === '' || $pin === '') {
            Auth::sendJsonError(400, 'Bitte Zugangsnummer und PIN eingeben.');
        }

        // photoTAN Fehlversuche checken (Sperrschutz nach 2 Fehlversuchen)
        if (isset($_SESSION['phototan_failures']) && $_SESSION['phototan_failures'] >= 2) {
            Auth::sendJsonError(403, 'photoTAN-Sperrschutz aktiv (2 Fehlversuche). Bitte erst auf der comdirect-Webseite anmelden/freigeben.');
        }

        try {
            $client = new ComdirectClient();

            // 1. Passwort-basierten Flow anstoßen
            $tokens = $client->getAccessTokenWithPassword($accessId, $pin);

            // 2. Session ermitteln
            $sessions = $client->getSessions($tokens['access_token']);
            if (empty($sessions)) {
                throw new Exception("Keine aktive Session für diesen Benutzer gefunden.");
            }
            $sessionObj = $sessions[0];
            $sessionId = (string)($sessionObj['identifier'] ?? '');

            // 3. photoTAN-Push initiieren
            $sessionObj['sessionTanActive'] = true;
            $sessionObj['activated2FA'] = true;
            $tanInfo = $client->validateSession($tokens['access_token'], $sessionId, $sessionObj);

            // Temporäre Auth-Daten in Session speichern
            $_SESSION['comdirect_temp_auth'] = [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => $tokens['expires_in'],
                'created_at' => $tokens['created_at'],
                'session_id' => $sessionId,
                'session_obj' => $sessionObj,
                'tan_info' => $tanInfo
            ];

            echo json_encode([
                'success' => true,
                'status' => 'pending',
                'message' => 'photoTAN-Push wurde gesendet. Bitte in der App freigeben.'
            ]);
            exit;

        } catch (Throwable $e) {
            (new Logger())->error('bank/api.php: start_auth_flow Fehler', ['error' => $e->getMessage()]);
            Auth::sendJsonError(500, 'Anmeldung bei comdirect fehlgeschlagen.');
        }
    }

    // Prüft den photoTAN Push Status und führt das Upgrade durch
    if ($action === 'check_phototan_status') {
        // Falls Sperre zurückgesetzt werden soll (Nutzer hat Checkbox angeklickt)
        $resetLock = isset($data['reset_lock']) && $data['reset_lock'] === true;
        if ($resetLock) {
            $_SESSION['phototan_failures'] = 0;
        }

        if (isset($_SESSION['phototan_failures']) && $_SESSION['phototan_failures'] >= 2) {
            echo json_encode([
                'success' => false,
                'status' => 'blocked',
                'message' => 'photoTAN-Sperrschutz aktiv (2 Fehlversuche). Bitte erst auf der comdirect-Webseite erfolgreich anmelden/TAN-freigeben.'
            ]);
            exit;
        }

        $tempAuth = $_SESSION['comdirect_temp_auth'] ?? null;
        if (!$tempAuth) {
            Auth::sendJsonError(400, 'Keine laufende Authentifizierung gefunden. Bitte erneut einloggen.');
        }

        try {
            $client = new ComdirectClient();

            // Status prüfen / Session aktivieren
            $activated = $client->activateSession(
                $tempAuth['access_token'],
                $tempAuth['session_id'],
                $tempAuth['session_obj'],
                $tempAuth['tan_info']
            );

            if (!$activated) {
                echo json_encode(['success' => true, 'status' => 'pending']);
                exit;
            }

            // Upgrade zu Secondary Token
            $secondaryTokens = $client->getSecondaryToken($tempAuth['access_token']);

            // In DB speichern
            $accountRepository = new BankAccountRepository();
            $accountId = $requireCheckingAccountId($accountRepository);

            $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
            $accountRepository->saveApiTokens($accountId, $secondaryTokens, $encryptionService);

            // Erfolg -> Zähler resetten & temp leeren
            $_SESSION['phototan_failures'] = 0;
            unset($_SESSION['comdirect_temp_auth']);

            echo json_encode(['success' => true, 'status' => 'authenticated']);
            exit;

        } catch (Throwable $e) {
            if (!isset($_SESSION['phototan_failures'])) {
                $_SESSION['phototan_failures'] = 0;
            }
            $_SESSION['phototan_failures']++;

            $failures = $_SESSION['phototan_failures'];
            (new Logger())->error("bank/api.php check_phototan_status error (Versuch $failures/2)", ['error' => $e->getMessage()]);

            $isBlocked = $failures >= 2;
            echo json_encode([
                'success' => false,
                'status' => $isBlocked ? 'blocked' : 'error',
                'message' => $isBlocked
                    ? 'photoTAN-Sperrschutz aktiv (2 Fehlversuche). Bitte erst auf der comdirect-Webseite erfolgreich anmelden/TAN-freigeben.'
                    : 'Fehler bei TAN-Aktivierung. Bitte erneut versuchen.'
            ]);
            exit;
        }
    }

    // Führt den eigentlichen Sync-Prozess aus
    if ($action === 'run_sync') {
        $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
        $repo = new BankAccountRepository();
        $accountId = $requireCheckingAccountId($repo);

        $tokens = $repo->getApiTokens($accountId, $encryptionService);
        if (!$tokens) {
            Auth::sendJsonError(401, 'Keine Tokens gefunden. Bitte zuerst authentifizieren.');
        }

        // Falls Token abläuft, versuchen wir ihn direkt über das Refresh-Token zu erneuern
        $isValid = $repo->areTokensValid($accountId, $encryptionService);
        if (!$isValid) {
            $client = new ComdirectClient();
            try {
                $refreshed = $client->refreshAccessToken($tokens['refresh_token']);
                $repo->saveApiTokens($accountId, $refreshed, $encryptionService);
                $tokens = $refreshed;
            } catch (Throwable $e) {
                (new Logger())->error("bank/api.php run_sync token refresh failed", ['error' => $e->getMessage()]);
                Auth::sendJsonError(401, 'Tokens abgelaufen und Refresh fehlgeschlagen. Bitte erneut anmelden.');
            }
        }

        // Sync ausführen
        $geminiClient = new GeminiClient();
        $bankRepo = new BankTransactionRepository();
        $aiClassifier = new AiTagClassifier($geminiClient);

        $bankGiroService = new BankGiroService(
            $bankRepo,
            $repo,
            $aiClassifier
        );

        $stats = $bankGiroService->syncWithComdirectApi($tokens);

        echo json_encode([
            'success' => true,
            'imported' => $stats['imported'],
            'ignored' => $stats['ignored'],
            'tagged' => $stats['tagged']
        ]);
        exit;
    }

    if ($action === 'check_token_status') {
        // Dynamisches Ermitteln des Girokontos aus der Datenbank
        $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
        $repo = new BankAccountRepository();
        $accountId = $requireCheckingAccountId($repo);

        $isValid = $repo->areTokensValid($accountId, $encryptionService);

        // Ist das Access-Token abgelaufen, zuerst einen Refresh über das gespeicherte
        // Refresh-Token versuchen. Erst wenn das scheitert, wird die Credential-Abfrage nötig.
        if (!$isValid) {
            $tokens = $repo->getApiTokens($accountId, $encryptionService);
            if ($tokens && !empty($tokens['refresh_token'])) {
                try {
                    $client = new ComdirectClient();
                    $refreshed = $client->refreshAccessToken($tokens['refresh_token']);
                    $repo->saveApiTokens($accountId, $refreshed, $encryptionService);
                    $isValid = $repo->areTokensValid($accountId, $encryptionService);
                } catch (Throwable $e) {
                    (new Logger())->error(
                        'bank/api.php check_token_status: Token-Refresh fehlgeschlagen.',
                        ['error' => $e->getMessage()]
                    );
                }
            }
        }

        echo json_encode(['success' => true, 'tokens_valid' => $isValid, 'account_id' => $accountId]);
        exit;
    }

    // Tag bearbeiten (Name & Farbe ändern)
    if ($action === 'update_tag') {
        $tagId = filter_var($data['tag_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($data['name'] ?? ''));
        $color = Sanitizer::hexColor($data['color'] ?? null);

        if (!$tagId || $name === '' || mb_strlen($name) > 50) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        (new BankTagRepository())->updateTag($tagId, $name, $color);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove_tag_from_tx') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $tagId = filter_var($data['tag_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$txId || !$tagId) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        (new BankTagRepository())->removeTagFromTransaction($txId, $tagId);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'create_and_assign_tag') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($data['name'] ?? ''));
        $color = Sanitizer::hexColor($data['color'] ?? null);

        if (!$txId || $name === '' || mb_strlen($name) > 50) {
            Auth::sendJsonError(400, 'Name ungültig oder zu lang');
        }

        $tagId = (new BankTagService())->createAndAssignTag($txId, $name, $color);

        echo json_encode([
            'success' => true,
            'tag' => [
                'id' => $tagId,
                'name' => $name,
                'color' => $color
            ]
        ]);
        exit;
    }

    // ----------------------------------------------------
    // Regelsystem-Aktionen (Phase 2.2)
    // ----------------------------------------------------

    // 1. Live-Test eines Regex-Musters
    if ($action === 'test_rule_pattern') {
        $textPattern = trim((string)($data['text_pattern'] ?? ''));
        $payeePattern = trim((string)($data['payee_pattern'] ?? ''));

        if ($textPattern === '' && $payeePattern === '') {
            echo json_encode(['success' => true, 'match_count' => 0]);
            exit;
        }

        $matcher = new RuleMatcher();
        $matchCount = $matcher->countMatchingTransactions($textPattern, $payeePattern);

        echo json_encode(['success' => true, 'match_count' => $matchCount]);
        exit;
    }

    // 2. Regel speichern oder aktualisieren
    if ($action === 'save_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $textPattern = trim((string)($data['text_pattern'] ?? '')) ?: null;
        $payeePattern = trim((string)($data['payee_pattern'] ?? '')) ?: null;
        $tagIds = is_array($data['tag_ids'] ?? null) ? array_map('intval', $data['tag_ids']) : [];
        $priority = filter_var($data['priority'] ?? 10, FILTER_VALIDATE_INT) ?: 10;

        if ($textPattern === null && $payeePattern === null) {
            Auth::sendJsonError(400, 'Mindestens ein Muster (Text oder Empfänger) muss angegeben werden.');
        }

        $result = (new BankTagService())->saveRuleAndApply(
            $ruleId,
            $txId,
            $textPattern,
            $payeePattern,
            $tagIds,
            $priority
        );

        echo json_encode([
            'success' => true,
            'rule_id' => $result['rule_id'],
            'retroactive_matches' => $result['retroactive_matches']
        ]);
        exit;
    }

    // 3. Regel löschen
    if ($action === 'delete_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$ruleId) {
            Auth::sendJsonError(400, 'Ungültige Rule-ID');
        }

        (new BankTagService())->deleteRuleAndCleanup($ruleId);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_cc_transaction_category') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$txId || !$categoryId) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        (new CreditCardRepository())->updateTransactionCategory($txId, $categoryId);

        echo json_encode(['success' => true]);
        exit;
    }

    // Manuelles Synchronisieren/Matchen von Kreditkartenabrechnungen mit Girokonto-Umsätzen
    if ($action === 'sync_cc_statements') {
        $matcher = new StatementMatcher();
        $linkedCount = $matcher->syncUnlinkedStatements();

        echo json_encode([
            'success' => true,
            'linked_count' => $linkedCount
        ]);
        exit;
    }

    // Alle Verträge für das Modal-Dropdown laden
    if ($action === 'get_contracts') {
        $contracts = (new BankContractRepository())->getContractOptions();

        echo json_encode(['success' => true, 'contracts' => $contracts]);
        exit;
    }

    // Vertragsregel speichern und Regel anlegen
    if ($action === 'save_contract_rule') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $contractId = filter_var($data['contract_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        if (!$txId) {
            Auth::sendJsonError(400, 'Ungültige Transaktions-ID.');
        }

        $contractId = (new ContractAssignmentService())->assignTransactionToContract($txId, $contractId, [
            'assign_only'     => !empty($data['assign_only']),
            'payee'           => trim((string)($data['auftraggeber_val'] ?? '')),
            'use_payee'       => !empty($data['use_auftraggeber']),
            'mandate_id'      => trim((string)($data['mandate_id'] ?? '')),
            'use_mandate'     => !empty($data['use_mandate']),
            'creditor_id'     => trim((string)($data['creditor_id'] ?? '')),
            'use_creditor_id' => !empty($data['use_creditor_id']),
            'text_pattern'    => trim((string)($data['text_pattern'] ?? '')),
        ]);

        echo json_encode(['success' => true, 'contract_id' => $contractId]);
        exit;
    }

    if ($action === 'test_contract_rule_pattern') {
        $useMandate = !empty($data['use_mandate']);
        $useCreditorId = !empty($data['use_creditor_id']);
        $useAuftraggeber = !empty($data['use_auftraggeber']);

        $matchCount = (new BankTransactionRepository())->countMatchingContractPatterns(
            $useMandate ? trim((string)($data['mandate_id'] ?? '')) : null,
            $useCreditorId ? trim((string)($data['creditor_id'] ?? '')) : null,
            $useAuftraggeber ? trim((string)($data['auftraggeber_val'] ?? '')) : null,
            trim((string)($data['text_pattern'] ?? ''))
        );

        echo json_encode(['success' => true, 'match_count' => $matchCount]);
        exit;
    }

    if ($action === 'get_contract_transactions') {
        $contractId = filter_var($data['contract_id'] ?? null, FILTER_VALIDATE_INT);
        $limit = filter_var($data['limit'] ?? 5, FILTER_VALIDATE_INT) ?: 5;

        if (!$contractId) {
            Auth::sendJsonError(400, 'Ungültige Vertrags-ID');
        }

        $transactions = (new BankContractRepository())->getTransactionsForContract($contractId, min($limit, 100));

        echo json_encode([
            'success' => true,
            'transactions' => $transactions
        ]);
        exit;
    }

    // Vertragsdetails speichern (aus dem Modal-Editor)
    if ($action === 'save_contract_details') {
        $contractId = filter_var($data['contract_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $name = trim((string)($data['name'] ?? ''));

        if ($name === '') {
            Auth::sendJsonError(400, 'Name des Vertrags darf nicht leer sein.');
        }

        $id = (new BankContractRepository())->saveContract($data, $contractId);

        echo json_encode([
            'success' => true,
            'contract_id' => $id
        ]);
        exit;
    }

    // Vertrag löschen
    if ($action === 'delete_contract') {
        $contractId = filter_var($data['contract_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$contractId) {
            Auth::sendJsonError(400, 'Ungültige Vertrags-ID');
        }

        (new ContractAssignmentService())->deleteContract($contractId);

        echo json_encode(['success' => true]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte Aktion');

} catch (Throwable $e) {
    (new Logger())->error('bank/api.php: Fehler bei API-Aktion', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}