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

        var activeLink = nav.querySelector('a.is-active');
        var view = 'dashboard';
        if (activeLink) {
            try {
                var activeUrl = new URL(activeLink.href, window.location.href);
                var resolvedView = activeUrl.searchParams.get('df_view') || 'dashboard';
                if (/^[a-z0-9_-]+$/.test(resolvedView)) {
                    view = resolvedView;
                }
            } catch (error) {
                view = 'dashboard';
            }
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

        var mobile = window.matchMedia('(max-width: 767px)');
        var navLinks = Array.prototype.slice.call(nav.querySelectorAll('a'));

        function syncAccessibility(open) {
            if (mobile.matches) {
                nav.setAttribute('aria-hidden', open ? 'false' : 'true');
                if (open) {
                    nav.removeAttribute('inert');
                    navLinks.forEach(function (link) { link.removeAttribute('tabindex'); });
                    backdrop.setAttribute('aria-hidden', 'false');
                    backdrop.removeAttribute('tabindex');
                } else {
                    nav.setAttribute('inert', '');
                    navLinks.forEach(function (link) { link.setAttribute('tabindex', '-1'); });
                    backdrop.setAttribute('aria-hidden', 'true');
                    backdrop.setAttribute('tabindex', '-1');
                }
            } else {
                nav.removeAttribute('aria-hidden');
                nav.removeAttribute('inert');
                navLinks.forEach(function (link) { link.removeAttribute('tabindex'); });
                backdrop.setAttribute('aria-hidden', 'true');
                backdrop.setAttribute('tabindex', '-1');
            }
        }

        function setOpen(open, restoreFocus) {
            shell.classList.toggle('is-nav-open', open);
            document.body.classList.toggle('df-nav-lock', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close DigiForge navigation' : 'Open DigiForge navigation');
            syncAccessibility(open);
            if (open && mobile.matches) {
                var target = nav.querySelector('a.is-active') || navLinks[0];
                if (target) {
                    window.setTimeout(function () { target.focus(); }, 0);
                }
            } else if (restoreFocus === true && mobile.matches) {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!shell.classList.contains('is-nav-open'), false);
        });
        backdrop.addEventListener('click', function () {
            setOpen(false, true);
        });
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                setOpen(false, false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && shell.classList.contains('is-nav-open')) {
                setOpen(false, true);
            }
        });

        function syncViewport(event) {
            if (!event.matches) {
                setOpen(false, false);
            } else {
                setOpen(false, false);
            }
        }
        if (typeof mobile.addEventListener === 'function') {
            mobile.addEventListener('change', syncViewport);
        } else if (typeof mobile.addListener === 'function') {
            mobile.addListener(syncViewport);
        }

        setOpen(false, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
