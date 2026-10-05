const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/pages/admin/sales/index.blade.php', 'utf8');
const itemHandler = source.match(/\$\('#item-code'\)\.keydown\(function\(event\) \{([\s\S]*?)\n        \}\);/)[1];
const qtyHandler = source.match(/\$\('#qty'\)\.on\('keydown', function\(e\) \{([\s\S]*?)\n        \}\)/)[1];
const hiddenHandler = source.match(/\$\('#item-modal'\)\.on\('hidden.bs.modal', function \(\) \{([\s\S]*?)\n            \}\);/)[1];
function setup(touch = false, value = '', delta = 100) {
    const state = {focus: '', opened: 0, scanned: 0};
    const event = {key: 'Enter', preventDefault() {}, stopPropagation() {}};
    const context = vm.createContext({
        Date: {now: () => 1000}, lastKeyTime: 1000 - delta, onClickedItem: 0,
        search_term: '', inputString: '', isItemModalOpen: false, document: {},
        window: {matchMedia: () => ({matches: touch})},
        $: selector => ({val: () => value, focus: () => state.focus = selector, off() {}}),
        openItemModalSafely: () => state.opened++, processBarcode: () => state.scanned++, event, e: event,
    });
    return {state, context, run: handler => vm.runInContext('(function(){' + handler + '})()', context)};
}
test('desktop Enter cycles item code to quantity and back before opening picker', () => {
    const h = setup();
    h.run(itemHandler);
    assert.equal(h.state.focus, '#qty');
    assert.equal(h.state.opened, 0);
    h.run(qtyHandler);
    assert.equal(h.state.focus, '#item-code');
    h.run(itemHandler);
    assert.equal(h.state.opened, 1);
    h.run(hiddenHandler);
    h.run(itemHandler);
    assert.equal(h.state.focus, '#qty');
    assert.equal(h.state.opened, 1);
});
test('desktop search with a product code still opens picker', () => {
    const h = setup(false, 'beras'); h.run(itemHandler); assert.equal(h.state.opened, 1);
});
test('mobile Enter still opens picker immediately even for fast input', () => {
    const h = setup(true, 'beras', 20); h.run(itemHandler);
    assert.equal(h.state.opened, 1); assert.equal(h.state.scanned, 0);
});
test('desktop scanner still processes barcode', () => {
    const h = setup(false, '12345', 20); h.run(itemHandler);
    assert.equal(h.state.scanned, 1); assert.equal(h.state.opened, 0);
});
