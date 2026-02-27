-- Add creation_date column to nodes table
-- Stores the client-side ISO timestamp of when the node was created
-- (distinct from created_at which is the server-side row insertion time)

ALTER TABLE nodes ADD COLUMN creation_date DATETIME DEFAULT NULL AFTER collapsed;
