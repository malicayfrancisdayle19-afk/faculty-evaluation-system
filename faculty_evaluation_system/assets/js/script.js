document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-login-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            const button = form.querySelector('button[type="submit"]');
            if (!button || button.disabled) {
                event.preventDefault();
                return;
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Signing in...';
        });
    });

    document.querySelectorAll('[data-confirm-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const message = form.getAttribute('data-confirm-message')
                || 'Save these changes?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById('password');
            const icon = button.querySelector('i');
            if (!input || !icon) return;

            const shouldShow = input.type === 'password';
            input.type = shouldShow ? 'text' : 'password';
            button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
            button.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
            icon.classList.toggle('bi-eye', !shouldShow);
            icon.classList.toggle('bi-eye-slash', shouldShow);
        });
    });

    document.querySelectorAll('[data-print-page]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    const sidebar = document.getElementById('portal-sidebar');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const closeButtons = document.querySelectorAll('[data-sidebar-close]');

    function setSidebarOpen(isOpen) {
        if (!sidebar || !backdrop || !toggle) return;
        sidebar.classList.toggle('is-open', isOpen);
        backdrop.classList.toggle('is-visible', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.body.classList.toggle('sidebar-open', isOpen);
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setSidebarOpen(!sidebar.classList.contains('is-open'));
        });
    }

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setSidebarOpen(false);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setSidebarOpen(false);
    });
});
