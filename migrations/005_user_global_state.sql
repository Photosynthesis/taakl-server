-- Migration 005: account-global synced state (user_global_state)
--
-- Generic key-value store for state that roams across devices, unlike the
-- settings table whose sync semantics are device-local-wins. Conflict
-- resolution is uniform last-write-wins by client_updated_at (client-stamped,
-- UTC). Adding a new global-state key requires no schema change.
--
-- First key: "tracking" — the running-session pointer. Its state_value is
-- {"nodeId": "...", "sessionId": "..."} (both null when nothing is running).

CREATE TABLE IF NOT EXISTS user_global_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    state_key VARCHAR(100) NOT NULL,
    state_value JSON,
    client_updated_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_state (user_id, state_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
