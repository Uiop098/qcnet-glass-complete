-- =============================================================================
-- schema.sql — Shared multi-domain database schema
-- =============================================================================
-- One database, shared by every domain deployment of this app. Room names
-- are NOT domain-prefixed — a room's password is its security boundary
-- (confirmed design decision), so two domains can have a room with the
-- same name and it's just two unrelated rows here.
--
-- Design approach: high-traffic, frequently-queried data (messages,
-- sessions, presence) gets real indexed columns. Everything else that
-- was a nested field in the old JSON config (banned users, muted users,
-- room theme, polls, scheduled messages, etc.) stays as a JSON column
-- on the `rooms` row — normalizing all of that into 15+ extra tables
-- would be a much larger, slower-to-ship rewrite for little real
-- performance gain, since those fields are read/written as a unit
-- alongside the room anyway (this is what read_config()/write_config()
-- did with the old JSON file, and that access pattern doesn't change
-- just because the storage engine did).
--
-- Run this once against a fresh database before deploying db.php.
-- Safe to re-run — every statement uses IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS rooms (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room                VARCHAR(190) NOT NULL,           -- the room slug, as typed by users — same as the old JSON filename
    title               VARCHAR(255) NOT NULL DEFAULT '',
    pin_hash            VARCHAR(255) NULL,
    host_password_hash  VARCHAR(255) NULL,
    enc_enabled         TINYINT(1) NOT NULL DEFAULT 0,
    enc_salt            VARCHAR(255) NULL,
    enc_super_recovery  TEXT NULL,
    is_premium          TINYINT(1) NOT NULL DEFAULT 0,
    premium_owner       VARCHAR(190) NULL,               -- lowercase username of the tenant/master super who created it
    premium_tier        VARCHAR(20) NULL,                -- 'tenant' or 'master'
    pinned_message_id   BIGINT UNSIGNED NULL,
    created_at          BIGINT UNSIGNED NOT NULL,          -- ms epoch, matches now_ms() in common.php
    last_activity       BIGINT UNSIGNED NULL,
    pending_delete_at   BIGINT UNSIGNED NULL,              -- set by the existing soft-delete/undo flow
    -- Everything else that used to be a JSON config field and doesn't
    -- need its own indexed column: bannedUsers, bannedIps, userIps,
    -- mutedUsers, roomTheme, requireFileApproval, fileRequests,
    -- extraUploads, bannedWords, slowModeSeconds, lastMessageAtByUser,
    -- lastMessageTextByUser, reports, coHosts, coSupers, hostNotifyEmail,
    -- hostLastDigestAt, scheduledMessages, polls, autoCleanupDays,
    -- premiumPerks. Kept as one JSON blob — read_config()/write_config()
    -- in db.php merge this with the real columns above into the exact
    -- same associative array shape the rest of the app already expects,
    -- so nothing outside the storage layer needs to change.
    extra_json          LONGTEXT NULL,
    UNIQUE KEY uniq_room (room),
    KEY idx_last_activity (last_activity),
    KEY idx_premium_owner (premium_owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id       BIGINT UNSIGNED NOT NULL,
    local_id      BIGINT UNSIGNED NOT NULL,   -- the per-room sequential id the app already uses (next_message_id) — kept separate from the global auto-increment `id` so existing client code that references message ids by room-relative number keeps working unchanged
    user          VARCHAR(190) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'member',
    type          VARCHAR(20)  NOT NULL DEFAULT 'text',   -- text | sticker | file | poll | system
    text          MEDIUMTEXT NULL,
    file_url      VARCHAR(500) NULL,
    file_name     VARCHAR(255) NULL,
    file_size     BIGINT UNSIGNED NULL,
    file_category VARCHAR(20) NULL,
    file_ext      VARCHAR(20) NULL,
    sticker_id    VARCHAR(50) NULL,
    sticker_emoji VARCHAR(20) NULL,
    poll_id       VARCHAR(50) NULL,
    reply_to_json TEXT NULL,          -- build_reply_snippet() output — small, kept as JSON rather than a join
    reactions_json TEXT NULL,         -- {emoji: [usernames]} — small enough to keep inline like the old format
    edited        TINYINT(1) NOT NULL DEFAULT 0,
    deleted       TINYINT(1) NOT NULL DEFAULT 0,
    ts            BIGINT UNSIGNED NOT NULL,
    expires_at    BIGINT UNSIGNED NULL,     -- for scheduled/self-destruct messages, mirrors old purge_expired() logic
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_room_local (room_id, local_id),   -- this is the actual performance win: indexed lookup instead of scanning every message in a room's JSON array
    KEY idx_room_ts (room_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    token       VARCHAR(64) NOT NULL PRIMARY KEY,   -- bin2hex(random_bytes(16)) from create_session() — unchanged format
    room        VARCHAR(190) NOT NULL,
    user        VARCHAR(190) NOT NULL,
    role        VARCHAR(20) NOT NULL,
    tier        VARCHAR(20) NULL,        -- 'master' or tenant identifier, for super_master sessions
    created_at  BIGINT UNSIGNED NOT NULL,
    expires_at  BIGINT UNSIGNED NOT NULL,
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS presence (
    room_id       BIGINT UNSIGNED NOT NULL,
    username_lower VARCHAR(190) NOT NULL,
    name          VARCHAR(190) NOT NULL,
    role          VARCHAR(20) NOT NULL,
    ts            BIGINT UNSIGNED NOT NULL,
    typing_until  BIGINT UNSIGNED NULL,
    PRIMARY KEY (room_id, username_lower),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dashboard side (dash_*.php) — tenants, admins, users. Mirrors the old
-- data_dash/ JSON structure the same way `rooms.extra_json` does: real
-- columns for what's indexed/queried often, JSON for the rest.
CREATE TABLE IF NOT EXISTS dash_tenants (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    VARCHAR(190) NOT NULL,
    admin_username VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    display_name   VARCHAR(190) NULL,
    created_at     BIGINT UNSIGNED NOT NULL,
    extra_json     LONGTEXT NULL,
    UNIQUE KEY uniq_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dash_users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     VARCHAR(190) NOT NULL,
    username      VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(190) NULL,
    created_at    BIGINT UNSIGNED NOT NULL,
    extra_json    LONGTEXT NULL,
    UNIQUE KEY uniq_tenant_user (tenant_id, username),
    FOREIGN KEY (tenant_id) REFERENCES dash_tenants(tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Large uploaded files (>1MB) — stored on the ORIGINATING domain's local
-- disk as before; this row is just the pointer + the encrypted link
-- token so any domain sharing this database can resolve it. See
-- file_links.php for the signer/verifier that produces link_token.
CREATE TABLE IF NOT EXISTS file_links (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_token    VARCHAR(255) NOT NULL,     -- encrypted+signed token, opaque to the client
    origin_domain VARCHAR(255) NOT NULL,     -- which domain's disk actually holds the file
    local_path    VARCHAR(500) NOT NULL,     -- path on that domain's server, never exposed to the client directly
    file_name     VARCHAR(255) NOT NULL,
    file_size     BIGINT UNSIGNED NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    room_id       BIGINT UNSIGNED NULL,
    created_at    BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uniq_link_token (link_token(191)),
    KEY idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small uploaded files (<=1MB) — stored directly in the database as an
-- encrypted BLOB, so every domain reading this shared DB automatically
-- has access without any file-sync step. This is the common case (chat
-- images, stickers, small attachments), so most uploads never touch a
-- domain's local disk at all.
CREATE TABLE IF NOT EXISTS file_blobs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name    VARCHAR(255) NOT NULL,
    file_size    BIGINT UNSIGNED NOT NULL,
    mime_type    VARCHAR(100) NOT NULL,
    data_encrypted LONGBLOB NOT NULL,   -- AES-256-GCM ciphertext, see file_links.php
    iv           VARBINARY(16) NOT NULL,
    auth_tag     VARBINARY(16) NOT NULL,
    room_id      BIGINT UNSIGNED NULL,
    created_at   BIGINT UNSIGNED NOT NULL,
    KEY idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
