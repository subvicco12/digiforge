(function () {
    'use strict';

    function boot() {
        var shell = document.querySelector('.df-portal-shell');
        if (!shell) {
            return;
        }

        var sidebar = shell.querySelector('.df-portal-sidebar');
        var brand = shell.querySelector('.df-brand');
        var nav = shell.querySelector('.df-nav');
        if (!sidebar || !brand || !nav) {
            return;
        }

        document.body.classList.add('df-portal-active');
        shell.classList.add('df-js');

        var params = new URLSearchParams(window.location.search);
        var view = params.get('df_view') || 'dashboard';
        if (!/^[a-z0-9_-]+$/.test(view)) {
            view = 'dashboard';
        }
        shell.classList.add('df-view-' + view);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'df-mobile-nav-toggle';
        toggle.setAttribute('aria-label', 'Open DigiForge navigation');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', 'df-primary-navigation');
        toggle.innerHTML = '<span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span>';
        brand.insertAdjacentElement('afterend', toggle);

        nav.id = 'df-primary-navigation';

        var backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'df-mobile-nav-backdrop';
        backdrop.setAttribute('aria-label', 'Close DigiForge navigation');
        shell.appendChild(backdrop);

        function setOpen(open) {
            shell.classList.toggle('is-nav-open', open);
            document.body.classList.toggle('df-nav-lock', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close DigiForge navigation' : 'Open DigiForge navigation');
        }

        toggle.addEventListener('click', function () {
            setOpen(!shell.classList.contains('is-nav-open'));
        });
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                setOpen(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        });

        var mobile = window.matchMedia('(max-width: 767px)');
        function syncViewport(event) {
            if (!event.matches) {
                setOpen(false);
            }
        }
        if (typeof mobile.addEventListener === 'function') {
            mobile.addEventListener('change', syncViewport);
        } else if (typeof mobile.addListener === 'function') {
            mobile.addListener(syncViewport);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
