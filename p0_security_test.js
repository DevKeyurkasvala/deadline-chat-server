'use strict';

const assert = require('assert');
const security = require('./security');

const goodSecret = 'a'.repeat(16) + 'unique-local-test-secret';
const badSecrets = ['', 'short', 'deadline-chat-local-dev-secret', 'CHANGE_ME_CHAT_SECRET'];

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

check('rejects forbidden secrets', () => {
    badSecrets.forEach((s) => assert.strictEqual(security.secretIsConfigured(s), false));
    assert.strictEqual(security.secretIsConfigured(goodSecret), true);
});

check('room token is bound to user and conversation', () => {
    const crypto = require('crypto');
    const userId = 1;
    const convId = 2;
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const payload = userId + '.' + convId + '.' + expires;
    const token = payload + '.' + crypto.createHmac('sha256', goodSecret).update(payload).digest('hex');
    assert.strictEqual(security.verifyRoomToken(token, goodSecret, 1, 2), true);
    assert.strictEqual(security.verifyRoomToken(token, goodSecret, 3, 2), false);
    assert.strictEqual(security.verifyRoomToken(token, goodSecret, 1, 99), false);
    assert.strictEqual(security.verifyRoomToken('1.2.9999999999.deadbeef', goodSecret, 1, 2), false);
});

check('socket token includes canMutate and rejects old 3-part tokens', () => {
    const crypto = require('crypto');
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const payload = '3.' + expires + '.0';
    const token = payload + '.' + crypto.createHmac('sha256', goodSecret).update(payload).digest('hex');
    const auth = security.verifySocketToken(token, goodSecret);
    assert.ok(auth);
    assert.strictEqual(auth.userId, 3);
    assert.strictEqual(auth.canMutate, false);
    const legacy = '3.' + expires + '.' + crypto.createHmac('sha256', goodSecret).update('3.' + expires).digest('hex');
    assert.strictEqual(security.verifySocketToken(legacy, goodSecret), null);
});

check('emit body validation', () => {
    assert.strictEqual(security.validateEmitBody(null), 'Invalid payload.');
    assert.strictEqual(security.validateEmitBody({ secret: 'x', event: 'chat:typing', rooms: ['user:1'] }), 'Invalid event.');
    assert.strictEqual(security.validateEmitBody({ secret: 'x', event: 'chat:message', rooms: ['evil:1'] }), 'Invalid rooms.');
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:message',
        rooms: ['user:1', 'conversation:2'],
        payload: { message: { id: 1 } },
    }), '');
});

check('ip allowlist', () => {
    assert.strictEqual(security.ipAllowed('8.8.8.8', []), true);
    assert.strictEqual(security.ipAllowed('8.8.8.8', ['127.0.0.1']), false);
    assert.strictEqual(security.ipAllowed('127.0.0.1', ['127.0.0.1']), true);
    assert.strictEqual(security.ipAllowed('::1', ['127.0.0.1']), true);
});

check('authorizeJoin rejects numeric-only and stolen room tokens', () => {
    const crypto = require('crypto');
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const payload = '1.2.' + expires;
    const token = payload + '.' + crypto.createHmac('sha256', goodSecret).update(payload).digest('hex');
    assert.strictEqual(security.authorizeJoin(2, 1, goodSecret), 0);
    assert.strictEqual(security.authorizeJoin({ conversation_id: 2 }, 1, goodSecret), 0);
    assert.strictEqual(security.authorizeJoin({ conversation_id: 2, room_token: token }, 3, goodSecret), 0);
    assert.strictEqual(security.authorizeJoin({ conversation_id: 99, room_token: token }, 1, goodSecret), 0);
    assert.strictEqual(security.authorizeJoin({ conversation_id: 2, room_token: token }, 1, goodSecret), 2);
});

check('secretsMatch is timing-safe and rejects mismatches', () => {
    assert.strictEqual(security.secretsMatch(goodSecret, goodSecret), true);
    assert.strictEqual(security.secretsMatch('wrong-secret-value', goodSecret), false);
    assert.strictEqual(security.secretsMatch('', goodSecret), false);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P0 unit checks passed.');
