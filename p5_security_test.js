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

check('browser cannot emit chat:mention as trusted state', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:mention',
        rooms: ['conversation:2'],
        payload: { mentioned_user_id: 9 },
    }), 'Invalid event.');
});

check('browser cannot emit project/client room join events', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:join_project',
        rooms: ['project:1'],
        payload: {},
    }), 'Invalid event.');
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:join_client',
        rooms: ['client:1'],
        payload: {},
    }), 'Invalid event.');
});

check('rooms still have to be conversation or user rooms', () => {
    const err = security.validateEmitBody({
        secret: 'x',
        event: 'chat:message',
        rooms: ['project:12'],
        payload: { id: 1 },
    });
    assert.notStrictEqual(err, '');
});

const server = fs.readFileSync(path.join(__dirname, 'server.js'), 'utf8');
check('Node does not listen for client-authored mention/project/client events', () => {
    assert.ok(server.indexOf("socket.on('chat:mention'") === -1);
    assert.ok(server.indexOf("socket.on('join_project'") === -1);
    assert.ok(server.indexOf("socket.on('join_client'") === -1);
});

check('authorizeJoin still requires a signed room token', () => {
    assert.ok(server.indexOf('authorizeJoin') !== -1);
    const sec = fs.readFileSync(path.join(__dirname, 'security.js'), 'utf8');
    assert.ok(sec.indexOf('verifyRoomToken') !== -1);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P5 unit checks passed.');
