<?php
require_once __DIR__ . '/../includes/protect.php';
sf_require_role([1]); // vain Pääkäyttäjä

// UI-kieli: sama logiikka kuin muilla sivuilla
$currentUiLang = $uiLang ?? ($_SESSION['ui_lang'] ?? $_SESSION['lang'] ?? 'fi');

$mysqli = sf_db();

// Hae kaikki roolit
$rolesRes = $mysqli->query("SELECT id, name FROM sf_roles ORDER BY id ASC");
$roles = [];
while ($r = $rolesRes->fetch_assoc()) {
    $roles[] = $r;
}

// Hae kaikki työmaat (koti työmaa -valintaa varten)
$worksitesRes = $mysqli->query("SELECT id, name, is_active FROM sf_worksites ORDER BY name ASC");
$worksites = [];
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w;
}

// Hae kaikki aktiiviset käyttäjät, mukana koti työmaa
$sql = "SELECT u.id,
               u.first_name,
               u.last_name,
               u.email,
               u.role_id,
               u.home_worksite_id,
               u.created_at,
               r.name AS role_name,
               ws.name AS home_worksite_name
        FROM sf_users u
        JOIN sf_roles r ON r.id = u.role_id
        LEFT JOIN sf_worksites ws ON ws.id = u.home_worksite_id
        WHERE u.is_active = 1
        ORDER BY u.created_at DESC";
$users = [];
$res = $mysqli->query($sql);
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}
?>
<div class="sf-users-page">
    <h1>
        <?= htmlspecialchars(sf_term('users_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </h1>

    <div class="sf-users-header">
        <button class="sf-btn sf-btn-primary" id="sfUserAddBtn">
            <?= htmlspecialchars(sf_term('users_add_button', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <table class="sf-table">
        <thead>
        <tr>
            <th><?= htmlspecialchars(sf_term('users_col_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_home_worksite', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_created', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars(sf_role_name((int)$u['role_id'], $u['role_name'], $currentUiLang)) ?></td>
                <td>
                    <?= $u['home_worksite_name']
                        ? htmlspecialchars($u['home_worksite_name'])
                        : htmlspecialchars(sf_term('users_home_worksite_none', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($u['created_at']) ?></td>
                <td>
                    <button class="sf-btn-small sf-edit-user"
                            data-id="<?= (int)$u['id']; ?>"
                            data-first="<?= htmlspecialchars($u['first_name']); ?>"
                            data-last="<?= htmlspecialchars($u['last_name']); ?>"
                            data-email="<?= htmlspecialchars($u['email']); ?>"
                            data-role="<?= (int)$u['role_id']; ?>"
                            data-home-worksite="<?= (int)($u['home_worksite_id'] ?? 0); ?>">
                        <?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button class="sf-btn-small sf-reset-pass"
                            data-id="<?= (int)$u['id']; ?>">
                        <?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button class="sf-btn-small sf-delete-user"
                            data-id="<?= (int)$u['id']; ?>">
                        <?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- MODAALI – Lisää / Muokkaa käyttäjä -->
<div class="sf-modal hidden" id="sfUserModal">
    <div class="sf-modal-content">
        <h2 id="sfUserModalTitle">
            <?= htmlspecialchars(sf_term('users_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

<form id="sfUserForm">
    <?php
    // Varmistus: lisätään aina csrf_token-niminen kenttä
    $csrfValue = $_SESSION['csrf_token'] ?? '';
    ?>
    <input type="hidden"
           name="csrf_token"
           value="<?= htmlspecialchars($csrfValue, ENT_QUOTES, 'UTF-8') ?>">

    <?php if (function_exists('sf_csrf_field')): ?>
        <?= sf_csrf_field() ?>
    <?php endif; ?>

    <input type="hidden" name="id" id="sfUserId">

            <label>
                <?= htmlspecialchars(sf_term('users_label_first_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" name="first_name" id="sfUserFirst" required>

            <label>
                <?= htmlspecialchars(sf_term('users_label_last_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" name="last_name" id="sfUserLast" required>

            <label>
                <?= htmlspecialchars(sf_term('users_label_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="email" name="email" id="sfUserEmail" required>

            <label>
                <?= htmlspecialchars(sf_term('users_label_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select name="role_id" id="sfUserRole" required>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id']; ?>">
                        <?= htmlspecialchars(sf_role_name((int)$r['id'], $r['name'], $currentUiLang), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>
                <?= htmlspecialchars(sf_term('users_label_home_worksite', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select name="home_worksite_id" id="sfUserHomeWorksite">
                <option value="">
                    <?= htmlspecialchars(sf_term('users_home_worksite_none', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php foreach ($worksites as $ws): ?>
                    <option value="<?= (int)$ws['id']; ?>">
                        <?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8'); ?>
                        <?= $ws['is_active'] ? '' : ' ('.sf_term('worksite_inactive', $currentUiLang).')' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>
                <?= htmlspecialchars(sf_term('users_label_password_new', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="password" name="password" id="sfUserPassword">

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="sfUserCancel">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAALI – Poisto -->
<div class="sf-modal hidden" id="sfDeleteModal">
    <div class="sf-modal-content">
        <h2>
            <?= htmlspecialchars(sf_term('users_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('users_delete_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <strong id="sfDeleteUserName"></strong>?
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" id="sfDeleteCancel">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="sfDeleteConfirm">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- MODAALI – Salasanan resetointi -->
<div class="sf-modal hidden" id="sfResetModal">
    <div class="sf-modal-content">
        <h2>
            <?= htmlspecialchars(sf_term('users_reset_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('users_reset_text_prefix', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <strong id="sfResetUserName"></strong>
            <?= htmlspecialchars(sf_term('users_reset_text_suffix', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" id="sfResetCancel">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="sfResetConfirm">
                <?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>