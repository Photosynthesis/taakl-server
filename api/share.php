<?php
/**
 * Share API handlers
 *
 * Authenticated (owner): POST /api/shares, GET /api/shares, DELETE /api/shares/{id}
 * Public (token is the auth): GET /api/share/{token}
 */

/**
 * POST /api/shares
 * Create a read-only share link for a node branch.
 */
function handleCreateShare(): void {
    $user = Auth::requireAuth();
    $data = Response::getJsonBody();
    Response::requireFields($data, ['nodeUuid']);

    $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
    $result = Share::create((int)$user['id'], trim($data['nodeUuid']), $options);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'api.taakl.app';

    Response::success([
        'id' => $result['id'],
        'token' => $result['token'],
        // Token rides in the fragment so page requests never log it
        'url' => $scheme . '://' . $host . '/share/#' . $result['token'],
        'options' => $result['options'],
        'node' => $result['node']
    ], 201);
}

/**
 * GET /api/shares
 * List the current user's active shares (tokens are not recoverable).
 */
function handleListShares(): void {
    $user = Auth::requireAuth();
    Response::success(['shares' => Share::listForUser((int)$user['id'])]);
}

/**
 * DELETE /api/shares/{id}
 * Revoke a share. Idempotent.
 */
function handleRevokeShare(string $shareId): void {
    $user = Auth::requireAuth();

    if (!Share::revoke((int)$user['id'], (int)$shareId)) {
        Response::error('Share not found', 404);
    }

    Response::success(['message' => 'Share revoked']);
}

/**
 * GET /api/share/{token}
 * Public share payload. Unknown, revoked, expired, and deleted-root cases are
 * indistinguishable (uniform 404).
 */
function handlePublicShare(string $token): void {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');

    $share = Share::resolveToken($token);
    $payload = $share ? Share::buildPayload($share) : null;

    if (!$payload) {
        Response::error('Share not found', 404);
    }

    Share::touch((int)$share['id']);
    Response::success($payload);
}
