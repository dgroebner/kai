<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Shared\Security\TokenEncryptionService;
use Kai\Tools\Bank\BankAccountRepository;
use Kai\Tools\Bank\RuleMatcher;
use Kai\Tools\Bank\StatementMatcher;
use Kai\Tools\Bank\ComdirectClient;
use Kai\Tools\Bank\BankGiroService;
use Kai\Tools\Bank\BankTransactionRepository;
use Kai\Tools\Bank\CategoryMatcher;
use Kai\Tools\Bank\AiTagClassifier;
use Kai\Tools\Shared\AI\GeminiClient;

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
$pdo = Database::getInstance()->getConnection();

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
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in'    => $tokens['expires_in'],
                'created_at'    => $tokens['created_at'],
                'session_id'    => $sessionId,
                'session_obj'   => $sessionObj,
                'tan_info'      => $tanInfo
            ];

            echo json_encode([
                'success' => true,
                'status'  => 'pending',
                'message' => 'photoTAN-Push wurde gesendet. Bitte in der App freigeben.'
            ]);
            exit;

        } catch (\Throwable $e) {
            (new Logger())->error('bank/api.php: start_auth_flow Fehler', ['error' => $e->getMessage()]);
            Auth::sendJsonError(500, $e->getMessage());
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
                'status'  => 'blocked',
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
            $stmtAccount = $pdo->query("SELECT id FROM bank_accounts WHERE account_type = 'checking' LIMIT 1");
            $accountId = $stmtAccount->fetchColumn();
            if (!$accountId) {
                throw new Exception("Kein Girokonto zum Speichern der Tokens gefunden.");
            }

            $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
            $repo = new BankAccountRepository();
            $repo->saveApiTokens((int)$accountId, $secondaryTokens, $encryptionService);

            // Erfolg -> Zähler resetten & temp leeren
            $_SESSION['phototan_failures'] = 0;
            unset($_SESSION['comdirect_temp_auth']);

            echo json_encode(['success' => true, 'status' => 'authenticated']);
            exit;

        } catch (\Throwable $e) {
            if (!isset($_SESSION['phototan_failures'])) {
                $_SESSION['phototan_failures'] = 0;
            }
            $_SESSION['phototan_failures']++;

            $failures = $_SESSION['phototan_failures'];
            (new Logger())->error("bank/api.php check_phototan_status error (Versuch $failures/2)", ['error' => $e->getMessage()]);

            $isBlocked = $failures >= 2;
            echo json_encode([
                'success' => false,
                'status'  => $isBlocked ? 'blocked' : 'error',
                'message' => $isBlocked 
                    ? 'photoTAN-Sperrschutz aktiv (2 Fehlversuche). Bitte erst auf der comdirect-Webseite erfolgreich anmelden/TAN-freigeben.'
                    : 'Fehler bei TAN-Aktivierung: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    // Führt den eigentlichen Sync-Prozess aus
    if ($action === 'run_sync') {
        $stmtAccount = $pdo->query("SELECT id FROM bank_accounts WHERE account_type = 'checking' LIMIT 1");
        $accountId = $stmtAccount->fetchColumn();

        if (!$accountId) {
            Auth::sendJsonError(404, 'Kein Girokonto für den API-Sync gefunden.');
        }

        $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
        $repo = new BankAccountRepository();

        $tokens = $repo->getApiTokens((int)$accountId, $encryptionService);
        if (!$tokens) {
            Auth::sendJsonError(401, 'Keine Tokens gefunden. Bitte zuerst authentifizieren.');
        }

        // Falls Token abläuft, versuchen wir ihn direkt über das Refresh-Token zu erneuern
        $isValid = $repo->areTokensValid((int)$accountId, $encryptionService);
        if (!$isValid) {
            $client = new ComdirectClient();
            try {
                $refreshed = $client->refreshAccessToken($tokens['refresh_token']);
                $repo->saveApiTokens((int)$accountId, $refreshed, $encryptionService);
                $tokens = $refreshed;
            } catch (\Throwable $e) {
                (new Logger())->error("bank/api.php run_sync token refresh failed", ['error' => $e->getMessage()]);
                Auth::sendJsonError(401, 'Tokens abgelaufen und Refresh fehlgeschlagen. Bitte erneut anmelden.');
            }
        }

        // Sync ausführen
        $geminiClient = new GeminiClient();
        $bankRepo = new BankTransactionRepository();
        $categoryMatcher = new CategoryMatcher();
        $aiClassifier = new AiTagClassifier($geminiClient);

        $bankGiroService = new BankGiroService(
            $bankRepo,
			$repo,
            $categoryMatcher,
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
        $stmtAccount = $pdo->query("SELECT id FROM bank_accounts WHERE account_type = 'checking' LIMIT 1");
        $accountId = $stmtAccount->fetchColumn();

        if (!$accountId) {
            Auth::sendJsonError(404, 'Kein Girokonto für den API-Sync gefunden.');
        }
        
        $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
        $repo = new BankAccountRepository();
        
        $isValid = $repo->areTokensValid((int)$accountId, $encryptionService);
        
        echo json_encode(['success' => true, 'tokens_valid' => $isValid, 'account_id' => (int)$accountId]);
        exit;
    }
	
	// Tag bearbeiten (Name & Farbe ändern)
    if ($action === 'update_tag') {
        $tagId = filter_var($data['tag_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($data['name'] ?? ''));
        $color = trim((string)($data['color'] ?? '#3b82f6'));

        if (!$tagId || $name === '' || mb_strlen($name) > 50) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $stmt = $pdo->prepare("UPDATE bank_tags SET name = :name, color = :color WHERE id = :id");
        $stmt->execute([':name' => $name, ':color' => $color, ':id' => $tagId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove_tag_from_tx') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $tagId = filter_var($data['tag_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$txId || !$tagId) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $stmt = $pdo->prepare("DELETE FROM bank_transaction_tags WHERE transaction_id = :tx_id AND tag_id = :tag_id");
        $stmt->execute([':tx_id' => $txId, ':tag_id' => $tagId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'create_and_assign_tag') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($data['name'] ?? ''));
        $color = trim((string)($data['color'] ?? '#3b82f6'));

        if (!$txId || $name === '' || mb_strlen($name) > 50) {
            Auth::sendJsonError(400, 'Name ungültig oder zu lang');
        }

        // Tag anlegen falls nicht vorhanden
        $stmtCreate = $pdo->prepare("INSERT INTO bank_tags (name, color) VALUES (:name, :color) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $stmtCreate->execute([':name' => $name, ':color' => $color]);
        $tagId = (int)$pdo->lastInsertId();

        // Tag zuweisen
        $stmtAssign = $pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
        $stmtAssign->execute([':tx_id' => $txId, ':tag_id' => $tagId]);

        echo json_encode([
            'success' => true, 
            'tag' => [
                'id'    => $tagId,
                'name'  => $name,
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
        
        if ($textPattern === '') {
            echo json_encode(['success' => true, 'match_count' => 0]);
            exit;
        }

        $matcher = new RuleMatcher($pdo);
        $stmtAll = $pdo->query("SELECT id, remittance_info FROM bank_giro_transactions");
        $allTx = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        $matchCount = 0;
        foreach ($allTx as $tx) {
            if ($matcher->matchesRule($tx['remittance_info'], $textPattern)) {
                $matchCount++;
            }
        }

        echo json_encode(['success' => true, 'match_count' => $matchCount]);
        exit;
    }

    // 2. Regel speichern oder aktualisieren
    if ($action === 'save_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT);
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $textPattern = trim((string)($data['text_pattern'] ?? ''));
        $payeePattern = trim((string)($data['payee_pattern'] ?? '')) ?: null;
        $tagIds = is_array($data['tag_ids'] ?? null) ? array_map('intval', $data['tag_ids']) : [];
        $priority = filter_var($data['priority'] ?? 10, FILTER_VALIDATE_INT) ?: 10;

        if (empty($textPattern) && empty($payeePattern)) {
            Auth::sendJsonError(400, 'Mindestens ein Muster (Text oder Empfänger) muss angegeben werden.');
        }

        $jsonTagIds = json_encode($tagIds);

        $pdo->beginTransaction();

        if ($ruleId) {
            $stmt = $pdo->prepare("
                UPDATE bank_tag_rules 
                SET text_pattern = :text_pattern, payee_pattern = :payee_pattern, tag_ids = :tag_ids, priority = :priority 
                WHERE id = :id
            ");
            $stmt->execute([
                ':text_pattern'  => $textPattern,
                ':payee_pattern' => $payeePattern,
                ':tag_ids'       => $jsonTagIds,
                ':priority'      => $priority,
                ':id'            => $ruleId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO bank_tag_rules (text_pattern, payee_pattern, tag_ids, priority) 
                VALUES (:text_pattern, :payee_pattern, :tag_ids, :priority)
            ");
            $stmt->execute([
                ':text_pattern'  => $textPattern,
                ':payee_pattern' => $payeePattern,
                ':tag_ids'       => $jsonTagIds,
                ':priority'      => $priority
            ]);
            $ruleId = (int)$pdo->lastInsertId();
        }

        // 1. Für die spezifische Transaktion sofort zuweisen
        if ($txId) {
            $stmtTx = $pdo->prepare("UPDATE bank_giro_transactions SET matched_rule_id = :rule_id WHERE id = :tx_id");
            $stmtTx->execute([':rule_id' => $ruleId, ':tx_id' => $txId]);

            $pdo->prepare("DELETE FROM bank_transaction_tags WHERE transaction_id = :tx_id")->execute([':tx_id' => $txId]);
            $stmtTag = $pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
            foreach ($tagIds as $tId) {
                $stmtTag->execute([':tx_id' => $txId, ':tag_id' => $tId]);
            }
        }

        $pdo->commit();

        // 2. RETROAKTIV: Auf alle verbleibenden regel-losen Umsätze in der DB anwenden!
        $matcher = new RuleMatcher($pdo);
        $matchedCount = $matcher->applyRuleToAllTransactions($ruleId);

        echo json_encode(['success' => true, 'rule_id' => $ruleId, 'retroactive_matches' => $matchedCount]);
        exit;
    }

    // 3. Regel löschen
    if ($action === 'delete_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$ruleId) {
            Auth::sendJsonError(400, 'Ungültige Rule-ID');
        }

        $pdo->beginTransaction();

        // A: Transaktionen ermitteln, die mit dieser Regel verknüpft waren
        $stmtTxs = $pdo->prepare("SELECT id FROM bank_giro_transactions WHERE matched_rule_id = :rule_id");
        $stmtTxs->execute([':rule_id' => $ruleId]);
        $affectedTxIds = $stmtTxs->fetchAll(PDO::FETCH_COLUMN);

        // B: Regel löschen (Setzt matched_rule_id durch FK ON DELETE SET NULL automatisch auf NULL)
        $stmt = $pdo->prepare("DELETE FROM bank_tag_rules WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);

        // C: Tags bei vormals getaggten Umsätzen der Regel entfernen
        if (!empty($affectedTxIds)) {
            $inClause = implode(',', array_map('intval', $affectedTxIds));
            $pdo->exec("DELETE FROM bank_transaction_tags WHERE transaction_id IN ($inClause)");
        }

        $pdo->commit();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_cc_transaction_category') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$txId || !$categoryId) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $stmt = $pdo->prepare("UPDATE bank_cc_transactions SET category_id = :cat_id WHERE id = :tx_id");
        $stmt->execute([':cat_id' => $categoryId, ':tx_id' => $txId]);

        echo json_encode(['success' => true]);
        exit;
    }
	
	// Manuelles Synchronisieren/Matchen von Kreditkartenabrechnungen mit Girokonto-Umsätzen
    if ($action === 'sync_cc_statements') {
        $matcher = new StatementMatcher($pdo);
        $linkedCount = $matcher->syncUnlinkedStatements();

        echo json_encode([
            'success' => true,
            'linked_count' => $linkedCount
        ]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte Aktion');

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    (new Logger())->error('bank/api.php: Fehler bei API-Aktion', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}