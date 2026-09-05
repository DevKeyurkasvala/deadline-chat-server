'use strict';

const crypto = require('crypto');

const FORBIDDEN_SECRETS = [
    'deadline-chat-local-dev-secret',
    'CHANGE_ME_CHAT_SECRET',
    'changeme',
    'secret',
];

const ALLOWED_EMIT_EVENTS = [
    'chat:message',
    'notification:new',
    'chat:read',
    'chat:reaction',
    'chat:message_edited',
    'chat:message_deleted',
];
const ROOM_PATTERN = /^(user|conversation):\d+$/;

function secretIsConfigured(secret) {
    if (typeof secret !== 'string' || secret.length < 16) {
        return false;
    }
    const lower = secret.toLowerCase();
    return FORBIDDEN_SECRETS.every((bad) => bad.toLowerCase() !== lower);
}

function hmacHex(payload, secret) {
    return crypto.createHmac('sha256', secret).update(payload).digest('hex');
}

function safeEqualHex(a, b) {
    const left = Buffer.from(String(a));
    const right = Buffer.from(String(b));
    if (left.length !== right.length) {
        return false;
    }
    return crypto.timingSafeEqual(left, right);
}

function verifySocketToken(token, secret) {
    if (!secretIsConfigured(secret) || typeof token !== 'string') {
        return null;
    }
    const parts = token.split('.');
    if (parts.length !== 4) {
        return null;
    }
    const [userId, expires, canMutate, sig] = parts;
    if (!/^\d+$/.test(userId) || !/^\d+$/.test(expires) || (canMutate !== '0' && canMutate !== '1')) {
        return null;
    }
    if (parseInt(expires, 10) < Math.floor(Date.now() / 1000)) {
        return null;
    }
    const payload = `${userId}.${expires}.${canMutate}`;
    if (!safeEqualHex(hmacHex(payload, secret), sig)) {
        return null;
    }
    return {
        userId: parseInt(userId, 10),
        canMutate: canMutate === '1',
    };
}

function verifyRoomToken(token, secret, userId, conversationId) {
    if (!secretIsConfigured(secret) || typeof token !== 'string') {
        return false;
    }
    const parts = token.split('.');
    if (parts.length !== 4) {
        return false;
    }
    const [tokenUser, tokenConv, expires, sig] = parts;
    if (!/^\d+$/.test(tokenUser) || !/^\d+$/.test(tokenConv) || !/^\d+$/.test(expires)) {
        return false;
    }
    if (parseInt(tokenUser, 10) !== userId || parseInt(tokenConv, 10) !== conversationId) {
        return false;
    }
    if (parseInt(expires, 10) < Math.floor(Date.now() / 1000)) {
        return false;
    }
    const payload = `${tokenUser}.${tokenConv}.${expires}`;
    return safeEqualHex(hmacHex(payload, secret), sig);
}

function clientIp(req) {
    const raw = req.socket && req.socket.remoteAddress ? req.socket.remoteAddress : '';
    return raw.replace(/^::ffff:/, '');
}

function secretsMatch(provided, expected) {
    if (typeof provided !== 'string' || typeof expected !== 'string' || provided === '' || expected === '') {
        return false;
    }
    if (!secretIsConfigured(expected)) {
        return false;
    }
    return safeEqualHex(hmacHex('deadline-chat-emit', provided), hmacHex('deadline-chat-emit', expected));
}

function authorizeJoin(payload, userId, secret) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return 0;
    }
    const conversationId = parseInt(payload.conversation_id, 10);
    const roomToken = typeof payload.room_token === 'string' ? payload.room_token : '';
    if (!Number.isFinite(conversationId) || conversationId <= 0 || userId <= 0) {
        return 0;
    }
    if (!verifyRoomToken(roomToken, secret, userId, conversationId)) {
        return 0;
    }
    return conversationId;
}

function ipAllowed(ip, allowList) {
    if (!allowList || allowList.length === 0) {
        return true;
    }
    if (allowList.indexOf(ip) !== -1) {
        return true;
    }
    if ((ip === '127.0.0.1' || ip === '::1') &&
        (allowList.indexOf('127.0.0.1') !== -1 || allowList.indexOf('::1') !== -1)) {
        return true;
    }
    return false;
}

function validatePresenceQuery(body) {
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
        return 'Invalid payload.';
    }
    if (typeof body.secret !== 'string' || body.secret === '') {
        return 'Unauthorized.';
    }
    if (!Array.isArray(body.user_ids) || body.user_ids.length === 0 || body.user_ids.length > 50) {
        return 'Invalid user_ids.';
    }
    const idsOk = body.user_ids.every((id) => {
        if (typeof id === 'number') {
            return Number.isInteger(id) && id > 0;
        }
        return typeof id === 'string' && /^\d+$/.test(id) && parseInt(id, 10) > 0;
    });
    if (!idsOk) {
        return 'Invalid user_ids.';
    }
    return '';
}

function validateEmitBody(body) {
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
        return 'Invalid payload.';
    }
    if (typeof body.secret !== 'string' || body.secret === '') {
        return 'Unauthorized.';
    }
    if (typeof body.event !== 'string' || ALLOWED_EMIT_EVENTS.indexOf(body.event) === -1) {
        return 'Invalid event.';
    }
    if (!Array.isArray(body.rooms) || body.rooms.length === 0 || body.rooms.length > 50) {
        return 'Invalid rooms.';
    }
    const roomsOk = body.rooms.every((room) => typeof room === 'string' && ROOM_PATTERN.test(room));
    if (!roomsOk) {
        return 'Invalid rooms.';
    }
    if (body.payload != null && (typeof body.payload !== 'object' || Array.isArray(body.payload))) {
        return 'Invalid payload.';
    }
    return '';
}

module.exports = {
    secretIsConfigured,
    secretsMatch,
    verifySocketToken,
    verifyRoomToken,
    authorizeJoin,
    clientIp,
    ipAllowed,
    validateEmitBody,
    validatePresenceQuery,
    ALLOWED_EMIT_EVENTS,
};
