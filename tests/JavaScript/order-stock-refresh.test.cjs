const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync('resources/views/pages/admin/order-stock/index.blade.php', 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const settle = () => new Promise(resolve => setImmediate(resolve));
const item = (stock = 0, qty = 1, id = 1) => ({id, product_code: 'B001', product_name: 'Beras', stock_system: stock, min_stock: 1, max_stock: 1, purchase_price: 10000, po_qty: qty});

function setup({empty = false} = {}) {
    const eventNode = (extra = {}) => ({handlers: {}, addEventListener(event, fn) {this.handlers[event] = fn;}, ...extra});
    let rows = [];
    function makeRow(id = '', stock = '', suggested = '') {
        const input = eventNode({value: String(suggested), name: `po_qty[${id}]`, matches: selector => selector === '.po-input'});
        const check = eventNode({checked: false, value: String(id)});
        const cells = {};
        for (const name of ['code', 'name', 'stock', 'min', 'max', 'price']) cells[name] = {textContent: name === 'stock' ? String(stock) : ''};
        const total = {textContent: ''};
        const row = {
            dataset: {id: String(id), price: '10000', suggestedPo: String(suggested)},
            querySelector(selector) {
                if (selector === '.row-check') return check;
                if (selector === '.po-input' || selector.startsWith('input[name^=')) return input;
                if (selector === '.row-total') return total;
                return cells[selector.match(/data-field="(\w+)"/)?.[1]];
            },
            getAttribute(name) {return name === 'data-price' ? this.dataset.price : null;},
            remove() {rows = rows.filter(r => r !== row);},
        };
        input.closest = check.closest = () => row;
        return row;
    }
    if (!empty) rows.push(makeRow(1, 1, 0));
    const body = eventNode({querySelectorAll: () => rows, appendChild: row => rows.push(row)});
    const form = eventNode({dataset: {stockUrl: '/order-stock?store=1', storeId: '1'}});
    const elements = {
        'order-stock-form': form,
        'order-stock-body': body,
        'order-stock-row-template': {content: {firstElementChild: {cloneNode: () => makeRow()}}},
        'order-stock-empty': {hidden: !empty},
        'order-stock-card': {hidden: empty},
        'order-stock-status': {textContent: ''},
        'order-stock-submit': {disabled: empty, textContent: ''},
        'order-stock-idempotency-key': {value: ''},
        'check-all': eventNode({checked: false, indeterminate: false}),
        'grand-total': {textContent: ''},
    };
    const documentEvents = {}, windowEvents = {}, timers = [], requests = [];
    const document = {
        hidden: false,
        getElementById: id => elements[id],
        querySelector: () => form,
        querySelectorAll(selector) {
            if (selector === '.order-row') return rows;
            return rows.map(row => row.querySelector(selector === '.row-check' ? selector : '.po-input'));
        },
        addEventListener: (event, fn) => {documentEvents[event] = fn;},
    };
    vm.runInNewContext(script.replace(/\{\{[\s\S]*?\}\}/g, '/order-stock/restock'), {
        document,
        window: {crypto: {randomUUID: () => 'request-1'}, setInterval: fn => timers.push(fn), addEventListener: (event, fn) => {windowEvents[event] = fn;}},
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject})),
    });
    documentEvents.DOMContentLoaded?.();
    return {elements, body, form, document, documentEvents, windowEvents, timers, requests, rows: () => rows,
        async respond(index, items, store = 1) {
            requests[index].resolve({ok: true, redirected: false, json: async () => ({store_id: store, items})});
            await settle();
        },
    };
}

test('sale from one to zero updates stock, suggested PO and total', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.cache, 'no-store');
    await h.respond(0, [item()]);
    const row = h.rows()[0];
    assert.equal(row.querySelector('[data-field="stock"]').textContent, '0');
    assert.equal(row.querySelector('.po-input').value, '1');
    assert.equal(h.elements['grand-total'].textContent, '10.000');
});

test('refresh preserves manual PO and selection while updating stock and price', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    const row = h.rows()[0];
    row.querySelector('.po-input').value = '5';
    row.querySelector('.row-check').checked = true;
    await h.respond(0, [{...item(), purchase_price: 12000}]);
    assert.equal(row.querySelector('.po-input').value, '5');
    assert.equal(row.querySelector('.row-check').checked, true);
    assert.equal(h.elements['grand-total'].textContent, '60.000');
});

test('purchase removes replenished items and new low-stock items appear from empty state', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    await h.respond(0, []);
    assert.equal(h.rows().length, 0);
    assert.equal(h.elements['order-stock-empty'].hidden, false);
    assert.equal(h.elements['order-stock-submit'].disabled, true);
    h.timers[0]();
    await h.respond(1, [item(0, 2, 2)]);
    assert.equal(h.rows().length, 1);
    assert.equal(h.rows()[0].querySelector('.po-input').name, 'po_qty[2]');
    assert.equal(h.rows()[0].querySelector('.row-check').checked, false);
    assert.equal(h.elements['order-stock-card'].hidden, false);
    assert.equal(h.elements['order-stock-submit'].disabled, false);
    const input = h.rows()[0].querySelector('.po-input');
    input.value = '3';
    h.body.handlers.input({target: input});
    assert.equal(h.elements['grand-total'].textContent, '30.000');
});

test('initially empty page refreshes and displays newly depleted products', async () => {
    const h = setup({empty: true});
    assert.equal(h.requests.length, 1);
    await h.respond(0, [item()]);
    assert.equal(h.rows().length, 1);
    assert.equal(h.elements['order-stock-empty'].hidden, true);
});

test('focus and history refresh stock without overlapping requests or polling hidden tabs', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    h.windowEvents.focus();
    assert.equal(h.requests.length, 1);
    await h.respond(0, [item()]);
    h.document.hidden = true;
    h.timers[0]();
    assert.equal(h.requests.length, 1);
    h.document.hidden = false;
    h.documentEvents.visibilitychange();
    await h.respond(1, [item()]);
    h.windowEvents.pageshow({persisted: true});
    assert.equal(h.requests.length, 3);
    await h.respond(2, [item()]);
});

test('failed refresh keeps entered values and can recover on next attempt', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    h.rows()[0].querySelector('.po-input').value = '7';
    h.requests[0].reject(new Error('offline'));
    await settle();
    assert.match(h.elements['order-stock-status'].textContent, /gagal/i);
    assert.equal(h.rows()[0].querySelector('.po-input').value, '7');
    h.timers[0]();
    await h.respond(1, [item()]);
    assert.doesNotMatch(h.elements['order-stock-status'].textContent, /gagal/i);
});

test('response for another store cannot replace the order list', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    await h.respond(0, [], 2);
    assert.equal(h.rows().length, 1);
    assert.match(h.elements['order-stock-status'].textContent, /gagal/i);
});

test('an in-flight refresh cannot change the order during submission', async () => {
    const h = setup();
    assert.equal(h.requests.length, 1);
    h.form.handlers.submit();
    await h.respond(0, []);
    assert.equal(h.rows().length, 1);
    assert.equal(h.elements['order-stock-submit'].disabled, true);
});

test('manual PO stays manual when a later suggestion happens to match it', async () => {
    const h = setup();
    const row = h.rows()[0];
    row.querySelector('.po-input').value = '5';
    await h.respond(0, [item(0, 5)]);
    h.timers[0]();
    await h.respond(1, [item(1, 4)]);
    assert.equal(row.querySelector('.po-input').value, '5');
});
