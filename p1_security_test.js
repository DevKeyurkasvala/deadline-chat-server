'use strict';

const assert = require('assert');
const crypto = require('crypto');
const security = require('./security');

const goodSecret = 'a'.repeat(16) + 'unique-local-test-secret';
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

function token(parts) {
    const payload = parts.join('.');
    return payload + '.' + crypto.createHmac('sha256', goodSecret).update(payload).digest('hex');
}

check('valid socket token works', () => {
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const auth = security.verifySocketToken(token(['1', String(expires), '1']), goodSecret);
    assert.ok(auth);
    assert.strictEqual(auth.userId, 1);
    assert.strictEqual(auth.canMutate, true);
});

check('expired socket token rejected', () => {
    const expires = Math.floor(Date.now() / 1000) - 10;
    assert.strictEqual(security.verifySocketToken(token(['1', String(expires), '1']), goodSecret), null);
});

check('invalid socket token rejected', () => {
    assert.strictEqual(security.verifySocketToken('1.9999999999.1.not-a-real-hmac', goodSecret), null);
    assert.strictEqual(security.verifySocketToken('', goodSecret), null);
});

check('expired room token rejected', () => {
    const expires = Math.floor(Date.now() / 1000) - 10;
    const room = token(['1', '2', String(expires)]);
    assert.strictEqual(security.verifyRoomToken(room, goodSecret, 1, 2), false);
    assert.strictEqual(security.authorizeJoin({ conversation_id: 2, room_token: room }, 1, goodSecret), 0);
});

check('wrong-user room token rejected', () => {
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const room = token(['1', '2', String(expires)]);
    assert.strictEqual(security.authorizeJoin({ conversation_id: 2, room_token: room }, 99, goodSecret), 0);
});

check('health-safe exports do not include secrets', () => {
    assert.strictEqual(typeof security.secretIsConfigured, 'function');
    assert.ok(!JSON.stringify(security).includes(goodSecret));
});

if (failed) {
    process.exit(1);
}
console.log('All Node P1 unit checks passed.');
