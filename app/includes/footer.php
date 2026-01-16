<?php
// app/includes/footer.php
$base = rtrim($config['base_url'] ?? '/', '/');
$currentPage = $_GET['page'] ?? 'list';
$uiLang = $_SESSION['ui_lang'] ?? 'fi';

// Get user info for admin check (same as header.php)
$user = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;
?>

<!-- Bottom Navigation (Mobile) -->
<nav class="sf-bottom-nav" aria-label="<?= htmlspecialchars(sf_term('mobile_nav', $uiLang) ?? 'Mobiilinavigaatio') ?>">
    <a href="<?= $base ?>/index.php?page=list" 
       class="sf-bottom-nav-item <?= $currentPage === 'list' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/list_icon.png" alt="" class="sf-bottom-nav-icon-img">
        <span><?= htmlspecialchars(sf_term('nav_list', $uiLang) ?? 'Lista') ?></span>
    </a>
    
    <?php if ($isAdmin): ?>
    <a href="<?= $base ?>/index.php?page=settings" 
       class="sf-bottom-nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
        <svg class="sf-bottom-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        <span><?= htmlspecialchars(sf_term('nav_settings', $uiLang) ?? 'Asetukset') ?></span>
    </a>
    <?php endif; ?>
    
    <button type="button" 
       class="sf-bottom-nav-item <?= $currentPage === 'profile' ? 'active' : '' ?>"
       data-modal-open="modalProfile">
        <svg class="sf-bottom-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
        <span><?= htmlspecialchars(sf_term('nav_profile', $uiLang) ?? 'Profiili') ?></span>
    </button>
</nav>

<!-- FAB - Floating Action Button -->
<?php if ($currentPage !== 'form'): ?>
<a href="<?= $base ?>/index.php?page=form" 
   class="sf-fab" 
   aria-label="<?= htmlspecialchars(sf_term('new_safetyflash', $uiLang) ?? 'Uusi Safetyflash') ?>">
    <img src="<?= $base ?>/assets/img/icons/add_new_icon.png" 
         alt="" 
         class="sf-fab-icon-img"
         aria-hidden="true">
</a>
<?php endif; ?>

<!-- Profiili-modal -->
<div class="sf-modal hidden" id="modalProfile" role="dialog" aria-modal="true" aria-labelledby="modalProfileTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalProfileTitle"><?= htmlspecialchars(sf_term('profile_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="sfProfileModalForm">
            <?= sf_csrf_field() ?>
            
            <div class="sf-profile-section">
                <h3><?= htmlspecialchars(sf_term('profile_personal_info', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                
                <div class="sf-field-row">
                    <div class="sf-field">
                        <label for="modalProfileFirst"><?= htmlspecialchars(sf_term('users_label_first_name', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" name="first_name" id="modalProfileFirst" class="sf-input" required>
                    </div>
                    <div class="sf-field">
                        <label for="modalProfileLast"><?= htmlspecialchars(sf_term('users_label_last_name', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" name="last_name" id="modalProfileLast" class="sf-input" required>
                    </div>
                </div>
                
                <div class="sf-field">
                    <label for="modalProfileEmail"><?= htmlspecialchars(sf_term('users_label_email', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="email" name="email" id="modalProfileEmail" class="sf-input" required>
                </div>
                
                <div class="sf-field">
                    <label><?= htmlspecialchars(sf_term('users_label_role', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="sf-profile-readonly" id="modalProfileRole">-</div>
                </div>
            </div>
            
            <div class="sf-profile-section">
                <h3><?= htmlspecialchars(sf_term('profile_worksite_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                
                <div class="sf-field">
                    <label for="modalProfileWorksite"><?= htmlspecialchars(sf_term('users_label_home_worksite', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                    <select name="home_worksite_id" id="modalProfileWorksite" class="sf-select">
                        <option value=""><?= htmlspecialchars(sf_term('users_home_worksite_none', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <!-- Worksites loaded dynamically -->
                    </select>
                    <p class="sf-help-text"><?= htmlspecialchars(sf_term('profile_worksite_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            
            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                    <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
        
        <!-- Salasanan vaihto -osio modalissa -->
        <div class="sf-modal-divider"></div>
        
        <details class="sf-password-section">
            <summary><?= htmlspecialchars(sf_term('profile_change_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></summary>
            
            <form id="sfPasswordModalForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-field">
                    <label for="modalCurrentPassword"><?= htmlspecialchars(sf_term('profile_current_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="password" name="current_password" id="modalCurrentPassword" class="sf-input" required>
                </div>
                
                <div class="sf-field-row">
                    <div class="sf-field">
                        <label for="modalNewPassword"><?= htmlspecialchars(sf_term('profile_new_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="password" name="new_password" id="modalNewPassword" class="sf-input" required minlength="8">
                    </div>
                    <div class="sf-field">
                        <label for="modalConfirmPassword"><?= htmlspecialchars(sf_term('profile_confirm_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="password" name="confirm_password" id="modalConfirmPassword" class="sf-input" required minlength="8">
                    </div>
                </div>
                
                <button type="submit" class="sf-btn sf-btn-secondary">
                    <?= htmlspecialchars(sf_term('profile_change_password', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </details>
    </div>
</div>