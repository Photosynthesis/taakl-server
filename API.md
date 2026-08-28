# Taakl Server API Documentation

**Server:** PHP with PDO + MySQL
**Base URL:** `/api/`
**Response Format:** JSON
**Default CORS Origin:** `*` (configurable)

---

## Authentication

All protected endpoints require a Bearer token in the `Authorization` header:

```
Authorization: Bearer <token>
```

- Tokens are 64 hex characters (32 random bytes), stored as SHA-256 hashes server-side
- Tokens expire after 30 days (configurable via `TOKEN_EXPIRY_DAYS`)
- Passwords are hashed with bcrypt (cost factor 12)

**Header fallbacks for CGI/FastCGI:** `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`

---

## Response Format

All responses use a consistent JSON structure:

**Success:**
```json
{
  "success": true,
  "...": "endpoint-specific fields"
}
```

**Error:**
```json
{
  "success": false,
  "error": "Human-readable error message"
}
```

**HTTP Status Codes:**

| Code | Meaning |
|------|---------|
| 200  | OK |
| 201  | Created (registration) |
| 204  | No Content (OPTIONS preflight) |
| 400  | Bad Request (validation errors, malformed JSON) |
| 401  | Unauthorized (missing/invalid/expired token) |
| 404  | Not Found (unknown endpoint) |
| 409  | Conflict (e.g. username already exists) |
| 500  | Server Error |

---

## Endpoints

### POST /api/register

Register a new user account. No authentication required.

**Request Body:**
```json
{
  "username": "string (3-50 chars, alphanumeric + _ - only)",
  "password": "string (min 8 chars)",
  "email": "string (optional, valid email format)"
}
```

**Response (201):**
```json
{
  "success": true,
  "user": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "username": "johndoe",
    "email": "john@example.com"
  },
  "token": "a1b2c3d4...64 hex chars"
}
```

**Errors:**
- `400` — Missing `username` or `password`, invalid format, validation failed
- `409` — Username already exists

---

### POST /api/login

Authenticate with username and password. No authentication required.

**Request Body:**
```json
{
  "username": "string",
  "password": "string"
}
```

**Response (200):**
```json
{
  "success": true,
  "user": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "username": "johndoe",
    "email": "john@example.com"
  },
  "token": "a1b2c3d4...64 hex chars"
}
```

**Errors:**
- `400` — Missing required fields
- `401` — Invalid username or password

---

### POST /api/logout

Invalidate the current token.

**Authentication:** Required

**Request Body:** None

**Response (200):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

### GET /api/me

Get the current authenticated user's info.

**Authentication:** Required

**Response (200):**
```json
{
  "success": true,
  "user": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "username": "johndoe",
    "email": "john@example.com"
  }
}
```

---

### POST /api/sync

Incremental sync — push client changes and pull server changes since `lastSyncTime`.

**Authentication:** Required

**Request Body:**
```json
{
  "lastSyncTime": "2024-01-15 10:30:00",
  "changes": [
    {
      "action": "insert|update|delete",
      "type": "node|node_session|client|project|task|session",
      "uuid": "unique-id",
      "data": { },
      "timestamp": "2024-01-15 10:31:00",
      "parentUuid": "parent-node-id"
    }
  ]
}
```

`lastSyncTime` can be `null` for the first sync. `timestamp` defaults to current server time if omitted.

#### Change Types

**node** (v2 — hierarchical tree):
```json
{
  "name": "string",
  "type": "folder|task",
  "parentId": "string or null",
  "childOrder": ["uuid1", "uuid2"],
  "collapsed": false,
  "status": "new|started|completed",
  "priority": 1,
  "billable": false,
  "estimate": 3600,
  "due": "2024-02-01",
  "starred": false,
  "notes": "string or null",
  "creation_date": "2024-01-15 10:00:00",
  "meta": {}
}
```

**node_session** (v2 — time sessions on nodes):
```json
{
  "start_time": "2024-01-15 10:00:00",
  "end_time": "2024-01-15 11:30:00",
  "notes": "string or null",
  "meta": {}
}
```
`parentUuid` must reference the owning node's UUID.

**client, project, task, session** (v1 — legacy flat hierarchy):

| Type | Fields |
|------|--------|
| client | `name` |
| project | `name` |
| task | `name`, `status`, `priority`, `billable`, `estimate`, `due`, `starred`, `notes` |
| session | `start_time`, `end_time`, `notes` |

**Response (200):**
```json
{
  "success": true,
  "serverTime": "2024-01-15 10:35:00",
  "changes": [
    {
      "action": "insert|update|delete",
      "type": "string",
      "uuid": "string",
      "parentUuid": "string or null",
      "data": { }
    }
  ],
  "rootOrder": ["uuid1", "uuid2"],
  "stats": {
    "processed": 5,
    "accepted": 4,
    "conflicts": 1,
    "returned": 12
  }
}
```

**Conflict Resolution:** Last-write-wins based on timestamp. The server accepts a client change only if the client's timestamp >= the server's `updated_at` for that record. Conflicts are counted in `stats.conflicts` but rejected changes are not returned in detail.

---

### POST /api/sync/full

Full sync upload — replace all server data with complete client dataset.

**Authentication:** Required

**Request Body:**
```json
{
  "ttData": {
    "dataVersion": 2,
    "userKey": "user-uuid",
    "nodes": {
      "node-uuid": {
        "id": "node-uuid",
        "name": "Project A",
        "type": "folder|task",
        "parentId": "parent-uuid or null",
        "childOrder": ["child-uuid-1", "child-uuid-2"],
        "collapsed": false,
        "status": "new",
        "priority": 1,
        "billable": false,
        "estimate": null,
        "due": null,
        "starred": false,
        "notes": null,
        "creation_date": "2024-01-15 10:00:00",
        "sessions": {
          "session-uuid": {
            "id": "session-uuid",
            "start_time": "2024-01-15 10:00:00",
            "end_time": "2024-01-15 11:30:00",
            "notes": null
          }
        }
      }
    },
    "rootOrder": ["node-uuid-1", "node-uuid-2"],
    "clients": { },
    "settings": {
      "key": "value"
    }
  }
}
```

Must have either `clients` (v1) or `nodes` (v2) or both. Uses upsert logic — safe to run multiple times.

**Response (200):**
```json
{
  "success": true,
  "message": "Data imported successfully",
  "stats": {
    "clients": 0,
    "projects": 0,
    "tasks": 0,
    "sessions": 0,
    "nodes": 15,
    "node_sessions": 42
  }
}
```

---

### GET /api/sync/full

Full sync download — retrieve complete server dataset.

**Authentication:** Required

**Response (200):**
```json
{
  "success": true,
  "ttData": {
    "dataVersion": 2,
    "userKey": "user-uuid",
    "clients": { },
    "nodes": {
      "node-uuid": {
        "id": "node-uuid",
        "name": "Project A",
        "type": "folder",
        "parentId": null,
        "childOrder": ["child-1", "child-2"],
        "collapsed": false,
        "status": null,
        "priority": null,
        "billable": null,
        "estimate": null,
        "due": null,
        "starred": null,
        "notes": null,
        "creation_date": "2024-01-15 10:00:00",
        "sessions": { }
      }
    },
    "rootOrder": ["node-uuid-1"],
    "settings": { }
  }
}
```

Only non-deleted records are returned.

---

### GET /api/settings

Retrieve all user settings.

**Authentication:** Required

**Response (200):**
```json
{
  "success": true,
  "settings": {
    "auto_synch": "yes",
    "theme": "dark"
  }
}
```

Returns `{}` if no settings exist. JSON values are automatically decoded.

---

### PUT /api/settings

Update user settings. Performs upsert — creates new keys, updates existing ones.

**Authentication:** Required

**Request Body:**
```json
{
  "settings": {
    "auto_synch": "yes",
    "theme": "dark"
  }
}
```

Or directly as a flat object:
```json
{
  "auto_synch": "yes",
  "theme": "dark"
}
```

Supports partial updates — only specified keys are changed.

**Response (200):**
```json
{
  "success": true,
  "message": "Settings updated successfully"
}
```

---

### POST /api/shares

Create a read-only share link for a node branch. The returned `token`/`url` are
shown **only once** — only a SHA-256 hash is stored.

**Authentication:** Required

**Request Body:**
```json
{
  "nodeUuid": "node-uuid",
  "options": { "include_notes": true }
}
```

`options` is optional; `include_notes` defaults to `true`.

**Response (201):**
```json
{
  "success": true,
  "id": 1,
  "token": "a1b2c3d4...64 hex chars",
  "url": "https://api.taakl.app/share/#a1b2c3d4...",
  "options": { "include_notes": true },
  "node": { "uuid": "node-uuid", "name": "Project X" }
}
```

The token rides in the URL fragment so page requests never log it.

**Errors:**
- `400` with `"code": "node_not_synced"` — node does not exist on the server
  (client must sync first)

---

### GET /api/shares

List the current user's active (non-revoked) shares. Tokens are not recoverable.

**Authentication:** Required

**Response (200):**
```json
{
  "success": true,
  "shares": [
    {
      "id": 1,
      "root_node_uuid": "node-uuid",
      "node_name": "Project X",
      "options": { "include_notes": true },
      "created_at": "2026-08-28 10:00:00",
      "expires_at": null,
      "access_count": 4,
      "last_accessed_at": "2026-08-28 12:00:00"
    }
  ]
}
```

---

### DELETE /api/shares/{id}

Revoke a share. Idempotent.

**Authentication:** Required

**Response (200):** `{ "success": true, "message": "Share revoked" }`
**Errors:** `404` — share does not exist for this user

---

### GET /api/share/{token}

**Public — no authentication; the 64-hex-char token is the credential.**
Returns the read-only subtree rooted at the share's node. Served with
`Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`,
`Referrer-Policy: no-referrer`.

**Response (200):**
```json
{
  "success": true,
  "share": { "created_at": "2026-08-28 10:00:00", "options": { "include_notes": true } },
  "rootUuid": "node-uuid",
  "nodes": {
    "node-uuid": {
      "id": "node-uuid",
      "name": "Project X",
      "type": "folder",
      "parentId": null,
      "childOrder": ["child-1"],
      "status": null,
      "priority": null,
      "estimate": null,
      "due": null,
      "starred": false,
      "notes": null,
      "creation_date": "2026-01-05 09:00:00",
      "time_logged": 0
    }
  }
}
```

- The share root's `parentId` is always `null` (ancestors are never exposed).
- `time_logged` is the node's **own** ended, non-deleted session seconds;
  viewers roll up descendant totals. Raw sessions are never included.
- `notes` is `null` when the share was created with `include_notes: false`.
- Soft-deleted nodes are excluded; `childOrder` only references included nodes.

**Errors (uniform):**
- `404` — unknown, revoked, or expired token, or the root node was deleted.
  These cases are deliberately indistinguishable.
- `413` — subtree exceeds 10,000 nodes.

The static viewer page for these links lives at `/share/` (see File Structure).
It reads the token from the URL fragment and calls this endpoint.

---

## Database Schema

### Users

| Column | Type | Notes |
|--------|------|-------|
| id | INT | Primary key, auto-increment |
| uuid | VARCHAR(36) | Unique, client-facing identifier |
| username | VARCHAR(255) | Unique |
| password_hash | VARCHAR(255) | bcrypt |
| email | VARCHAR(255) | Optional |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### Auth Tokens

| Column | Type | Notes |
|--------|------|-------|
| id | INT | Primary key |
| user_id | INT | FK to users |
| token_hash | VARCHAR(64) | SHA-256 hash, unique |
| expires_at | DATETIME | |
| created_at | DATETIME | |

### Nodes (v2)

| Column | Type | Notes |
|--------|------|-------|
| uuid | VARCHAR(36) | Primary key |
| user_id | INT | FK to users |
| parent_uuid | VARCHAR(36) | Nullable, FK to nodes |
| name | VARCHAR(255) | |
| node_type | VARCHAR(20) | `folder` or `task` |
| child_order | JSON | Array of child UUIDs |
| collapsed | TINYINT(1) | |
| status | VARCHAR(50) | Nullable (tasks only) |
| priority | INT | Nullable |
| billable | TINYINT(1) | Nullable |
| estimate | INT | Seconds, nullable |
| due | DATE | Nullable |
| starred | TINYINT(1) | Nullable |
| notes | TEXT | Nullable |
| creation_date | DATETIME | Nullable |
| meta | JSON | Nullable, arbitrary metadata |
| deleted_at | DATETIME | Soft delete |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### Node Sessions (v2)

| Column | Type | Notes |
|--------|------|-------|
| uuid | VARCHAR(36) | Primary key |
| node_id | INT | FK to nodes.id (internal integer id, not the uuid) |
| start_time | DATETIME | |
| end_time | DATETIME | Nullable (running session) |
| notes | TEXT | Nullable |
| meta | JSON | Nullable |
| deleted_at | DATETIME | Soft delete |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### User Data Meta

| Column | Type | Notes |
|--------|------|-------|
| user_id | INT | Primary key, FK to users |
| data_version | INT | Currently 2 |
| root_order | JSON | Array of top-level node UUIDs |
| updated_at | DATETIME | |

### Settings

| Column | Type | Notes |
|--------|------|-------|
| id | INT | Primary key |
| user_id | INT | FK to users |
| setting_key | VARCHAR(100) | |
| setting_value | TEXT | JSON-encoded |
| updated_at | DATETIME | |

Unique constraint on `(user_id, setting_key)`.

### Shares

| Column | Type | Notes |
|--------|------|-------|
| id | INT | Primary key |
| user_id | INT | FK to users |
| root_node_uuid | VARCHAR(36) | Shared branch root (belongs to user_id) |
| token_hash | VARCHAR(64) | SHA-256 hash, unique; plaintext never stored |
| options | JSON | `{"include_notes": bool}` |
| created_at | DATETIME | |
| expires_at | DATETIME | Nullable (NULL = no expiry) |
| revoked_at | DATETIME | Nullable soft revocation |
| last_accessed_at | DATETIME | |
| access_count | INT | |

### Legacy Tables (v1)

| Table | Key Columns |
|-------|-------------|
| clients | uuid, user_id, name, meta, deleted_at, updated_at |
| projects | uuid, client_id (FK to clients), name, meta, deleted_at, updated_at |
| tasks | uuid, project_id (FK to projects), name, status, priority, billable, estimate, due, starred, notes, meta, deleted_at, updated_at |
| sessions | uuid, task_id (FK to tasks), start_time, end_time, notes, meta, deleted_at, updated_at |

---

## Security

- All database queries use PDO prepared statements (no SQL injection)
- CORS headers: allows GET, POST, PUT, DELETE, OPTIONS; max-age 86400s
- `.htaccess` denies access to `config.local.php`, `.git`, `.env`
- Security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `X-XSS-Protection: 1; mode=block`
- Rate limiting: configurable (default 100 requests/minute)
- Soft deletes: records are never physically removed

---

## Configuration

**File:** `config.php` (override with `config.local.php`, which is gitignored)

| Constant | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_NAME` | `taakl` | Database name |
| `DB_USER` | `taakl_user` | Database user |
| `DB_PASS` | `change_this_password` | Database password |
| `DB_CHARSET` | `utf8mb4` | Connection charset |
| `TOKEN_EXPIRY_DAYS` | `30` | Token lifetime |
| `BCRYPT_COST` | `12` | Password hash cost |
| `RATE_LIMIT` | `100` | Requests per minute |
| `CORS_ORIGIN` | `*` | Allowed CORS origin |

Timezone is set to UTC server-wide.

---

## Migrations

| File | Description |
|------|-------------|
| `001_initial.sql` | V1 schema: users, auth_tokens, clients, projects, tasks, sessions, settings |
| `002_nodes.sql` | V2 schema: nodes, node_sessions, user_data_meta |
| `003_add_creation_date.sql` | Adds `creation_date` column to nodes |
| `004_shares.sql` | Read-only share links (shares table) |

---

## File Structure

```
taakl-server/
  index.php                     # Main router
  config.php                    # Configuration defaults
  config.local.php              # Local overrides (gitignored)
  .htaccess                     # Apache rewrites & security
  lib/
    Response.php                # Response formatting & validation helpers
    Database.php                # PDO wrapper
    Auth.php                    # Token-based authentication
    Sync.php                    # Incremental and full sync logic
    Share.php                   # Share links & public payload assembly
  api/
    auth.php                    # /api/register, login, logout, me
    sync.php                    # /api/sync, sync/full
    settings.php                # /api/settings
    share.php                   # /api/shares, /api/share/{token}
  share/
    index.html                  # Standalone read-only share viewer (static)
    share.js                    # Viewer logic — mirrors client filter semantics
    share.css                   # Viewer styles
    .htaccess                   # noindex / no-referrer / no-store headers
  migrations/
    001_initial.sql             # V1 schema
    002_nodes.sql               # V2 node structure
    003_add_creation_date.sql   # creation_date column
    004_shares.sql              # shares table
```

The `share/` viewer deliberately duplicates a small amount of client rendering
logic (search/recency filters, time roll-up, `prettyTime`) so the client app
never loads in an unauthenticated context. The duplication contract is listed in
the header comment of `share/share.js` — behavior changes to those client
functions must be ported by hand.
