-- Read-only share links for node branches
-- A share exposes the subtree rooted at root_node_uuid via a secret URL token.

CREATE TABLE IF NOT EXISTS shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    root_node_uuid VARCHAR(36) NOT NULL,       -- must belong to user_id
    token_hash VARCHAR(64) UNIQUE NOT NULL,    -- SHA-256 of the URL token
    options JSON,                              -- {"include_notes": true} etc.
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,          -- NULL = no expiry
    revoked_at DATETIME DEFAULT NULL,
    last_accessed_at DATETIME DEFAULT NULL,
    access_count INT DEFAULT 0,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
