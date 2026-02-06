<?php
/**
 * Sync logic for incremental and full data synchronization
 */
class Sync {
    private int $userId;
    private string $userUuid;

    public function __construct(int $userId, string $userUuid) {
        $this->userId = $userId;
        $this->userUuid = $userUuid;
    }

    /**
     * Process incremental sync
     */
    public function processSync(array $changes, ?string $lastSyncTime): array {
        $stats = ['processed' => 0, 'accepted' => 0, 'conflicts' => 0];
        $serverChanges = [];

        Database::beginTransaction();

        try {
            // Process incoming changes
            foreach ($changes as $change) {
                $stats['processed']++;
                $result = $this->processChange($change);
                if ($result) {
                    $stats['accepted']++;
                } else {
                    $stats['conflicts']++;
                }
            }

            // Get server changes since last sync
            if ($lastSyncTime) {
                $serverChanges = $this->getChangesSince($lastSyncTime);
            }

            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }

        return [
            'serverTime' => date('Y-m-d H:i:s'),
            'changes' => $serverChanges,
            'stats' => array_merge($stats, ['returned' => count($serverChanges)])
        ];
    }

    /**
     * Process a single change
     */
    private function processChange(array $change): bool {
        $action = $change['action'] ?? '';
        $type = $change['type'] ?? '';
        $uuid = $change['uuid'] ?? '';
        $data = $change['data'] ?? [];
        $timestamp = $change['timestamp'] ?? date('Y-m-d H:i:s');
        $parentUuid = $change['parentUuid'] ?? null;

        if (!$action || !$type || !$uuid) {
            return false;
        }

        switch ($action) {
            case 'insert':
                return $this->handleInsert($type, $uuid, $data, $parentUuid);
            case 'update':
                return $this->handleUpdate($type, $uuid, $data, $timestamp);
            case 'delete':
                return $this->handleDelete($type, $uuid, $timestamp);
            default:
                return false;
        }
    }

    /**
     * Handle insert action
     */
    private function handleInsert(string $type, string $uuid, array $data, ?string $parentUuid): bool {
        switch ($type) {
            case 'client':
                return $this->insertClient($uuid, $data);
            case 'project':
                return $this->insertProject($uuid, $data, $parentUuid);
            case 'task':
                return $this->insertTask($uuid, $data, $parentUuid);
            case 'session':
                return $this->insertSession($uuid, $data, $parentUuid);
            // v2 node types
            case 'node':
                return $this->insertNode($uuid, $data, $parentUuid);
            case 'node_session':
                return $this->insertNodeSession($uuid, $data, $parentUuid);
            default:
                return false;
        }
    }

    /**
     * Handle update action
     */
    private function handleUpdate(string $type, string $uuid, array $data, string $timestamp): bool {
        switch ($type) {
            case 'client':
                return $this->updateClient($uuid, $data, $timestamp);
            case 'project':
                return $this->updateProject($uuid, $data, $timestamp);
            case 'task':
                return $this->updateTask($uuid, $data, $timestamp);
            case 'session':
                return $this->updateSession($uuid, $data, $timestamp);
            // v2 node types
            case 'node':
                return $this->updateNodeRecord($uuid, $data, $timestamp);
            case 'node_session':
                return $this->updateNodeSession($uuid, $data, $timestamp);
            default:
                return false;
        }
    }

    /**
     * Handle delete action (soft delete)
     */
    private function handleDelete(string $type, string $uuid, string $timestamp): bool {
        $table = $this->getTableForType($type);
        if (!$table) {
            return false;
        }

        // Get existing record to check timestamp
        $existing = $this->getRecordByUuid($type, $uuid);
        if (!$existing) {
            return false;
        }

        // Only delete if client timestamp is newer
        if ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null) {
            return false;
        }

        // For v2 node types, use different query (uuid is unique per user)
        if ($type === 'node' || $type === 'node_session') {
            Database::execute(
                "UPDATE {$table} SET deleted_at = ? WHERE id = ?",
                [$timestamp, $existing['id']]
            );
        } else {
            Database::execute(
                "UPDATE {$table} SET deleted_at = ? WHERE uuid = ?",
                [$timestamp, $uuid]
            );
        }

        return true;
    }

    /**
     * Insert a client
     */
    private function insertClient(string $uuid, array $data): bool {
        // Check if already exists
        $existing = Database::queryOne(
            "SELECT id FROM clients WHERE uuid = ? AND user_id = ?",
            [$uuid, $this->userId]
        );

        if ($existing) {
            return false;
        }

        Database::insert('clients', [
            'uuid' => $uuid,
            'user_id' => $this->userId,
            'name' => $data['name'] ?? 'Unnamed Client',
            'meta' => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return true;
    }

    /**
     * Insert a project
     */
    private function insertProject(string $uuid, array $data, ?string $clientUuid): bool {
        if (!$clientUuid) {
            return false;
        }

        $client = Database::queryOne(
            "SELECT id FROM clients WHERE uuid = ? AND user_id = ?",
            [$clientUuid, $this->userId]
        );

        if (!$client) {
            return false;
        }

        $existing = Database::queryOne(
            "SELECT id FROM projects WHERE uuid = ? AND client_id = ?",
            [$uuid, $client['id']]
        );

        if ($existing) {
            return false;
        }

        Database::insert('projects', [
            'uuid' => $uuid,
            'client_id' => $client['id'],
            'name' => $data['name'] ?? 'Unnamed Project',
            'meta' => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return true;
    }

    /**
     * Insert a task
     */
    private function insertTask(string $uuid, array $data, ?string $projectUuid): bool {
        if (!$projectUuid) {
            return false;
        }

        $project = $this->getProjectByUuid($projectUuid);
        if (!$project) {
            return false;
        }

        $existing = Database::queryOne(
            "SELECT id FROM tasks WHERE uuid = ? AND project_id = ?",
            [$uuid, $project['id']]
        );

        if ($existing) {
            return false;
        }

        Database::insert('tasks', [
            'uuid' => $uuid,
            'project_id' => $project['id'],
            'name' => $data['name'] ?? 'Unnamed Task',
            'status' => $data['status'] ?? 'new',
            'priority' => $data['priority'] ?? 1,
            'billable' => $data['billable'] ?? 1,
            'estimate' => $data['estimate'] ?? null,
            'due' => $data['due'] ?? null,
            'starred' => $data['starred'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'meta' => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return true;
    }

    /**
     * Insert a session
     */
    private function insertSession(string $uuid, array $data, ?string $taskUuid): bool {
        if (!$taskUuid) {
            return false;
        }

        $task = $this->getTaskByUuid($taskUuid);
        if (!$task) {
            return false;
        }

        $existing = Database::queryOne(
            "SELECT id FROM sessions WHERE uuid = ? AND task_id = ?",
            [$uuid, $task['id']]
        );

        if ($existing) {
            return false;
        }

        Database::insert('sessions', [
            'uuid' => $uuid,
            'task_id' => $task['id'],
            'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
            'end_time' => $data['end_time'] ?? null,
            'notes' => $data['notes'] ?? null,
            'meta' => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return true;
    }

    /**
     * Update a client
     */
    private function updateClient(string $uuid, array $data, string $timestamp): bool {
        $existing = Database::queryOne(
            "SELECT * FROM clients WHERE uuid = ? AND user_id = ?",
            [$uuid, $this->userId]
        );

        if (!$existing || ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null)) {
            return false;
        }

        $updates = [];
        if (isset($data['name'])) $updates['name'] = $data['name'];
        if (isset($data['meta'])) $updates['meta'] = json_encode($data['meta']);
        if (isset($data['deleted'])) $updates['deleted_at'] = $data['deleted'] ? $timestamp : null;

        if (empty($updates)) {
            return true;
        }

        Database::update('clients', $updates, ['id' => $existing['id']]);
        return true;
    }

    /**
     * Update a project
     */
    private function updateProject(string $uuid, array $data, string $timestamp): bool {
        $existing = $this->getProjectByUuid($uuid);

        if (!$existing || ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null)) {
            return false;
        }

        $updates = [];
        if (isset($data['name'])) $updates['name'] = $data['name'];
        if (isset($data['meta'])) $updates['meta'] = json_encode($data['meta']);

        if (empty($updates)) {
            return true;
        }

        Database::update('projects', $updates, ['id' => $existing['id']]);
        return true;
    }

    /**
     * Update a task
     */
    private function updateTask(string $uuid, array $data, string $timestamp): bool {
        $existing = $this->getTaskByUuid($uuid);

        if (!$existing || ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null)) {
            return false;
        }

        $updates = [];
        if (isset($data['name'])) $updates['name'] = $data['name'];
        if (isset($data['status'])) $updates['status'] = $data['status'];
        if (isset($data['priority'])) $updates['priority'] = $data['priority'];
        if (isset($data['billable'])) $updates['billable'] = $data['billable'];
        if (isset($data['estimate'])) $updates['estimate'] = $data['estimate'];
        if (isset($data['due'])) $updates['due'] = $data['due'];
        if (isset($data['starred'])) $updates['starred'] = $data['starred'];
        if (isset($data['notes'])) $updates['notes'] = $data['notes'];
        if (isset($data['meta'])) $updates['meta'] = json_encode($data['meta']);

        if (empty($updates)) {
            return true;
        }

        Database::update('tasks', $updates, ['id' => $existing['id']]);
        return true;
    }

    /**
     * Update a session
     */
    private function updateSession(string $uuid, array $data, string $timestamp): bool {
        $existing = $this->getSessionByUuid($uuid);

        if (!$existing || ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null)) {
            return false;
        }

        $updates = [];
        if (isset($data['start_time'])) $updates['start_time'] = $data['start_time'];
        if (isset($data['end_time'])) $updates['end_time'] = $data['end_time'];
        if (isset($data['notes'])) $updates['notes'] = $data['notes'];
        if (isset($data['meta'])) $updates['meta'] = json_encode($data['meta']);

        if (empty($updates)) {
            return true;
        }

        Database::update('sessions', $updates, ['id' => $existing['id']]);
        return true;
    }

    // ==================== v2 Node Methods ====================

    /**
     * Insert a node (v2 structure)
     */
    private function insertNode(string $uuid, array $data, ?string $parentUuid): bool {
        // Check if already exists
        $existing = Database::queryOne(
            "SELECT id FROM nodes WHERE uuid = ? AND user_id = ?",
            [$uuid, $this->userId]
        );

        if ($existing) {
            return false;
        }

        Database::insert('nodes', [
            'uuid' => $uuid,
            'user_id' => $this->userId,
            'parent_uuid' => $parentUuid,
            'name' => $data['name'] ?? 'Unnamed',
            'node_type' => $data['type'] ?? 'task',
            'child_order' => isset($data['childOrder']) ? json_encode($data['childOrder']) : '[]',
            'collapsed' => $data['collapsed'] ?? 0,
            'status' => $data['status'] ?? 'new',
            'priority' => $data['priority'] ?? 3,
            'billable' => $data['billable'] ?? 1,
            'estimate' => $data['estimate'] ?? null,
            'due' => $data['due'] ?: null,
            'starred' => $data['starred'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'meta' => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        // Update rootOrder if this is a root node
        if ($parentUuid === null) {
            $this->addToRootOrder($uuid);
        }

        return true;
    }

    /**
     * Insert a node session (v2 structure)
     */
    private function insertNodeSession(string $uuid, array $data, ?string $nodeUuid): bool {
        if (!$nodeUuid) {
            return false;
        }

        $node = $this->getNodeByUuid($nodeUuid);
        if (!$node) {
            return false;
        }

        $existing = Database::queryOne(
            "SELECT id FROM node_sessions WHERE uuid = ? AND node_id = ?",
            [$uuid, $node['id']]
        );

        if ($existing) {
            return false;
        }

        Database::insert('node_sessions', [
            'uuid' => $uuid,
            'node_id' => $node['id'],
            'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
            'end_time' => $data['end_time'] ?? null,
            'notes' => $data['notes'] ?? null,
            'meta' => isset($data['meta']) ? json_encode($data['meta']) : null
        ]);

        return true;
    }

    /**
     * Update a node (v2 structure)
     */
    private function updateNodeRecord(string $uuid, array $data, string $timestamp): bool {
        $existing = $this->getNodeByUuid($uuid);

        if (!$existing || ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null)) {
            return false;
        }

        $updates = [];
        if (isset($data['name'])) $updates['name'] = $data['name'];
        if (isset($data['type'])) $updates['node_type'] = $data['type'];
        if (isset($data['parentId'])) $updates['parent_uuid'] = $data['parentId'];
        if (isset($data['childOrder'])) $updates['child_order'] = json_encode($data['childOrder']);
        if (isset($data['collapsed'])) $updates['collapsed'] = $data['collapsed'];
        if (isset($data['status'])) $updates['status'] = $data['status'];
        if (isset($data['priority'])) $updates['priority'] = $data['priority'];
        if (isset($data['billable'])) $updates['billable'] = $data['billable'];
        if (isset($data['estimate'])) $updates['estimate'] = $data['estimate'];
        if (isset($data['due'])) $updates['due'] = $data['due'] ?: null;
        if (isset($data['starred'])) $updates['starred'] = $data['starred'];
        if (isset($data['notes'])) $updates['notes'] = $data['notes'];
        if (isset($data['meta'])) $updates['meta'] = json_encode($data['meta']);

        if (empty($updates)) {
            return true;
        }

        Database::update('nodes', $updates, ['id' => $existing['id']]);
        return true;
    }

    /**
     * Update a node session (v2 structure)
     */
    private function updateNodeSession(string $uuid, array $data, string $timestamp): bool {
        $existing = $this->getNodeSessionByUuid($uuid);

        if (!$existing || ($existing['updated_at'] > $timestamp && $existing['deleted_at'] === null)) {
            return false;
        }

        $updates = [];
        if (isset($data['start_time'])) $updates['start_time'] = $data['start_time'];
        if (isset($data['end_time'])) $updates['end_time'] = $data['end_time'];
        if (isset($data['notes'])) $updates['notes'] = $data['notes'];
        if (isset($data['meta'])) $updates['meta'] = json_encode($data['meta']);

        if (empty($updates)) {
            return true;
        }

        Database::update('node_sessions', $updates, ['id' => $existing['id']]);
        return true;
    }

    /**
     * Get node by UUID (v2 structure)
     */
    private function getNodeByUuid(string $uuid): ?array {
        return Database::queryOne(
            "SELECT * FROM nodes WHERE uuid = ? AND user_id = ?",
            [$uuid, $this->userId]
        );
    }

    /**
     * Get node session by UUID (v2 structure)
     */
    private function getNodeSessionByUuid(string $uuid): ?array {
        return Database::queryOne(
            "SELECT ns.* FROM node_sessions ns
             JOIN nodes n ON ns.node_id = n.id
             WHERE ns.uuid = ? AND n.user_id = ?",
            [$uuid, $this->userId]
        );
    }

    /**
     * Get or create user data meta
     */
    private function getUserDataMeta(): array {
        $meta = Database::queryOne(
            "SELECT * FROM user_data_meta WHERE user_id = ?",
            [$this->userId]
        );

        if (!$meta) {
            Database::insert('user_data_meta', [
                'user_id' => $this->userId,
                'data_version' => 2,
                'root_order' => '[]'
            ]);
            return [
                'data_version' => 2,
                'root_order' => []
            ];
        }

        return [
            'data_version' => (int) $meta['data_version'],
            'root_order' => json_decode($meta['root_order'] ?? '[]', true) ?: []
        ];
    }

    /**
     * Save user data meta
     */
    private function saveUserDataMeta(int $dataVersion, array $rootOrder): void {
        $existing = Database::queryOne(
            "SELECT id FROM user_data_meta WHERE user_id = ?",
            [$this->userId]
        );

        $data = [
            'data_version' => $dataVersion,
            'root_order' => json_encode($rootOrder)
        ];

        if ($existing) {
            Database::update('user_data_meta', $data, ['id' => $existing['id']]);
        } else {
            Database::insert('user_data_meta', array_merge($data, ['user_id' => $this->userId]));
        }
    }

    /**
     * Add node to root order
     */
    private function addToRootOrder(string $uuid): void {
        $meta = $this->getUserDataMeta();
        if (!in_array($uuid, $meta['root_order'])) {
            $meta['root_order'][] = $uuid;
            $this->saveUserDataMeta($meta['data_version'], $meta['root_order']);
        }
    }

    // ==================== End v2 Node Methods ====================

    /**
     * Get changes since a given time
     */
    private function getChangesSince(string $since): array {
        $changes = [];

        // Get client changes
        $clients = Database::query(
            "SELECT * FROM clients WHERE user_id = ? AND updated_at > ?",
            [$this->userId, $since]
        );

        foreach ($clients as $client) {
            $changes[] = [
                'action' => $client['deleted_at'] ? 'delete' : 'update',
                'type' => 'client',
                'uuid' => $client['uuid'],
                'data' => $this->formatClientData($client)
            ];
        }

        // Get project changes
        $projects = Database::query(
            "SELECT p.* FROM projects p
             JOIN clients c ON p.client_id = c.id
             WHERE c.user_id = ? AND p.updated_at > ?",
            [$this->userId, $since]
        );

        foreach ($projects as $project) {
            $client = Database::queryOne("SELECT uuid FROM clients WHERE id = ?", [$project['client_id']]);
            $changes[] = [
                'action' => $project['deleted_at'] ? 'delete' : 'update',
                'type' => 'project',
                'uuid' => $project['uuid'],
                'parentUuid' => $client['uuid'],
                'data' => $this->formatProjectData($project)
            ];
        }

        // Get task changes
        $tasks = Database::query(
            "SELECT t.* FROM tasks t
             JOIN projects p ON t.project_id = p.id
             JOIN clients c ON p.client_id = c.id
             WHERE c.user_id = ? AND t.updated_at > ?",
            [$this->userId, $since]
        );

        foreach ($tasks as $task) {
            $project = Database::queryOne("SELECT uuid FROM projects WHERE id = ?", [$task['project_id']]);
            $changes[] = [
                'action' => $task['deleted_at'] ? 'delete' : 'update',
                'type' => 'task',
                'uuid' => $task['uuid'],
                'parentUuid' => $project['uuid'],
                'data' => $this->formatTaskData($task)
            ];
        }

        // Get session changes
        $sessions = Database::query(
            "SELECT s.* FROM sessions s
             JOIN tasks t ON s.task_id = t.id
             JOIN projects p ON t.project_id = p.id
             JOIN clients c ON p.client_id = c.id
             WHERE c.user_id = ? AND s.updated_at > ?",
            [$this->userId, $since]
        );

        foreach ($sessions as $session) {
            $task = Database::queryOne("SELECT uuid FROM tasks WHERE id = ?", [$session['task_id']]);
            $changes[] = [
                'action' => $session['deleted_at'] ? 'delete' : 'update',
                'type' => 'session',
                'uuid' => $session['uuid'],
                'parentUuid' => $task['uuid'],
                'data' => $this->formatSessionData($session)
            ];
        }

        // Get node changes (v2)
        $nodes = Database::query(
            "SELECT * FROM nodes WHERE user_id = ? AND updated_at > ?",
            [$this->userId, $since]
        );

        foreach ($nodes as $node) {
            $changes[] = [
                'action' => $node['deleted_at'] ? 'delete' : 'update',
                'type' => 'node',
                'uuid' => $node['uuid'],
                'parentUuid' => $node['parent_uuid'],
                'data' => $this->formatNodeData($node)
            ];
        }

        // Get node session changes (v2)
        $nodeSessions = Database::query(
            "SELECT ns.*, n.uuid as node_uuid FROM node_sessions ns
             JOIN nodes n ON ns.node_id = n.id
             WHERE n.user_id = ? AND ns.updated_at > ?",
            [$this->userId, $since]
        );

        foreach ($nodeSessions as $nodeSession) {
            $changes[] = [
                'action' => $nodeSession['deleted_at'] ? 'delete' : 'update',
                'type' => 'node_session',
                'uuid' => $nodeSession['uuid'],
                'parentUuid' => $nodeSession['node_uuid'],
                'data' => $this->formatNodeSessionData($nodeSession)
            ];
        }

        return $changes;
    }

    /**
     * Get full data export (for full sync)
     */
    public function getFullData(): array {
        // Get user data meta (v2 info)
        $meta = $this->getUserDataMeta();

        $data = [
            'dataVersion' => $meta['data_version'],
            'userKey' => $this->userUuid,
            'clients' => [],
            'nodes' => [],
            'rootOrder' => $meta['root_order'],
            'settings' => $this->getSettings()
        ];

        // === Legacy v1 data (clients/projects/tasks) ===
        $clients = Database::query(
            "SELECT * FROM clients WHERE user_id = ? AND deleted_at IS NULL",
            [$this->userId]
        );

        foreach ($clients as $client) {
            $clientData = [
                'id' => $client['uuid'],
                'name' => $client['name'],
                'projects' => []
            ];

            $projects = Database::query(
                "SELECT * FROM projects WHERE client_id = ? AND deleted_at IS NULL",
                [$client['id']]
            );

            foreach ($projects as $project) {
                $projectData = [
                    'id' => $project['uuid'],
                    'name' => $project['name'],
                    'tasks' => []
                ];

                $tasks = Database::query(
                    "SELECT * FROM tasks WHERE project_id = ? AND deleted_at IS NULL",
                    [$project['id']]
                );

                foreach ($tasks as $task) {
                    $taskData = [
                        'id' => $task['uuid'],
                        'name' => $task['name'],
                        'status' => $task['status'],
                        'priority' => (string) $task['priority'],
                        'billable' => (string) $task['billable'],
                        'estimate' => $task['estimate'],
                        'due' => $task['due'],
                        'starred' => (string) $task['starred'],
                        'notes' => $task['notes'],
                        'sessions' => []
                    ];

                    $sessions = Database::query(
                        "SELECT * FROM sessions WHERE task_id = ? AND deleted_at IS NULL",
                        [$task['id']]
                    );

                    foreach ($sessions as $session) {
                        $taskData['sessions'][$session['uuid']] = [
                            'id' => $session['uuid'],
                            'start_time' => $session['start_time'],
                            'end_time' => $session['end_time'],
                            'notes' => $session['notes']
                        ];
                    }

                    $projectData['tasks'][$task['uuid']] = $taskData;
                }

                $clientData['projects'][$project['uuid']] = $projectData;
            }

            $data['clients'][$client['uuid']] = $clientData;
        }

        // === v2 Node data ===
        $nodes = Database::query(
            "SELECT * FROM nodes WHERE user_id = ? AND deleted_at IS NULL",
            [$this->userId]
        );

        foreach ($nodes as $node) {
            $nodeData = [
                'id' => $node['uuid'],
                'name' => $node['name'],
                'type' => $node['node_type'],
                'parentId' => $node['parent_uuid'],
                'childOrder' => json_decode($node['child_order'] ?? '[]', true) ?: [],
                'collapsed' => (bool) $node['collapsed']
            ];

            // Include task-specific fields
            if ($node['node_type'] === 'task') {
                $nodeData['status'] = $node['status'];
                $nodeData['priority'] = (string) $node['priority'];
                $nodeData['billable'] = (string) $node['billable'];
                $nodeData['estimate'] = $node['estimate'];
                $nodeData['due'] = $node['due'];
                $nodeData['starred'] = (string) $node['starred'];
                $nodeData['notes'] = $node['notes'];
                $nodeData['sessions'] = [];

                // Get node sessions
                $nodeSessions = Database::query(
                    "SELECT * FROM node_sessions WHERE node_id = ? AND deleted_at IS NULL",
                    [$node['id']]
                );

                foreach ($nodeSessions as $session) {
                    $nodeData['sessions'][$session['uuid']] = [
                        'id' => $session['uuid'],
                        'start_time' => $session['start_time'],
                        'end_time' => $session['end_time'],
                        'notes' => $session['notes']
                    ];
                }
            }

            $data['nodes'][$node['uuid']] = $nodeData;
        }

        return $data;
    }

    /**
     * Import full data (for full sync upload)
     */
    public function importFullData(array $data): array {
        $stats = ['clients' => 0, 'projects' => 0, 'tasks' => 0, 'sessions' => 0, 'nodes' => 0, 'node_sessions' => 0];

        Database::beginTransaction();

        try {
            // === Process legacy v1 clients ===
            $clients = $data['clients'] ?? [];
            foreach ($clients as $clientUuid => $clientData) {
                $this->upsertClient($clientUuid, $clientData);
                $stats['clients']++;

                // Process projects
                $projects = $clientData['projects'] ?? [];
                foreach ($projects as $projectUuid => $projectData) {
                    $this->upsertProject($projectUuid, $projectData, $clientUuid);
                    $stats['projects']++;

                    // Process tasks
                    $tasks = $projectData['tasks'] ?? [];
                    foreach ($tasks as $taskUuid => $taskData) {
                        $this->upsertTask($taskUuid, $taskData, $projectUuid);
                        $stats['tasks']++;

                        // Process sessions
                        $sessions = $taskData['sessions'] ?? [];
                        foreach ($sessions as $sessionUuid => $sessionData) {
                            $this->upsertSession($sessionUuid, $sessionData, $taskUuid);
                            $stats['sessions']++;
                        }
                    }
                }
            }

            // === Process v2 nodes ===
            $nodes = $data['nodes'] ?? [];
            foreach ($nodes as $nodeUuid => $nodeData) {
                $this->upsertNode($nodeUuid, $nodeData);
                $stats['nodes']++;

                // Process node sessions
                $sessions = $nodeData['sessions'] ?? [];
                foreach ($sessions as $sessionUuid => $sessionData) {
                    $this->upsertNodeSession($sessionUuid, $sessionData, $nodeUuid);
                    $stats['node_sessions']++;
                }
            }

            // === Process rootOrder and dataVersion ===
            $dataVersion = $data['dataVersion'] ?? 2;
            $rootOrder = $data['rootOrder'] ?? [];
            $this->saveUserDataMeta($dataVersion, $rootOrder);

            // Process settings
            if (isset($data['settings'])) {
                $this->saveSettings($data['settings']);
            }

            Database::commit();
        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }

        return $stats;
    }

    /**
     * Upsert a client
     */
    private function upsertClient(string $uuid, array $data): void {
        $existing = Database::queryOne(
            "SELECT id FROM clients WHERE uuid = ? AND user_id = ?",
            [$uuid, $this->userId]
        );

        $fields = [
            'name' => $data['name'] ?? 'Unnamed Client',
            'deleted_at' => null
        ];

        if ($existing) {
            Database::update('clients', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('clients', array_merge($fields, [
                'uuid' => $uuid,
                'user_id' => $this->userId
            ]));
        }
    }

    /**
     * Upsert a project
     */
    private function upsertProject(string $uuid, array $data, string $clientUuid): void {
        $client = Database::queryOne(
            "SELECT id FROM clients WHERE uuid = ? AND user_id = ?",
            [$clientUuid, $this->userId]
        );

        if (!$client) {
            return;
        }

        $existing = Database::queryOne(
            "SELECT id FROM projects WHERE uuid = ? AND client_id = ?",
            [$uuid, $client['id']]
        );

        $fields = [
            'name' => $data['name'] ?? 'Unnamed Project',
            'deleted_at' => null
        ];

        if ($existing) {
            Database::update('projects', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('projects', array_merge($fields, [
                'uuid' => $uuid,
                'client_id' => $client['id']
            ]));
        }
    }

    /**
     * Upsert a task
     */
    private function upsertTask(string $uuid, array $data, string $projectUuid): void {
        $project = $this->getProjectByUuid($projectUuid);

        if (!$project) {
            return;
        }

        $existing = Database::queryOne(
            "SELECT id FROM tasks WHERE uuid = ? AND project_id = ?",
            [$uuid, $project['id']]
        );

        $fields = [
            'name' => $data['name'] ?? 'Unnamed Task',
            'status' => $data['status'] ?? 'new',
            'priority' => $data['priority'] ?? 1,
            'billable' => $data['billable'] ?? 1,
            'estimate' => $data['estimate'] ?? null,
            'due' => $data['due'] ?? null,
            'starred' => $data['starred'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'deleted_at' => null
        ];

        if ($existing) {
            Database::update('tasks', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('tasks', array_merge($fields, [
                'uuid' => $uuid,
                'project_id' => $project['id']
            ]));
        }
    }

    /**
     * Upsert a session
     */
    private function upsertSession(string $uuid, array $data, string $taskUuid): void {
        $task = $this->getTaskByUuid($taskUuid);

        if (!$task) {
            return;
        }

        $existing = Database::queryOne(
            "SELECT id FROM sessions WHERE uuid = ? AND task_id = ?",
            [$uuid, $task['id']]
        );

        $fields = [
            'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
            'end_time' => $data['end_time'] ?? null,
            'notes' => $data['notes'] ?? null,
            'deleted_at' => null
        ];

        if ($existing) {
            Database::update('sessions', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('sessions', array_merge($fields, [
                'uuid' => $uuid,
                'task_id' => $task['id']
            ]));
        }
    }

    /**
     * Upsert a node (v2 structure)
     */
    private function upsertNode(string $uuid, array $data): void {
        $existing = Database::queryOne(
            "SELECT id FROM nodes WHERE uuid = ? AND user_id = ?",
            [$uuid, $this->userId]
        );

        $fields = [
            'name' => $data['name'] ?? 'Unnamed',
            'node_type' => $data['type'] ?? 'task',
            'parent_uuid' => $data['parentId'] ?? null,
            'child_order' => isset($data['childOrder']) ? json_encode($data['childOrder']) : '[]',
            'collapsed' => $data['collapsed'] ?? 0,
            'status' => $data['status'] ?? 'new',
            'priority' => $data['priority'] ?? 3,
            'billable' => $data['billable'] ?? 1,
            'estimate' => $data['estimate'] ?? null,
            'due' => ($data['due'] ?? null) ?: null,
            'starred' => $data['starred'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'deleted_at' => null
        ];

        if ($existing) {
            Database::update('nodes', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('nodes', array_merge($fields, [
                'uuid' => $uuid,
                'user_id' => $this->userId
            ]));
        }
    }

    /**
     * Upsert a node session (v2 structure)
     */
    private function upsertNodeSession(string $uuid, array $data, string $nodeUuid): void {
        $node = $this->getNodeByUuid($nodeUuid);

        if (!$node) {
            return;
        }

        $existing = Database::queryOne(
            "SELECT id FROM node_sessions WHERE uuid = ? AND node_id = ?",
            [$uuid, $node['id']]
        );

        $fields = [
            'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
            'end_time' => $data['end_time'] ?? null,
            'notes' => $data['notes'] ?? null,
            'deleted_at' => null
        ];

        if ($existing) {
            Database::update('node_sessions', $fields, ['id' => $existing['id']]);
        } else {
            Database::insert('node_sessions', array_merge($fields, [
                'uuid' => $uuid,
                'node_id' => $node['id']
            ]));
        }
    }

    /**
     * Get user settings
     */
    public function getSettings(): array {
        $settings = [];
        $rows = Database::query(
            "SELECT setting_key, setting_value FROM settings WHERE user_id = ?",
            [$this->userId]
        );

        foreach ($rows as $row) {
            $value = $row['setting_value'];
            // Try to decode JSON values
            $decoded = json_decode($value, true);
            $settings[$row['setting_key']] = ($decoded !== null) ? $decoded : $value;
        }

        return $settings;
    }

    /**
     * Save user settings
     */
    public function saveSettings(array $settings): void {
        foreach ($settings as $key => $value) {
            $stringValue = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

            $existing = Database::queryOne(
                "SELECT id FROM settings WHERE user_id = ? AND setting_key = ?",
                [$this->userId, $key]
            );

            if ($existing) {
                Database::update('settings', ['setting_value' => $stringValue], ['id' => $existing['id']]);
            } else {
                Database::insert('settings', [
                    'user_id' => $this->userId,
                    'setting_key' => $key,
                    'setting_value' => $stringValue
                ]);
            }
        }
    }

    // Helper methods

    private function getTableForType(string $type): ?string {
        return match($type) {
            'client' => 'clients',
            'project' => 'projects',
            'task' => 'tasks',
            'session' => 'sessions',
            'node' => 'nodes',
            'node_session' => 'node_sessions',
            default => null
        };
    }

    private function getRecordByUuid(string $type, string $uuid): ?array {
        return match($type) {
            'client' => Database::queryOne(
                "SELECT * FROM clients WHERE uuid = ? AND user_id = ?",
                [$uuid, $this->userId]
            ),
            'project' => $this->getProjectByUuid($uuid),
            'task' => $this->getTaskByUuid($uuid),
            'session' => $this->getSessionByUuid($uuid),
            'node' => $this->getNodeByUuid($uuid),
            'node_session' => $this->getNodeSessionByUuid($uuid),
            default => null
        };
    }

    private function getProjectByUuid(string $uuid): ?array {
        return Database::queryOne(
            "SELECT p.* FROM projects p
             JOIN clients c ON p.client_id = c.id
             WHERE p.uuid = ? AND c.user_id = ?",
            [$uuid, $this->userId]
        );
    }

    private function getTaskByUuid(string $uuid): ?array {
        return Database::queryOne(
            "SELECT t.* FROM tasks t
             JOIN projects p ON t.project_id = p.id
             JOIN clients c ON p.client_id = c.id
             WHERE t.uuid = ? AND c.user_id = ?",
            [$uuid, $this->userId]
        );
    }

    private function getSessionByUuid(string $uuid): ?array {
        return Database::queryOne(
            "SELECT s.* FROM sessions s
             JOIN tasks t ON s.task_id = t.id
             JOIN projects p ON t.project_id = p.id
             JOIN clients c ON p.client_id = c.id
             WHERE s.uuid = ? AND c.user_id = ?",
            [$uuid, $this->userId]
        );
    }

    private function formatClientData(array $client): array {
        return [
            'id' => $client['uuid'],
            'name' => $client['name']
        ];
    }

    private function formatProjectData(array $project): array {
        return [
            'id' => $project['uuid'],
            'name' => $project['name']
        ];
    }

    private function formatTaskData(array $task): array {
        return [
            'id' => $task['uuid'],
            'name' => $task['name'],
            'status' => $task['status'],
            'priority' => (string) $task['priority'],
            'billable' => (string) $task['billable'],
            'estimate' => $task['estimate'],
            'due' => $task['due'],
            'starred' => (string) $task['starred'],
            'notes' => $task['notes']
        ];
    }

    private function formatSessionData(array $session): array {
        return [
            'id' => $session['uuid'],
            'start_time' => $session['start_time'],
            'end_time' => $session['end_time'],
            'notes' => $session['notes']
        ];
    }

    private function formatNodeData(array $node): array {
        $data = [
            'id' => $node['uuid'],
            'name' => $node['name'],
            'type' => $node['node_type'],
            'parentId' => $node['parent_uuid'],
            'childOrder' => json_decode($node['child_order'] ?? '[]', true) ?: [],
            'collapsed' => (bool) $node['collapsed']
        ];

        // Include task-specific fields
        if ($node['node_type'] === 'task') {
            $data['status'] = $node['status'];
            $data['priority'] = (string) $node['priority'];
            $data['billable'] = (string) $node['billable'];
            $data['estimate'] = $node['estimate'];
            $data['due'] = $node['due'];
            $data['starred'] = (string) $node['starred'];
            $data['notes'] = $node['notes'];
        }

        return $data;
    }

    private function formatNodeSessionData(array $session): array {
        return [
            'id' => $session['uuid'],
            'start_time' => $session['start_time'],
            'end_time' => $session['end_time'],
            'notes' => $session['notes']
        ];
    }
}
