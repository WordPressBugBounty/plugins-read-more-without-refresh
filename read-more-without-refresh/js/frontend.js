/**
 * Read More Without Refresh Pro - Frontend (v5.0)
 *
 * Modules: toggle + animations, accordion groups, truncate ellipsis, lazy
 * <template> rendering, conditional display, deep linking, remember state,
 * expand/collapse all, A/B variants, content locker (email/share), engaged
 * CTA injection, sections (Wikipedia mode) and REST analytics.
 *
 * No console output in production: debug logs only when WP_DEBUG is on.
 *
 * @package ReadMoreWithoutRefreshPro
 * @version 5.0.0
 */

(function () {
    'use strict';

    var settings = window.rmwrSettings || {};

    function debug() {
        if (settings.debug && window.console) {
            console.log.apply(console, ['RMWR:'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    /* ---------------------------------------------------------------------
     * Storage helpers (fail silently in private mode)
     * ------------------------------------------------------------------ */

    function storeGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }

    function storeSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* noop */ }
    }

    /* ---------------------------------------------------------------------
     * Analytics
     * ------------------------------------------------------------------ */

    function track(key, postId, event, variant) {
        if (!settings.analytics || !settings.restUrl || !key) {
            return;
        }

        try {
            window.fetch(settings.restUrl.replace(/\/$/, '') + '/track', {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ k: key, p: parseInt(postId, 10) || 0, e: event, v: variant || '' })
            }).catch(function () { /* never disturb the visitor */ });
        } catch (e) { /* noop */ }

        // Google Analytics 4, when present.
        if (typeof window.gtag === 'function' && event === 'expand') {
            try {
                window.gtag('event', 'read_more_clicked', { event_category: 'Read More', value: key });
            } catch (e) { /* noop */ }
        }

        // Public hook for custom integrations.
        try {
            window.dispatchEvent(new CustomEvent('rmwr:' + event, {
                detail: { instanceKey: key, postId: postId, variant: variant || '', timestamp: new Date().toISOString() }
            }));
        } catch (e) { /* noop */ }
    }

    /* ---------------------------------------------------------------------
     * A/B variants
     * ------------------------------------------------------------------ */

    var abVariant = '';

    function initAB() {
        var variants = settings.abVariants || [];
        if (!variants.length || variants.length < 2) {
            return;
        }

        var idx = parseInt(storeGet('rmwr_ab_idx'), 10);
        if (isNaN(idx) || idx < 0 || idx >= variants.length) {
            idx = Math.floor(Math.random() * variants.length);
            storeSet('rmwr_ab_idx', String(idx));
        }

        abVariant = variants[idx];

        document.querySelectorAll('.read-link[data-ab="1"]').forEach(function (link) {
            var textEl = link.querySelector('.rmwr-text');
            if (textEl && link.getAttribute('aria-expanded') !== 'true') {
                textEl.textContent = abVariant;
            }
            link.dataset.openText = abVariant;
        });

        debug('A/B variant assigned:', abVariant);
    }

    /* ---------------------------------------------------------------------
     * Animations (expand/collapse)
     * ------------------------------------------------------------------ */

    function clearAnimStyles(el) {
        el.style.maxHeight = '';
        el.style.overflow = '';
        el.style.transform = '';
        el.style.perspective = '';
        el.style.transformOrigin = '';
        el.style.transition = '';
        el.style.opacity = '';
    }

    function expandEl(element, wrapper, instant) {
        element.style.display = 'block';

        var animation = instant ? 'none' : ((wrapper && wrapper.dataset.animation) || 'none');
        var duration = parseInt(wrapper ? wrapper.dataset.duration : 0, 10) || 300;

        if (animation === 'none') {
            return;
        }

        element.style.overflow = 'hidden';
        element.style.transition = 'all ' + duration + 'ms ease-in-out';

        switch (animation) {
            case 'fade':
            case 'slide':
                element.style.opacity = '0';
                element.style.maxHeight = '0';
                void element.offsetHeight;
                element.style.opacity = '1';
                element.style.maxHeight = element.scrollHeight + 'px';
                break;
            case 'flip':
                element.style.opacity = '0';
                element.style.transform = 'rotateX(-90deg)';
                void element.offsetHeight;
                element.style.opacity = '1';
                element.style.transform = 'rotateX(0deg)';
                break;
            case 'zoom':
                element.style.opacity = '0';
                element.style.transform = 'scale(0.8)';
                void element.offsetHeight;
                element.style.opacity = '1';
                element.style.transform = 'scale(1)';
                break;
            case 'bounce':
                element.style.opacity = '0';
                element.style.transform = 'translateY(-20px)';
                void element.offsetHeight;
                element.style.transition = 'all ' + duration + 'ms cubic-bezier(0.68, -0.55, 0.265, 1.55)';
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
                break;
            case 'rotate':
                element.style.opacity = '0';
                element.style.transform = 'rotate(-180deg) scale(0.8)';
                void element.offsetHeight;
                element.style.opacity = '1';
                element.style.transform = 'rotate(0deg) scale(1)';
                break;
            case 'scale':
                element.style.opacity = '0';
                element.style.transform = 'scaleY(0)';
                element.style.transformOrigin = 'top';
                void element.offsetHeight;
                element.style.opacity = '1';
                element.style.transform = 'scaleY(1)';
                break;
            case 'elastic':
                element.style.opacity = '0';
                element.style.maxHeight = '0';
                void element.offsetHeight;
                element.style.transition = 'all ' + duration + 'ms cubic-bezier(0.68, -0.55, 0.265, 1.55)';
                element.style.opacity = '1';
                element.style.maxHeight = element.scrollHeight + 'px';
                break;
            default:
                clearAnimStyles(element);
                return;
        }

        window.setTimeout(function () { clearAnimStyles(element); }, duration);
    }

    function collapseEl(element, wrapper, instant) {
        var animation = instant ? 'none' : ((wrapper && wrapper.dataset.animation) || 'none');
        var duration = parseInt(wrapper ? wrapper.dataset.duration : 0, 10) || 300;

        if (animation === 'none') {
            element.style.display = 'none';
            return;
        }

        element.style.transition = 'all ' + duration + 'ms ease-in-out';
        element.style.overflow = 'hidden';

        switch (animation) {
            case 'fade':
            case 'slide':
            case 'elastic':
                element.style.opacity = '1';
                element.style.maxHeight = element.scrollHeight + 'px';
                void element.offsetHeight;
                element.style.opacity = '0';
                element.style.maxHeight = '0';
                break;
            case 'flip':
                void element.offsetHeight;
                element.style.opacity = '0';
                element.style.transform = 'rotateX(90deg)';
                break;
            case 'zoom':
                void element.offsetHeight;
                element.style.opacity = '0';
                element.style.transform = 'scale(0.8)';
                break;
            case 'bounce':
                void element.offsetHeight;
                element.style.opacity = '0';
                element.style.transform = 'translateY(-20px)';
                break;
            case 'rotate':
                void element.offsetHeight;
                element.style.opacity = '0';
                element.style.transform = 'rotate(180deg) scale(0.8)';
                break;
            case 'scale':
                element.style.transformOrigin = 'top';
                void element.offsetHeight;
                element.style.opacity = '0';
                element.style.transform = 'scaleY(0)';
                break;
        }

        window.setTimeout(function () {
            element.style.display = 'none';
            clearAnimStyles(element);
        }, duration);
    }

    /* ---------------------------------------------------------------------
     * Core toggle
     * ------------------------------------------------------------------ */

    function getParts(wrapper) {
        var id = wrapper.dataset.id;
        return {
            id: id,
            key: wrapper.dataset.key || id,
            postId: wrapper.dataset.post || 0,
            link: document.getElementById('readlink' + id),
            content: document.getElementById('read' + id)
        };
    }

    function isExpanded(content) {
        return content.style.display !== 'none' && content.getAttribute('aria-hidden') !== 'true';
    }

    function setButtonText(link, text) {
        var textEl = link.querySelector('.rmwr-text');
        if (textEl) {
            textEl.textContent = text;
        } else {
            link.textContent = text;
        }
    }

    function materializeLazy(content) {
        var tpl = content.querySelector('template.rmwr-tpl');
        if (tpl) {
            content.appendChild(tpl.content.cloneNode(true));
            tpl.remove();
        }
    }

    function rememberState(key, expanded) {
        if (!settings.remember) {
            return;
        }
        var map;
        try { map = JSON.parse(storeGet('rmwr_state') || '{}'); } catch (e) { map = {}; }
        if (expanded) {
            map[key] = 1;
        } else {
            delete map[key];
        }
        storeSet('rmwr_state', JSON.stringify(map));
    }

    function toggle(wrapper, opts) {
        opts = opts || {};
        var parts = getParts(wrapper);
        if (!parts.link || !parts.content) {
            return;
        }

        var expanded = isExpanded(parts.content);
        var openText = parts.link.dataset.openText || 'Read More';
        var closeText = parts.link.dataset.closeText || 'Read Less';

        if (!expanded) {
            // Locker interception: show the unlock UI instead of the content.
            if (wrapper.dataset.lock && !isUnlocked(parts.key)) {
                showLocker(wrapper, parts);
                return;
            }

            // Accordion: collapse the other items of the group first.
            if (wrapper.dataset.mode === 'accordion' && wrapper.dataset.accordionId) {
                document.querySelectorAll('[data-accordion-id="' + wrapper.dataset.accordionId + '"]').forEach(function (other) {
                    if (other !== wrapper) {
                        var op = getParts(other);
                        if (op.link && op.content && isExpanded(op.content)) {
                            collapseInstance(other, op);
                        }
                    }
                });
            }

            materializeLazy(parts.content);
            expandEl(parts.content, wrapper, opts.instant);
            setButtonText(parts.link, closeText);
            parts.link.setAttribute('aria-expanded', 'true');
            parts.content.setAttribute('aria-hidden', 'false');

            hideEllipsis(parts.id, true);

            if (!opts.instant && wrapper.dataset.smoothScroll !== 'false') {
                smoothScrollTo(parts.content, parseInt(wrapper.dataset.scrollOffset || '0', 10));
            }

            if (!opts.silent) {
                var variant = parts.link.dataset.ab === '1' ? abVariant : '';
                track(parts.key, parts.postId, 'expand', variant);
            }

            maybeShowCta(wrapper, parts);
            rememberState(parts.key, true);
        } else {
            collapseInstance(wrapper, parts, opts);
            rememberState(parts.key, false);
        }
    }

    function collapseInstance(wrapper, parts, opts) {
        opts = opts || {};
        var openText = parts.link.dataset.openText || 'Read More';
        collapseEl(parts.content, wrapper, opts.instant);
        setButtonText(parts.link, openText);
        parts.link.setAttribute('aria-expanded', 'false');
        parts.content.setAttribute('aria-hidden', 'true');
        hideEllipsis(parts.id, false);
    }

    function hideEllipsis(id, hidden) {
        var el = document.querySelector('.rmwr-ellipsis[data-for="' + id + '"]');
        if (el) {
            el.style.display = hidden ? 'none' : '';
        }
    }

    function smoothScrollTo(element, offset) {
        if (!element) { return; }
        var top = element.getBoundingClientRect().top + window.pageYOffset - (offset || 0);
        window.scrollTo({ top: top, behavior: 'smooth' });
    }

    /* ---------------------------------------------------------------------
     * Content Locker
     * ------------------------------------------------------------------ */

    function isUnlocked(key) {
        return storeGet('rmwr_unlock_' + key) === '1';
    }

    function markUnlocked(key) {
        storeSet('rmwr_unlock_' + key, '1');
    }

    function showLocker(wrapper, parts) {
        var existing = wrapper.querySelector('.rmwr-lock-box');
        if (existing) {
            existing.style.display = '';
            return;
        }

        var tpl = wrapper.querySelector('template.rmwr-lock-tpl');
        if (!tpl) {
            return;
        }

        wrapper.appendChild(tpl.content.cloneNode(true));
        var box = wrapper.querySelector('.rmwr-lock-box');
        if (!box) {
            return;
        }

        var form = box.querySelector('.rmwr-lock-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitLockerForm(form, wrapper, parts, box);
            });
        }

        box.querySelectorAll('.rmwr-share-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openShareWindow(btn.dataset.network);
                markUnlocked(parts.key);
                track(parts.key, parts.postId, 'share');
                box.remove();
                toggle(wrapper, { silent: true });
            });
        });
    }

    function submitLockerForm(form, wrapper, parts, box) {
        var locker = settings.locker || {};
        var emailInput = form.querySelector('.rmwr-lock-email');
        var errorEl = form.querySelector('.rmwr-lock-error');
        var submitBtn = form.querySelector('.rmwr-lock-submit');
        var consentInput = form.querySelector('input[name="rmwr_consent"]');
        var email = emailInput ? emailInput.value.trim() : '';

        function fail(message) {
            if (errorEl) {
                errorEl.textContent = message;
                errorEl.hidden = false;
            }
            if (submitBtn) { submitBtn.disabled = false; }
        }

        if (!email || email.indexOf('@') < 1) {
            fail(locker.invalidEmail || 'Please enter a valid email address.');
            return;
        }

        if (submitBtn) { submitBtn.disabled = true; }
        if (errorEl) { errorEl.hidden = true; }

        window.fetch(settings.restUrl.replace(/\/$/, '') + '/unlock', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: email,
                k: parts.key,
                p: parseInt(parts.postId, 10) || 0,
                consent: !!(consentInput && consentInput.checked)
            })
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        }).then(function (result) {
            if (result.ok && result.data && result.data.ok) {
                markUnlocked(parts.key);
                track(parts.key, parts.postId, 'unlock');
                box.remove();
                toggle(wrapper, { silent: true });
            } else {
                fail((settings.locker || {}).genericError || 'Something went wrong. Please try again.');
            }
        }).catch(function () {
            fail((settings.locker || {}).genericError || 'Something went wrong. Please try again.');
        });
    }

    function openShareWindow(network) {
        var url = encodeURIComponent(window.location.href);
        var title = encodeURIComponent(document.title);
        var shareUrl = '';

        if (network === 'facebook') {
            shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + url;
        } else if (network === 'x') {
            shareUrl = 'https://twitter.com/intent/tweet?url=' + url + '&text=' + title;
        } else if (network === 'linkedin') {
            shareUrl = 'https://www.linkedin.com/sharing/share-offsite/?url=' + url;
        }

        if (shareUrl) {
            window.open(shareUrl, 'rmwr-share', 'width=600,height=500,noopener');
        }
    }

    /* ---------------------------------------------------------------------
     * Engaged-user CTA
     * ------------------------------------------------------------------ */

    function maybeShowCta(wrapper, parts) {
        var cta = settings.cta || {};
        if (wrapper.dataset.cta !== '1' || !cta.html || wrapper.dataset.ctaShown === '1') {
            return;
        }
        wrapper.dataset.ctaShown = '1';

        window.setTimeout(function () {
            var box = document.createElement('div');
            box.className = 'rmwr-cta-box';
            box.innerHTML = cta.html;

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'rmwr-cta-close';
            close.setAttribute('aria-label', 'Close');
            close.innerHTML = '&times;';
            close.addEventListener('click', function () { box.remove(); });
            box.appendChild(close);

            box.addEventListener('click', function (e) {
                if (e.target.closest('a, button:not(.rmwr-cta-close)')) {
                    track(parts.key, parts.postId, 'cta');
                }
            });

            parts.content.insertAdjacentElement('afterend', box);
        }, (parseInt(cta.delay, 10) || 0) * 1000);
    }

    /* ---------------------------------------------------------------------
     * Sections (Wikipedia mode)
     * ------------------------------------------------------------------ */

    function toggleSection(btn, forceOpen) {
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (!panel) {
            return;
        }
        var open = btn.getAttribute('aria-expanded') === 'true';
        if (forceOpen && open) {
            return;
        }
        var next = forceOpen ? true : !open;
        btn.setAttribute('aria-expanded', next ? 'true' : 'false');
        panel.hidden = !next;
    }

    /* ---------------------------------------------------------------------
     * Expand/Collapse all
     * ------------------------------------------------------------------ */

    function toggleAll(button) {
        var group = button.dataset.group || '';
        var wrappers = group
            ? document.querySelectorAll('.rmwr-wrapper[data-accordion-id="' + group + '"]')
            : document.querySelectorAll('.rmwr-wrapper');

        var anyCollapsed = false;
        wrappers.forEach(function (wrapper) {
            var parts = getParts(wrapper);
            if (parts.content && !isExpanded(parts.content)) {
                anyCollapsed = true;
            }
        });

        wrappers.forEach(function (wrapper) {
            var parts = getParts(wrapper);
            if (!parts.content || !parts.link) {
                return;
            }
            if (anyCollapsed && !isExpanded(parts.content)) {
                toggle(wrapper, { silent: true, instant: true });
            } else if (!anyCollapsed && isExpanded(parts.content)) {
                toggle(wrapper, { silent: true, instant: true });
            }
        });

        // Sections too, when no specific group is set.
        if (!group) {
            document.querySelectorAll('.rmwr-sec-btn').forEach(function (btn) {
                toggleSection(btn, anyCollapsed);
            });
        }

        button.textContent = anyCollapsed
            ? (button.dataset.closeText || 'Collapse all')
            : (button.dataset.openText || 'Expand all');
    }

    /* ---------------------------------------------------------------------
     * Conditional display
     * ------------------------------------------------------------------ */

    function applyConditionalDisplay(wrapper) {
        var device = wrapper.dataset.device || 'all';
        if (device !== 'all') {
            var isMobile = window.innerWidth <= 768;
            if ((device === 'mobile' && !isMobile) || (device === 'desktop' && isMobile)) {
                wrapper.style.display = 'none';
                return;
            }
        }

        var showAfter = wrapper.dataset.showAfter;
        if (showAfter) {
            var seconds = parseInt(String(showAfter).replace('seconds', ''), 10) || 0;
            if (seconds > 0) {
                wrapper.style.visibility = 'hidden';
                wrapper.style.opacity = '0';
                window.setTimeout(function () {
                    wrapper.style.transition = 'opacity 0.5s ease';
                    wrapper.style.visibility = '';
                    wrapper.style.opacity = '1';
                }, seconds * 1000);
            }
        }

        if (wrapper.dataset.scroll === 'true') {
            wrapper.style.opacity = '0';
            wrapper.style.transition = 'opacity 0.5s ease';
            var reveal = function () {
                var rect = wrapper.getBoundingClientRect();
                var viewport = window.innerHeight || document.documentElement.clientHeight;
                if (rect.top < viewport - 100) {
                    wrapper.style.opacity = '1';
                    window.removeEventListener('scroll', reveal);
                }
            };
            window.addEventListener('scroll', reveal, { passive: true });
            reveal();
        }
    }

    /* ---------------------------------------------------------------------
     * Deep linking
     * ------------------------------------------------------------------ */

    function handleHash() {
        if (!settings.deepLink || !window.location.hash) {
            return;
        }

        var hash = window.location.hash.slice(1);
        if (!hash) {
            return;
        }

        var target = null;
        try { target = document.getElementById(hash); } catch (e) { return; }
        if (!target) {
            return;
        }

        // Section heading anchor (#rmwr-sec-slug).
        if (target.classList.contains('rmwr-sec-title')) {
            var btn = target.querySelector('.rmwr-sec-btn');
            if (btn) {
                toggleSection(btn, true);
                window.setTimeout(function () { smoothScrollTo(target, 60); }, 50);
            }
            return;
        }

        // Wrapper / content id (#rmwr-p12-1 or #readrmwr-p12-1).
        var wrapper = target.closest ? target.closest('.rmwr-wrapper') : null;
        if (!wrapper) {
            wrapper = document.querySelector('.rmwr-wrapper[data-id="' + hash + '"]');
        }
        if (wrapper) {
            var parts = getParts(wrapper);
            if (parts.content && !isExpanded(parts.content)) {
                toggle(wrapper, { silent: true });
            }
            window.setTimeout(function () { smoothScrollTo(wrapper, 60); }, 50);
            return;
        }

        // Element hidden inside a collapsed area or section panel.
        var container = target.closest ? (target.closest('.read_div') || target.closest('.rmwr-sec-panel')) : null;
        if (container) {
            if (container.classList.contains('read_div')) {
                var w = container.closest('.rmwr-wrapper');
                if (w) { toggle(w, { silent: true }); }
            } else {
                var sbtn = document.querySelector('.rmwr-sec-btn[aria-controls="' + container.id + '"]');
                if (sbtn) { toggleSection(sbtn, true); }
            }
            window.setTimeout(function () { smoothScrollTo(target, 60); }, 50);
        }
    }

    /* ---------------------------------------------------------------------
     * Init
     * ------------------------------------------------------------------ */

    function restoreRememberedState() {
        if (!settings.remember) {
            return;
        }
        var map;
        try { map = JSON.parse(storeGet('rmwr_state') || '{}'); } catch (e) { map = {}; }

        document.querySelectorAll('.rmwr-wrapper').forEach(function (wrapper) {
            var parts = getParts(wrapper);
            if (parts.key && map[parts.key] && parts.content && !isExpanded(parts.content) && !wrapper.dataset.lock) {
                toggle(wrapper, { silent: true, instant: true });
            }
        });
    }

    function init() {
        document.querySelectorAll('.rmwr-wrapper').forEach(applyConditionalDisplay);
        initAB();
        restoreRememberedState();
        handleHash();

        debug('initialized', document.querySelectorAll('.rmwr-wrapper').length, 'instances');

        document.addEventListener('click', function (e) {
            var secBtn = e.target.closest('.rmwr-sec-btn');
            if (secBtn) {
                e.preventDefault();
                toggleSection(secBtn);
                return;
            }

            var allBtn = e.target.closest('.rmwr-toggle-all');
            if (allBtn) {
                e.preventDefault();
                toggleAll(allBtn);
                return;
            }

            var tocLink = e.target.closest('.rmwr-toc a');
            if (tocLink) {
                // Let the browser set the hash; expansion follows on hashchange.
                window.setTimeout(handleHash, 30);
                return;
            }

            var link = e.target.closest('.read-link');
            if (link && link.id && link.id.indexOf('readlink') === 0) {
                e.preventDefault();
                var wrapper = link.closest('.rmwr-wrapper');
                if (wrapper) {
                    toggle(wrapper);
                }
            }
        });

        window.addEventListener('hashchange', handleHash);

        if (settings.printExpand !== false) {
            window.addEventListener('beforeprint', function () {
                document.querySelectorAll('.read_div').forEach(function (content) {
                    materializeLazy(content);
                    content.style.display = 'block';
                    content.setAttribute('aria-hidden', 'false');
                });
                document.querySelectorAll('.rmwr-sec-panel').forEach(function (panel) {
                    panel.hidden = false;
                });
            });
        }

        // Support content injected after load (AJAX pagination, quick view...).
        if (typeof MutationObserver !== 'undefined' && document.body) {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                        if (node.nodeType !== 1) {
                            return;
                        }
                        var wrappers = node.classList && node.classList.contains('rmwr-wrapper')
                            ? [node]
                            : (node.querySelectorAll ? node.querySelectorAll('.rmwr-wrapper') : []);
                        Array.prototype.forEach.call(wrappers, applyConditionalDisplay);
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    // Public API for theme developers.
    window.RMWR = {
        toggle: function (idOrKey) {
            var wrapper = document.querySelector('.rmwr-wrapper[data-id="' + idOrKey + '"]')
                || document.querySelector('.rmwr-wrapper[data-key="' + idOrKey + '"]');
            if (wrapper) {
                toggle(wrapper);
            }
        },
        expandAll: function () {
            document.querySelectorAll('.rmwr-wrapper').forEach(function (wrapper) {
                var parts = getParts(wrapper);
                if (parts.content && !isExpanded(parts.content)) {
                    toggle(wrapper, { silent: true, instant: true });
                }
            });
        },
        track: track
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
