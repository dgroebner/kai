<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Kassenbon\ReceiptQueryRepository;
use Kai\Tools\Kassenbon\ReceiptRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage('ebon_read');

$csrfToken = Auth::csrfToken();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);
    exit('Ungültiger Kassenbon.');
}

try {
    $receiptQueryRepository = new ReceiptQueryRepository();

    // Bon inkl. Verknüpfung zu Girokonto oder Kreditkarte laden
    $receipt = $receiptQueryRepository->getReceiptById($id);

    if (!$receipt) {
        http_response_code(404);
        exit('Kassenbon nicht gefunden.');
    }

    $items = $receiptQueryRepository->getItemsForReceipt($id);
    $linkedCharges = $receiptQueryRepository->getLinkedChargesForReceipt($id);

    // Zuordnung der Ladevorgänge zu den einzelnen Positionen ($items)
    $chargesByItemId = [];
    $unassignedCharges = [];

    foreach ($linkedCharges as $vc) {
        if (!empty($vc['receipt_item_id'])) {
            $chargesByItemId[(int)$vc['receipt_item_id']] = $vc;
        } else {
            $unassignedCharges[] = $vc;
        }
    }

    if (!empty($unassignedCharges)) {
        if (count($items) === 1 && count($unassignedCharges) === 1) {
            $singleItemId = (int)$items[0]['id'];
            $singleChargeId = (int)$unassignedCharges[0]['id'];
            $chargesByItemId[$singleItemId] = $unassignedCharges[0];
            $unassignedCharges = [];
            try {
                \Kai\Tools\Shared\Db\Database::getInstance()->getConnection()
                    ->prepare("UPDATE vehicle_charges SET receipt_item_id = :item_id WHERE id = :charge_id AND receipt_item_id IS NULL")
                    ->execute([':item_id' => $singleItemId, ':charge_id' => $singleChargeId]);
            } catch (\Throwable) {}
        } else {
            foreach ($unassignedCharges as $uIdx => $uCharge) {
                foreach ($items as $it) {
                    $itemId = (int)$it['id'];
                    if (isset($chargesByItemId[$itemId])) {
                        continue;
                    }
                    $qty = (float)$it['quantity'];
                    $price = (float)$it['total_price'];
                    $cNetKwh = (float)$uCharge['charged_net_kwh'];
                    $cLossKwh = (float)($uCharge['loss_kwh'] ?? 0);
                    $cCost = (float)$uCharge['cost_eur'];

                    $kwhMatch = ($qty > 0 && (abs($qty - ($cNetKwh + $cLossKwh)) <= 0.6 || ($cNetKwh >= $qty * 0.78 && $cNetKwh <= $qty + 0.5)));
                    $priceMatch = ($price > 0 && abs($price - $cCost) <= 0.05);

                    if ($kwhMatch || $priceMatch) {
                        $chargesByItemId[$itemId] = $uCharge;
                        unset($unassignedCharges[$uIdx]);
                        break;
                    }
                }
            }
        }
    }

    // Bereits vergebene Kategorien für das Inline-Dropdown
    $allCategories = new ReceiptRepository()->getKnownCategories();
} catch (Throwable $e) {
    new Logger()->error('kassenbon/detail.php: Datenbankfehler.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler. Bitte versuche es später erneut.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>eBon <?= htmlspecialchars($receipt['store'] ?? '', ENT_QUOTES, 'UTF-8') ?> - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <div>
            <h1>🧾 <?= htmlspecialchars($receipt['store'] ?? '', ENT_QUOTES, 'UTF-8') ?> <small
                        class="page-header-sub">(<?= date('d.m.Y', strtotime($receipt['purchase_date'])) ?>)</small>
            </h1>
            <div style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
                <?php if (!empty($receipt['bank_giro_transaction_id'])): ?>
                    <a href="../bank/index.php?tx=<?= (int)$receipt['bank_giro_transaction_id'] ?>"
                       class="badge badge-success" style="text-decoration: none; font-size: 0.85rem;"
                       title="Zur Girokonto-Ansicht wechseln">
                        🟢 Girokonto abgebucht (<?= date('d.m.Y', strtotime($receipt['giro_booking_date'])) ?>) &rarr;
                    </a>
                <?php elseif (!empty($receipt['bank_cc_transaction_id'])): ?>
                    <a href="../bank/detail.php?id=<?= (int)$receipt['cc_statement_id'] ?>" class="badge badge-success"
                       style="text-decoration: none; font-size: 0.85rem;" title="Zur Kreditkartenabrechnung wechseln">
                        🟢 Kreditkarte abgebucht (<?= date('d.m.Y', strtotime($receipt['cc_booking_date'])) ?>) &rarr;
                    </a>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size: 0.85rem;">🟡 Zahlung offen</span>
                <?php endif; ?>

                <?php if (!empty($receipt['contract_id'])): ?>
                    <a href="../bank/contracts.php#contract-<?= (int)$receipt['contract_id'] ?>"
                       class="badge badge-info" style="text-decoration: none; font-size: 0.85rem;"
                       title="Zum Vertrag in der Finanzverwaltung wechseln">
                        📑 Vertrag: <?= htmlspecialchars($receipt['contract_name'] ?? 'Vertrag', ENT_QUOTES, 'UTF-8') ?> &rarr;
                    </a>
                <?php endif; ?>

                <?php foreach ($linkedCharges as $vc): 
                    $vcDate = (new DateTime($vc['start_time']))->format('Y-m-d');
                    $vcTime = (new DateTime($vc['start_time']))->format('d.m.Y H:i');
                ?>
                    <a href="../car/index.php?tab=charges&date=<?= $vcDate ?>&charge_id=<?= (int)$vc['id'] ?>#charge-<?= (int)$vc['id'] ?>"
                       class="badge badge-primary" style="text-decoration: none; font-size: 0.85rem;"
                       title="Zum VW ID.Buzz Ladevorgang wechseln">
                        🚐 ID.Buzz Ladevorgang #<?= (int)$vc['id'] ?> (<?= $vcTime ?>) &rarr;
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <a href="index.php" class="btn btn-outline">&larr; Zurück zu der Übersicht</a>
    </header>

<?php
// product_key und verknüpften Ladevorgang für jedes Item ergänzen
$itemsWithKey = array_map(static function (array $item) use ($chargesByItemId): array {
    $item['product_key'] = \Kai\Tools\Kassenbon\OpenFoodFactsQueueRepository::normalizeProductKey($item['name'] ?? '');
    $item['linked_charge'] = $chargesByItemId[(int)$item['id']] ?? null;
    return $item;
}, $items);

$meta = $receipt['meta'] ?? [];
$isInvoice = (!empty($receipt['document_type']) && in_array($receipt['document_type'], ['invoice', 'mobile_bill'], true))
    || !empty($receipt['invoice_number'])
    || !empty($meta['phone_profiles']);
$phoneProfiles = $meta['phone_profiles'] ?? [];
?>
    <main id="kassenbonDetailApp"
          data-categories='<?= htmlspecialchars(json_encode($allCategories), ENT_QUOTES, 'UTF-8') ?>'
          data-items='<?= htmlspecialchars(json_encode($itemsWithKey), ENT_QUOTES, 'UTF-8') ?>'
          data-total="<?= (float)$receipt['total'] ?>">

        <?php if ($isInvoice): ?>
            <!-- Rechnungs-Kopfdaten -->
            <section class="card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-top: 0; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                    <span>📄 Rechnungsdaten</span>
                    <?php if (!empty($receipt['billing_period'])): ?>
                        <span class="badge badge-primary" style="font-size: 0.85rem; font-weight: normal;">
                            Zeitraum: <?= htmlspecialchars($receipt['billing_period'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                    <?php if (!empty($receipt['invoice_number'])): ?>
                        <div style="min-width: 0;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Rechnungsnummer</div>
                            <strong style="word-break: break-all; display: block;"><?= htmlspecialchars($receipt['invoice_number'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($receipt['due_date'])): ?>
                        <div style="min-width: 0;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">SEPA-Abbuchungstermin</div>
                            <strong style="color: var(--color-green, #10b981); display: block;">
                                <?= date('d.m.Y', strtotime($receipt['due_date'])) ?>
                            </strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($meta['mandate_reference'])): ?>
                        <div style="min-width: 0;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Mandatsreferenz</div>
                            <span style="font-family: monospace; font-size: 0.82rem; word-break: break-all; overflow-wrap: anywhere; display: block;"><?= htmlspecialchars($meta['mandate_reference'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($meta['customer_account']) || !empty($meta['customer_number'])): ?>
                        <div style="min-width: 0;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Kundenkonto / Kd.-Nr.</div>
                            <span style="word-break: break-word; display: block;"><?= htmlspecialchars(($meta['customer_account'] ?? '') . ($meta['customer_account'] && $meta['customer_number'] ? ' / ' : '') . ($meta['customer_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($meta['net_amount']) || isset($meta['tax_amount'])): ?>
                        <div style="min-width: 0;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Steueraufschlüsselung</div>
                            <span style="display: block;">
                                Netto: <?= number_format((float)($meta['net_amount'] ?? 0), 2, ',', '.') ?> € &bull;
                                19% USt: <?= number_format((float)($meta['tax_amount'] ?? 0), 2, ',', '.') ?> €
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (!empty($phoneProfiles)): ?>
                <!-- Aufschlüsselung nach Mobilfunk-Rufnummern -->
                <section class="card" style="margin-bottom: 1.5rem;">
                    <h3 style="margin-top: 0; margin-bottom: 1rem;">📱 Mobilfunk-Rufnummern (<?= count($phoneProfiles) ?> Profile)</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                        <?php foreach ($phoneProfiles as $p): ?>
                            <div class="card" style="border: 1px solid var(--border-color); background: var(--bg-surface-alt, rgba(255,255,255,0.03)); padding: 1rem; border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                    <div>
                                        <strong style="font-size: 1.05rem; font-family: monospace;"><?= htmlspecialchars($p['phone_number'] ?? 'Ohne Nummer', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if (!empty($p['tariff'])): ?>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($p['tariff'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="amount-bold" style="font-size: 1.05rem;">
                                        <?= number_format((float)($p['total'] ?? 0), 2, ',', '.') ?> €
                                    </span>
                                </div>
                                <?php if (!empty($p['profile_number'])): ?>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                                        Karten-/Profil-Nr.: <?= htmlspecialchars($p['profile_number'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($p['items']) && is_array($p['items'])): ?>
                                    <ul style="margin: 0.5rem 0 0 0; padding-left: 1.2rem; font-size: 0.8rem; color: var(--text-muted);">
                                        <?php foreach ($p['items'] as $pItem): ?>
                                            <li>
                                                <?= htmlspecialchars($pItem['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>:
                                                <strong><?= number_format((float)($pItem['price'] ?? 0), 2, ',', '.') ?> €</strong>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <!-- Kopfbereich: Donut & Legende für reguläre Kassenbons -->
            <section class="card">
                <h3>Kategorien-Anteil</h3>
                <div class="analysis-grid">
                    <div class="category-legend" id="categoryLegend"></div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                        <div class="chart-center-text">
                            <span class="label">Gesamt</span>
                            <span class="value"><?= number_format((float)$receipt['total'], 2, ',', '.') ?> €</span>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Einzelpositionen -->
        <section class="card u-mt-lg">
            <div class="page-header page-header-flush">
                <h2>Positionen</h2>
                <button id="resetFilterBtn" class="btn btn-sm btn-outline hidden">Filter zurücksetzen</button>
            </div>

            <div class="table-responsive">
                <table class="receipts-table stack-table" id="itemsTable">
                    <thead>
                    <tr>
                        <th>Menge</th>
                        <th>Artikel</th>
                        <th>Kategorie</th>
                        <th class="text-right">Einzelpreis</th>
                        <th class="text-right">Gesamt</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($itemsWithKey as $item): ?>
                        <tr class="js-off-item-row"
                            data-item-id="<?= (int)$item['id'] ?>"
                            data-item-name="<?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-product-key="<?= htmlspecialchars($item['product_key'], ENT_QUOTES, 'UTF-8') ?>"
                            data-category-name="<?= htmlspecialchars($item['category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?>">
                            <td data-label="Menge"><?= number_format((float)$item['quantity'], 3, ',', '.') ?> x</td>
                            <td data-label="Artikel" class="amount-bold">
                                <?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($item['linked_charge'])): 
                                    $vc = $item['linked_charge'];
                                    $chargeDateStr = date('d.m.Y H:i', strtotime($vc['start_time']));
                                    $targetDate = date('Y-m-d', strtotime($vc['start_time']));
                                ?>
                                    <div style="margin-top: 0.35rem;">
                                        <a href="../car/index.php?tab=charges&date=<?= $targetDate ?>&charge_id=<?= (int)$vc['id'] ?>#charge-<?= (int)$vc['id'] ?>"
                                           class="badge badge-primary"
                                           style="text-decoration: none; font-size: 0.75rem; font-weight: normal; display: inline-flex; align-items: center; gap: 0.35rem;"
                                           title="Zum Ladevorgang im ID.Buzz Dashboard wechseln">
                                            <span>🚐 Ladevorgang #<?= (int)$vc['id'] ?> (<?= $chargeDateStr ?> &bull; <?= number_format((float)$vc['charged_net_kwh'], 1, ',', '.') ?> kWh Netto) &rarr;</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Kategorie" class="category-cell">
                                        <span class="clickable-badge" data-item-id="<?= (int)$item['id'] ?>">
                                            <span class="badge-text"><?= htmlspecialchars($item['category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="edit-icon">✏️</span>
                                        </span>
                            </td>
                            <td data-label="Einzelpreis" class="text-right">
                                <?= number_format((float)$item['unit_price'], 2, ',', '.') ?> €
                            </td>
                            <td data-label="Gesamt" class="text-right amount-bold">
                                <?= number_format((float)$item['total_price'], 2, ',', '.') ?> €
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- OFF-Produkt-Popup -->
<div id="offItemModal" class="modal-overlay hidden">
  <div class="modal-dialog modal-dialog--lg">
    <div class="modal-header">
      <h3 id="offModalTitle">Produktinfo</h3>
      <button type="button" class="js-modal-close btn btn-sm btn-outline">✕</button>
    </div>
    <div class="modal-body" id="offModalBody">
      <div class="off-loading">⏳ Lade Produktdaten...</div>
    </div>
  </div>
</div>

<script src="../js/chart.min.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/kassenbon.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>
