'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

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

const js = fs.readFileSync(path.join(__dirname, '../assets/dist/js/chat.js'), 'utf8');
const page = fs.readFileSync(path.join(__dirname, '../chat.php'), 'utf8');

check('search results still escape HTML', () => {
    assert.ok(js.indexOf('escapeHtml(c.title)') !== -1);
    assert.ok(js.indexOf('escapeHtml(m.snippet') !== -1);
});
check('mentions insert usernames not raw HTML', () => {
    assert.ok(js.indexOf("replace(/@([A-Za-z0-9._-]{0,40})$/, '@' + username + ' ')") !== -1);
});
check('attachment URLs stay on download API', () => {
    assert.ok(js.indexOf('download_attachment.php?id=') !== -1);
    assert.ok(js.indexOf('storage/chat') === -1);
});
check('composer labels remain visible', () => {
    assert.ok(page.indexOf('>Attach<') !== -1);
    assert.ok(page.indexOf('>Send<') !== -1);
});

if (failed) {
    process.exit(1);
}
console.log('All Node P8 unit checks passed.');
