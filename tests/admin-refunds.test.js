const { test } = require('node:test');
const assert = require('node:assert/strict');
const { randomUUID, randomFillSync } = require('node:crypto');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('assets/js/admin-refunds.js', 'utf8');
function browser(storage = new Map(), terminalKeys = [], crypto = { randomUUID }) {
  let prefilter;
  const alerts = [];
  vm.runInNewContext(source, {
    URLSearchParams,
    recurrenteRefunds: { orderId: '42', terminalKeys, blocked: 'Blocked safely' },
    jQuery: { ajaxPrefilter(callback) { prefilter = callback; } },
    window: {
      crypto, alert(message) { alerts.push(message); },
      sessionStorage: {
        getItem(key) { return storage.get(key) || null; },
        setItem(key, value) { storage.set(key, value); },
        removeItem(key) { storage.delete(key); }
      }
    }
  });
  return {
    alerts, storage,
    submit(changes = {}) {
      const data = new URLSearchParams({ action: 'woocommerce_refund_line_items', order_id: '42', api_refund: 'true',
        refund_amount: '25.00', refund_reason: 'Customer request', restock_refunded_items: 'true', ...changes }).toString();
      const xhr = { headers: {}, aborted: false, callback: null,
        setRequestHeader(key, value) { this.headers[key] = value; },
        done(callback) { this.callback = callback; }, abort() { this.aborted = true; } };
      prefilter({ data }, {}, xhr);
      return xhr;
    }
  };
}
test('request identity is saved before HTTP, and response loss/reload keeps the same UUID', () => {
  const first = browser();
  const request = first.submit();
  const key = request.headers['X-Recurrente-Refund-Operation'];
  assert.match(key, /^[a-f0-9-]{36}$/);
  assert.equal(JSON.parse(first.storage.get('recurrente-refund-42')).id, key);
  assert.equal(browser(first.storage).submit().headers['X-Recurrente-Refund-Operation'], key);
});
test('pending/error responses keep the identity; a confirmed response permits a distinct equal partial', () => {
  const page = browser();
  const first = page.submit();
  first.callback({ success: false });
  assert.equal(page.submit().headers['X-Recurrente-Refund-Operation'], first.headers['X-Recurrente-Refund-Operation']);
  first.callback({ success: true });
  assert.notEqual(page.submit().headers['X-Recurrente-Refund-Operation'], first.headers['X-Recurrente-Refund-Operation']);
});
test('a rejected form can be corrected using the same identity; the server journal owns immutable parameters', () => {
  const page = browser();
  const invalid = page.submit({ refund_amount: '150.00' });
  invalid.callback({ success: false, data: { error: 'Invalid refund amount' } });
  const changed = page.submit({ refund_amount: '25.00', line_item_totals: '{"1":"25.00"}' });
  assert.equal(changed.aborted, false);
  assert.equal(changed.headers['X-Recurrente-Refund-Operation'], invalid.headers['X-Recurrente-Refund-Operation']);
  assert.deepEqual(page.alerts, []);
});
test('a fresh order page can start another operation after a persisted terminal result', () => {
  const page = browser();
  const first = page.submit().headers['X-Recurrente-Refund-Operation'];
  const next = browser(page.storage, ['woo_refund_' + first]);
  assert.notEqual(next.submit().headers['X-Recurrente-Refund-Operation'], first);
});
test('manual refunds and requests for other orders stay outside the Recurrente money contract', () => {
  const page = browser();
  assert.deepEqual(page.submit({ api_refund: 'false' }).headers, {});
  assert.deepEqual(page.submit({ order_id: '43' }).headers, {});
  assert.equal(page.storage.size, 0);
});
test('unavailable durable browser storage prevents an unidentifiable money request', () => {
  const storage = new Map();
  storage.set = () => { throw new Error('Storage unavailable'); };
  const page = browser(storage);
  assert.equal(page.submit().aborted, true);
  assert.equal(page.alerts.length, 1);
});
test('browsers without randomUUID still use a cryptographic UUID v4', () => {
  const page = browser(new Map(), [], { getRandomValues: randomFillSync });
  assert.match(page.submit().headers['X-Recurrente-Refund-Operation'], /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
});
