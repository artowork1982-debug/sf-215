/**
 * SafetyFlash Copy-to-Clipboard Module
 * Provides functionality to copy preview images to clipboard
 */

(function () {
    'use strict';

    /**
     * Copy an HTML element as an image to clipboard
     * @param {HTMLElement} element - The element to capture
     * @param {Object} options - html2canvas options (unused, kept for compatibility)
     * @returns {Promise<boolean>} - Resolves with true on success
     */
    async function copyImageToClipboard(element, options = {}) {
        // Check if Clipboard API is supported
        if (!navigator.clipboard || !ClipboardItem) {
            throw new Error('Clipboard API not supported in this browser');
        }

        // Check if HTTPS or localhost
        if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
            throw new Error('Clipboard API requires HTTPS or localhost');
        }

        try {
            // Find the image element
            const img = element.querySelector('img');
            if (!img || !img.src) {
                throw new Error('No image found');
            }

            // Fetch the original image
            const response = await fetch(img.src);
            if (!response.ok) {
                throw new Error('Failed to fetch image');
            }
            const blob = await response.blob();

            // Convert to PNG if needed (clipboard requires PNG)
            let pngBlob = blob;
            if (blob.type === 'image/jpeg' || blob.type === 'image/jpg') {
                const imageBitmap = await createImageBitmap(blob);
                const canvas = document.createElement('canvas');
                canvas.width = imageBitmap.width;
                canvas.height = imageBitmap.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(imageBitmap, 0, 0);
                pngBlob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            }

            // Create ClipboardItem and write to clipboard
            const clipboardItem = new ClipboardItem({ 'image/png': pngBlob });
            await navigator.clipboard.write([clipboardItem]);

            return true;
        } catch (error) {
            console.error('Copy to clipboard failed:', error);
            throw error;
        }
    }

    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {string} type - 'success' or 'error'
     */
    function showCopyToast(message, type = 'success') {
        // Use global toast if available (from header.php)
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
            return;
        }

        // Fallback: create inline toast with same styling as global toast
        const existingToast = document.getElementById('sfCopyToast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.id = 'sfCopyToast';

        const isError = type === 'error';
        const bgColor = isError
            ? 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'
            : 'linear-gradient(135deg, #10b981 0%, #059669 100%)';

        toast.style.cssText = `
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 100001;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            max-width: 340px;
            font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 0.95rem;
            font-weight: 600;
            color: #fff;
            background: ${bgColor};
            opacity: 0;
            transform: translateX(100px);
            transition: opacity 0.25s ease, transform 0.25s ease;
        `;

        const iconSvg = isError
            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>'
            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';

        toast.innerHTML = `
            <span style="flex-shrink: 0; width: 20px; height: 20px;">${iconSvg}</span>
            <span>${message}</span>
        `;

        document.body.appendChild(toast);

        // Trigger animation
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(0)';
        });

        // Auto remove after 3 seconds
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100px)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Add a copy button to a target element
     * @param {HTMLElement} targetElement - The element to add button to
     * @param {Object} buttonOptions - Button configuration
     * @returns {HTMLElement} - The created button element
     */
    function addCopyButton(targetElement, buttonOptions = {}) {
        const {
            label = 'Copy image',
            copyingLabel = 'Copying...',
            successMessage = 'Image copied to clipboard!',
            errorMessage = 'Copy failed',
            // Use the existing copy-icon.svg instead of inline SVG
            iconSvg = '<img src="' + encodeURI((window.SF_BASE_URL || '')) + '/assets/img/icons/copy-icon.svg" alt="" width="16" height="16" style="width:16px;height:16px;">',
            position = 'top-right',
            className = ''
        } = buttonOptions;

        // Create button
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `sf-copy-btn ${className}`.trim();
        button.setAttribute('data-position', position);
        button.setAttribute('aria-label', label);
        button.innerHTML = `
            ${iconSvg}
            <span class="sf-copy-btn-label">${label}</span>
        `;

        // Add click handler
        button.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            // Check if already copying
            if (button.disabled) return;

            // Disable button and show loading
            button.disabled = true;
            const originalHtml = button.innerHTML;
            button.innerHTML = `
                <span class="sf-spinner"></span>
                <span class="sf-copy-btn-label">${copyingLabel}</span>
            `;

            try {
                // Copy the target element
                await copyImageToClipboard(targetElement);

                // Show success toast
                showCopyToast(successMessage, 'success');
            } catch (error) {
                console.error('Copy failed:', error);

                // Show error toast
                let errorMsg = errorMessage;
                if (error.message.includes('not supported')) {
                    errorMsg += ' (Browser not supported)';
                } else if (error.message.includes('HTTPS')) {
                    errorMsg += ' (HTTPS required)';
                }
                showCopyToast(errorMsg, 'error');
            } finally {
                // Re-enable button
                button.innerHTML = originalHtml;
                button.disabled = false;
            }
        });

        // Add button to target element's parent
        if (targetElement.parentElement) {
            targetElement.parentElement.style.position = 'relative';
            targetElement.parentElement.appendChild(button);
        }

        return button;
    }

    // Export functions globally
    window.SafetyFlashCopy = {
        copyImageToClipboard,
        showCopyToast,
        addCopyButton
    };
})();