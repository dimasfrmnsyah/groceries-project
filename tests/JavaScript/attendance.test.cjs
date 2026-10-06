const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const source = fs.readFileSync('public/assets/js/attendance.js', 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
function setup() {
    const elements = {};
    for (const id of ['widget', 'modal', 'action', 'detail', 'hint', 'modal-title', 'modal-intro', 'message', 'confirm-out']) {
        const classes = new Set();
        elements[id] = {
            textContent: '', disabled: false, dataset: {}, handlers: {},
            addEventListener(type, handler) { this.handlers[type] = handler; },
            classList: {
                add: value => classes.add(value), remove: value => classes.delete(value),
                contains: value => classes.has(value),
                toggle(value, on) { if (on) classes.add(value); else classes.delete(value); },
            },
            querySelectorAll: () => [],
        };
    }
    elements.widget.dataset = {statusUrl: '/status', checkInUrl: '/in', checkOutUrl: '/out'};
    const calls = [];
    const document = {
        readyState: 'complete',
        getElementById: id => elements[id.replace('attendance-', '')],
        querySelector: () => ({content: 'csrf'}),
    };
    const bootstrap = {Modal: {getOrCreateInstance: () => ({show() { elements.modal.classList.add('show'); }})}};
    vm.runInNewContext(source, {
        document, window: {bootstrap, addEventListener() {}}, bootstrap, crypto: webcrypto,
        AbortController, setTimeout, clearTimeout, Uint8Array,
        fetch(url, options) { return new Promise((resolve, reject) => calls.push({url, options, resolve, reject})); },
    });
    const respond = async (index, attendance, status = 200) => {
        calls[index].resolve({ok: status === 200, status, json: async () => ({attendance, message: 'Gagal'})});
        await settle();
    };
    return {elements, calls, respond};
}
const openShift = {id: 7, checked_in_at: '06/10/2026 08:00:00 WIB', checked_out_at: null};
const closedShift = {...openShift, checked_out_at: '06/10/2026 17:30:00 WIB'};

test('button changes from check-in to check-out and suppresses double-click while saving', async () => {
    const h = setup();
    await h.respond(0, null);
    assert.equal(h.elements.action.textContent, 'Absen Masuk');
    h.elements.action.handlers.click();
    h.elements.action.handlers.click();
    assert.equal(h.calls.length, 2);
    assert.equal(h.elements.action.disabled, true);
    assert.equal(h.calls[1].options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.match(JSON.parse(h.calls[1].options.body).request_key, /^[a-f0-9-]{36}$/);
    await h.respond(1, openShift);
    assert.equal(h.elements.action.textContent, 'Absen Keluar');
    assert.equal(h.elements.action.disabled, false);
});

test('check-out requires confirmation and displays completed summary', async () => {
    const h = setup();
    await h.respond(0, openShift);
    h.elements.action.handlers.click();
    assert.equal(h.calls.length, 1);
    assert.equal(h.elements['confirm-out'].classList.contains('d-none'), false);
    h.elements['confirm-out'].handlers.click();
    assert.deepEqual(JSON.parse(h.calls[1].options.body), {attendance_id: 7});
    await h.respond(1, closedShift);
    assert.equal(h.elements['modal-title'].textContent, 'Absen keluar berhasil');
    assert.equal(h.elements.action.textContent, 'Absen Masuk');
    assert.equal(h.elements['confirm-out'].classList.contains('d-none'), true);
});

test('network failure preserves request identity after status reload before retry', async () => {
    const h = setup();
    await h.respond(0, null);
    h.elements.action.handlers.click();
    const originalBody = h.calls[1].options.body;
    h.calls[1].reject(new Error('offline'));
    await settle();
    assert.equal(h.elements.action.textContent, 'Muat ulang absensi');
    h.elements.action.handlers.click();
    await h.respond(2, null);
    h.elements.action.handlers.click();
    assert.equal(h.calls[3].options.body, originalBody);
    await h.respond(3, openShift);
});

test('expired session shows a recoverable message and never sends a blind write', async () => {
    const h = setup();
    await h.respond(0, null, 401);
    h.elements.action.handlers.click();
    assert.equal(h.calls[1].url, '/status');
    await h.respond(1, null, 401);
    assert.match(h.elements.message.textContent, /Sesi telah berakhir/);
    assert.equal(h.elements.action.disabled, false);
});
