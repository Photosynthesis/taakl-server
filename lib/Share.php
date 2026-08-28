<?php
/**
 * Read-only share links: creation, revocation, and public payload assembly.
 * The URL token is the only credential; only its SHA-256 hash is stored.
 */
class Share {
    /** Refuse to serve subtrees larger than this (protects shared hosting). */
    const MAX_NODES = 10000;

    /**
     * Create a share for a node owned by the user.
     * Returns ['id', 'token', 'node' => ['uuid', 'name']] or sends an error response.
     */
    public static function create(int $userId, string $nodeUuid, array $options = []): array {
        $node = Database::queryOne(
            "SELECT uuid, name FROM nodes WHERE user_id = ? AND uuid = ? AND deleted_at IS NULL",
            [$userId, $nodeUuid]
        );

        if (!$node) {
            // Distinct error so the client can prompt "sync first"
            Response::error('Node not found on server — sync before sharing', 400, ['code' => 'node_not_synced']);
        }

        $token = Auth::generateToken();

        $cleanOptions = [
            'include_notes' => !isset($options['include_notes']) || (bool)$options['include_notes']
        ];

        $shareId = Database::insert('shares', [
            'user_id' => $userId,
            'root_node_uuid' => $nodeUuid,
            'token_hash' => Auth::hashToken($token),
            'options' => json_encode($cleanOptions)
        ]);

        return [
            'id' => $shareId,
            'token' => $token,
            'options' => $cleanOptions,
            'node' => ['uuid' => $node['uuid'], 'name' => $node['name']]
        ];
    }

    /**
     * List a user's active (non-revoked) shares. Tokens are not recoverable.
     */
    public static function listForUser(int $userId): array {
        $rows = Database::query(
            "SELECT s.id, s.root_node_uuid, n.name AS node_name, s.options,
                    s.created_at, s.expires_at, s.access_count, s.last_accessed_at
             FROM shares s
             LEFT JOIN nodes n ON n.uuid = s.root_node_uuid AND n.user_id = s.user_id
             WHERE s.user_id = ? AND s.revoked_at IS NULL
             ORDER BY s.created_at DESC",
            [$userId]
        );

        foreach ($rows as &$row) {
            $row['options'] = $row['options'] ? json_decode($row['options'], true) : null;
        }

        return $rows;
    }

    /**
     * Revoke a share owned by the user. Returns false if it doesn't exist for them.
     */
    public static function revoke(int $userId, int $shareId): bool {
        $share = Database::queryOne(
            "SELECT id FROM shares WHERE id = ? AND user_id = ?",
            [$shareId, $userId]
        );
        if (!$share) {
            return false;
        }

        Database::execute(
            "UPDATE shares SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL",
            [$shareId]
        );
        return true;
    }

    /**
     * Resolve a URL token to a live share row, or null.
     * Unknown, revoked, and expired all resolve to null — callers must not distinguish.
     */
    public static function resolveToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM shares
             WHERE token_hash = ? AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())",
            [Auth::hashToken($token)]
        );
    }

    /**
     * Record an access on a share.
     */
    public static function touch(int $shareId): void {
        Database::execute(
            "UPDATE shares SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id = ?",
            [$shareId]
        );
    }

    /**
     * Build the public payload for a share: the subtree rooted at root_node_uuid.
     * Returns null if the root node no longer exists (deleted) — caller sends 404.
     */
    public static function buildPayload(array $share): ?array {
        $userId = (int)$share['user_id'];
        $rootUuid = $share['root_node_uuid'];
        $options = $share['options'] ? json_decode($share['options'], true) : [];
        $includeNotes = !isset($options['include_notes']) || $options['include_notes'];

        $root = Database::queryOne(
            "SELECT * FROM nodes WHERE user_id = ? AND uuid = ? AND deleted_at IS NULL",
            [$userId, $rootUuid]
        );
        if (!$root) {
            return null;
        }

        // Breadth-first walk down parent_uuid, strictly scoped to the owner
        $rows = [$rootUuid => $root];
        $frontier = [$rootUuid];

        while (!empty($frontier)) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $children = Database::query(
                "SELECT * FROM nodes
                 WHERE user_id = ? AND parent_uuid IN ($placeholders) AND deleted_at IS NULL",
                array_merge([$userId], $frontier)
            );

            $frontier = [];
            foreach ($children as $child) {
                if (isset($rows[$child['uuid']])) {
                    continue; // defensive: ignore cycles
                }
                $rows[$child['uuid']] = $child;
                $frontier[] = $child['uuid'];
            }

            if (count($rows) > self::MAX_NODES) {
                Response::error('Shared branch is too large to display', 413);
            }
        }

        $timeLogged = self::timeLoggedByUuid($userId, array_keys($rows));

        $nodes = [];
        foreach ($rows as $uuid => $row) {
            $childOrder = $row['child_order'] ? (json_decode($row['child_order'], true) ?: []) : [];
            // Only reference children that made it into the payload; append any stragglers
            $childOrder = array_values(array_filter($childOrder, function ($cid) use ($rows) {
                return isset($rows[$cid]);
            }));
            foreach ($rows as $cUuid => $cRow) {
                if ($cRow['parent_uuid'] === $uuid && !in_array($cUuid, $childOrder, true)) {
                    $childOrder[] = $cUuid;
                }
            }

            $nodes[$uuid] = [
                'id' => $uuid,
                'name' => $row['name'],
                'type' => $row['node_type'],
                // The share root renders as top level regardless of its real parent
                'parentId' => ($uuid === $rootUuid) ? null : $row['parent_uuid'],
                'childOrder' => $childOrder,
                'status' => $row['status'],
                'priority' => isset($row['priority']) ? (int)$row['priority'] : null,
                'estimate' => isset($row['estimate']) ? (int)$row['estimate'] : null,
                'due' => $row['due'],
                'starred' => (bool)$row['starred'],
                'notes' => $includeNotes ? $row['notes'] : null,
                'creation_date' => $row['creation_date'],
                'time_logged' => $timeLogged[$uuid] ?? 0
            ];
        }

        return [
            'share' => [
                'created_at' => $share['created_at'],
                'options' => ['include_notes' => $includeNotes]
            ],
            'rootUuid' => $rootUuid,
            'nodes' => $nodes
        ];
    }

    /**
     * Aggregate ended, non-deleted session seconds per node uuid (own sessions only).
     */
    private static function timeLoggedByUuid(int $userId, array $uuids): array {
        if (empty($uuids)) {
            return [];
        }

        $totals = [];
        // Chunk to keep IN() lists bounded
        foreach (array_chunk($uuids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::query(
                "SELECT n.uuid, SUM(TIMESTAMPDIFF(SECOND, s.start_time, s.end_time)) AS secs
                 FROM node_sessions s
                 JOIN nodes n ON s.node_id = n.id
                 WHERE n.user_id = ? AND n.uuid IN ($placeholders)
                   AND s.deleted_at IS NULL AND s.end_time IS NOT NULL
                 GROUP BY n.uuid",
                array_merge([$userId], $chunk)
            );
            foreach ($rows as $row) {
                $totals[$row['uuid']] = max(0, (int)$row['secs']);
            }
        }

        return $totals;
    }
}
