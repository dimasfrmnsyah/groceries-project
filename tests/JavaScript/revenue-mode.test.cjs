const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/layouts/header.blade.php', 'utf8')
    .split('const revenueForm = ')[1].split('function showRevenueModal()')[0];
const script = ('const revenueForm = ' + source)
    .replace(/\{\{ route\('staff.checkDailyRevenue'\) \}\}/g, 'http://localhost/check-daily-revenue');
const settle = () => new Promise(resolve => setImmediate(resolve));
function setup({simple = true, locked = true, amount = '', cash = 0, qr = 0, expense = 0} = {}) {
    const elements = {};
    for (const id of ['revenueForm', 'amount', 'denominations-payload', 'revenue-qr', 'revenue-pengeluaran', 'revenue-cash-display', 'revenue-qr-display', 'revenue-expense-display', 'revenue-total-display', 'revenueSubmitButton', 'revenueValidationMessage', 'revenueModal']) {
        elements[id] = {value: '', textContent: '', disabled: false, handlers: {}, addEventListener(e, fn) {this.handlers[e] = fn;}};
    }
    elements.revenueForm.dataset = {'revenueSimple': simple ? '1' : '0', revenueLocked: locked ? '1' : '0'};
    elements.revenueForm.checkValidity = () => !simple || (elements.amount.value !== '' && Number(elements.amount.value) >= 0);
    elements.amount.value = amount;
    elements['revenue-qr'].value = qr;
    elements['revenue-pengeluaran'].value = expense;
    const count = {value: cash, addEventListener() {}};
    const rows = simple ? [] : [{dataset: {key: 'note_100000', value: '100000'}, querySelector: selector => selector.includes('count') ? count : {}}];
    const requests = [];
    const context = vm.createContext({
        document: {
            getElementById: id => elements[id],
            querySelectorAll: selector => selector.includes('-row') ? rows : (simple ? [] : [count]),
        },
        window: {location: {origin: 'http://localhost'}, clearTimeout() {}, setTimeout() {}}, URL,
        fetch: url => new Promise(resolve => requests.push({url, resolve})),
    });
    vm.runInContext(script, context);
    return {elements, requests, run: code => vm.runInContext(code, context), async respond(index, matches) {
        requests[index].resolve({ok: true, json: async () => ({matches})}); await settle();
    }};
}

test('simple mode preserves manually entered total during calculation and submit', () => {
    const h = setup({amount: '125000'});
    assert.equal(h.elements.amount.value, '125000');
    h.elements.revenueForm.handlers.submit();
    assert.equal(h.elements.amount.value, '125000');
    h.run('validateRevenueAmount()');
    assert.equal(new URL(h.requests[0].url).searchParams.get('amount'), '125000');
});

test('blank simple input is never converted into a matching zero sale', () => {
    const h = setup();
    h.run('validateRevenueAmount()');
    assert.equal(h.requests.length, 0);
    assert.equal(h.elements.amount.value, '');
    assert.equal(h.elements.revenueSubmitButton.disabled, true);
});

test('changing amount immediately disables logout and ignores a stale match response', async () => {
    const h = setup({amount: '125000'});
    h.run('validateRevenueAmount()');
    h.elements.amount.value = '100000';
    h.elements.amount.handlers.input();
    assert.equal(h.elements.revenueSubmitButton.disabled, true);
    await h.respond(0, true);
    assert.equal(h.elements.revenueSubmitButton.disabled, true);
    h.run('validateRevenueAmount()');
    await h.respond(1, false);
    assert.equal(h.elements.revenueSubmitButton.disabled, true);
    h.elements.amount.value = '125000';
    h.elements.amount.handlers.input();
    h.run('validateRevenueAmount()');
    await h.respond(2, true);
    assert.equal(h.elements.revenueSubmitButton.disabled, false);
});

test('detailed mode preserves cash plus QR plus expense calculation', () => {
    const h = setup({simple: false, cash: 1, qr: 20000, expense: 5000});
    assert.equal(h.elements.amount.value, '125000');
    assert.deepEqual(JSON.parse(h.elements['denominations-payload'].value), {note_100000: 1});
});
