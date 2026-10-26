<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Projects\Mail\ProjectsMailboxCleaner;
use Kai\Tools\Projects\ProjectsAdminRepository;
use Kai\Tools\Projects\Security\ProjectsAuth;
use Kai\Tools\Shared\Security\Auth;

// Nur für Super-Admin zugänglich
ProjectsAuth::requireSuperAdminPage();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$adminRepo = new ProjectsAdminRepository();
$users = $adminRepo->getAllUsers();
$appUrl = ProjectsAuth::getAppUrl();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= ProjectsAuth::csrfToken() ?>">
    <title>Projects Administration</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0284c7">

    <!-- Favicon & PWA Icons -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="icon" type="image/png" sizes="192x192" href="icon-192.png">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">

    <link rel="stylesheet" href="styles.css?v=<?= APP_VERSION ?>">
    <script src="http.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="admin.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body style="background: var(--proj-bg, #0f172a); color: var(--proj-text, #f8fafc); padding: 24px;">
    <div style="max-width: 1000px; margin: 0 auto;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
            <div>
                <a href="index.php" style="color: var(--proj-text-muted, #94a3b8); text-decoration: none; font-size: 13px;">← Zurück zur Zeiterfassung</a>
                <h1 style="font-size: 24px; font-weight: 800; margin-top: 4px;">⏱️ Projects Administration</h1>
                <p style="color: var(--proj-text-muted, #94a3b8); font-size: 13px;">Nutzerverwaltung & Isolation für die Projektzeiterfassung</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" id="btn-purge-mailbox" class="proj-btn proj-btn-secondary proj-btn-sm" title="Eingehende E-Mails im Postfach projects@agent-smith.de jetzt restlos löschen">
                    🧹 Postfach leeren
                </button>
                <button type="button" class="proj-btn proj-btn-primary proj-btn-sm" data-open-modal="modal-invite-user">
                    + Nutzer einladen
                </button>
            </div>
        </div>

        <div class="proj-card" style="background: #1e293b; border-color: #334155;">
            <h2 style="font-size: 16px; font-weight: 700; margin-bottom: 16px;">Freigeschaltete Mitarbeiter</h2>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 1px solid #334155; color: #94a3b8;">
                            <th style="padding: 10px;">E-Mail</th>
                            <th style="padding: 10px;">Name</th>
                            <th style="padding: 10px;">Bundesland</th>
                            <th style="padding: 10px;">Status</th>
                            <th style="padding: 10px;">Registriert am</th>
                            <th style="padding: 10px; text-align: right;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" style="padding: 20px; text-align: center; color: #94a3b8;">
                                    Noch keine Mitarbeiter eingeladen.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <tr style="border-bottom: 1px solid #334155;">
                                    <td style="padding: 10px; font-weight: 600;"><?= htmlspecialchars($u['email']) ?></td>
                                    <td style="padding: 10px;"><?= htmlspecialchars($u['name'] ?? '—') ?></td>
                                    <td style="padding: 10px;">
                                        <span class="proj-brand-badge"><?= htmlspecialchars($u['state_code']) ?></span>
                                    </td>
                                    <td style="padding: 10px;">
                                        <?php if ($u['status'] === 'active'): ?>
                                            <span style="color: #10b981; font-weight: 700;">● Aktiv</span>
                                        <?php elseif ($u['status'] === 'invited'): ?>
                                            <span style="color: #f59e0b; font-weight: 700;">○ Eingeladen</span>
                                        <?php else: ?>
                                            <span style="color: #ef4444; font-weight: 700;">✕ Deaktiviert</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px; color: #94a3b8;">
                                        <?= date('d.m.Y H:i', strtotime($u['created_at'])) ?>
                                    </td>
                                    <td style="padding: 10px; text-align: right;">
                                        <?php 
                                        $currentProjUserId = ProjectsAuth::getUserId();
                                        $adminEmail = strtolower(trim((string)($_ENV['ADMIN_EMAIL'] ?? '')));
                                        $isSelf = ((int)$u['id'] === $currentProjUserId) || (strtolower($u['email']) === $adminEmail);
                                        ?>
                                        <?php if ($isSelf): ?>
                                            <span style="font-size: 12px; color: var(--proj-text-muted, #94a3b8); font-style: italic; padding: 4px 8px;">(Eigenes Admin-Konto)</span>
                                        <?php else: ?>
                                            <button type="button" class="proj-btn proj-btn-ghost proj-btn-sm" data-toggle-status="<?= $u['id'] ?>" data-new-status="<?= ($u['status'] === 'disabled') ? 'active' : 'disabled' ?>">
                                                <?= ($u['status'] === 'disabled') ? 'Aktivieren' : 'Deaktivieren' ?>
                                            </button>
                                            <button type="button" class="proj-btn proj-btn-danger proj-btn-sm" data-delete-user="<?= $u['id'] ?>" style="margin-left: 4px;">
                                                Löschen
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <footer style="text-align: center; padding: 24px 0 16px; font-size: 11px; color: var(--proj-text-muted, #94a3b8);">
            Projects Administration v<?= APP_VERSION ?>
        </footer>
    </div>

    <!-- Modal: Nutzer einladen -->
    <div id="modal-invite-user" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card" style="background: #1e293b; border-color: #334155;">
            <div class="proj-modal-header">
                <h3 class="proj-modal-title">Mitarbeiter zu Projects einladen</h3>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>
            <form id="form-invite-user">
                <input type="hidden" name="csrf_token" value="<?= ProjectsAuth::csrfToken() ?>">
                <div class="proj-form-group">
                    <label for="inv-email">E-Mail-Adresse</label>
                    <input type="email" id="inv-email" name="email" required placeholder="kollege@firma.de">
                </div>
                <div class="proj-form-group">
                    <label for="inv-name">Name (optional)</label>
                    <input type="text" id="inv-name" name="name" placeholder="Vorname Nachname">
                </div>
                <div class="proj-form-group">
                    <label for="inv-state">Bundesland für Feiertage</label>
                    <select id="inv-state" name="state_code" required>
                        <option value="SN" selected>Sachsen (SN) — inkl. Buß- und Bettag</option>
                        <option value="HH">Hamburg (HH) — ohne Buß- und Bettag</option>
                    </select>
                </div>
                <div class="proj-form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="send_mail" value="1" checked style="width: auto;">
                        <span>Einladungs-E-Mail sofort senden</span>
                    </label>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Abbrechen</button>
                    <button type="submit" class="proj-btn proj-btn-primary">Einladen</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
