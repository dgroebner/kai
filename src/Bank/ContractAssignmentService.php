<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Orchestriert die Zuordnung von Girokonto-Umsätzen zu Verträgen inklusive
 * automatischer Vertragsanlage und Erkennungsregeln.
 */
class ContractAssignmentService
{
    /** Priorität, mit der aus einem Umsatz abgeleitete Regeln angelegt werden. */
    private const int DEFAULT_RULE_PRIORITY = 10;

    /** Maximale Länge des automatisch abgeleiteten Vertragsnamens. */
    private const int MAX_CONTRACT_NAME_LENGTH = 100;

    private PDO $pdo;
    private BankContractRepository $contractRepository;
    private BankTransactionRepository $transactionRepository;

    public function __construct(
        ?BankContractRepository    $contractRepository = null,
        ?BankTransactionRepository $transactionRepository = null
    )
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->contractRepository = $contractRepository ?? new BankContractRepository();
        $this->transactionRepository = $transactionRepository ?? new BankTransactionRepository();
    }

    /**
     * Verknüpft einen Umsatz mit einem Vertrag. Ist keine Vertrags-ID angegeben,
     * wird aus den Umsatzdaten ein neuer Vertrag abgeleitet.
     *
     * Die `use_*`-Schalter steuern, ob aus dem jeweiligen Muster eine Erkennungsregel
     * entsteht. `payee` dient unabhängig davon als Namensvorschlag für neue Verträge.
     *
     * @param array{
     *     assign_only?: bool,
     *     payee?: ?string,
     *     use_payee?: bool,
     *     mandate_id?: ?string,
     *     use_mandate?: bool,
     *     creditor_id?: ?string,
     *     use_creditor_id?: bool,
     *     text_pattern?: ?string
     * } $patterns Muster, aus denen Erkennungsregeln erzeugt werden
     * @return int ID des verknüpften Vertrags
     * @throws Throwable
     */
    public function assignTransactionToContract(int $transactionId, ?int $contractId, array $patterns = []): int
    {
        $assignOnly = !empty($patterns['assign_only']);
        $payee = $this->normalize($patterns['payee'] ?? null);

        $this->pdo->beginTransaction();

        try {
            if ($contractId === null || $contractId <= 0) {
                $contractId = $this->createContractFromTransaction($transactionId, $payee);
            }

            if (!$assignOnly) {
                $this->createRulesFromPatterns($contractId, $patterns, $payee);
            }

            $this->transactionRepository->assignContract($transactionId, $contractId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $contractId;
    }

    /**
     * Normalisiert einen Musterwert auf einen getrimmten String oder null.
     */
    private function normalize(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    /**
     * Leitet aus einem Umsatz einen neuen Vertrag ab (Name, Betrag, Auftraggeber, Mandat).
     */
    private function createContractFromTransaction(int $transactionId, ?string $payeeOverride): int
    {
        $transaction = $this->transactionRepository->getTransactionById($transactionId);
        if ($transaction === null) {
            throw new RuntimeException("Umsatz $transactionId für die Vertragsanlage nicht gefunden.");
        }

        $extractedPayee = trim((string)(
        $transaction['remitter'] ?: ($transaction['creditor'] ?: ($transaction['debitor'] ?? ''))
        ));

        $name = $payeeOverride
            ?? ($extractedPayee !== '' ? $extractedPayee : (string)($transaction['remittance_info'] ?? ''));
        $name = trim($name) !== '' ? $name : 'Neuer Vertrag';

        $amount = (float)($transaction['amount'] ?? 0);

        // Richtung und Typ basierend auf dem Betrag (positiv = Einnahme, negativ = Ausgabe) bestimmen
        $direction = $amount >= 0 ? 'income' : 'expense';

        return $this->contractRepository->saveContract([
            'name' => mb_substr($name, 0, self::MAX_CONTRACT_NAME_LENGTH),
            'direction' => $direction,
            'type' => 'vertrag', // Oder bei Bedarf via UI steuerbar machen
            'status' => 'aktiv',
            'auftraggeber' => $extractedPayger ?? ($extractedPayee !== '' ? $extractedPayee : null),
            'mandatsnummer' => $transaction['dc_mandate_id'] ?? null,
            'betrag' => abs($amount),
            'frequenz' => 'monatlich',
        ]);
    }

    /**
     * Legt aus den übergebenen Mustern die Erkennungsregeln eines Vertrags an.
     * Ein Muster wird nur berücksichtigt, wenn sein `use_*`-Schalter gesetzt ist.
     */
    private function createRulesFromPatterns(int $contractId, array $patterns, ?string $payee): void
    {
        $candidates = [
            ['exact_match', $this->normalize($patterns['mandate_id'] ?? null), !empty($patterns['use_mandate'])],
            ['exact_match', $this->normalize($patterns['creditor_id'] ?? null), !empty($patterns['use_creditor_id'])],
            ['substring', $payee, !empty($patterns['use_payee'])],
            ['regex', $this->normalize($patterns['text_pattern'] ?? null), true],
        ];

        foreach ($candidates as [$patternType, $value, $isEnabled]) {
            if ($isEnabled && $value !== null) {
                $this->contractRepository->addRule($contractId, $patternType, $value, self::DEFAULT_RULE_PRIORITY);
            }
        }
    }

    /**
     * Löscht einen Vertrag und hebt zuvor die Verknüpfung aller zugeordneten Umsätze auf.
     * @throws Throwable
     */
    public function deleteContract(int $contractId): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->transactionRepository->unlinkContract($contractId);
            $this->contractRepository->deleteContract($contractId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
