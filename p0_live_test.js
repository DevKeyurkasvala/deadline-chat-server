'use strict';

const fs = require('fs');
const http = require('http');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');
const security = require('./security');

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

const env = loadEnv();
const SECRET = process.env.CHAT_INTERNAL_SECRET || env.CHAT_INTERNAL_SECRET || '';
const PORT = parseInt(process.env.CHAT_PORT || env.CHAT_PORT || '3015', 10);
const BASE = 'http://127.0.0.1:' + PORT;

let failed = 0;
function pass(name) {
    console.log('PASS ' + name);
}
function fail(name, detail) {
    failed += 1;
    console.log('FAIL ' + name + (detail ? ': ' + detail : ''));
}

function request(method, urlPath, options) {
    const opts = options || {};
    return new Promise((resolve) => {
        const req = http.request({
            hostname: '127.0.0.1',
            port: PORT,
            path: urlPath,
            method,
            headers: opts.headers || {},
            timeout: 4000,
        }, (res) => {
            let body = '';
            res.on('data', (chunk) => {
                body += chunk;
            });
            res.on('end', () => {
                resolve({ status: res.statusCode, body });
            });
        });
        req.on('error', (err) => resolve({ status: 0, body: '', error: err.message }));
        req.on('timeout', () => {
            req.destroy();
            resolve({ status: 0, body: '', error: 'timeout' });
        });
        if (opts.body != null) {
            req.write(opts.body);
        }
        req.end();
    });
}

function hmacToken(parts) {
    const payload = parts.join('.');
    return payload + '.' + crypto.createHmac('sha256', SECRET).update(payload).digest('hex');
}

async function main() {
    if (!security.secretIsConfigured(SECRET)) {
        fail('live secret is configured for PHP/Node');
        process.exit(1);
    }
    pass('live secret is configured and is not a forbidden placeholder');

    const missing = spawn(process.execPath, [path.join(__dirname, 'server.js')], {
        env: { PATH: process.env.PATH, CHAT_INTERNAL_SECRET: 'CHANGE_ME_CHAT_SECRET', CHAT_PORT: '39991' },
        cwd: __dirname,
    });
    const missingCode = await new Promise((resolve) => {
        missing.on('exit', (code) => resolve(code));
        setTimeout(() => {
            missing.kill('SIGTERM');
            resolve(-1);
        }, 4000);
    });
    if (missingCode === 1) {
        pass('missing/placeholder secret refuses to start');
    } else {
        fail('missing/placeholder secret refuses to start', 'exit=' + missingCode);
    }

    const health = await request('GET', '/health');
    if (health.status === 200 && health.body.indexOf('deadline-chat') !== -1 && health.body.indexOf(SECRET) === -1) {
        pass('health does not expose secret');
    } else {
        fail('health does not expose secret', 'status=' + health.status);
    }

    const validBody = JSON.stringify({
        secret: SECRET,
        event: 'chat:message',
        rooms: ['user:1'],
        payload: { conversation_id: 2, message: { id: 0 } },
    });
    const valid = await request('POST', '/internal/emit', {
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(validBody) },
        body: validBody,
    });
    if (valid.status === 200 && valid.body.indexOf('"ok":true') !== -1) {
        pass('valid PHP-style emit works');
    } else {
        fail('valid PHP-style emit works', 'status=' + valid.status);
    }

    const wrong = await request('POST', '/internal/emit', {
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            secret: 'wrong-secret-value-16x',
            event: 'chat:message',
            rooms: ['user:1'],
            payload: {},
        }),
    });
    if (wrong.status === 403 && wrong.body.indexOf(SECRET) === -1) {
        pass('wrong secret is rejected without leaking details');
    } else {
        fail('wrong secret is rejected without leaking details', 'status=' + wrong.status);
    }

    const malformed = await request('POST', '/internal/emit', {
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ secret: SECRET, event: 'chat:typing', rooms: ['user:1'] }),
    });
    if (malformed.status === 400) {
        pass('malformed emit event is rejected');
    } else {
        fail('malformed emit event is rejected', 'status=' + malformed.status);
    }

    const badType = await request('POST', '/internal/emit', {
        headers: { 'Content-Type': 'text/plain' },
        body: 'secret=' + SECRET,
    });
    if (badType.status === 415) {
        pass('non-JSON emit is rejected');
    } else {
        fail('non-JSON emit is rejected', 'status=' + badType.status);
    }

    const method = await request('GET', '/internal/emit');
    if (method.status === 405) {
        pass('non-POST emit is rejected');
    } else {
        fail('non-POST emit is rejected', 'status=' + method.status);
    }

    const noSecret = await request('POST', '/internal/emit', {
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event: 'chat:message', rooms: ['user:1'], payload: {} }),
    });
    if (noSecret.status === 403) {
        pass('unauthorized emit without secret is rejected');
    } else {
        fail('unauthorized emit without secret is rejected', 'status=' + noSecret.status);
    }

    const userA = hmacToken(['1', String(Math.floor(Date.now() / 1000) + 3600), '1']);
    const userB = hmacToken(['99', String(Math.floor(Date.now() / 1000) + 3600), '1']);
    const roomA = hmacToken(['1', '2', String(Math.floor(Date.now() / 1000) + 3600)]);
    const stolen = roomA;
    if (security.verifySocketToken(userA, SECRET) && security.verifySocketToken(userB, SECRET)) {
        pass('authorized and unauthorized socket tokens verify independently');
    } else {
        fail('authorized and unauthorized socket tokens verify independently');
    }
    if (security.authorizeJoin({ conversation_id: 2, room_token: roomA }, 1, SECRET) === 2) {
        pass('user A can join own authorized room');
    } else {
        fail('user A can join own authorized room');
    }
    if (security.authorizeJoin({ conversation_id: 2, room_token: stolen }, 99, SECRET) === 0) {
        pass('user B cannot join user A room with stolen/mismatched token');
    } else {
        fail('user B cannot join user A room with stolen/mismatched token');
    }
    if (security.authorizeJoin({ conversation_id: 2 }, 99, SECRET) === 0) {
        pass('user B cannot join by conversation id alone');
    } else {
        fail('user B cannot join by conversation id alone');
    }

    if (failed) {
        process.exit(1);
    }
    console.log('All live P0 checks passed.');
}

main().catch((err) => {
    console.error('Live P0 test failed.');
    process.exit(1);
});
