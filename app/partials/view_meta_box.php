<?php
// app/pages/partials/view_meta_box.php
// Erotettu osatiedostoksi selkeyden vuoksi. Sisällytetään view.php:hen.

// Tässä oletetaan, että muuttujat, kuten $flash, $currentUiLang, $typeLabel,
// ovat saatavilla `view.php`-pääsivulta.

// Tarvittavien funktioiden varmistus (esim. sf_term)
if (!function_exists('sf_term')) {
    // Varmista, että funktio on olemassa tai lataa se
    require_once __DIR__ . '/../../includes/statuses.php'; // Oletettu sijainti
}

$stateClassMap = [
    'draft'          => 'status-pill-draft',
    'pending_review' => 'status-pill-pending',
    'request_info'   => 'status-pill-request',
    'reviewed'       => 'status-pill-reviewed',
    'to_comms'       => 'status-pill-comms',
    'published'      => 'status-pill-published',
];
$metaStatusClass = $stateClassMap[$flash['state']] ?? '';
$statusLabel     = function_exists('sf_status_label') ? (sf_status_label($flash['state'], $currentUiLang) ?? '') : '';

?>
<div class="view-box meta-box">
    <div class="meta-status-top">
        <div class="meta-status-left" aria-hidden="true">
            <span class="meta-status-label">
                <?= htmlspecialchars(sf_term('view_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="status-pill <?= htmlspecialchars($metaStatusClass ?: '') ?>">
                <?= htmlspecialchars($statusLabel) ?>
            </span>
            <?php if (!empty($flash['is_archived'])): ?>
                <span class="status-pill status-pill-archived" style="margin-left: 8px;">
                    <?= htmlspecialchars(sf_term('status_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- SISÄLTÖ: Safetyflashin tiedot -->
    <h2 class="section-heading">
        <span class="section-heading-icon" aria-hidden="true">
            <!-- Dokumentti-ikoni -->
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M6 3h9l3 3v15H6V3z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                <path d="M9 9h6M9 13h6M9 17h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="section-heading-text">
            <?= htmlspecialchars(sf_term('view_details_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </h2>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_title_internal', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['title'] ?? '') ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_summary_short', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= nl2br(htmlspecialchars($flash['summary'] ?? '')) ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_description_long', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= nl2br(htmlspecialchars($flash['description'] ?? '')) ?></div>
    </div>

    <?php if ($flash['type'] === 'green'): ?>
        <?php if (!empty($flash['root_causes'])): ?>
            <div class="meta-item">
                <strong><?= htmlspecialchars(sf_term('root_causes_label', $currentUiLang) ?? 'Juurisyyt', ENT_QUOTES, 'UTF-8') ?>:</strong>
                <div><?= nl2br(htmlspecialchars($flash['root_causes'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash['actions'])): ?>
            <div class="meta-item">
                <strong><?= htmlspecialchars(sf_term('actions_label', $currentUiLang) ?? 'Toimenpiteet', ENT_QUOTES, 'UTF-8') ?>:</strong>
                <div><?= nl2br(htmlspecialchars($flash['actions'])) ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($typeLabel) ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_site', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div>
            <?= htmlspecialchars($flash['site'] ?? '') ?>
            <?php if (!empty($flash['site_detail'])): ?>
                &nbsp;–&nbsp;<?= htmlspecialchars($flash['site_detail']) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_occurred_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['occurredFmt'] ?? '') ?></div>
    </div>

    <!-- JÄRJESTELMÄTIEDOT: kieli, luotu, muokattu -->
    <hr class="meta-separator">

    <h2 class="section-heading section-heading-system">
        <span class="section-heading-icon" aria-hidden="true">
            <!-- Ratas-ikoni -->
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z" fill="none" stroke="currentColor" stroke-width="1.6"/>
                <path d="M4 12h2M18 12h2M12 4v2M12 18v2M7 7l1.5 1.5M15.5 15.5L17 17M7 17l1.5-1.5M15.5 8.5L17 7"
                      fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="section-heading-text">
            <?= htmlspecialchars(sf_term('meta_system_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </h2>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars(ucfirst((string)($flash['lang'] ?? ''))) ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_created_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['createdFmt'] ?? '') ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_updated_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['updatedFmt'] ?? '') ?></div>
    </div>
</div>