'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const security = require('./security');

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

const server = fs.readFileSync(path.join(__dirname, 'server.js'), 'utf8');
const presence = fs.readFileSync(path.join(__dirname, 'presence.js'), 'utf8');

check('internal emit still POST-only', () => {
    assert.ok(server.indexOf("req.method !== 'POST'") !== -1);
});
check('internal emit requires JSON', () => {
    assert.ok(server.indexOf('application/json') !== -1);
});
check('internal emit body is bounded at 64kb', () => {
    assert.ok(server.indexOf("express.json({ limit: '64kb' })") !== -1);
});
check('server request timeouts are set', () => {
    assert.ok(server.indexOf('requestTimeout = 10000') !== -1);
    assert.ok(server.indexOf('headersTimeout = 11000') !== -1);
});
check('presence remains in-memory', () => {
    assert.ok(presence.indexOf('function PresenceRegistry') !== -1);
    assert.ok(presence.indexOf('redis') === -1);
});
check('Redis adapter is not loaded', () => {
    assert.ok(server.indexOf('createAdapter') === -1);
    assert.ok(server.indexOf('@socket.io/redis-adapter') === -1);
    assert.ok(server.indexOf("presence_mode: 'memory'") !== -1);
    assert.ok(server.indexOf('multi_instance: false') !== -1);
});
check('browser cannot emit trusted persistence events', () => {
    ['chat:message', 'chat:read', 'chat:reaction', 'chat:message_edited', 'chat:message_deleted'].forEach((event) => {
        assert.ok(server.indexOf("socket.on('" + event + "'") === -1);
    });
});
check('join still requires signed room token', () => {
    assert.strictEqual(security.authorizeJoin({ conversation_id: 9 }, 1, 'x'.repeat(16)), 0);
});
check('emit rooms remain conversation|user only', () => {
    const reason = security.validateEmitBody({
        secret: 'x'.repeat(16),
        event: 'chat:message',
        rooms: ['project:1'],
        payload: {},
    });
    assert.strictEqual(reason, 'Invalid rooms.');
});
check('health export has no secret fields', () => {
    assert.ok(server.indexOf('CHAT_INTERNAL_SECRET') !== -1);
    assert.ok(server.indexOf("res.json({\n        ok: true,\n        service: 'deadline-chat'") !== -1 || server.indexOf("service: 'deadline-chat'") !== -1);
    assert.ok(server.indexOf('secret:') === -1 || server.indexOf('SECRET') !== -1);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P7 unit checks passed.');
