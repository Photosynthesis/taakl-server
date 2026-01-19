<?php
/**
 * Settings API handlers
 */

/**
 * GET /api/settings
 * Get user settings
 */
function handleGetSettings(): void {
    $user = Auth::requireAuth();

    $sync = new Sync($user['id'], $user['uuid']);
    $settings = $sync->getSettings();

    Response::success(['settings' => $settings]);
}

/**
 * PUT /api/settings
 * Update user settings
 */
function handleUpdateSettings(): void {
    $user = Auth::requireAuth();
    $data = Response::getJsonBody();

    $settings = $data['settings'] ?? $data;

    if (empty($settings) || !is_array($settings)) {
        Response::error('Invalid settings format', 400);
    }

    $sync = new Sync($user['id'], $user['uuid']);
    $sync->saveSettings($settings);

    Response::success(['message' => 'Settings updated successfully']);
}
