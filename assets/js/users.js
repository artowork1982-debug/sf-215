console.log("users.js loaded");

(function () {
    var base = typeof SF_BASE_URL !== "undefined"
        ? SF_BASE_URL.replace(/\/+$/, "")
        : "";

    var I18N = (window.SF_I18N && window.SF_I18N.users) ? window.SF_I18N.users : {};
    function tr(key, fallback) {
        return (I18N && I18N[key]) ? I18N[key] : fallback;
    }

    // Hae CSRF-token lomakkeesta tai meta-tagista
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

    document.addEventListener("click", function (e) {
        var settingsPage = document.querySelector(".sf-settings-page");
        if (!settingsPage) return;

        if (e.target.closest("#sfUserAddBtn")) {
            var userModal = document.getElementById("sfUserModal");
            var userForm = document.getElementById("sfUserForm");
            var userTitle = document.getElementById("sfUserModalTitle");
            var inputId = document.getElementById("sfUserId");
            var inputPass = document.getElementById("sfUserPassword");
            var selectHomeWs = document.getElementById("sfUserHomeWorksite");
            var passwordField = document.getElementById("sfPasswordField");
            var autoPasswordInfo = document.getElementById("sfAutoPasswordInfo");

            if (userModal && userForm) {
                userTitle.textContent = tr("addUser", "Lisää käyttäjä");
                userForm.reset();
                inputId.value = "";
                if (selectHomeWs) selectHomeWs.value = "";

                // Hide password field and show auto-password info for new users
                if (passwordField) passwordField.style.display = "none";
                if (autoPasswordInfo) autoPasswordInfo.style.display = "block";
                if (inputPass) inputPass.required = false;

                userModal.classList.remove("hidden");
            }
            return;
        }

        var editBtn = e.target.closest(".sf-edit-user");
        if (editBtn) {
            var userModal = document.getElementById("sfUserModal");
            var userTitle = document.getElementById("sfUserModalTitle");
            var inputId = document.getElementById("sfUserId");
            var inputFirst = document.getElementById("sfUserFirst");
            var inputLast = document.getElementById("sfUserLast");
            var inputEmail = document.getElementById("sfUserEmail");
            var selectRole = document.getElementById("sfUserRole");
            var selectHomeWs = document.getElementById("sfUserHomeWorksite");
            var inputPass = document.getElementById("sfUserPassword");
            var passwordField = document.getElementById("sfPasswordField");
            var autoPasswordInfo = document.getElementById("sfAutoPasswordInfo");

            if (userModal) {
                userTitle.textContent = tr("editUser", "Muokkaa käyttäjää");
                inputId.value = editBtn.dataset.id || "";
                inputFirst.value = editBtn.dataset.first || "";
                inputLast.value = editBtn.dataset.last || "";
                inputEmail.value = editBtn.dataset.email || "";
                selectRole.value = editBtn.dataset.role || "";

                if (selectHomeWs) {
                    var homeWs = editBtn.dataset.homeWorksite || "";
                    selectHomeWs.value = homeWs === "0" ? "" : homeWs;
                }

                inputPass.value = "";
                inputPass.required = false;

                // Show password field and hide auto-password info for editing
                if (passwordField) passwordField.style.display = "block";
                if (autoPasswordInfo) autoPasswordInfo.style.display = "none";

                userModal.classList.remove("hidden");
            }
            return;
        }

        var delBtn = e.target.closest(".sf-delete-user");
        if (delBtn) {
            var deleteModal = document.getElementById("sfDeleteModal");
            var deleteName = document.getElementById("sfDeleteUserName");

            var row = delBtn.closest("tr");
            var card = delBtn.closest(".sf-user-card");
            var name = "käyttäjä";

            if (row) {
                var nameCell = row.querySelector("td");
                name = nameCell ? nameCell.textContent.trim() : name;
            } else if (card) {
                var nameEl = card.querySelector(".sf-user-card-name");
                name = nameEl ? nameEl.textContent.trim() : name;
            }

            if (deleteModal) {
                deleteModal.dataset.userId = delBtn.dataset.id || "";
                if (deleteName) deleteName.textContent = name;
                deleteModal.classList.remove("hidden");
            }
            return;
        }

        var resetBtn = e.target.closest(".sf-reset-pass");
        if (resetBtn) {
            var resetModal = document.getElementById("sfResetModal");
            var resetName = document.getElementById("sfResetUserName");

            var row = resetBtn.closest("tr");
            var card = resetBtn.closest(".sf-user-card");
            var email = "";

            if (row) {
                var emailCell = row.querySelector("td:nth-child(2)");
                email = emailCell ? emailCell.textContent.trim() : "";
            } else if (card) {
                var emailEl = card.querySelector(".sf-user-card-email");
                email = emailEl ? emailEl.textContent.trim() : "";
            }

            if (resetModal) {
                resetModal.dataset.userId = resetBtn.dataset.id || "";
                if (resetName) resetName.textContent = email;
                resetModal.classList.remove("hidden");
            }
            return;
        }

        if (e.target.closest("#sfUserCancel")) {
            var modal = document.getElementById("sfUserModal");
            if (modal) modal.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfDeleteCancel")) {
            var modal = document.getElementById("sfDeleteModal");
            if (modal) modal.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfResetCancel")) {
            var modal = document.getElementById("sfResetModal");
            if (modal) modal.classList.add("hidden");
            return;
        }

        if (e.target.closest("#sfDeleteConfirm")) {
            var modal = document.getElementById("sfDeleteModal");
            var userId = modal ? modal.dataset.userId : null;

            if (userId) {
                var body = new URLSearchParams();
                body.set("id", userId);

                // Lisää CSRF-token
                var csrfToken = getCsrfToken();
                if (csrfToken) {
                    body.set("csrf_token", csrfToken);
                }

                fetch(base + "/app/api/users_delete.php", {
                    method: "POST",
                    body: body
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.ok) {
                            window.location = base + "/index.php?page=settings&tab=users&notice=user_deleted";
                        } else {
                            alert(res.error || tr("errDelete", "Virhe poistossa"));
                        }
                    })
                    .catch(function () { alert(tr("errNetwork", "Verkkovirhe.")); });
            }
            return;
        }

        if (e.target.closest("#sfResetConfirm")) {
            var modal = document.getElementById("sfResetModal");
            var userId = modal ? modal.dataset.userId : null;

            if (userId) {
                var body = new URLSearchParams();
                body.set("id", userId);

                // Lisää CSRF-token
                var csrfToken = getCsrfToken();
                if (csrfToken) {
                    body.set("csrf_token", csrfToken);
                }

                fetch(base + "/app/api/users_reset_password.php", {
                    method: "POST",
                    body: body
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.ok) {
                            modal.classList.add("hidden");
                            alert(tr("newPassPrefix", "Uusi salasana: ") + res.password);
                            window.location = base + "/index.php?page=settings&tab=users&notice=user_pass_reset";
                        } else {
                            alert(res.error || tr("errReset", "Virhe salasanan resetoinnissa"));
                        }
                    })
                    .catch(function () { alert(tr("errNetwork", "Verkkovirhe.")); });
            }
            return;
        }
    });

    document.addEventListener("submit", function (e) {
        var userForm = e.target.closest("#sfUserForm");
        if (!userForm) return;

        e.preventDefault();

        var formData = new FormData(userForm);
        var inputId = document.getElementById("sfUserId");
        var isEdit = inputId && inputId.value !== "";

        var csrfInput = userForm.querySelector('input[name="csrf_token"]');
        if (csrfInput && csrfInput.value) {
            formData.set("csrf_token", csrfInput.value);
        }

        if (formData.get("home_worksite_id") === "") {
            formData.set("home_worksite_id", "");
        }

        var url = base + (isEdit ? "/app/api/users_update.php" : "/app/api/users_create.php");

        fetch(url, {
            method: "POST",
            body: formData
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.ok) {
                    var notice = isEdit ? "user_updated" : "user_created";

                    // Show success message with toast if available
                    if (!isEdit && res.password_sent !== undefined) {
                        var successMsg = res.password_sent
                            ? tr("userCreatedEmailSent", "Käyttäjä luotu! Kirjautumistiedot lähetetty sähköpostiin.")
                            : tr("userCreated", "Käyttäjä luotu!");

                        // Try to show toast notification if showSuccessToast is available
                        if (typeof showSuccessToast === "function") {
                            showSuccessToast(successMsg);
                        }
                    }

                    window.location = base + "/index.php?page=settings&tab=users&notice=" + notice;
                } else {
                    alert(res.error || tr("errSave", "Virhe tallennuksessa"));
                }
            })
            .catch(function () { alert(tr("errNetwork", "Verkkovirhe.")); });
    });

    // ===== USER FILTERING LOGIC =====
    (function initUserFilters() {
        var filterToggle = document.getElementById("sfUsersFiltersToggle");
        var filterContent = document.getElementById("sfUsersFiltersContent");
        var filterRole = document.getElementById("sfFilterRole");
        var filterWorksite = document.getElementById("sfFilterWorksite");
        var filterSearch = document.getElementById("sfFilterSearch");
        var filterLoginStatus = document.getElementById("sfFilterLoginStatus");
        var filterClear = document.getElementById("sfFilterClear");

        if (!filterRole || !filterWorksite || !filterSearch || !filterLoginStatus) {
            return; // Filters not on this page
        }

        // Toggle filters on mobile
        if (filterToggle && filterContent) {
            filterToggle.addEventListener("click", function () {
                filterContent.classList.toggle("active");
            });
        }

        // Parse URL params to restore filter state
        function getUrlParams() {
            var params = new URLSearchParams(window.location.search);
            return {
                role: params.get("filter_role") || "",
                worksite: params.get("filter_worksite") || "",
                search: params.get("filter_search") || "",
                loginStatus: params.get("filter_login") || ""
            };
        }

        // Update URL with current filter state
        function updateUrl() {
            var params = new URLSearchParams(window.location.search);

            if (filterRole.value) {
                params.set("filter_role", filterRole.value);
            } else {
                params.delete("filter_role");
            }

            if (filterWorksite.value) {
                params.set("filter_worksite", filterWorksite.value);
            } else {
                params.delete("filter_worksite");
            }

            if (filterSearch.value) {
                params.set("filter_search", filterSearch.value);
            } else {
                params.delete("filter_search");
            }

            if (filterLoginStatus.value) {
                params.set("filter_login", filterLoginStatus.value);
            } else {
                params.delete("filter_login");
            }

            var paramsString = params.toString();
            var newUrl = paramsString ? window.location.pathname + "?" + paramsString : window.location.pathname;
            window.history.replaceState({}, "", newUrl);
        }

        // Check if an element should be shown based on filter criteria
        function shouldShowElement(element, roleVal, worksiteVal, searchVal, loginVal) {
            // Role filter
            if (roleVal && element.dataset.roleId !== roleVal) {
                return false;
            }

            // Worksite filter
            if (worksiteVal && element.dataset.worksiteId !== worksiteVal) {
                return false;
            }

            // Search filter
            if (searchVal) {
                var name = (element.dataset.name || "").toLowerCase();
                var email = (element.dataset.email || "").toLowerCase();
                if (!name.includes(searchVal) && !email.includes(searchVal)) {
                    return false;
                }
            }

            // Login status filter
            if (loginVal === "logged" && element.dataset.hasLoggedIn !== "1") {
                return false;
            }
            if (loginVal === "never" && element.dataset.hasLoggedIn !== "0") {
                return false;
            }

            return true;
        }

        // Apply filters to user cards and table rows
        function applyFilters() {
            var roleVal = filterRole.value;
            var worksiteVal = filterWorksite.value;
            var searchVal = filterSearch.value.toLowerCase().trim();
            var loginVal = filterLoginStatus.value;

            // Filter cards (mobile)
            var cards = document.querySelectorAll(".sf-user-card");
            cards.forEach(function (card) {
                var show = shouldShowElement(card, roleVal, worksiteVal, searchVal, loginVal);
                card.style.display = show ? "" : "none";
            });

            // Filter table rows (desktop)
            var rows = document.querySelectorAll(".sf-table-users tbody tr");
            rows.forEach(function (row) {
                var show = shouldShowElement(row, roleVal, worksiteVal, searchVal, loginVal);
                row.style.display = show ? "" : "none";
            });

            updateUrl();
        }

        // Clear all filters
        function clearFilters() {
            filterRole.value = "";
            filterWorksite.value = "";
            filterSearch.value = "";
            filterLoginStatus.value = "";
            applyFilters();
        }

        // Restore filter state from URL
        var urlParams = getUrlParams();
        if (urlParams.role) filterRole.value = urlParams.role;
        if (urlParams.worksite) filterWorksite.value = urlParams.worksite;
        if (urlParams.search) filterSearch.value = urlParams.search;
        if (urlParams.loginStatus) filterLoginStatus.value = urlParams.loginStatus;

        // Apply filters on page load if any are set
        if (urlParams.role || urlParams.worksite || urlParams.search || urlParams.loginStatus) {
            applyFilters();
        }

        // Event listeners
        filterRole.addEventListener("change", applyFilters);
        filterWorksite.addEventListener("change", applyFilters);
        filterSearch.addEventListener("input", applyFilters);
        filterLoginStatus.addEventListener("change", applyFilters);
        filterClear.addEventListener("click", clearFilters);
    })();
})();