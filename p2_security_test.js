'use strict';

const assert = require('assert');
const security = require('./security');

const goodSecret = 'a'.repeat(16) + 'unique-local-test-secret';
const crypto = require('crypto');

function token(parts) {
    const payload = parts.join('.');
    return payload + '.' + crypto.createHmac('sha256', goodSecret).update(payload).digest('hex');
}

let failed = 0;
function check(name, fn) {
    try {
        fn();
        console.log('PASS ' + name);
    } catch (e) {
        failed += 1;
        console.log('FAIL ' + name + ': ' + e.message);
    }
}

check('view-only socket token still cannot mutate', () => {
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const auth = security.verifySocketToken(token(['3', String(expires), '0']), goodSecret);
    assert.ok(auth);
    assert.strictEqual(auth.canMutate, false);
});

check('writer socket token can mutate', () => {
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const auth = security.verifySocketToken(token(['1', String(expires), '1']), goodSecret);
    assert.ok(auth);
    assert.strictEqual(auth.canMutate, true);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P2 unit checks passed.');
