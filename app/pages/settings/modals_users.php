<?php
// app/pages/settings/modals_users.php
// Modaalit käyttäjien hallintaan
?>

<!-- MODAALI – Lisää / Muokkaa käyttäjä -->
<div class="sf-modal hidden" id="sfUserModal">
    <div class="sf-modal-content">
        <h2 id="sfUserModalTitle">
            <?= htmlspecialchars(sf_term('users_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <form id="sfUserForm">
            <?php
            // CSRF-token lomakkeeseen
            $csrfToken = $_SESSION['csrf_token'] ?? '';
            ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" id="sfUserId">

            <label><?= htmlspecialchars(sf_term('users_label_first_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" name="first_name" id="sfUserFirst" required>

            <label><?= htmlspecialchars(sf_term('users_label_last_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" name="last_name" id="sfUserLast" required>

            <label><?= htmlspecialchars(sf_term('users_label_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="email" name="email" id="sfUserEmail" required>

            <label><?= htmlspecialchars(sf_term('users_label_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select name="role_id" id="sfUserRole" required>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id'] ?>">
                        <?= htmlspecialchars(sf_role_name((int)$r['id'], $r['name'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label><?= htmlspecialchars(sf_term('users_label_home_worksite', $currentUiLang) ?? 'Kotityömaa', ENT_QUOTES, 'UTF-8') ?></label>
            <select name="home_worksite_id" id="sfUserHomeWorksite">
                <option value="">
                    <?= htmlspecialchars(sf_term('users_home_worksite_none', $currentUiLang) ?? '–', ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php foreach ($worksites as $ws): ?>
                    <option value="<?= (int)$ws['id'] ?>">
                        <?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Password field - only shown when editing existing user -->
            <div id="sfPasswordField">
                <label><?= htmlspecialchars(sf_term('users_label_password_new', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="password" name="password" id="sfUserPassword">
            </div>

            <!-- Info box - only shown when creating new user -->
            <div class="sf-info-box" id="sfAutoPasswordInfo" style="display:none;background:#e0f2fe;border-left:4px solid #0ea5e9;padding:12px;margin:10px 0;border-radius:4px;">
                <p style="margin:0;color:#075985;font-size:14px;">
                    ℹ️ <?= htmlspecialchars(sf_term('users_auto_password_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

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
        <h2><?= htmlspecialchars(sf_term('users_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
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
        <h2><?= htmlspecialchars(sf_term('users_reset_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
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

<script>
(function () {
    // Hae CSRF-token
    function getCsrfToken() {
        var tokenInput = document.querySelector('input[name="csrf_token"]');
        if (tokenInput && tokenInput.value) {
            return tokenInput.value;
        }
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken) {
            return metaToken.getAttribute('content');
        }
        return '';
    }
    
    // Delegoidut event listenerit - toimivat AJAX-latauksen jälkeenkin
    document.addEventListener('click', function (e) {

        // LISÄÄ KÄYTTÄJÄ -nappi
        if (e.target.closest('#sfUserAddBtn')) {
            var modal = document.getElementById('sfUserModal');
            var form = document.getElementById('sfUserForm');

            if (modal && form) {
                form.reset();
                document.getElementById('sfUserId').value = '';
                document.getElementById('sfUserModalTitle').textContent =
                    '<?= htmlspecialchars(sf_term('users_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
                
                // Hide password field and show auto-password info for new users
                var passwordField = document.getElementById('sfPasswordField');
                var autoPasswordInfo = document.getElementById('sfAutoPasswordInfo');
                if (passwordField) passwordField.style.display = 'none';
                if (autoPasswordInfo) autoPasswordInfo.style.display = 'block';
                
                modal.classList.remove('hidden');
            }
            return;
        }

        // MUOKKAA KÄYTTÄJÄ -nappi
        var editBtn = e.target.closest('.sf-edit-user');
        if (editBtn) {
            var modal = document.getElementById('sfUserModal');
            if (modal) {
                document.getElementById('sfUserId').value = editBtn.dataset.id || '';
                document.getElementById('sfUserFirst').value = editBtn.dataset.first || '';
                document.getElementById('sfUserLast').value = editBtn.dataset.last || '';
                document.getElementById('sfUserEmail').value = editBtn.dataset.email || '';
                document.getElementById('sfUserRole').value = editBtn.dataset.role || '';
                document.getElementById('sfUserHomeWorksite').value = editBtn.dataset.homeWorksite || '';
                document.getElementById('sfUserPassword').value = '';
                document.getElementById('sfUserModalTitle').textContent =
                    '<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
                
                // Show password field and hide auto-password info for editing
                var passwordField = document.getElementById('sfPasswordField');
                var autoPasswordInfo = document.getElementById('sfAutoPasswordInfo');
                if (passwordField) passwordField.style.display = 'block';
                if (autoPasswordInfo) autoPasswordInfo.style.display = 'none';
                
                modal.classList.remove('hidden');
            }
            return;
        }

        // POISTA KÄYTTÄJÄ -nappi
        var deleteBtn = e.target.closest('.sf-delete-user');
        if (deleteBtn) {
            var modal = document.getElementById('sfDeleteModal');
            if (modal) {
                var row = deleteBtn.closest('tr') || deleteBtn.closest('.sf-user-card');
                var name = '';

                if (row) {
                    var nameEl = row.querySelector('td') || row.querySelector('.sf-user-card-name');
                    name = nameEl ? nameEl.textContent.trim() : '';
                }

                document.getElementById('sfDeleteUserName').textContent = name;
                modal.dataset.userId = deleteBtn.dataset.id || '';
                modal.classList.remove('hidden');
            }
            return;
        }

        // NOLLAA SALASANA -nappi
        var resetBtn = e.target.closest('.sf-reset-pass');
        if (resetBtn) {
            var modal = document.getElementById('sfResetModal');
            if (modal) {
                var row = resetBtn.closest('tr') || resetBtn.closest('.sf-user-card');
                var email = '';

                if (row) {
                    var emailEl = row.querySelector('td:nth-child(2)') || row.querySelector('.sf-user-card-email');
                    email = emailEl ? emailEl.textContent.trim() : '';
                }

                document.getElementById('sfResetUserName').textContent = email;
                modal.dataset.userId = resetBtn.dataset.id || '';
                modal.classList.remove('hidden');
            }
            return;
        }

        // PERUUTA-napit
        if (e.target.closest('#sfUserCancel')) {
            var m = document.getElementById('sfUserModal');
            if (m) m.classList.add('hidden');
            return;
        }
        if (e.target.closest('#sfDeleteCancel')) {
            var m = document.getElementById('sfDeleteModal');
            if (m) m.classList.add('hidden');
            return;
        }
        if (e.target.closest('#sfResetCancel')) {
            var m = document.getElementById('sfResetModal');
            if (m) m.classList.add('hidden');
            return;
        }

        // POISTA VAHVISTA
        if (e.target.closest('#sfDeleteConfirm')) {
            var modal = document.getElementById('sfDeleteModal');
            var userId = modal ? modal.dataset.userId : null;

            if (userId) {
                var csrfToken = getCsrfToken();
                var body = 'sf_action=delete&id=' + encodeURIComponent(userId);
                if (csrfToken) {
                    body += '&csrf_token=' + encodeURIComponent(csrfToken);
                }
                
                fetch('<?= $baseUrl ?>/app/actions/users_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function () {
                    window.location.reload();
                });
            }
            return;
        }

        // NOLLAA VAHVISTA
        if (e.target.closest('#sfResetConfirm')) {
            var modal = document.getElementById('sfResetModal');
            var userId = modal ? modal.dataset.userId : null;

            if (userId) {
                var csrfToken = getCsrfToken();
                var body = 'sf_action=reset_password&id=' + encodeURIComponent(userId);
                if (csrfToken) {
                    body += '&csrf_token=' + encodeURIComponent(csrfToken);
                }
                
                fetch('<?= $baseUrl ?>/app/actions/users_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function () {
                    window.location.reload();
                });
            }
            return;
        }
    });

    // LOMAKKEEN SUBMIT (delegoitu)
    document.addEventListener('submit', function (e) {
        if (e.target.closest('#sfUserForm')) {
            e.preventDefault();

            var form = e.target;
            var formData = new FormData(form);
            var userId = document.getElementById('sfUserId').value;
            formData.append('sf_action', userId ? 'update' : 'create');

            fetch('<?= $baseUrl ?>/app/actions/users_save.php', {
                method: 'POST',
                body: formData
            }).then(function () {
                var m = document.getElementById('sfUserModal');
                if (m) m.classList.add('hidden');
                window.location.reload();
            });
        }
    });
})();
</script>