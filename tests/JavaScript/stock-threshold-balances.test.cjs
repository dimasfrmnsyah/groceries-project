const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const view = fs.readFileSync('resources/views/pages/admin/threshold/index.blade.php', 'utf8');
const script = view.match(/<script id="threshold-stock-refresh">([\s\S]*?)<\/script>/)?.[1] ?? '';
const settle = () => new Promise(resolve => setImmediate(resolve));

function setup() {
    const stock = {textContent: '1'};
    const min = {value: '7'};
    const max = {value: '20'};
    const row = {
        dataset: {id: '1'},
        querySelector: selector => selector === '[data-stock-system]' ? stock : null,
        querySelectorAll: () => [min, max],
    };
    const form = {dataset: {stockUrl: '/stock-threshold?store=1', storeId: '1'}};
    const status = {textContent: ''};
    const elements = {
        'threshold-form': form,
        'threshold-table-body': {querySelectorAll: () => [row]},
        'threshold-stock-status': status,
    };
    const documentEvents = {};
    const windowEvents = {};
    const timers = [];
    const requests = [];
    const document = {
        hidden: false,
        getElementById: id => elements[id],
        addEventListener: (event, fn) => { documentEvents[event] = fn; },
    };
    vm.runInNewContext(script, {
        document,
        window: {
            addEventListener: (event, fn) => { windowEvents[event] = fn; },
            setInterval: fn => timers.push(fn),
        },
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject})),
    });
    documentEvents.DOMContentLoaded?.();
    return {stock, min, max, status, document, documentEvents, windowEvents, timers, requests,
        async respond(index, quantity, store = 1) {
            requests[index].resolve({ok: true, redirected: false, json: async () => ({store_id: store, rows: [{id: 1, stock_system: quantity}]})});
            await settle();
        },
    };
}

test('refresh displays purchases and a sale to zero without overwriting unsaved min/max', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/stock-threshold?store=1');
    assert.equal(h.requests[0].options.cache, 'no-store');
    await h.respond(0, 3);
    assert.equal(h.stock.textContent, '3');
    h.timers[0]();
    await h.respond(1, 0);
    assert.equal(h.stock.textContent, '0');
    assert.equal(h.min.value, '7');
    assert.equal(h.max.value, '20');
});

test('returning to the tab and restoring browser history fetch fresh stock', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    await h.respond(0, 1);
    h.windowEvents.focus();
    await h.respond(1, 0);
    h.windowEvents.pageshow({persisted: true});
    await h.respond(2, 4);
    assert.equal(h.stock.textContent, '4');
});

test('refresh skips hidden tabs and prevents overlapping requests', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    h.windowEvents.focus();
    h.timers[0]();
    assert.equal(h.requests.length, 1);
    await h.respond(0, 1);
    h.document.hidden = true;
    h.timers[0]();
    assert.equal(h.requests.length, 1);
    h.document.hidden = false;
    h.documentEvents.visibilitychange();
    assert.equal(h.requests.length, 2);
    await h.respond(1, 0);
    assert.equal(h.stock.textContent, '0');
});

test('network failure retains the balance, reports stale data, and allows retry', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    h.requests[0].reject(new Error('offline'));
    await settle();
    assert.equal(h.stock.textContent, '1');
    assert.match(h.status.textContent, /gagal/i);
    h.timers[0]();
    await h.respond(1, 0);
    assert.equal(h.stock.textContent, '0');
    assert.doesNotMatch(h.status.textContent, /gagal/i);
});

test('a response for a different store cannot replace the displayed balance', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    await h.respond(0, 99, 2);
    assert.equal(h.stock.textContent, '1');
    assert.match(h.status.textContent, /gagal/i);
});
