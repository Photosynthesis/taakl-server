<?php
/**
 * Sync API handlers
 */

/**
 * POST /api/sync
 * Incremental sync endpoint
 */
function handleSync(): void {
    $user = Auth::requireAuth();
    $data = Response::getJsonBody();

    $lastSyncTime = $data['lastSyncTime'] ?? null;
    $changes = $data['changes'] ?? [];

    $sync = new Sync($user['id'], $user['uuid']);
    $result = $sync->processSync($changes, $lastSyncTime);

    Response::success($result);
}

/**
 * POST /api/sync/full
 * Full sync upload - receives complete client data
 * Supports both v1 (clients/projects/tasks) and v2 (nodes/rootOrder) formats
 */
function handleFullSyncUpload(): void {
    $user = Auth::requireAuth();
    $data = Response::getJsonBody();

    // Support both wrapped and unwrapped data formats
    $ttData = $data['ttData'] ?? $data;

    if (empty($ttData)) {
        Response::error('Invalid data format - empty data', 400);
    }

    // Validate: must have either v1 clients OR v2 nodes (or both)
    $hasV1Data = isset($ttData['clients']) && !empty($ttData['clients']);
    $hasV2Data = isset($ttData['nodes']) && !empty($ttData['nodes']);

    if (!$hasV1Data && !$hasV2Data) {
        Response::error('Invalid data format - missing clients or nodes', 400);
    }

    $sync = new Sync($user['id'], $user['uuid']);
    $stats = $sync->importFullData($ttData);

    Response::success([
        'message' => 'Data imported successfully',
        'stats' => $stats
    ]);
}

/**
 * GET /api/sync/full
 * Full sync download - returns complete server data
 */
function handleFullSyncDownload(): void {
    $user = Auth::requireAuth();

    $sync = new Sync($user['id'], $user['uuid']);
    $data = $sync->getFullData();

    Response::success(['ttData' => $data]);
}
