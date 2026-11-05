'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'frontend.js'), 'utf8');
const tests = [];

function test(name, callback) {
    tests.push({ name, callback });
}

class ClassList {
    constructor(element) {
        this.element = element;
    }
    values() {
        return this.element.className.split(/\s+/).filter(Boolean);
    }
    add(name) {
        const values = this.values();
        if (!values.includes(name)) values.push(name);
        this.element.className = values.join(' ');
    }
    contains(name) {
        return this.values().includes(name);
    }
}

class Element {
    constructor(tagName, document) {
        this.tagName = tagName.toUpperCase();
        this.ownerDocument = document;
        this.children = [];
        this.parentNode = null;
        this.listeners = {};
        this.className = '';
        this.classList = new ClassList(this);
        this.id = '';
        this.name = '';
        this.type = '';
        this.value = '';
        this.method = 'get';
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.isConnected = false;
        this.form = null;
    }
    appendChild(child) {
        child.parentNode = this;
        child.setConnected(this.isConnected);
        this.children.push(child);
        return child;
    }
    setConnected(connected) {
        this.isConnected = connected;
        this.children.forEach(child => child.setConnected(connected));
    }
    setAttribute(name, value) {
        this[name] = String(value);
    }
    getAttribute(name) {
        return name === 'role' ? this.role || null : this[name] || null;
    }
    addEventListener(type, callback) {
        if (!this.listeners[type]) this.listeners[type] = [];
        this.listeners[type].push(callback);
    }
    dispatchEvent(event) {
        if (!event.target) event.target = this;
        event.currentTarget = this;
        const listeners = (this.listeners[event.type] || []).slice();
        for (const listener of listeners) {
            listener.call(this, event);
            if (event.immediatePropagationStopped) break;
        }
        return !event.defaultPrevented;
    }
    requestSubmit(submitter = null) {
        this.dispatchEvent(submitEvent(submitter));
    }
    matches(selectors) {
        return selectors.split(',').some(selector => this.matchesOne(selector.trim()));
    }
    matchesOne(selector) {
        if (selector.startsWith('#')) return this.id === selector.slice(1);
        if (selector === '.wpcf7 form') {
            return this.tagName === 'FORM' && Boolean(this.closest('.wpcf7'));
        }
        const formClass = selector.match(/^form\.([\w-]+)$/);
        if (formClass) return this.tagName === 'FORM' && this.classList.contains(formClass[1]);
        if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
        return this.tagName === selector.toUpperCase();
    }
    closest(selectors) {
        let current = this;
        while (current) {
            if (selectors.split(',').some(selector => current.matchesOne(selector.trim()))) return current;
            current = current.parentNode;
        }
        return null;
    }
    querySelectorAll(selector) {
        const found = [];
        for (const child of this.children) {
            if (child.matches(selector)) found.push(child);
            found.push(...child.querySelectorAll(selector));
        }
        return found;
    }
    querySelector(selector) {
        if (selector === '.ysc-captcha-error') {
            return this.children.find(child => child.classList.contains('ysc-captcha-error')) || null;
        }
        if (selector === 'input[name="smart-token"]') {
            return this.querySelectorAll('input').find(child => child.name === 'smart-token') || null;
        }
        return this.querySelectorAll(selector)[0] || null;
    }
}

class Document {
    constructor() {
        this.readyState = 'complete';
        this.body = new Element('body', this);
        this.body.setConnected(true);
        this.listeners = {};
    }
    createElement(tagName) {
        return new Element(tagName, this);
    }
    getElementById(id) {
        if (this.body.id === id) return this.body;
        return this.body.querySelectorAll('#' + id)[0] || null;
    }
    querySelectorAll(selector) {
        return this.body.querySelectorAll(selector);
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }
    addEventListener(type, callback) {
        if (!this.listeners[type]) this.listeners[type] = [];
        this.listeners[type].push(callback);
    }
}

function submitEvent(submitter) {
    return {
        type: 'submit',
        submitter,
        target: null,
        currentTarget: null,
        defaultPrevented: false,
        immediatePropagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopImmediatePropagation() { this.immediatePropagationStopped = true; }
    };
}

function createEnvironment(options = {}) {
    const document = new Document();
    let timerId = 0;
    let now = 0;
    const timers = new Map();
    const captcha = {
        renderCalls: [],
        resets: [],
        executions: [],
        handlers: {},
        render(host, params) {
            this.renderCalls.push({ host, params });
            this.params = params;
            return 0;
        },
        reset(widgetId) { this.resets.push(widgetId); },
        execute(widgetId) { this.executions.push(widgetId); },
        subscribe(widgetId, event, callback) { this.handlers[event] = callback; },
        emit(event) {
            if (this.handlers[event]) this.handlers[event]();
        }
    };
    const config = {
        sitekey: 'client-key',
        language: 'ru',
        selectors: ['form.wpcf7-form'],
        hideShield: false,
        message: 'Captcha error',
        busyMessage: 'Another form is pending'
    };
    const window = {
        document,
        yscCaptchaConfig: config,
        smartCaptcha: options.providerMissing ? undefined : captcha
    };
    window.window = window;

    function setTimeoutFake(callback, delay) {
        const id = ++timerId;
        timers.set(id, { callback, at: now + delay });
        return id;
    }
    function clearTimeoutFake(id) {
        timers.delete(id);
    }
    function runTimers() {
        while (timers.size) {
            const next = [...timers.entries()].sort((a, b) => a[1].at - b[1].at)[0];
            timers.delete(next[0]);
            now = next[1].at;
            next[1].callback();
        }
    }

    const context = {
        window,
        document,
        HTMLFormElement: Element,
        WeakMap,
        Error,
        setTimeout: setTimeoutFake,
        clearTimeout: clearTimeoutFake,
        console
    };
    vm.runInNewContext(source, context, { filename: 'frontend.js' });
    return { window, document, captcha, config, runTimers };
}

function addForm(env) {
    const form = env.document.createElement('form');
    form.className = 'wpcf7-form';
    form.method = 'post';
    env.document.body.appendChild(form);
    return form;
}

function tokenInput(form) {
    return form.querySelector('input[name="smart-token"]');
}

test('Виджет создаётся один раз, стандартное уведомление включено по умолчанию', () => {
    const env = createEnvironment();
    addForm(env);
    env.window.yscSmartCaptchaOnload();
    env.window.yscSmartCaptchaOnload();
    assert.equal(env.captcha.renderCalls.length, 1);
    assert.equal(env.captcha.renderCalls[0].params.invisible, true);
    assert.equal(env.captcha.renderCalls[0].params.hideShield, false);
});

test('Настройка разрешает скрыть стандартное уведомление', () => {
    const env = createEnvironment();
    env.config.hideShield = true;
    addForm(env);
    env.window.yscSmartCaptchaOnload();
    assert.equal(env.captcha.renderCalls[0].params.hideShield, true);
});

test('Успешная проверка сохраняет submitter и токен до завершения submit', () => {
    const env = createEnvironment();
    const form = addForm(env);
    env.window.yscSmartCaptchaOnload();
    const button = env.document.createElement('button');
    button.form = form;
    const submissions = [];
    form.addEventListener('submit', event => {
        submissions.push({
            token: tokenInput(form).value,
            submitter: event.submitter
        });
    });

    form.requestSubmit(button);
    assert.equal(env.captcha.executions.length, 1);
    assert.equal(submissions.length, 0);
    env.captcha.params.callback('valid-token');
    assert.equal(submissions.length, 1);
    assert.equal(submissions[0].token, 'valid-token');
    assert.equal(submissions[0].submitter, button);
    env.runTimers();
    assert.equal(tokenInput(form).value, '');
});

test('Повторный клик не запускает вторую проверку, другая форма получает busyMessage', () => {
    const env = createEnvironment();
    const first = addForm(env);
    const second = addForm(env);
    env.window.yscSmartCaptchaOnload();
    first.requestSubmit();
    first.requestSubmit();
    second.requestSubmit();
    assert.equal(env.captcha.executions.length, 1);
    assert.equal(second.querySelector('.ysc-captcha-error').textContent, env.config.busyMessage);
    env.captcha.params.callback('first-form-token');
    env.runTimers();
    assert.equal(tokenInput(first).value, '');
});

test('После network-error форма показывает ошибку и может повторить проверку', () => {
    const env = createEnvironment();
    const form = addForm(env);
    env.window.yscSmartCaptchaOnload();
    form.requestSubmit();
    env.captcha.emit('network-error');
    assert.equal(form.querySelector('.ysc-captcha-error').textContent, env.config.message);
    assert.equal(tokenInput(form).value, '');
    form.requestSubmit();
    assert.equal(env.captcha.executions.length, 2);
    env.captcha.params.callback('retry-token');
    env.runTimers();
    assert.equal(form.querySelector('.ysc-captcha-error').hidden, true);
});

test('javascript-error и token-expired не считаются успешной проверкой', () => {
    for (const eventName of ['javascript-error', 'token-expired']) {
        const env = createEnvironment();
        const form = addForm(env);
        env.window.yscSmartCaptchaOnload();
        let submitted = false;
        form.addEventListener('submit', () => { submitted = true; });
        form.requestSubmit();
        env.captcha.emit(eventName);
        assert.equal(submitted, false);
        assert.equal(form.querySelector('.ysc-captcha-error').textContent, env.config.message);
    }
});

test('challenge-hidden не отменяет успешный callback того же цикла', () => {
    const env = createEnvironment();
    const form = addForm(env);
    env.window.yscSmartCaptchaOnload();
    let submitted = false;
    form.addEventListener('submit', () => { submitted = true; });
    form.requestSubmit();
    env.captcha.emit('challenge-hidden');
    env.captcha.params.callback('same-turn-token');
    env.runTimers();
    assert.equal(submitted, true);
    assert.equal(form.querySelector('.ysc-captcha-error').hidden, true);
    assert.equal(form.querySelector('.ysc-captcha-error').textContent, '');
});

test('Недоступный провайдер завершает ожидание ошибкой без отправки формы', () => {
    const env = createEnvironment({ providerMissing: true });
    const form = addForm(env);
    env.window.yscSmartCaptchaOnload();
    let submitted = false;
    form.addEventListener('submit', () => { submitted = true; });
    form.requestSubmit();
    env.runTimers();
    assert.equal(submitted, false);
    assert.equal(form.querySelector('.ysc-captcha-error').textContent, env.config.message);
});

let failures = 0;
for (const { name, callback } of tests) {
    try {
        callback();
        console.log(`[OK] ${name}`);
    } catch (error) {
        failures++;
        console.error(`[FAIL] ${name}\n  ${error.stack}`);
    }
}
console.log(`\nТестов: ${tests.length}, ошибок: ${failures}`);
process.exitCode = failures ? 1 : 0;
