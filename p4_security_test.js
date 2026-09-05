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

check('chat:reaction is an allowed PHP emit event', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:reaction',
        rooms: ['conversation:2'],
        payload: { message_id: 1, reactions: {} },
    }), '');
});

check('chat:message_edited is an allowed PHP emit event', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:message_edited',
        rooms: ['conversation:2'],
        payload: { message_id: 1, body: 'x' },
    }), '');
});

check('chat:message_deleted is an allowed PHP emit event', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:message_deleted',
        rooms: ['conversation:2'],
        payload: { message_id: 1, deleted_at: 'now' },
    }), '');
});

check('browser-forged attachment events are not emit-allowed', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:attachment_uploaded',
        rooms: ['user:1'],
        payload: {},
    }), 'Invalid event.');
});

const server = fs.readFileSync(path.join(__dirname, 'server.js'), 'utf8');
check('Node does not accept client-authored reaction/edit/delete', () => {
    assert.ok(server.indexOf("socket.on('chat:reaction'") === -1);
    assert.ok(server.indexOf("socket.on('chat:message_edited'") === -1);
    assert.ok(server.indexOf("socket.on('chat:message_deleted'") === -1);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P4 unit checks passed.');
