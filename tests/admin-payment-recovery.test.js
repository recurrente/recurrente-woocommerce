const assert = require("node:assert/strict");
const { readFileSync } = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");

const source = readFileSync(path.join(__dirname, "../assets/js/admin-payment-recovery.js"), "utf8");
const recoveryAction = "recurrente_reconcile_payment";

function page({ action = recoveryAction, loading = false, hasPendingRow = true } = {}) {
  const form = new EventTarget();
  const select = { form, value: action, disabled: false };
  const buttons = [{ disabled: false }, { disabled: false }];
  const controls = [...buttons, select];
  const attributes = new Map();
  const pending = { style: { display: "none" } };
  const box = {
    setAttribute: (name, value) => attributes.set(name, value),
    getAttribute: (name) => attributes.get(name) ?? null,
    removeAttribute: (name) => attributes.delete(name),
    querySelectorAll: () => controls,
  };
  const document = Object.assign(new EventTarget(), {
    readyState: loading ? "loading" : "complete",
    querySelector: (selector) => selector.startsWith("select") ? select : hasPendingRow ? pending : null,
    getElementById: () => box,
  });
  const timers = [];
  const window = Object.assign(new EventTarget(), {
    setTimeout: (callback) => timers.push(callback),
  });
  vm.runInNewContext(source, { document, window });
  return { form, select, buttons, controls, pending, box, document, window, timers };
}

function submit(current) {
  const event = new Event("submit", { cancelable: true });
  current.form.dispatchEvent(event);
  return event;
}

function showFromHistory(current, persisted) {
  const event = new Event("pageshow");
  Object.defineProperty(event, "persisted", { value: persisted });
  current.window.dispatchEvent(event);
}

test("a recovery submit gives immediate feedback and lets the native form collect its fields", () => {
  const current = page();
  assert.equal(submit(current).defaultPrevented, false);
  assert.equal(current.pending.style.display, "");
  assert.equal(current.box.getAttribute("aria-busy"), "true");
  assert.equal(current.select.value, recoveryAction);
  assert.ok(current.controls.every((control) => !control.disabled));
  current.timers.forEach((callback) => callback());
  assert.ok(current.controls.every((control) => control.disabled));
});

test("further submits are blocked immediately, including after the selected action changes", () => {
  const current = page();
  submit(current);
  assert.equal(submit(current).defaultPrevented, true);
  current.select.value = "send_order_details";
  assert.equal(submit(current).defaultPrevented, true);
});

test("other native order actions keep their normal submit behavior", () => {
  const current = page({ action: "send_order_details" });
  assert.equal(submit(current).defaultPrevented, false);
  assert.equal(current.pending.style.display, "none");
  assert.equal(current.box.getAttribute("aria-busy"), null);
  assert.equal(current.timers.length, 0);
  assert.ok(current.controls.every((control) => !control.disabled));
});

test("a page restored from browser history can submit again", () => {
  const current = page();
  submit(current);
  current.timers.forEach((callback) => callback());
  showFromHistory(current, true);
  assert.equal(current.pending.style.display, "none");
  assert.equal(current.box.getAttribute("aria-busy"), null);
  assert.ok(current.controls.every((control) => !control.disabled));
  assert.equal(submit(current).defaultPrevented, false);
});

test("a normal pageshow event does not release an in-flight submission", () => {
  const current = page();
  submit(current);
  current.timers.forEach((callback) => callback());
  showFromHistory(current, false);
  assert.equal(submit(current).defaultPrevented, true);
  assert.ok(current.controls.every((control) => control.disabled));
});

test("pages without the recovery action's pending row are left alone", () => {
  const current = page({ hasPendingRow: false });
  assert.equal(submit(current).defaultPrevented, false);
  assert.equal(current.pending.style.display, "none");
  assert.equal(current.timers.length, 0);
});

test("the recovery action works when the footer script loads before DOMContentLoaded", () => {
  const current = page({ loading: true });
  submit(current);
  assert.equal(current.pending.style.display, "none");
  current.document.dispatchEvent(new Event("DOMContentLoaded"));
  assert.equal(submit(current).defaultPrevented, false);
  assert.equal(current.pending.style.display, "");
  assert.equal(submit(current).defaultPrevented, true);
});
