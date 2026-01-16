<?php
// app/pages/settings/tab_users.php
declare(strict_types=1);

// Muuttujat tulevat settings.php:stä: $mysqli, $baseUrl, $currentUiLang

// Hae työmaat (vain aktiiviset, ei passivoituja kotityömaa-valikkoon)
$worksites = [];
$worksitesRes = $mysqli->query("SELECT id, name, is_active FROM sf_worksites WHERE is_active = 1 ORDER BY name ASC");
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w;
}

// Hae roolit
$roles = [];
$rolesRes = $mysqli->query('SELECT id, name FROM sf_roles ORDER BY id ASC');
while ($r = $rolesRes->fetch_assoc()) {
    $roles[] = $r;
}

// Hae käyttäjät
$users = [];
$sqlUsers = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.role_id,
        u.home_worksite_id,
        u.created_at,
        u.last_login_at,
        r.name AS role_name,
        ws.name AS home_worksite_name
    FROM sf_users u
    JOIN sf_roles r ON r.id = u.role_id
    LEFT JOIN sf_worksites ws ON ws.id = u.home_worksite_id
    WHERE u.is_active = 1
    ORDER BY u.created_at DESC
";
$resUsers = $mysqli->query($sqlUsers);
while ($row = $resUsers->fetch_assoc()) {
    $users[] = $row;
}
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/users.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(sf_term('users_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
</h2>

<div class="sf-users-header">
    <button class="sf-btn sf-btn-primary" id="sfUserAddBtn" type="button">
        <?= htmlspecialchars(sf_term('users_add_button', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </button>
</div>

<!-- USER FILTERS -->
<div class="sf-users-filters">
    <button type="button" class="sf-filters-toggle" id="sfUsersFiltersToggle">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="22,3 2,3 10,12.46 10,19 14,21 14,12.46"/>
        </svg>
        <?= htmlspecialchars(sf_term('users_filter_toggle', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </button>
    
    <div class="sf-users-filters-content" id="sfUsersFiltersContent">
        <div class="sf-filter-group">
            <label for="sfFilterRole"><?= htmlspecialchars(sf_term('users_filter_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="sfFilterRole" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int)$role['id'] ?>">
                        <?= htmlspecialchars(sf_role_name((int)$role['id'], $role['name'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="sf-filter-group">
            <label for="sfFilterWorksite"><?= htmlspecialchars(sf_term('users_filter_worksite', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="sfFilterWorksite" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($worksites as $ws): ?>
                    <option value="<?= (int)$ws['id'] ?>">
                        <?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="sf-filter-group">
            <label for="sfFilterSearch"><?= htmlspecialchars(sf_term('users_filter_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" id="sfFilterSearch" class="sf-filter-input" placeholder="<?= htmlspecialchars(sf_term('users_filter_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        
        <div class="sf-filter-group">
            <label for="sfFilterLoginStatus"><?= htmlspecialchars(sf_term('users_filter_login_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="sfFilterLoginStatus" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="logged"><?= htmlspecialchars(sf_term('users_filter_login_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="never"><?= htmlspecialchars(sf_term('users_filter_login_never', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            </select>
        </div>
        
        <button type="button" class="sf-btn sf-btn-secondary" id="sfFilterClear">
            <?= htmlspecialchars(sf_term('users_filter_clear', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</div>

<!-- Skeleton loading for users table -->
<div class="skeleton-wrapper">
    <div class="skeleton-container skeleton-table-container" id="skeletonTable">
        <div class="skeleton-table">
            <div class="skeleton-table-header">
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 25%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
            </div>
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="skeleton-table-row">
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 25%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Actual content -->
    <div class="actual-content">

<!-- MOBIILI: Korttinäkymä -->
<div class="sf-users-cards">
    <?php foreach ($users as $u): ?>
        <div class="sf-user-card"
             data-role-id="<?= (int)$u['role_id'] ?>"
             data-worksite-id="<?= (int)($u['home_worksite_id'] ?? 0) ?>"
             data-name="<?= htmlspecialchars(strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
             data-email="<?= htmlspecialchars(strtolower($u['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
             data-has-logged-in="<?= !empty($u['last_login_at']) ? '1' : '0' ?>">
            <div class="sf-user-card-header">
                <div class="sf-user-card-name">
                    <?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?>
                </div>
                <div class="sf-user-card-role">
                    <?= htmlspecialchars(sf_role_name((int)$u['role_id'], $u['role_name'] ?? '', $currentUiLang)) ?>
                </div>
            </div>

            <div class="sf-user-card-email">
                <?= htmlspecialchars($u['email'] ?? '') ?>
            </div>
            <div class="sf-user-card-last-login">
    <strong><?= htmlspecialchars(sf_term('users_col_last_login', $currentUiLang) ?? 'Viimeksi kirjautunut', ENT_QUOTES, 'UTF-8') ?>:</strong>
    <?php
    if (!empty($u['last_login_at'])) {
        echo htmlspecialchars(date('d.m.Y H:i', strtotime($u['last_login_at'])), ENT_QUOTES, 'UTF-8');
    } else {
        echo '<span class="sf-last-login-never">' . htmlspecialchars(sf_term('users_last_login_never', $currentUiLang) ?? 'Ei koskaan', ENT_QUOTES, 'UTF-8') . '</span>';
    }
    ?>
</div>

            <?php if (!empty($u['home_worksite_name'])): ?>
                <div class="sf-user-card-worksite">
                    🏗️ <?= htmlspecialchars($u['home_worksite_name']) ?>
                </div>
            <?php endif; ?>

            <div class="sf-user-card-actions">
                <button
                    class="sf-btn-small sf-edit-user sf-btn-icon"
                    type="button"
                    title="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= (int) $u['id'] ?>"
                    data-first="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-last="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-role="<?= (int) $u['role_id'] ?>"
                    data-home-worksite="<?= (int) ($u['home_worksite_id'] ?? 0) ?>"
                >
                    <img src="<?= $baseUrl ?>/assets/img/icons/edit_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                    <span class="sf-btn-text"><?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>

                <button
                    class="sf-btn-small sf-reset-pass sf-btn-icon"
                    type="button"
                    title="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= (int) $u['id'] ?>"
                >
                    <img src="<?= $baseUrl ?>/assets/img/icons/locked_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                    <span class="sf-btn-text"><?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>

                <button
                    class="sf-btn-small sf-delete-user sf-btn-danger sf-btn-icon"
                    type="button"
                    title="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= (int) $u['id'] ?>"
                >
                    <img src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- DESKTOP: Taulukkonäkymä -->
<table class="sf-table sf-table-users">
    <thead>
        <tr>
            <th><?= htmlspecialchars(sf_term('users_col_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_home_worksite', $currentUiLang) ?? 'Kotityömaa', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('users_col_created', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('users_col_last_login', $currentUiLang) ?? 'Viimeksi kirjautunut', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('users_col_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($users as $u): ?>
            <tr data-role-id="<?= (int)$u['role_id'] ?>"
                data-worksite-id="<?= (int)($u['home_worksite_id'] ?? 0) ?>"
                data-name="<?= htmlspecialchars(strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                data-email="<?= htmlspecialchars(strtolower($u['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-has-logged-in="<?= !empty($u['last_login_at']) ? '1' : '0' ?>">
                <td><?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td>
                <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td><?= htmlspecialchars(sf_role_name((int)$u['role_id'], $u['role_name'] ?? '', $currentUiLang)) ?></td>
                <td>
                    <?php
                    if (!empty($u['home_worksite_name'])) {
                        echo htmlspecialchars($u['home_worksite_name'], ENT_QUOTES, 'UTF-8');
                    } else {
                        echo htmlspecialchars(
                            sf_term('users_home_worksite_none', $currentUiLang) ?? '–',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    }
                    ?>
                </td>
<td><?= htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>

<td>
    <?php
    if (!empty($u['last_login_at'])) {
        echo htmlspecialchars(
            date('d.m.Y H:i', strtotime($u['last_login_at'])),
            ENT_QUOTES,
            'UTF-8'
        );
    } else {
        echo htmlspecialchars(
            sf_term('users_last_login_never', $currentUiLang) ?? 'Ei koskaan',
            ENT_QUOTES,
            'UTF-8'
        );
    }
    ?>
</td>

<td>
    <button
        class="sf-btn-small sf-edit-user sf-btn-icon"
        type="button"
        title="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        data-id="<?= (int) $u['id'] ?>"
        data-first="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-last="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-role="<?= (int) $u['role_id'] ?>"
        data-home-worksite="<?= (int) ($u['home_worksite_id'] ?? 0) ?>"
    >
        <img src="<?= $baseUrl ?>/assets/img/icons/edit_icon.svg" alt="" class="sf-icon" aria-hidden="true">
    </button>

    <button
        class="sf-btn-small sf-reset-pass sf-btn-icon"
        type="button"
        title="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        data-id="<?= (int) $u['id'] ?>"
    >
        <img src="<?= $baseUrl ?>/assets/img/icons/locked_icon.svg" alt="" class="sf-icon" aria-hidden="true">
    </button>

    <button
        class="sf-btn-small sf-delete-user sf-btn-danger sf-btn-icon"
        type="button"
        title="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        data-id="<?= (int) $u['id'] ?>"
    >
        <img src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg" alt="" class="sf-icon" aria-hidden="true">
    </button>
</td>
</tr>
        <?php endforeach; ?>
    </tbody>
</table>
    </div> <!-- .actual-content -->
</div> <!-- .skeleton-wrapper -->

<!-- DEBUG START -->
<?php
$modalPath = __DIR__ . '/modals_users.php';
echo "<!-- Modal path: " . $modalPath . " -->";
echo "<!-- File exists: " . (file_exists($modalPath) ? 'YES' : 'NO') . " -->";
if (file_exists($modalPath)) {
    echo "<!-- File size: " . filesize($modalPath) . " bytes -->";
}
?>
<!-- DEBUG END -->

<?php include __DIR__ . '/modals_users.php'; ?>

<script>
// Varmista että users.js toiminnot ovat käytettävissä AJAX-latauksen jälkeen
(function() {
    console.log("tab_users.php inline script loaded");
})();
</script>