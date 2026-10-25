const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync('resources/views/pages/admin/threshold/index.blade.php', 'utf8').split('<script>')[1].split('</script>')[0];

function setup() {
    const element = (value = '') => ({
        value, dataset: {}, disabled: false, style: {}, handlers: {},
        classList: {toggle() {}, add() {}, remove() {}},
        addEventListener(event, handler) { this.handlers[event] = handler; }, focus() {},
    });
    const min = element('2'), max = element('10'), expected = element('0'), payload = element('');
    const row = {...element(), dataset: {id: '1', name: 'beras', code: 'b1'},
        querySelectorAll: () => [min, max], scrollIntoView() {}};
    const label = {...element(), textContent: 'Simpan'}, button = element();
    button.querySelector = selector => selector === '.btn-label' ? label : element();
    const form = element();
    form.querySelector = selector => selector.includes('expected_count') ? expected : payload;
    const elements = {'threshold-search': element(), 'threshold-table-body': {querySelectorAll: () => [row]},
        'threshold-form': form, 'threshold-submit': button, 'threshold-items-payload': payload,
        'threshold-message': {...element(), textContent: ''}};
    vm.runInNewContext(script, {document: {getElementById: id => elements[id],
        addEventListener: (_, handler) => handler()}, window: {addEventListener() {}}, setTimeout() {}});
    return {min, max, expected, payload, form, submit() {
        const event = {prevented: false, preventDefault() {this.prevented = true;}};
        form.handlers.submit(event);
        return event;
    }};
}

test('submit captures changed values even without an input event', () => {
    const h = setup();
    h.min.value = '5';
    const event = h.submit();
    assert.equal(event.prevented, false);
    assert.equal(h.expected.value, '1');
    assert.equal(h.min.disabled, true);
    assert.equal(h.max.disabled, true);
    assert.deepEqual(JSON.parse(h.payload.value), {'1': {min_stock: '5', max_stock: '10'}});
});

test('no changes does not submit or lock the form', () => {
    const h = setup();
    assert.equal(h.submit().prevented, true);
    h.max.value = '20';
    assert.equal(h.submit().prevented, false);
    assert.equal(JSON.parse(h.payload.value)['1'].max_stock, '20');
});
