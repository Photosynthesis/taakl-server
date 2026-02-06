-- Taakl Server v2 Node Structure Migration
-- Adds support for the hierarchical node-based data structure

-- Nodes table - flat storage of hierarchical nodes
CREATE TABLE IF NOT EXISTS nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL,
    user_id INT NOT NULL,
    parent_uuid VARCHAR(36) DEFAULT NULL,      -- UUID of parent node (NULL for root level)
    name VARCHAR(255) NOT NULL,
    node_type VARCHAR(50) DEFAULT 'task',      -- 'task' or 'folder'
    child_order JSON,                          -- Array of child node UUIDs in order
    collapsed TINYINT(1) DEFAULT 0,

    -- Task-specific fields (only used when node_type = 'task')
    status VARCHAR(50) DEFAULT 'new',
    priority TINYINT DEFAULT 3,
    billable TINYINT(1) DEFAULT 1,
    estimate INT DEFAULT NULL,
    due DATE DEFAULT NULL,
    starred TINYINT(1) DEFAULT 0,
    notes TEXT,

    meta JSON,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_uuid_user (uuid, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_updated (user_id, updated_at),
    INDEX idx_user_deleted (user_id, deleted_at),
    INDEX idx_parent (user_id, parent_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Node sessions table - sessions attached to nodes
CREATE TABLE IF NOT EXISTS node_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL,
    node_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    notes TEXT,
    meta JSON,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_uuid_node (uuid, node_id),
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_updated (node_id, updated_at),
    INDEX idx_start_time (start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User data version tracking
-- Stores the data structure version and root order for each user
CREATE TABLE IF NOT EXISTS user_data_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    data_version INT DEFAULT 2,
    root_order JSON,                           -- Array of root-level node UUIDs in order
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
