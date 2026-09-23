-- Migration 006: client action timestamps for true last-write-wins
--
-- Conflict resolution previously compared the client's action timestamp
-- against updated_at, which is the server's WRITE-RECEIPT time (and was
-- stamped in the server's local timezone besides) — so "last write wins"
-- was really "first push wins" in any concurrency window. Store the
-- client-stamped UTC action time and compare action-time vs action-time.
-- NULL on legacy rows; code falls back to updated_at for those.

ALTER TABLE nodes ADD COLUMN client_updated_at DATETIME NULL;
ALTER TABLE node_sessions ADD COLUMN client_updated_at DATETIME NULL;
