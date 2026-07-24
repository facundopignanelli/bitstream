document.addEventListener('DOMContentLoaded', function () {
    // Check if this is a PWA share target launch
    const urlParams = new URLSearchParams(window.location.search);
    const isShareTarget = urlParams.has('url') || urlParams.has('shared_url');

    if (isShareTarget) {
        console.log('BitStream: PWA Share target detected');

        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2c6e49;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(44,110,73,0.3);
            z-index: 10000;
            font-size: 14px;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s ease;
        `;
        notification.textContent = 'Content shared to BitStream!';
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.opacity = '1';
            notification.style.transform = 'translateY(0)';
        }, 100);

        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(-20px)';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    // Initialize modules
    if (window.BitStream) {
        if (window.BitStream.Lightbox && typeof window.BitStream.Lightbox.init === 'function') {
            window.BitStream.Lightbox.init();
        }
        if (window.BitStream.Cropper && typeof window.BitStream.Cropper.init === 'function') {
            window.BitStream.Cropper.init();
        }
        if (window.BitStream.Uploader && typeof window.BitStream.Uploader.init === 'function') {
            window.BitStream.Uploader.init();
        }
        if (window.BitStream.Composer && typeof window.BitStream.Composer.init === 'function') {
            window.BitStream.Composer.init();
        }
        if (window.BitStream.Timeline && typeof window.BitStream.Timeline.init === 'function') {
            window.BitStream.Timeline.init();
        }
    }
});

// Mobile-only textarea auto-grow: starts at 160px, grows as user types.
function bsMobileAutoResize(el) {
    const form = el.closest('form');
    let flexItem = el;
    
    if (form) {
        while (flexItem && flexItem.parentNode !== form) {
            flexItem = flexItem.parentNode;
        }
    }
    if (!flexItem || flexItem === form) {
        flexItem = el.closest('.bs-textarea-container') || el;
    }

    let offset = 0;
    if (flexItem.classList.contains('bs-edit-field')) {
        offset = 30; // Account for label and margin in the edit field
    }

    flexItem.style.height = 'auto';
    const baseHeight = Math.max(el.scrollHeight + offset, 160);
    flexItem.style.height = '';
    flexItem.style.setProperty('flex-basis', baseHeight + 'px', 'important');
}

function updateQuickActionCounter(triggerName) {
    document.querySelectorAll('[data-composer-modal-trigger="' + triggerName + '"]').forEach(trigger => {
        const span = trigger.querySelector('span');
        if (span) {
            const match = span.textContent.match(/\((\d+)\)/);
            if (match) {
                const currentCount = parseInt(match[1], 10);
                const newCount = Math.max(0, currentCount - 1);
                span.textContent = span.textContent.replace(/\(\d+\)/, '(' + newCount + ')');
            }
        }
    });
}

// Namespace exports under window.BitStream.UI with backwards-compatibility aliases
window.BitStream = window.BitStream || {};
window.BitStream.UI = window.BitStream.UI || {};
window.BitStream.UI.bsMobileAutoResize = bsMobileAutoResize;
window.BitStream.UI.updateQuickActionCounter = updateQuickActionCounter;

window.bsMobileAutoResize = bsMobileAutoResize;
window.updateQuickActionCounter = updateQuickActionCounter;
