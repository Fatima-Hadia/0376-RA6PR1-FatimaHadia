/**
 * StaffLog - Main JavaScript
 * Handles interactive features, modals, and UI enhancements
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initModals();
    initSidebar();
    initFormValidation();
    initAutoHideAlerts();
    initConfirmButtons();
});

/**
 * Modal System
 */
function initModals() {
    // Open modal buttons
    document.querySelectorAll('[data-modal-open]').forEach(button => {
        button.addEventListener('click', function() {
            const modalId = this.getAttribute('data-modal-open');
            openModal(modalId);
        });
    });

    // Close modal buttons
    document.querySelectorAll('[data-modal-close]').forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal-overlay');
            if (modal) closeModal(modal.id);
        });
    });

    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal-overlay.active');
            if (activeModal) {
                closeModal(activeModal.id);
            }
        }
    });
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Focus first input in modal
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Reset form if exists
        const form = modal.querySelector('form');
        if (form) form.reset();
    }
}

/**
 * Sidebar Toggle (Mobile)
 */
function initSidebar() {
    const hamburger = document.querySelector('.hamburger');
    const sidebar = document.querySelector('.sidebar');
    
    if (hamburger && sidebar) {
        hamburger.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });

        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (sidebar.classList.contains('open') && 
                !sidebar.contains(e.target) && 
                !hamburger.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Set active state on current page link
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar-nav a').forEach(link => {
        const href = link.getAttribute('href');
        if (href && currentPath.endsWith(href)) {
            link.classList.add('active');
        }
    });
}

/**
 * Form Validation
 */
function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Check required fields
            form.querySelectorAll('[required]').forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    showFieldError(field, 'Aquest camp és obligatori');
                } else {
                    clearFieldError(field);
                }
            });

            // Email validation
            form.querySelectorAll('input[type="email"]').forEach(field => {
                if (field.value && !isValidEmail(field.value)) {
                    isValid = false;
                    showFieldError(field, 'Introdueix un email vàlid');
                }
            });

            // Password confirmation
            const password = form.querySelector('input[name="password"]');
            const passwordConfirm = form.querySelector('input[name="password_confirm"]');
            if (password && passwordConfirm && password.value !== passwordConfirm.value) {
                isValid = false;
                showFieldError(passwordConfirm, 'Les contrasenyes no coincideixen');
            }

            if (!isValid) {
                e.preventDefault();
            }
        });
    });
}

function showFieldError(field, message) {
    clearFieldError(field);
    field.style.borderColor = '#EF4444';
    
    const error = document.createElement('span');
    error.className = 'field-error';
    error.textContent = message;
    error.style.cssText = 'color: #EF4444; font-size: 0.75rem; margin-top: 0.25rem; display: block;';
    field.parentNode.appendChild(error);
}

function clearFieldError(field) {
    const error = field.parentNode.querySelector('.field-error');
    if (error) error.remove();
    field.style.borderColor = '';
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/**
 * Auto-hide alerts after 5 seconds
 */
function initAutoHideAlerts() {
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
}

/**
 * Confirmation buttons
 */
function initConfirmButtons() {
    document.querySelectorAll('[data-confirm]').forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });
}

/**
 * Clock In/Out confirmation
 */
function initClockConfirmation() {
    const clockInBtn = document.getElementById('clock-in-btn');
    const clockOutBtn = document.getElementById('clock-out-btn');
    
    if (clockInBtn) {
        clockInBtn.addEventListener('click', function(e) {
            if (!confirm('Confirmar entrada?')) {
                e.preventDefault();
            }
        });
    }
    
    if (clockOutBtn) {
        clockOutBtn.addEventListener('click', function(e) {
            if (!confirm('Confirmar sortida?')) {
                e.preventDefault();
            }
        });
    }
}

/**
 * Data table search and sort (basic)
 */
function initDataTables() {
    // Future enhancement: Add search and sort to tables
}

/**
 * Chart initialization helper
 */
function initCharts() {
    // Charts are initialized inline in the PHP files
    // This function can be used for global chart settings
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#64748B';
    }
}

/**
 * Utility: Format number with locale
 */
function formatNumber(num) {
    return new Intl.NumberFormat('ca-ES').format(num);
}

/**
 * Utility: Format currency
 */
function formatCurrency(amount) {
    return new Intl.NumberFormat('ca-ES', {
        style: 'currency',
        currency: 'EUR'
    }).format(amount);
}

/**
 * Utility: Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Utility: Copy to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('Copiat al porta-retalls', 'success');
    }).catch(() => {
        showNotification('Error en copiar', 'error');
    });
}

/**
 * Show notification toast
 */
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        animation: fadeIn 0.3s ease-out;
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Initialize clock confirmation if on dashboard
initClockConfirmation();
initCharts();