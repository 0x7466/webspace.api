<?php
/**
 * WebSpace API - Single-file REST API for file management
 *
 * Drop this file on any PHP-enabled web server and use HTTP requests
 * to manage files with a Bearer token for authentication.
 */

// =============================================================================
// CONFIGURATION
// =============================================================================

define('BEARER_TOKEN', '');

// =============================================================================
// AUTHENTICATION
// =============================================================================

function authenticate(): void
{
    if (empty(BEARER_TOKEN)) {
        http_response_code(500);
        echo 'Server misconfiguration: BEARER_TOKEN not set';
        exit;
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';

    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    }

    if ($token !== BEARER_TOKEN) {
        errorResponse(401, 'Unauthorized');
    }
}

authenticate();

// =============================================================================
// VALIDATION
// =============================================================================

function validatePath(string $path): string
{
    if (empty($path)) {
        errorResponse(400, 'Path is required');
    }

    // Prevent null bytes
    if (str_contains($path, "\0")) {
        errorResponse(400, 'Invalid path');
    }

    // Resolve to real path
    $realPath = realpath($path);

    if ($realPath === false) {
        // Path doesn't exist yet - check parent
        $parent = dirname($path);
        $realParent = realpath($parent);

        if ($realParent === false) {
            errorResponse(400, 'Invalid path');
        }

        // Return the non-existent path as-is for creation operations
        // but ensure parent is valid
        return $path;
    }

    return $realPath;
}

function pathExists(string $path): bool
{
    return realpath($path) !== false;
}

function isDirectory(string $path): bool
{
    $real = realpath($path);
    return $real !== false && is_dir($real);
}

function isFile(string $path): bool
{
    $real = realpath($path);
    return $real !== false && is_file($real);
}

// =============================================================================
// RESPONSE HELPERS
// =============================================================================

function jsonResponse(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function errorResponse(int $status, string $message): void
{
    jsonResponse($status, ['error' => $message]);
}

function rawFileResponse(string $path): void
{
    $realPath = realpath($path);
    if ($realPath === false || !is_file($realPath)) {
        errorResponse(404, 'File not found');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($realPath) ?: 'application/octet-stream';

    http_response_code(200);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($realPath));
    readfile($realPath);
    exit;
}

// =============================================================================
// HANDLERS
// =============================================================================

function handleGet(string $path): void
{
    if (str_ends_with($path, '/')) {
        // List directory
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            errorResponse(404, 'Directory not found');
        }

        $items = [];
        $entries = scandir($realPath);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $realPath . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($fullPath);

            $items[] = [
                'name' => $entry . ($isDir ? '/' : ''),
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? null : filesize($fullPath),
                'modified' => date('c', filemtime($fullPath)),
                'permissions' => substr(sprintf('%o', fileperms($fullPath)), -3),
            ];
        }

        jsonResponse(200, [
            'path' => $path,
            'items' => $items,
        ]);
    } else {
        // Read file
        rawFileResponse($path);
    }
}

function handlePost(string $path, bool $force): void
{
    $body = file_get_contents('php://input');

    if (str_ends_with($path, '/')) {
        // Create directory
        if (pathExists($path)) {
            errorResponse(409, 'Already exists');
        }

        if (!mkdir($path, 0755, true)) {
            errorResponse(500, 'Failed to create directory');
        }

        jsonResponse(201, ['message' => 'Directory created', 'path' => $path]);
    } else {
        // Create file
        $exists = pathExists($path);

        if ($exists && !$force) {
            errorResponse(409, 'File already exists');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($path, $body) === false) {
            errorResponse(500, 'Failed to write file');
        }

        $status = $exists ? 200 : 201;
        $message = $exists ? 'File overwritten' : 'File created';

        jsonResponse($status, ['message' => $message, 'path' => $path]);
    }
}

function handlePut(string $path): void
{
    if (str_ends_with($path, '/')) {
        errorResponse(400, 'PUT is only for files');
    }

    if (!pathExists($path)) {
        errorResponse(404, 'File not found');
    }

    $body = file_get_contents('php://input');

    if (file_put_contents($path, $body) === false) {
        errorResponse(500, 'Failed to write file');
    }

    jsonResponse(200, ['message' => 'File replaced', 'path' => $path]);
}

function handlePatch(string $path): void
{
    if (str_ends_with($path, '/')) {
        errorResponse(400, 'PATCH is only for files');
    }

    if (!pathExists($path)) {
        errorResponse(404, 'File not found');
    }

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if ($data === null || !isset($data['from_line'])) {
        errorResponse(400, 'Invalid JSON body. Required: from_line, content');
    }

    $fromLine = (int) $data['from_line'];
    $toLine = isset($data['to_line']) ? (int) $data['to_line'] : $fromLine;
    $newContent = $data['content'] ?? '';

    if ($fromLine < 1 || $toLine < $fromLine) {
        errorResponse(400, 'Invalid line range');
    }

    $realPath = realpath($path);
    $originalContent = file_get_contents($realPath);

    if ($originalContent === false) {
        errorResponse(500, 'Failed to read file');
    }

    // Detect line endings
    $lineEnding = "\n";
    if (str_contains($originalContent, "\r\n")) {
        $lineEnding = "\r\n";
    } elseif (str_contains($originalContent, "\r")) {
        $lineEnding = "\r";
    }

    // Normalize to \n for processing
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $originalContent);
    $lines = explode("\n", $normalizedContent);

    $totalLines = count($lines);

    // Handle trailing newline
    $hasTrailingNewline = str_ends_with($normalizedContent, "\n");
    if ($hasTrailingNewline && $lines[count($lines) - 1] === '') {
        array_pop($lines);
        $totalLines--;
    }

    if ($fromLine > $totalLines) {
        errorResponse(400, 'from_line exceeds file length');
    }

    $toLine = min($toLine, $totalLines);

    // Build new content
    $before = array_slice($lines, 0, $fromLine - 1);
    $after = array_slice($lines, $toLine);

    $newLines = explode("\n", $newContent);
    $result = array_merge($before, $newLines, $after);

    // Convert back to original line endings
    $output = implode($lineEnding, $result);

    // Preserve trailing newline if original had it
    if ($hasTrailingNewline) {
        $output .= $lineEnding;
    }

    if (file_put_contents($realPath, $output) === false) {
        errorResponse(500, 'Failed to write file');
    }

    jsonResponse(200, ['message' => 'File patched', 'path' => $path]);
}

function handleDelete(string $path): void
{
    if (str_ends_with($path, '/')) {
        // Delete directory
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            errorResponse(404, 'Directory not found');
        }

        $entries = scandir($realPath);
        $hasEntries = false;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $hasEntries = true;
                break;
            }
        }

        if ($hasEntries) {
            errorResponse(409, 'Directory not empty');
        }

        if (!rmdir($realPath)) {
            errorResponse(500, 'Failed to delete directory');
        }

        jsonResponse(204, []);
    } else {
        // Delete file
        if (!pathExists($path)) {
            errorResponse(404, 'File not found');
        }

        if (!unlink($path)) {
            errorResponse(500, 'Failed to delete file');
        }

        jsonResponse(204, []);
    }
}

// =============================================================================
// OPENAPI SPECIFICATION
// =============================================================================

function getOpenApiSpec(): array
{
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . $_SERVER['PHP_SELF'];

    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'WebSpace API',
            'description' => 'Single-file REST API for file management on web servers',
            'version' => '1.0.0',
        ],
        'servers' => [
            ['url' => $baseUrl, 'description' => 'Current server'],
        ],
        'security' => [
            ['BearerAuth' => []],
        ],
        'components' => [
            'securitySchemes' => [
                'BearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                ],
            ],
            'schemas' => [
                'DirectoryItem' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Entry name with trailing / for directories'],
                        'type' => ['type' => 'string', 'enum' => ['file', 'directory']],
                        'size' => ['type' => 'integer', 'nullable' => true, 'description' => 'File size in bytes, null for directories'],
                        'modified' => ['type' => 'string', 'format' => 'date-time'],
                        'permissions' => ['type' => 'string', 'description' => 'Unix permissions (e.g., "644")'],
                    ],
                ],
                'DirectoryListing' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'items' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/DirectoryItem'],
                        ],
                    ],
                ],
                'ErrorResponse' => [
                    'type' => 'object',
                    'properties' => [
                        'error' => ['type' => 'string'],
                    ],
                ],
                'SuccessResponse' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'path' => ['type' => 'string'],
                    ],
                ],
                'PatchBody' => [
                    'type' => 'object',
                    'required' => ['from_line', 'content'],
                    'properties' => [
                        'from_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Starting line number (1-indexed)'],
                        'to_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Ending line number (inclusive). Defaults to from_line'],
                        'content' => ['type' => 'string', 'description' => 'New content for the line range'],
                    ],
                ],
            ],
        ],
        'paths' => [
            '/' => [
                'get' => [
                    'summary' => 'List directory contents',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Directory path (must end with /)'],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Directory listing',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/DirectoryListing'],
                                ],
                            ],
                        ],
                        '404' => [
                            'description' => 'Directory not found',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Create directory',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Directory path (must end with /)'],
                    ],
                    'responses' => [
                        '201' => ['description' => 'Directory created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                        '409' => ['description' => 'Already exists', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete empty directory',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Directory path (must end with /)'],
                    ],
                    'responses' => [
                        '204' => ['description' => 'Directory deleted'],
                        '404' => ['description' => 'Directory not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                        '409' => ['description' => 'Directory not empty', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
            ],
            '/file' => [
                'get' => [
                    'summary' => 'Read file',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'File path (must not end with /)'],
                    ],
                    'responses' => [
                        '200' => ['description' => 'File content', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                        '404' => ['description' => 'File not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
                'post' => [
                    'summary' => 'Create file',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'File path (must not end with /)'],
                        ['name' => 'force', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean'], 'description' => 'Overwrite if exists'],
                    ],
                    'requestBody' => [
                        'description' => 'File content',
                        'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'File overwritten (force=true)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                        '201' => ['description' => 'File created', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                        '409' => ['description' => 'File already exists', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
                'put' => [
                    'summary' => 'Replace file',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'File path (must not end with /)'],
                    ],
                    'requestBody' => [
                        'description' => 'New file content',
                        'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'File replaced', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                        '404' => ['description' => 'File not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
                'patch' => [
                    'summary' => 'Patch file (line replacement)',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'File path (must not end with /)'],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PatchBody']]],
                    ],
                    'responses' => [
                        '200' => ['description' => 'File patched', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessResponse']]]],
                        '400' => ['description' => 'Invalid request', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                        '404' => ['description' => 'File not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete file',
                    'parameters' => [
                        ['name' => 'path', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'File path (must not end with /)'],
                    ],
                    'responses' => [
                        '204' => ['description' => 'File deleted'],
                        '404' => ['description' => 'File not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                    ],
                ],
            ],
        ],
    ];
}

// =============================================================================
// MAIN ROUTER
// =============================================================================

// OpenAPI spec endpoint
if (isset($_GET['openapi'])) {
    header('Content-Type: application/json');
    echo json_encode(getOpenApiSpec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$path = $_GET['path'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if (empty($path)) {
    errorResponse(400, 'path parameter is required');
}

// Security: prevent path traversal attempts
if (str_contains($path, '..')) {
    errorResponse(400, 'Invalid path');
}

// Validate path (but don't require existence for creation ops)
validatePath($path);

$force = isset($_GET['force']) && $_GET['force'] === 'true';

switch ($method) {
    case 'GET':
        handleGet($path);
        break;

    case 'POST':
        handlePost($path, $force);
        break;

    case 'PUT':
        handlePut($path);
        break;

    case 'PATCH':
        handlePatch($path);
        break;

    case 'DELETE':
        handleDelete($path);
        break;

    default:
        errorResponse(405, 'Method not allowed');
}
