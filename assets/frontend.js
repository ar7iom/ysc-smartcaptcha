(function () {
    'use strict';
    if (window.yscCaptchaInitialized) return;
    window.yscCaptchaInitialized = true;
    var config = window.yscCaptchaConfig;
    if (!config || !config.selectors.length) return;
    var states = new WeakMap();
    var pending = null;
    var widgetId = null;
    var rendering = false;
    var ready = false;
    var timer = null;
    var scanTimer = null;
    var selector = config.selectors.join(',');

    function message(form, text) {
        var box = form.querySelector('.ysc-captcha-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'ysc-captcha-error';
            box.setAttribute('role', 'alert');
            form.appendChild(box);
        }
        box.textContent = text || '';
        box.hidden = !text;
    }
    function clearToken(form) {
        var state = states.get(form);
        if (state) {
            state.input.value = '';
            state.verified = false;
        }
    }
    function resetWidget() {
        if (ready && window.smartCaptcha) {
            try { window.smartCaptcha.reset(widgetId); } catch (error) { /* next execute reports failure */ }
        }
    }
    function fail() {
        clearTimeout(timer);
        var job = pending;
        pending = null;
        if (job) {
            clearToken(job.form);
            message(job.form, config.message);
        }
        resetWidget();
    }
    function onToken(token) {
        if (!pending || !token) return;
        clearTimeout(timer);
        var job = pending;
        pending = null;
        if (!job.form.isConnected) return;
        var state = states.get(job.form);
        state.input.value = token;
        state.verified = true;
        try {
            // Native submit event reaches CF7, Impreza and WooCommerce handlers.
            if (typeof job.form.requestSubmit === 'function') {
                if (job.submitter && job.submitter.form === job.form && !job.submitter.disabled) {
                    job.form.requestSubmit(job.submitter);
                } else {
                    job.form.requestSubmit();
                }
            } else {
                var event = document.createEvent('Event');
                event.initEvent('submit', true, true);
                if (job.form.dispatchEvent(event)) HTMLFormElement.prototype.submit.call(job.form);
            }
        } catch (error) {
            message(job.form, config.message);
        } finally {
            state.verified = false;
            // Keep the token until submit/AJAX listeners finish serializing the form.
            setTimeout(function () { clearToken(job.form); }, 0);
        }
    }
    function renderWidget() {
        if (ready || rendering || !document.body || !window.smartCaptcha) return;
        rendering = true;
        var host = document.getElementById('ysc-captcha-host');
        if (!host) {
            host = document.createElement('div');
            host.id = 'ysc-captcha-host';
            document.body.appendChild(host);
        }
        try {
            widgetId = window.smartCaptcha.render(host, {
                sitekey: config.sitekey,
                invisible: true,
                hideShield: true,
                hl: config.language,
                callback: onToken
            });
            ready = typeof widgetId === 'number';
            if (!ready) throw new Error('Invalid widget identifier');
            if (typeof window.smartCaptcha.subscribe === 'function') {
                window.smartCaptcha.subscribe(widgetId, 'network-error', fail);
                window.smartCaptcha.subscribe(widgetId, 'javascript-error', fail);
                window.smartCaptcha.subscribe(widgetId, 'token-expired', function () {
                    if (pending) fail();
                });
                window.smartCaptcha.subscribe(widgetId, 'challenge-hidden', function () {
                    // A success callback can follow challenge-hidden in the same turn.
                    setTimeout(function () { if (pending) fail(); }, 0);
                });
            }
        } catch (error) {
            ready = false;
            widgetId = null;
            fail();
        } finally {
            rendering = false;
        }
    }
    function execute() {
        renderWidget();
        if (!pending || !ready) return;
        // Reset before obtaining a new token, never during submit serialization.
        resetWidget();
        if (!pending) return;
        try { window.smartCaptcha.execute(widgetId); } catch (error) { fail(); }
    }
    function onSubmit(event) {
        var form = event.currentTarget;
        var state = states.get(form);
        if (state.verified) {
            state.verified = false;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending) {
            if (pending.form !== form) message(form, config.busyMessage);
            return;
        }
        message(form, '');
        clearToken(form);
        pending = { form: form, submitter: event.submitter || null };
        // Network failures must not leave forms waiting indefinitely.
        timer = setTimeout(fail, 120000);
        execute();
    }
    function initForm(form) {
        if (states.has(form) || !form.matches(selector)) return;
        if (form.closest('#ysc-captcha-host, .smart-captcha, #wpadminbar')) return;
        if (form.getAttribute('role') === 'search' || form.method.toLowerCase() === 'get') return;
        var input = form.querySelector('input[name="smart-token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'smart-token';
            form.appendChild(input);
        }
        input.classList.add('ysc-token-input');
        states.set(form, { input: input, verified: false });
        form.classList.add('ysc-protected');
        form.addEventListener('submit', onSubmit, true);
    }
    function scan() {
        document.querySelectorAll(selector).forEach(initForm);
        if (document.querySelector(selector)) renderWidget();
    }
    function scheduleScan() {
        clearTimeout(scanTimer);
        scanTimer = setTimeout(scan, 150);
    }
    window.yscSmartCaptchaOnload = function () {
        scan();
        if (pending) execute();
    };
    function init() {
        scan();
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    for (var j = 0; j < mutations[i].addedNodes.length; j++) {
                        var node = mutations[i].addedNodes[j];
                        if (node.nodeType === 1 && !node.closest('#ysc-captcha-host') &&
                            (node.matches(selector) || node.querySelector(selector))) {
                            scheduleScan();
                            return;
                        }
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    ['us_init', 'us_grid_loaded', 'us_popup_open', 'wpcf7submit'].forEach(function (name) {
        document.addEventListener(name, scheduleScan);
    });
})();
