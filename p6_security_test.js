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

check('browser cannot emit trusted audit events', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x',
        event: 'chat:audit',
        rooms: ['conversation:1'],
        payload: {},
    }), 'Invalid event.');
});

const worker = fs.readFileSync(path.join(__dirname, '../cron/chat_retention_worker.php'), 'utf8');
check('retention worker refuses non-CLI execution', () => {
    assert.ok(worker.indexOf("PHP_SAPI !== 'cli'") !== -1);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P6 unit checks passed.');
