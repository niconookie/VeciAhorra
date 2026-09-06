'use strict';
// Local clock/API simulation of the actual checkout polling functions. No network.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = process.env.VECIAHORRA_TEST_PLUGIN_ROOT || path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'assets/frontend/js/veciahorra-checkout.js'), 'utf8');
let assertions = 0;
function check(value, message) { assertions++; assert.ok(value, message); }
function actualFunction(name) {
    const start = source.indexOf('        function ' + name + '(');
    assert.ok(start >= 0, name);
    const end = source.indexOf('\n        function ', start + 1);
    return source.slice(start, end);
}
function fixture() {
    let now = 1000;
    let serial = 0;
    const timers = new Map();
    const calls = [];
    const response = {payment_status: 'payment_verifying', terminal: false, poll_after_ms: 3000, message: 'Verifying', next_action: 'wait'};
    const element = () => ({hidden: false, className: '', textContent: '', removeAttribute() {}, setAttribute() {}, focus() {}});
    const context = {
        Date: {now: () => now}, Number, Promise, AbortController, encodeURIComponent,
        REQUEST_TIMEOUT: 12000, paymentStartedAt: 1000, paymentStopped: false,
        paymentInFlight: false, paymentTimer: null, paymentController: null,
        checkoutPublicId: 'chk_' + 'A'.repeat(43), lastPaymentState: null,
        requestOptions: () => ({}),
        window: {setTimeout(fn, delay) { const id = ++serial; timers.set(id, {fn, delay}); return id; }, clearTimeout(id) { timers.delete(id); }, location: {pathname: '/checkout'}},
        config: {pages: {}, api: {get(url) { calls.push({url, at: now}); return Promise.resolve({success: true, data: {...response}}); }}}
    };
    for (const name of ['loading', 'error', 'empty', 'content', 'paymentPanel', 'paymentAction', 'paymentMessage', 'paymentRefresh']) { context[name] = element(); }
    vm.createContext(context);
    vm.runInContext(['validCheckoutPublicId', 'stopPaymentPolling', 'paymentDelay', 'renderPaymentState', 'pollPaymentStatus'].map(actualFunction).join('\n'), context);
    return {context, calls, timers, response, now(value) { now = value; }};
}
(async () => {
    const f = fixture();
    await f.context.pollPaymentStatus();
    check(f.calls.length === 1, 'First observation is immediate.');
    check([...f.timers.values()][0].delay === 3000, 'Verifying response waits 3000 ms initially.');
    for (const [elapsed, floor] of [[0, 2500], [14999, 2500], [15000, 5000], [59999, 5000], [60000, 12000]]) {
        f.now(1000 + elapsed);
        check(f.context.paymentDelay(0) === floor, 'Exact adaptive floor at ' + elapsed);
        check(f.context.paymentDelay(3000) >= 2000, 'No aggressive polling.');
    }
    const next = [...f.timers.entries()][0];
    f.timers.delete(next[0]);
    f.now(81000);
    f.response.terminal = true; f.response.payment_status = 'completed'; f.response.next_action = 'view_order';
    await next[1].fn();
    check(f.calls.length === 2 && f.context.paymentStopped, 'Terminal response stops polling.');
    check(f.timers.size === 0, 'No timers survive terminal.');
    await f.context.pollPaymentStatus();
    check(f.calls.length === 2, 'Zero requests after terminal.');
    const ambiguous = fixture();
    ambiguous.context.renderPaymentState({payment_status: 'manual_review', terminal: true, message: 'Ambiguous create', next_action: 'contact_support'});
    await ambiguous.context.pollPaymentStatus();
    check(ambiguous.calls.length === 0 && ambiguous.timers.size === 0, 'Terminal create_ambiguous projection has zero polling.');
    console.log('PASS payment-return-wait assertions=' + assertions + ' simulated_status_requests=2 external_calls=0 wait_fix=NOT_PROVEN');
})().catch(error => { console.error(error); process.exitCode = 1; });
