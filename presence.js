'use strict';

const LAST_SEEN_CAP = 5000;
const MAX_CONVERSATIONS = 50;

function PresenceRegistry() {
    this.users = new Map();
    this.sockets = new Map();
    this.lastSeen = new Map();
    this.offlineConversations = new Map();
}

PresenceRegistry.prototype._touchLastSeen = function (userId, at) {
    if (this.lastSeen.has(userId)) {
        this.lastSeen.delete(userId);
    }
    this.lastSeen.set(userId, at);
    while (this.lastSeen.size > LAST_SEEN_CAP) {
        const oldest = this.lastSeen.keys().next().value;
        this.lastSeen.delete(oldest);
        this.offlineConversations.delete(oldest);
    }
};

PresenceRegistry.prototype.connect = function (socketId, userId) {
    const sid = String(socketId || '');
    const uid = parseInt(userId, 10) || 0;
    if (sid === '' || uid <= 0) {
        return { userId: 0, socketCount: 0, transition: null, lastSeen: null };
    }
    if (this.sockets.has(sid)) {
        return this.snapshotUser(uid);
    }
    this.sockets.set(sid, uid);
    let entry = this.users.get(uid);
    if (!entry) {
        entry = { sockets: new Set(), conversations: new Set() };
        this.users.set(uid, entry);
    }
    const wasOnline = entry.sockets.size > 0;
    entry.sockets.add(sid);
    const saved = this.offlineConversations.get(uid);
    if (saved) {
        saved.forEach((id) => entry.conversations.add(id));
        this.offlineConversations.delete(uid);
    }
    return {
        userId: uid,
        socketCount: entry.sockets.size,
        transition: wasOnline ? null : 'online',
        lastSeen: this.lastSeen.get(uid) || null,
    };
};

PresenceRegistry.prototype.disconnect = function (socketId) {
    const sid = String(socketId || '');
    const uid = this.sockets.get(sid);
    if (!uid) {
        return { userId: 0, socketCount: 0, transition: null, lastSeen: null };
    }
    this.sockets.delete(sid);
    const entry = this.users.get(uid);
    if (!entry) {
        return { userId: uid, socketCount: 0, transition: 'offline', lastSeen: Date.now() };
    }
    entry.sockets.delete(sid);
    if (entry.sockets.size > 0) {
        return {
            userId: uid,
            socketCount: entry.sockets.size,
            transition: null,
            lastSeen: this.lastSeen.get(uid) || null,
        };
    }
    const at = Date.now();
    this._touchLastSeen(uid, at);
    this.offlineConversations.set(uid, new Set(entry.conversations));
    this.users.delete(uid);
    return {
        userId: uid,
        socketCount: 0,
        transition: 'offline',
        lastSeen: at,
        conversations: Array.from(this.offlineConversations.get(uid) || []),
    };
};

PresenceRegistry.prototype.joinConversation = function (userId, conversationId) {
    const uid = parseInt(userId, 10) || 0;
    const cid = parseInt(conversationId, 10) || 0;
    const entry = this.users.get(uid);
    if (!entry || cid <= 0) {
        return false;
    }
    if (entry.conversations.size >= MAX_CONVERSATIONS && !entry.conversations.has(cid)) {
        const first = entry.conversations.values().next().value;
        entry.conversations.delete(first);
    }
    entry.conversations.add(cid);
    return true;
};

PresenceRegistry.prototype.leaveConversation = function (userId, conversationId) {
    const uid = parseInt(userId, 10) || 0;
    const cid = parseInt(conversationId, 10) || 0;
    const entry = this.users.get(uid);
    if (!entry || cid <= 0) {
        return false;
    }
    return entry.conversations.delete(cid);
};

PresenceRegistry.prototype.conversationsOf = function (userId) {
    const uid = parseInt(userId, 10) || 0;
    const entry = this.users.get(uid);
    if (entry) {
        return Array.from(entry.conversations);
    }
    const offline = this.offlineConversations.get(uid);
    return offline ? Array.from(offline) : [];
};

PresenceRegistry.prototype.isOnline = function (userId) {
    const entry = this.users.get(parseInt(userId, 10) || 0);
    return !!(entry && entry.sockets.size > 0);
};

PresenceRegistry.prototype.socketCount = function (userId) {
    const entry = this.users.get(parseInt(userId, 10) || 0);
    return entry ? entry.sockets.size : 0;
};

PresenceRegistry.prototype.onlineCount = function () {
    return this.users.size;
};

PresenceRegistry.prototype.socketTotal = function () {
    return this.sockets.size;
};

PresenceRegistry.prototype.snapshotUser = function (userId) {
    const uid = parseInt(userId, 10) || 0;
    const online = this.isOnline(uid);
    return {
        userId: uid,
        socketCount: this.socketCount(uid),
        transition: null,
        lastSeen: online ? null : (this.lastSeen.get(uid) || null),
        status: online ? 'online' : 'offline',
    };
};

PresenceRegistry.prototype.snapshot = function (userIds) {
    const ids = Array.isArray(userIds) ? userIds : [];
    const out = [];
    const seen = {};
    ids.forEach((raw) => {
        const uid = parseInt(raw, 10) || 0;
        if (uid <= 0 || seen[uid]) {
            return;
        }
        seen[uid] = true;
        const online = this.isOnline(uid);
        out.push({
            user_id: uid,
            status: online ? 'online' : 'offline',
            last_seen: online ? null : (this.lastSeen.get(uid) || null),
        });
    });
    return out;
};

PresenceRegistry.prototype.snapshotForConversation = function (conversationId) {
    const cid = parseInt(conversationId, 10) || 0;
    if (cid <= 0) {
        return [];
    }
    const out = [];
    this.users.forEach((entry, uid) => {
        if (entry.conversations.has(cid) && entry.sockets.size > 0) {
            out.push({
                user_id: uid,
                status: 'online',
                last_seen: null,
            });
        }
    });
    this.offlineConversations.forEach((convs, uid) => {
        if (convs.has(cid) && !this.isOnline(uid)) {
            out.push({
                user_id: uid,
                status: 'offline',
                last_seen: this.lastSeen.get(uid) || null,
            });
        }
    });
    return out;
};

PresenceRegistry.prototype.publicPresence = function (userId) {
    const uid = parseInt(userId, 10) || 0;
    const online = this.isOnline(uid);
    return {
        user_id: uid,
        status: online ? 'online' : 'offline',
        last_seen: online ? null : (this.lastSeen.get(uid) || null),
    };
};

PresenceRegistry.prototype.clear = function () {
    this.users.clear();
    this.sockets.clear();
    this.lastSeen.clear();
    this.offlineConversations.clear();
};

module.exports = {
    PresenceRegistry,
    LAST_SEEN_CAP,
    MAX_CONVERSATIONS,
};
