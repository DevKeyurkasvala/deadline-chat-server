'use strict';

const assert = require('assert');
const crypto = require('crypto');
const security = require('./security');
const { PresenceRegistry } = require('./presence');

const goodSecret = 'a'.repeat(16) + 'unique-local-test-secret';

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

check('first socket marks user online', () => {
    const p = new PresenceRegistry();
    const result = p.connect('s1', 10);
    assert.strictEqual(result.transition, 'online');
    assert.strictEqual(p.isOnline(10), true);
    assert.strictEqual(p.socketCount(10), 1);
});

check('second socket stays online', () => {
    const p = new PresenceRegistry();
    p.connect('s1', 10);
    const second = p.connect('s2', 10);
    assert.strictEqual(second.transition, null);
    assert.strictEqual(p.isOnline(10), true);
    assert.strictEqual(p.socketCount(10), 2);
});

check('one socket disconnect keeps user online', () => {
    const p = new PresenceRegistry();
    p.connect('s1', 10);
    p.connect('s2', 10);
    const left = p.disconnect('s1');
    assert.strictEqual(left.transition, null);
    assert.strictEqual(p.isOnline(10), true);
    assert.strictEqual(p.socketCount(10), 1);
});

check('final socket disconnect marks offline and last_seen', () => {
    const p = new PresenceRegistry();
    p.connect('s1', 10);
    const left = p.disconnect('s1');
    assert.strictEqual(left.transition, 'offline');
    assert.strictEqual(p.isOnline(10), false);
    assert.ok(left.lastSeen > 0);
    const snap = p.snapshot([10]);
    assert.strictEqual(snap[0].status, 'offline');
    assert.ok(snap[0].last_seen > 0);
});

check('presence identity cannot be claimed for another user', () => {
    const p = new PresenceRegistry();
    p.connect('s1', 10);
    assert.strictEqual(p.isOnline(99), false);
    assert.strictEqual(p.snapshot([99])[0].status, 'offline');
});

check('conversation snapshot only includes joined users', () => {
    const p = new PresenceRegistry();
    p.connect('s1', 10);
    p.connect('s2', 11);
    p.joinConversation(10, 5);
    const snap = p.snapshotForConversation(5);
    const ids = snap.map((row) => row.user_id);
    assert.ok(ids.indexOf(10) !== -1);
    assert.ok(ids.indexOf(11) === -1);
});

check('Node restart / clear wipes runtime presence', () => {
    const p = new PresenceRegistry();
    p.connect('s1', 10);
    p.joinConversation(10, 5);
    p.clear();
    assert.strictEqual(p.isOnline(10), false);
    assert.strictEqual(p.onlineCount(), 0);
    assert.strictEqual(p.snapshot([10])[0].last_seen, null);
});

check('socket token identity is server-derived', () => {
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const auth = security.verifySocketToken(token(['7', String(expires), '1']), goodSecret);
    assert.strictEqual(auth.userId, 7);
});

check('chat:read is an allowed PHP emit event', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:read',
        rooms: ['conversation:2', 'user:1'],
        payload: { conversation_id: 2, user_id: 1, last_read_message_id: 9 },
    }), '');
});

check('presence:update cannot be injected via /internal/emit', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'presence:update',
        rooms: ['user:1'],
        payload: { user_id: 1, status: 'online' },
    }), 'Invalid event.');
});

check('presence query rejects empty or oversized user lists', () => {
    assert.strictEqual(security.validatePresenceQuery({ secret: 'x', user_ids: [] }), 'Invalid user_ids.');
    assert.strictEqual(security.validatePresenceQuery({
        secret: 'x',
        user_ids: new Array(51).fill(1),
    }), 'Invalid user_ids.');
});

check('presence query rejects non-numeric user ids', () => {
    assert.strictEqual(security.validatePresenceQuery({
        secret: 'x',
        user_ids: ['1; drop table'],
    }), 'Invalid user_ids.');
});

check('view-only socket still cannot mutate', () => {
    const expires = Math.floor(Date.now() / 1000) + 3600;
    const auth = security.verifySocketToken(token(['3', String(expires), '0']), goodSecret);
    assert.strictEqual(auth.canMutate, false);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P3 unit checks passed.');
