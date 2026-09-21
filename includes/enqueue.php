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
    if (ysc_get_form_selectors() === array()) return;

    wp_enqueue_style(
        'ysc-smartcaptcha',
        YSC_PLUGIN_URL . 'assets/frontend.css',
        array(),
        YSC_VERSION
    );

    // render=onload отключает автопоиск .smart-captcha и вызывает наш callback.
    // Это исключает двойной render() и связанные React hydration #418/#423.
    $captcha_src = 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=yscSmartCaptchaOnload';

    wp_enqueue_script(
        'yandex-smartcaptcha',
        $captcha_src,
        array(),
        null,
        true
    );

    // Callback onload должен существовать до загрузки captcha.js.
    wp_add_inline_script('yandex-smartcaptcha', ysc_get_inline_js(), 'before');
}

/**
 * Язык виджета по локали WordPress.
 */
function ysc_get_widget_language() {
    $locale  = function_exists('determine_locale') ? determine_locale() : get_locale();
    $lang    = strtolower(substr((string) $locale, 0, 2));
    $allowed = array('ru', 'en', 'be', 'kk', 'tt', 'uk', 'uz', 'tr');
    return in_array($lang, $allowed, true) ? $lang : 'ru';
}

/**
 * Селекторы только для включённых типов форм.
 * Без широких подстрочных селекторов вроде [class*="w-form"] —
 * они цепляют форму поиска Impreza (class="w-form-row").
 */
function ysc_get_form_selectors() {
    $forms     = get_option('ysc_forms_enabled', array());
    $selectors = array();

    if (!empty($forms['cf7'])) {
        $selectors[] = 'form.wpcf7-form';
        $selectors[] = '.wpcf7 form';
    }

    if (!empty($forms['impreza'])) {
        $selectors[] = 'form.w-form';
        $selectors[] = 'form.us-form';
        $selectors[] = 'form.for_cform';
    }

    if (!empty($forms['woo'])) {
        $selectors[] = 'form.woocommerce-checkout';
        $selectors[] = 'form.woocommerce-form-login';
        $selectors[] = 'form.woocommerce-form-register';
    }

    if (!empty($forms['wp_login'])) {
        $selectors[] = '#loginform';
    }

    return $selectors;
}

// ============================================================
// 3. ИНЛАЙН JS
// ============================================================

function ysc_get_inline_js() {
    $client_key    = esc_js(YSC_CLIENT_KEY);
    $hl            = esc_js(ysc_get_widget_language());
    $selectors_js  = wp_json_encode(ysc_get_form_selectors());

    if (!is_string($selectors_js) || $selectors_js === '') {
        $selectors_js = '[]';
    }

    return <<<JS
(function() {
    'use strict';

    var YSC_CLIENT_KEY = '{$client_key}';
    var YSC_HL         = '{$hl}';
    var FORM_SELECTORS = {$selectors_js};

    var widgetId       = null;
    var widgetReady    = false;
    var isRendering    = false;
    var pendingExecute = null;
    var hostEl         = null;
    var scanTimeout    = null;
    var isScanning     = false;

    function closest(el, selector) {
        if (!el) return null;
        if (el.closest) return el.closest(selector);
        while (el && el.nodeType === 1) {
            if (el.matches && el.matches(selector)) return el;
            el = el.parentElement;
        }
        return null;
    }

    function isSkippableForm(form) {
        if (!form || form.tagName !== 'FORM') return true;
        if (closest(form, '#ysc-captcha-host, .smart-captcha, .SmartCaptcha-Overlay, #wpadminbar')) {
            return true;
        }
        if (form.getAttribute('role') === 'search') return true;
        if (closest(form, '.w-search, .search-form, form.search-form')) return true;
        if (form.classList.contains('w-form-row')) return true;

        var method = (form.getAttribute('method') || '').toLowerCase();
        if (method === 'get') return true;

        return false;
    }

    function matchesEnabledType(form) {
        for (var i = 0; i < FORM_SELECTORS.length; i++) {
            try {
                if (form.matches(FORM_SELECTORS[i])) return true;
            } catch (e) {}
        }
        return false;
    }

    function shouldProtectForm(form) {
        if (isSkippableForm(form)) return false;
        if (form.classList.contains('ysc-protected')) return true;
        return matchesEnabledType(form);
    }

    function getHost() {
        if (hostEl && hostEl.isConnected) return hostEl;
        hostEl = document.getElementById('ysc-captcha-host');
        if (!hostEl) {
            hostEl = document.createElement('div');
            hostEl.id = 'ysc-captcha-host';
            (document.body || document.documentElement).appendChild(hostEl);
        }
        return hostEl;
    }

    function submitForm(form) {
        if (form.classList.contains('wpcf7-form') && typeof jQuery !== 'undefined') {
            jQuery(form).trigger('submit');
            return;
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }
        form.submit();
    }

    function onToken(token) {
        var form = pendingExecute;
        pendingExecute = null;
        if (!form) return;

        var tokenInput = form.querySelector('.ysc-token-input');
        if (tokenInput) tokenInput.value = token;
        form.dataset.yscVerified = 'true';
        submitForm(form);
    }

    function renderSharedWidget() {
        if (widgetReady || isRendering) return;
        if (typeof smartCaptcha === 'undefined') return;
        if (!document.body) return;

        var host = getHost();
        if (host.getAttribute('data-captcha-rendered') === 'true') {
            widgetReady = true;
            return;
        }

        isRendering = true;
        host.setAttribute('data-captcha-rendered', 'true');

        try {
            widgetId = smartCaptcha.render(host, {
                sitekey:    YSC_CLIENT_KEY,
                invisible:  true,
                hideShield: true,
                robustness: 'auto',
                hl:         YSC_HL,
                callback:   onToken
            });
            widgetReady = true;
        } catch (err) {
            host.removeAttribute('data-captcha-rendered');
            widgetId = null;
            widgetReady = false;
        } finally {
            isRendering = false;
        }
    }

    function executeForForm(form) {
        pendingExecute = form;
        if (!widgetReady || widgetId === null) {
            renderSharedWidget();
        }
        if (typeof smartCaptcha === 'undefined' || !widgetReady || widgetId === null) {
            return;
        }
        smartCaptcha.execute(widgetId);
    }

    function onFormSubmit(e) {
        var form = e.currentTarget;
        if (form.dataset.yscVerified === 'true') {
            form.dataset.yscVerified = 'false';
            if (widgetReady && widgetId !== null && typeof smartCaptcha !== 'undefined') {
                try { smartCaptcha.reset(widgetId); } catch (err) {}
            }
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        executeForForm(form);
    }

    function initCaptchaOnForm(form) {
        if (!shouldProtectForm(form)) return;
        if (form.dataset.yscInit === 'true') return;
        form.dataset.yscInit = 'true';
        form.classList.add('ysc-protected');

        if (!form.querySelector('.ysc-token-input')) {
            var tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'smart-token';
            tokenInput.className = 'ysc-token-input';
            form.appendChild(tokenInput);
        }

        form.addEventListener('submit', onFormSubmit, true);
        renderSharedWidget();
    }

    function scanAndInit() {
        if (isScanning) return;
        isScanning = true;
        try {
            for (var i = 0; i < FORM_SELECTORS.length; i++) {
                try {
                    document.querySelectorAll(FORM_SELECTORS[i]).forEach(initCaptchaOnForm);
                } catch (e) {}
            }
            document.querySelectorAll('form.ysc-protected').forEach(initCaptchaOnForm);
        } finally {
            isScanning = false;
        }
    }

    function scheduleScan() {
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(scanAndInit, 400);
    }

    function nodeContainsForm(node) {
        if (!node || node.nodeType !== 1) return false;
        if (closest(node, '#ysc-captcha-host, .smart-captcha, .SmartCaptcha-Overlay')) {
            return false;
        }
        if (node.tagName === 'FORM') return shouldProtectForm(node);
        if (!node.querySelector) return false;
        var forms = node.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            if (shouldProtectForm(forms[i])) return true;
        }
        return false;
    }

    window.yscSmartCaptchaOnload = function() {
        renderSharedWidget();
        scanAndInit();
        if (pendingExecute) executeForForm(pendingExecute);
    };

    function startObserver() {
        if (!document.body || typeof MutationObserver === 'undefined') return;
        var observer = new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    if (nodeContainsForm(added[j])) {
                        scheduleScan();
                        return;
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function hookJQueryAjax() {
        if (typeof jQuery === 'undefined') return;
        jQuery(document).ajaxComplete(function() {
            scheduleScan();
        });
    }

    function init() {
        scanAndInit();
        startObserver();
        hookJQueryAjax();
        if (typeof smartCaptcha !== 'undefined') {
            renderSharedWidget();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('wpcf7mailsent',   scanAndInit);
    document.addEventListener('wpcf7invalid',    scanAndInit);
    document.addEventListener('wpcf7spam',       scanAndInit);
    document.addEventListener('wpcf7mailfailed', scanAndInit);
    document.addEventListener('wpcf7submit',     scanAndInit);
    document.addEventListener('us_init',         scanAndInit);
    document.addEventListener('us_grid_loaded',  scanAndInit);
    document.addEventListener('us_popup_open',   scanAndInit);
})();
JS;
}
