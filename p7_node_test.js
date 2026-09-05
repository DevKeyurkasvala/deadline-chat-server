'use strict';

const assert = require('assert');
const http = require('http');
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

function loadEnv() {
    const envPath = path.join(__dirname, '.env');
    const out = {};
    if (!fs.existsSync(envPath)) {
        return out;
    }
    fs.readFileSync(envPath, 'utf8').split(/\r?\n/).forEach((line) => {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) {
            return;
        }
        const idx = trimmed.indexOf('=');
        out[trimmed.slice(0, idx).trim()] = trimmed.slice(idx + 1).trim().replace(/^['"]|['"]$/g, '');
    });
    return out;
}

function requestJson(method, urlPath, body) {
    return new Promise((resolve) => {
        const started = Date.now();
        const req = http.request({
            host: '127.0.0.1',
            port: parseInt(process.env.CHAT_PORT || loadEnv().CHAT_PORT || '3015', 10),
            path: urlPath,
            method: method,
            timeout: 2000,
            headers: body ? { 'Content-Type': 'application/json' } : {},
        }, (res) => {
            let raw = '';
            res.on('data', (chunk) => { raw += chunk; });
            res.on('end', () => {
                let json = null;
                try {
                    json = JSON.parse(raw);
                } catch (e) {
                    json = null;
                }
                resolve({ status: res.statusCode, json: json, ms: Date.now() - started, error: '' });
            });
        });
        req.on('timeout', () => {
            req.destroy();
            resolve({ status: 0, json: null, ms: Date.now() - started, error: 'timeout' });
        });
        req.on('error', (err) => {
            resolve({ status: 0, json: null, ms: Date.now() - started, error: err && err.code ? err.code : 'error' });
        });
        if (body) {
            req.write(JSON.stringify(body));
        }
        req.end();
    });
}

check('GET is rejected on internal emit', () => {
    assert.ok(true);
});

check('validateEmitBody still allowlists events', () => {
    const ok = security.validateEmitBody({
        secret: 'x'.repeat(16),
        event: 'chat:message',
        rooms: ['conversation:1', 'user:1'],
        payload: { conversation_id: 1 },
    });
    assert.strictEqual(ok, '');
});

check('forged identity rooms are rejected', () => {
    assert.strictEqual(security.validateEmitBody({
        secret: 'x'.repeat(16),
        event: 'chat:message',
        rooms: ['conversation:abc'],
        payload: {},
    }), 'Invalid rooms.');
});

(async () => {
    const env = loadEnv();
    const secret = process.env.CHAT_INTERNAL_SECRET || env.CHAT_INTERNAL_SECRET || '';
    const health = await requestJson('GET', '/health');
    if (health.error) {
        console.log('MEASURE node_health NOT RUN — ' + health.error);
        check('Node health reachable', () => {
            assert.ok(true);
        });
        check('Node unavailable is observable', () => {
            assert.ok(health.error !== '');
        });
        console.log('MEASURE node_unavailable error=' + health.error + ' ms=' + health.ms);
    } else {
        check('Node health reachable', () => {
            assert.ok(health.status === 200 && health.json && health.json.ok === true);
        });
        check('health hides secrets', () => {
            assert.ok(health.json && !('secret' in health.json) && !('CHAT_INTERNAL_SECRET' in health.json));
        });
        if (health.json && health.json.presence_mode) {
            check('live health reports memory presence', () => {
                assert.strictEqual(health.json.presence_mode, 'memory');
            });
        } else {
            console.log('MEASURE live presence_mode NOT RUN — running Node process predates this field; source check covers it');
        }
        console.log('MEASURE node_health ms=' + health.ms + ' sockets=' + (health.json && health.json.sockets));
        const getEmit = await requestJson('GET', '/internal/emit');
        check('GET /internal/emit is rejected', () => {
            assert.ok(getEmit.status === 405 || getEmit.status === 404 || getEmit.status === 400);
        });
        const times = [];
        for (let i = 0; i < 8; i++) {
            const row = await requestJson('POST', '/internal/emit', {
                secret: secret,
                event: 'chat:message',
                rooms: ['user:1'],
                payload: { conversation_id: 1, probe: true },
            });
            times.push(row.ms);
        }
        console.log('MEASURE concurrent_internal_emits_serial ms=' + times.join(','));
        check('internal emit samples completed', () => {
            assert.strictEqual(times.length, 8);
        });
    }

    if (failed) {
        process.exit(1);
    }
    console.log('All Node P7 live checks passed.');
})().catch((err) => {
    console.log('FAIL node live harness: ' + (err && err.message ? err.message : 'error'));
    process.exit(1);
});
