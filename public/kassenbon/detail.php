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
?>
    <main id="kassenbonDetailApp"
          data-categories='<?= htmlspecialchars(json_encode($allCategories), ENT_QUOTES, 'UTF-8') ?>'
          data-items='<?= htmlspecialchars(json_encode($itemsWithKey), ENT_QUOTES, 'UTF-8') ?>'
          data-total="<?= (float)$receipt['total'] ?>">

        <!-- Kopfbereich: Donut & Legende -->
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
