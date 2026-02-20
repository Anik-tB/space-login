/**
 * SafeSpace Toast Notification System
 * Usage: showToast('Message here', 'success' | 'error' | 'warning' | 'info')
 */

(function () {
    // Create container on first load
    function getContainer() {
        let container = document.getElementById('ss-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'ss-toast-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            Object.assign(container.style, {
                position: 'fixed',
                top: '1.25rem',
                right: '1.25rem',
                zIndex: '99999',
                display: 'flex',
                flexDirection: 'column',
                gap: '0.6rem',
                maxWidth: '360px',
                width: 'calc(100vw - 2.5rem)',
                pointerEvents: 'none'
            });
            document.body.appendChild(container);
        }
        return container;
    }

    const ICONS = {
        success: '✅',
        error:   '❌',
        warning: '⚠️',
        info:    'ℹ️'
    };

    const COLORS = {
        success: { bg: 'rgba(34, 197, 94, 0.15)',  border: 'rgba(34, 197, 94, 0.4)',  text: '#4ade80' },
        error:   { bg: 'rgba(239, 68, 68, 0.15)',  border: 'rgba(239, 68, 68, 0.4)',  text: '#f87171' },
        warning: { bg: 'rgba(245, 158, 11, 0.15)', border: 'rgba(245, 158, 11, 0.4)', text: '#fbbf24' },
        info:    { bg: 'rgba(99, 102, 241, 0.15)', border: 'rgba(99, 102, 241, 0.4)', text: '#a5b4fc' }
    };

    window.showToast = function (message, type = 'info', duration = 3500) {
        const container = getContainer();
        const color = COLORS[type] || COLORS.info;
        const icon  = ICONS[type]  || ICONS.info;

        const toast = document.createElement('div');
        toast.setAttribute('role', 'alert');
        Object.assign(toast.style, {
            background:    color.bg,
            border:        `1px solid ${color.border}`,
            borderLeft:    `4px solid ${color.border}`,
            borderRadius:  '12px',
            padding:       '0.85rem 1rem',
            display:       'flex',
            alignItems:    'flex-start',
            gap:           '0.6rem',
            backdropFilter:'blur(12px)',
            WebkitBackdropFilter: 'blur(12px)',
            boxShadow:     '0 8px 24px rgba(0,0,0,0.25)',
            pointerEvents: 'auto',
            cursor:        'pointer',
            opacity:       '0',
            transform:     'translateX(0.5rem)',
            transition:    'opacity 0.25s ease, transform 0.25s ease',
            fontFamily:    "'Inter', 'Segoe UI', sans-serif",
            fontSize:      '0.9rem',
            lineHeight:    '1.5',
            color:         '#f1f5f9'
        });

        toast.innerHTML = `
            <span style="font-size:1.1rem;flex-shrink:0;margin-top:1px">${icon}</span>
            <span style="flex:1">${message}</span>
            <button onclick="this.parentElement.remove()" style="
                background:none;border:none;color:rgba(255,255,255,0.4);
                font-size:1.1rem;cursor:pointer;padding:0;line-height:1;
                margin-left:0.25rem;flex-shrink:0;
            " aria-label="Dismiss">×</button>
        `;

        container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                toast.style.opacity  = '1';
                toast.style.transform = 'translateX(0)';
            });
        });

        // Progress bar
        const bar = document.createElement('div');
        Object.assign(bar.style, {
            position:  'absolute',
            bottom:    '0',
            left:      '0',
            height:    '2px',
            width:     '100%',
            background: color.border,
            borderRadius: '0 0 12px 12px',
            transition: `width ${duration}ms linear`
        });
        toast.style.position = 'relative';
        toast.style.overflow = 'hidden';
        toast.appendChild(bar);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => { bar.style.width = '0%'; });
        });

        // Auto-dismiss
        const timer = setTimeout(() => dismissToast(toast), duration);
        toast.addEventListener('click', () => {
            clearTimeout(timer);
            dismissToast(toast);
        });
    };

    function dismissToast(toast) {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(0.5rem)';
        setTimeout(() => toast.remove(), 280);
    }

    // Auto-show toasts injected by PHP (data-toast attributes)
    document.addEventListener('DOMContentLoaded', () => {
        const phpToasts = document.querySelectorAll('[data-toast]');
        phpToasts.forEach((el, i) => {
            const msg  = el.getAttribute('data-toast');
            const type = el.getAttribute('data-toast-type') || 'info';
            if (msg) {
                setTimeout(() => showToast(msg, type), i * 300);
            }
            el.remove();
        });
    });
})();
