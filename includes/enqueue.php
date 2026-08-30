<?php
defined('ABSPATH') || exit;

// ============================================================
// 2. ПОДКЛЮЧЕНИЕ СКРИПТА
// ============================================================

add_action('wp_enqueue_scripts',    'ysc_enqueue_captcha_script');
add_action('login_enqueue_scripts', 'ysc_enqueue_captcha_script');

function ysc_enqueue_captcha_script() {
    if (ysc_is_limit_reached()) return;
    if (!YSC_CLIENT_KEY)        return;

    wp_enqueue_script(
        'yandex-smartcaptcha',
        'https://smartcaptcha.yandexcloud.net/captcha.js',
        array(),
        null,
        true
    );

    wp_add_inline_script('yandex-smartcaptcha', ysc_get_inline_js());
}

// ============================================================
// 3. ИНЛАЙН JS
// ============================================================

function ysc_get_inline_js() {
    $client_key = esc_js(YSC_CLIENT_KEY);

    return <<<JS
(function() {
    'use strict';

    var YSC_CLIENT_KEY  = '{$client_key}';
    var captchaIndex    = 0;
    var pendingForms    = [];
    var widgetMap       = new WeakMap();
    var scanTimeout     = null;
    var isScanning      = false;

    var FORM_SELECTORS = [
        // Contact Form 7
        'form.wpcf7-form',
        '.wpcf7 form',

        // Impreza / UpSolution
        'form.w-form',
        'form.us-form',
        'form[class*="us-form"]',
        'form[class*="w-form"]',
        '.w-form form',
        '.us-form form',
        '.wpb_content_element form',
        '.vc_column-inner form',
        '.us-grid form',
        '.reusable-block form',
        '[data-us-block] form',
        '.us-post-custom form',

        // WooCommerce
        'form.woocommerce-checkout',
        'form.woocommerce-form-login',
        'form.woocommerce-form-register',

        // WordPress login
        '#loginform'
    ];

    function markForms() {
        FORM_SELECTORS.forEach(function(selector) {
            try {
                document.querySelectorAll(selector).forEach(function(form) {
                    if (!form.classList.contains('ysc-protected')) {
                        form.classList.add('ysc-protected');
                    }
                });
            } catch(e) {}
        });
    }

    function renderWidget(form, containerId) {
        if (typeof smartCaptcha === 'undefined') {
            pendingForms.push({ form: form, containerId: containerId });
            return;
        }

        var container = document.getElementById(containerId);
        if (!container) return;

        if (widgetMap.has(form)) return;
        if (container.hasAttribute('data-captcha-rendered')) return;

        if (container.classList.contains('smart-captcha_invisible') ||
            container.classList.contains('smart-captcha') ||
            container.hasAttribute('data-testid') ||
            container.querySelector('[data-testid*="smartCaptcha"]') ||
            container.children.length > 0) {
            container.setAttribute('data-captcha-rendered', 'true');
            widgetMap.set(form, 'already-rendered');
            return;
        }

        container.innerHTML = '';
        container.setAttribute('data-captcha-rendered', 'true');
        widgetMap.set(form, 'rendering');

        try {
            var widgetId = smartCaptcha.render(container, {
                sitekey:    YSC_CLIENT_KEY,
                invisible:  true,
                hideShield: true,
                robustness: 'auto',
                callback:   function(token) {
                    var tokenInput = form.querySelector('.ysc-token-input');
                    if (tokenInput) tokenInput.value = token;
                    form.dataset.yscVerified = 'true';

                    if (form.classList.contains('wpcf7-form') && typeof jQuery !== 'undefined') {
                        jQuery(form).trigger('submit');
                    } else if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            });

            widgetMap.set(form, widgetId);

            form.addEventListener('submit', function(e) {
                if (form.dataset.yscVerified !== 'true') {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    smartCaptcha.execute(widgetId);
                } else {
                    form.dataset.yscVerified = 'false';
                    smartCaptcha.reset(widgetId);
                }
            }, true);

        } catch(err) {
            container.removeAttribute('data-captcha-rendered');
            widgetMap.delete(form);
        }
    }

    function initCaptchaOnForm(form) {
        if (form.dataset.yscInit === 'true') return;
        form.dataset.yscInit = 'true';

        captchaIndex++;
        var containerId = 'ysc-container-' + captchaIndex;

        var container    = document.createElement('div');
        container.id     = containerId;
        container.style.display = 'none';
        form.appendChild(container);

        var tokenInput       = document.createElement('input');
        tokenInput.type      = 'hidden';
        tokenInput.name      = 'smart-token';
        tokenInput.classList.add('ysc-token-input');
        form.appendChild(tokenInput);

        renderWidget(form, containerId);
    }

    function initAllProtectedForms() {
        document.querySelectorAll('form.ysc-protected').forEach(function(form) {
            initCaptchaOnForm(form);
        });
    }

    function scanAndInit() {
        if (isScanning) return;
        isScanning = true;
        try {
            markForms();
            initAllProtectedForms();
        } finally {
            isScanning = false;
        }
    }

    function scheduleScan() {
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(scanAndInit, 500);
    }

    window.smartCaptchaReadyCallback = function() {
        var pending = pendingForms.slice();
        pendingForms = [];
        pending.forEach(function(item) {
            renderWidget(item.form, item.containerId);
        });
        scanAndInit();
    };

    var observer = new MutationObserver(function(mutations) {
        var needScan = false;
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                if (node.tagName === 'FORM' || node.querySelector('form')) {
                    needScan = true;
                }
            });
        });
        if (needScan) scheduleScan();
    });

    observer.observe(document.body, { childList: true, subtree: true });

    function hookJQueryAjax() {
        if (typeof jQuery === 'undefined') return;
        jQuery(document).ajaxComplete(function() {
            scheduleScan();
        });
    }

    function hookFetch() {
        if (typeof window.fetch !== 'function') return;
        var originalFetch = window.fetch;
        window.fetch = function() {
            return originalFetch.apply(this, arguments).then(function(response) {
                scheduleScan();
                return response;
            });
        };
    }

    function startFallbackInterval() {
        var count    = 0;
        var interval = setInterval(function() {
            scanAndInit();
            if (++count >= 8) clearInterval(interval);
        }, 1000);
    }

    // CF7 события
    document.addEventListener('wpcf7mailsent',   scanAndInit);
    document.addEventListener('wpcf7invalid',    scanAndInit);
    document.addEventListener('wpcf7spam',       scanAndInit);
    document.addEventListener('wpcf7mailfailed', scanAndInit);
    document.addEventListener('wpcf7submit',     scanAndInit);

    // Impreza события
    document.addEventListener('us_init',        scanAndInit);
    document.addEventListener('us_grid_loaded', scanAndInit);
    document.addEventListener('us_popup_open',  scanAndInit);

    function init() {
        scanAndInit();
        hookJQueryAjax();
        hookFetch();
        startFallbackInterval();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('load', function() {
        setTimeout(scanAndInit, 1000);
    });

})();
JS;
}
