<?php
/**
 * JSON Response helper
 */
class Response {
    /**
     * Send a JSON success response
     */
    public static function success(array $data = [], int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true], $data));
        exit;
    }

    /**
     * Send a JSON error response
     */
    public static function error(string $message, int $code = 400, array $extra = []): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'success' => false,
            'error' => $message
        ], $extra));
        exit;
    }

    /**
     * Send CORS headers
     */
    public static function cors(): void {
        header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');

        // Handle preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Get JSON request body
     */
    public static function getJsonBody(): array {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return [];
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::error('Invalid JSON in request body', 400);
        }

        return $data;
    }

    /**
     * Validate required fields exist in data
     */
    public static function requireFields(array $data, array $fields): void {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            self::error('Missing required fields: ' . implode(', ', $missing), 400);
        }
    }
}
