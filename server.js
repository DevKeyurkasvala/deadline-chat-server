'use strict';

const fs = require('fs');
const path = require('path');
const http = require('http');
const express = require('express');
const cors = require('cors');
const { Server } = require('socket.io');
const security = require('./security');
const { PresenceRegistry } = require('./presence');

function loadEnv() {
    const envPath = path.join(__dirname, '.env');
    const out = {};
    if (!fs.existsSync(envPath)) {
        return out;
    }
    const lines = fs.readFileSync(envPath, 'utf8').split(/\r?\n/);
    lines.forEach((line) => {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) {
            return;
        }
        const idx = trimmed.indexOf('=');
        const key = trimmed.slice(0, idx).trim();
        const value = trimmed.slice(idx + 1).trim().replace(/^['"]|['"]$/g, '');
        out[key] = value;
    });
    return out;
}

const env = loadEnv();
// Render injects PORT. CHAT_PORT / 3015 are local-development fallbacks only.
const PORT = parseInt(process.env.PORT || process.env.CHAT_PORT || env.CHAT_PORT || '3015', 10);
const BIND = process.env.CHAT_BIND || env.CHAT_BIND || '0.0.0.0';
const SECRET = process.env.CHAT_INTERNAL_SECRET || env.CHAT_INTERNAL_SECRET || '';
const CORS_ORIGINS = String(process.env.CHAT_CORS_ORIGINS || env.CHAT_CORS_ORIGINS || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
const EMIT_ALLOW_IPS = String(process.env.CHAT_EMIT_ALLOW_IPS || env.CHAT_EMIT_ALLOW_IPS || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);

if (!security.secretIsConfigured(SECRET)) {
    console.error('Chat server refused to start: CHAT_INTERNAL_SECRET is missing, too short, or a forbidden placeholder.');
    process.exit(1);
}

function corsOrigin(origin, callback) {
    if (!origin) {
        callback(null, true);
        return;
    }
    if (CORS_ORIGINS.length === 0) {
        callback(null, true);
        return;
    }
    if (CORS_ORIGINS.indexOf(origin) !== -1 || CORS_ORIGINS.indexOf('*') !== -1) {
        callback(null, true);
        return;
    }
    callback(new Error('Origin not allowed'));
}

const app = express();
app.set('trust proxy', 1);
app.use(cors({ origin: corsOrigin, credentials: true }));
app.use(express.json({ limit: '64kb' }));

const startedAt = Date.now();
const presence = new PresenceRegistry();
let shuttingDown = false;

function logSafe(scope, event, fields) {
    const row = Object.assign({ scope: scope, event: event }, fields || {});
    console.log(JSON.stringify(row));
}

app.get('/health', (_req, res) => {
    res.json({
        ok: true,
        service: 'deadline-chat',
        uptime_sec: Math.floor((Date.now() - startedAt) / 1000),
        sockets: presence.socketTotal(),
        online_users: presence.onlineCount(),
        presence_mode: 'memory',
        multi_instance: false,
    });
});

const server = http.createServer(app);
const io = new Server(server, {
    path: '/socket.io/',
    cors: { origin: corsOrigin, credentials: true },
    pingInterval: 20000,
    pingTimeout: 20000,
    allowEIO3: true,
});

function emitPresence(userId, extraRooms) {
    const payload = presence.publicPresence(userId);
    if (!payload.user_id) {
        return;
    }
    const rooms = presence.conversationsOf(userId).map((id) => 'conversation:' + id);
    rooms.push('user:' + userId);
    if (Array.isArray(extraRooms)) {
        extraRooms.forEach((room) => {
            if (room) {
                rooms.push(room);
            }
        });
    }
    Array.from(new Set(rooms)).forEach((room) => {
        io.to(room).emit('presence:update', payload);
    });
}

io.use((socket, next) => {
    if (shuttingDown) {
        next(new Error('Unavailable'));
        return;
    }
    const token = socket.handshake.auth && socket.handshake.auth.token
        ? socket.handshake.auth.token
        : socket.handshake.query.token;
    const auth = security.verifySocketToken(String(token || ''), SECRET);
    if (!auth || auth.userId <= 0) {
        next(new Error('Unauthorized'));
        return;
    }
    socket.data.userId = auth.userId;
    socket.data.canMutate = !!auth.canMutate;
    next();
});

io.on('connection', (socket) => {
    const userId = socket.data.userId;
    socket.join('user:' + userId);
    const connected = presence.connect(socket.id, userId);
    logSafe('chat_socket', 'socket_connected', {
        user_id: userId,
        socket_count: connected.socketCount,
    });
    if (connected.transition === 'online') {
        logSafe('chat_presence', 'presence_transition', {
            user_id: userId,
            status: 'online',
        });
        emitPresence(userId);
    }

    socket.on('chat:join', (payload) => {
        try {
            const conversationId = security.authorizeJoin(payload, userId, SECRET);
            if (conversationId <= 0) {
                const requested = payload && typeof payload === 'object' ? parseInt(payload.conversation_id, 10) : 0;
                if (requested > 0) {
                    console.warn('chat:join denied user=' + userId + ' conversation=' + requested);
                }
                return;
            }
            socket.join('conversation:' + conversationId);
            presence.joinConversation(userId, conversationId);
            io.to('conversation:' + conversationId).emit('presence:update', presence.publicPresence(userId));
            socket.emit('presence:snapshot', {
                conversation_id: conversationId,
                users: presence.snapshotForConversation(conversationId),
            });
        } catch (err) {
            console.warn('chat:join error user=' + userId);
        }
    });

    socket.on('chat:leave', (payload) => {
        try {
            const conversationId = typeof payload === 'object' && payload
                ? parseInt(payload.conversation_id, 10)
                : parseInt(payload, 10);
            if (conversationId > 0) {
                socket.leave('conversation:' + conversationId);
            }
        } catch (err) {
            console.warn('chat:leave error user=' + userId);
        }
    });

    socket.on('chat:typing', (payload) => {
        try {
            if (!socket.data.canMutate) {
                return;
            }
            if (!payload || typeof payload !== 'object') {
                return;
            }
            const conversationId = parseInt(payload.conversation_id, 10);
            if (conversationId <= 0) {
                return;
            }
            if (!socket.rooms.has('conversation:' + conversationId)) {
                return;
            }
            socket.to('conversation:' + conversationId).emit('chat:typing', {
                conversation_id: conversationId,
                user_id: userId,
                user_name: payload.user_name ? String(payload.user_name).slice(0, 80) : '',
                is_typing: !!payload.is_typing,
            });
        } catch (err) {
            console.warn('chat:typing error user=' + userId);
        }
    });

    socket.on('disconnect', () => {
        const left = presence.disconnect(socket.id);
        logSafe('chat_socket', 'socket_disconnected', {
            user_id: userId,
            socket_count: left.socketCount,
        });
        if (left.transition === 'offline') {
            logSafe('chat_presence', 'presence_transition', {
                user_id: userId,
                status: 'offline',
            });
            emitPresence(userId, (left.conversations || []).map((id) => 'conversation:' + id));
        }
    });
});

app.use('/internal/emit', (req, res, next) => {
    if (req.method !== 'POST') {
        res.status(405).json({ ok: false });
        return;
    }
    next();
});

app.post('/internal/emit', (req, res) => {
    const contentType = String(req.headers['content-type'] || '');
    if (contentType.indexOf('application/json') === -1) {
        res.status(415).json({ ok: false });
        return;
    }
    const ip = security.clientIp(req);
    if (!security.ipAllowed(ip, EMIT_ALLOW_IPS)) {
        console.warn('internal/emit denied: source not allowed');
        res.status(403).json({ ok: false });
        return;
    }
    const reason = security.validateEmitBody(req.body);
    if (reason === 'Unauthorized.') {
        console.warn('internal/emit denied: missing secret');
        res.status(403).json({ ok: false });
        return;
    }
    if (reason) {
        console.warn('internal/emit denied: invalid payload');
        res.status(400).json({ ok: false });
        return;
    }
    if (!security.secretsMatch(String(req.body.secret || ''), SECRET)) {
        console.warn('internal/emit denied: secret mismatch');
        res.status(403).json({ ok: false });
        return;
    }
    req.body.rooms.forEach((room) => {
        io.to(room).emit(req.body.event, req.body.payload || {});
    });
    res.json({ ok: true });
});

app.post('/internal/presence', (req, res) => {
    const contentType = String(req.headers['content-type'] || '');
    if (contentType.indexOf('application/json') === -1) {
        res.status(415).json({ ok: false });
        return;
    }
    const ip = security.clientIp(req);
    if (!security.ipAllowed(ip, EMIT_ALLOW_IPS)) {
        console.warn('internal/presence denied: source not allowed');
        res.status(403).json({ ok: false });
        return;
    }
    const reason = security.validatePresenceQuery(req.body);
    if (reason === 'Unauthorized.') {
        console.warn('internal/presence denied: missing secret');
        res.status(403).json({ ok: false });
        return;
    }
    if (reason) {
        res.status(400).json({ ok: false });
        return;
    }
    if (!security.secretsMatch(String(req.body.secret || ''), SECRET)) {
        console.warn('internal/presence denied: secret mismatch');
        res.status(403).json({ ok: false });
        return;
    }
    res.json({
        ok: true,
        users: presence.snapshot(req.body.user_ids),
    });
});

app.use((err, _req, res, _next) => {
    if (!err) {
        res.status(400).json({ ok: false });
        return;
    }
    if (err.type === 'entity.too.large' || err.status === 413) {
        res.status(413).json({ ok: false });
        return;
    }
    res.status(400).json({ ok: false });
});

server.requestTimeout = 10000;
server.headersTimeout = 11000;
// Idle socket timeout must exceed Socket.IO pingInterval (20s) or WSS drops.
server.timeout = 120000;

function shutdown(signal) {
    if (shuttingDown) {
        return;
    }
    shuttingDown = true;
    logSafe('chat_socket', 'shutdown', { signal: String(signal) });
    io.close();
    presence.clear();
    server.close(() => {
        process.exit(0);
    });
    setTimeout(() => {
        process.exit(1);
    }, 5000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('uncaughtException', (err) => {
    console.error('uncaughtException: ' + (err && err.message ? err.message : 'error'));
});
process.on('unhandledRejection', () => {
    console.error('unhandledRejection');
});

server.listen(PORT, BIND, () => {
    if (CORS_ORIGINS.length === 0 || CORS_ORIGINS.indexOf('*') !== -1) {
        console.warn('CHAT_CORS_ORIGINS is empty or wildcard; set explicit PHP origins in production.');
    }
    if (EMIT_ALLOW_IPS.length === 0) {
        console.log('Deadline chat Socket.IO listening on ' + BIND + ':' + PORT + ' (emit allowlist unset; secret-only)');
    } else {
        console.log('Deadline chat Socket.IO listening on ' + BIND + ':' + PORT + ' (emit allowlist enabled)');
    }
});
