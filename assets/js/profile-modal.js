
// assets/js/profile-modal.js
(function() {
    "use strict";
    
    const base = window.SF_BASE_URL || '';
    
    // Open profile modal
    document.addEventListener('click', function(e) {
        const opener = e.target.closest('[data-modal-open="modalProfile"]');
        if (!opener) return;
        
        e.preventDefault();
        openProfileModal();
    });
    
    async function openProfileModal() {
        const modal = document.getElementById('modalProfile');
        if (!modal) return;
        
        // Load user data
        try {
            const response = await fetch(base + '/app/api/profile_get.php');
            const data = await response.json();
            
            if (data.ok && data.user) {
                // Fill user data
                document.getElementById('modalProfileFirst').value = data.user.first_name || '';
                document.getElementById('modalProfileLast').value = data.user.last_name || '';
                document.getElementById('modalProfileEmail').value = data.user.email || '';
                document.getElementById('modalProfileRole').textContent = data.user.role_name || '-';
                
                // Fill worksites dropdown
                const worksiteSelect = document.getElementById('modalProfileWorksite');
                if (worksiteSelect && data.worksites) {
                    // Keep first option (none)
                    const firstOption = worksiteSelect.options[0];
                    worksiteSelect.innerHTML = '';
                    worksiteSelect.appendChild(firstOption);
                    
                    // Add worksites
                    data.worksites.forEach(function(ws) {
                        const option = document.createElement('option');
                        option.value = ws.id;
                        option.textContent = ws.name;
                        if (parseInt(ws.id) === parseInt(data.user.home_worksite_id || 0)) {
                            option.selected = true;
                        }
                        worksiteSelect.appendChild(option);
                    });
                }
            }
        } catch (err) {
            console.error('Error loading profile:', err);
        }
        
        // Open modal
        modal.classList.remove('hidden');
    }
    
    // Save profile
    const profileForm = document.getElementById('sfProfileModalForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch(base + '/app/api/profile_update.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    // Show toast
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', window.SF_PROFILE_I18N?.profileUpdated || 'Profiili päivitetty!');
                    }
                    
                    // Close modal
                    const modal = document.getElementById('modalProfile');
                    if (modal) {
                        modal.classList.add('hidden');
                    }
                    
                    // Update header name if visible
                    const headerName = document.querySelector('.sf-user-name');
                    if (headerName) {
                        const firstName = formData.get('first_name');
                        const lastName = formData.get('last_name');
                        headerName.textContent = firstName + ' ' + lastName;
                    }
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', result.error || 'Virhe tallennuksessa');
                    }
                }
            } catch (err) {
                console.error('Profile update error:', err);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', 'Virhe tallennuksessa');
                }
            }
        });
    }
    
    // Change password
    const passwordForm = document.getElementById('sfPasswordModalForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const newPass = document.getElementById('modalNewPassword').value;
            const confirmPass = document.getElementById('modalConfirmPassword').value;
            
            if (newPass !== confirmPass) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', window.SF_PROFILE_I18N?.passwordsMismatch || 'Salasanat eivät täsmää');
                }
                return;
            }
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch(base + '/app/api/profile_password.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    // Show toast
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', window.SF_PROFILE_I18N?.passwordChanged || 'Salasana vaihdettu!');
                    }
                    
                    // Clear fields
                    this.reset();
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', result.error || 'Virhe salasanan vaihdossa');
                    }
                }
            } catch (err) {
                console.error('Password change error:', err);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', 'Virhe salasanan vaihdossa');
                }
            }
        });
    }
})();