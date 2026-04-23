# WebSpace API

A single-file REST API for file management on web servers. Drop it on any PHP-enabled server and manage files via HTTP.

## Features

- **Zero dependencies** — Pure PHP, no composer, no libraries
- **Single file** — Copy one file, you're done
- **RESTful** — Standard HTTP methods and status codes
- **OpenAPI spec** — Built-in API documentation at `?openapi`
- **Bearer token auth** — Simple, secure authentication
- **Binary-safe** — Upload images, archives, any file type

## Installation

1. Copy `webspace.api.php` to your web server
2. Set a secure `BEARER_TOKEN` at the top of the file
3. Done

```php
define('BEARER_TOKEN', 'your-secure-random-token-here');
```

## Quick Start

```bash
# List directory
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://example.com/webspace.api.php?path=/var/www/html/"

# Read file
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://example.com/webspace.api.php?path=/var/www/html/config.php"

# Create file
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
  -d "Hello, World!" \
  "https://example.com/webspace.api.php?path=/var/www/html/hello.txt"

# Create directory
curl -X POST -H "Authorization: Bearer YOUR_TOKEN" \
  "https://example.com/webspace.api.php?path=/var/www/html/newfolder/"

# Replace file
curl -X PUT -H "Authorization: Bearer YOUR_TOKEN" \
  -d "New content" \
  "https://example.com/webspace.api.php?path=/var/www/html/hello.txt"

# Patch file (replace lines 5-8)
curl -X PATCH -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"from_line": 5, "to_line": 8, "content": "new line 5\nnew line 6"}' \
  "https://example.com/webspace.api.php?path=/var/www/html/config.php"

# Delete file
curl -X DELETE -H "Authorization: Bearer YOUR_TOKEN" \
  "https://example.com/webspace.api.php?path=/var/www/html/hello.txt"

# Delete empty directory
curl -X DELETE -H "Authorization: Bearer YOUR_TOKEN" \
  "https://example.com/webspace.api.php?path=/var/www/html/newfolder/"
```

## API Reference

### Path Convention

- **Trailing `/`** = directory
- **No trailing `/`** = file

| Method | Path Ends With | Action |
|--------|---------------|--------|
| `GET` | `/` | List directory |
| `GET` | no `/` | Read file |
| `POST` | `/` | Create directory |
| `POST` | no `/` | Create file |
| `PUT` | no `/` | Replace file |
| `PATCH` | no `/` | Patch file (line replacement) |
| `DELETE` | `/` | Delete empty directory |
| `DELETE` | no `/` | Delete file |

### Endpoints

#### List Directory

```http
GET /webspace.api.php?path=/var/www/html/
Authorization: Bearer YOUR_TOKEN
```

**Response:**
```json
{
  "path": "/var/www/html/",
  "items": [
    {
      "name": "index.php",
      "type": "file",
      "size": 2048,
      "modified": "2026-04-22T10:30:00+00:00",
      "permissions": "644"
    },
    {
      "name": "uploads/",
      "type": "directory",
      "size": null,
      "modified": "2026-04-22T09:00:00+00:00",
      "permissions": "755"
    }
  ]
}
```

#### Read File

```http
GET /webspace.api.php?path=/var/www/html/config.php
Authorization: Bearer YOUR_TOKEN
```

**Response:** Raw file content with detected MIME type

#### Create File

```http
POST /webspace.api.php?path=/var/www/html/newfile.txt
Authorization: Bearer YOUR_TOKEN
Content-Type: text/plain

Your file content here
```

**Response:** `201 Created` or `409 Conflict` if file exists

#### Create File (Force Overwrite)

```http
POST /webspace.api.php?path=/var/www/html/existing.txt&force=true
Authorization: Bearer YOUR_TOKEN

New content
```

**Response:** `200 OK` (overwritten) or `201 Created` (new file)

#### Create Directory

```http
POST /webspace.api.php?path=/var/www/html/newfolder/
Authorization: Bearer YOUR_TOKEN
```

**Response:** `201 Created`

#### Replace File

```http
PUT /webspace.api.php?path=/var/www/html/config.php
Authorization: Bearer YOUR_TOKEN

Complete new file content
```

**Response:** `200 OK` or `404 Not Found`

#### Patch File (Line Replacement)

```http
PATCH /webspace.api.php?path=/var/www/html/config.php
Authorization: Bearer YOUR_TOKEN
Content-Type: application/json

{
  "from_line": 5,
  "to_line": 8,
  "content": "define('DEBUG', true);\ndefine('VERSION', '2.0');"
}
```

**Parameters:**
- `from_line` (required): Starting line number (1-indexed)
- `to_line` (optional): Ending line number (inclusive). Defaults to `from_line`
- `content` (required): New content for the line range

**Response:** `200 OK`

Line endings are auto-detected and preserved.

#### Delete File

```http
DELETE /webspace.api.php?path=/var/www/html/unwanted.txt
Authorization: Bearer YOUR_TOKEN
```

**Response:** `204 No Content`

#### Delete Directory

```http
DELETE /webspace.api.php?path=/var/www/html/emptyfolder/
Authorization: Bearer YOUR_TOKEN
```

**Response:** `204 No Content` or `409 Conflict` if not empty

### OpenAPI Specification

Access the full OpenAPI 3.1.0 spec:

```http
GET /webspace.api.php?openapi
Authorization: Bearer YOUR_TOKEN
```

## Error Responses

All errors return JSON:

```json
{
  "error": "Error message here"
}
```

| Status | Meaning |
|--------|---------|
| `400` | Bad request (invalid path, missing parameters) |
| `401` | Unauthorized (invalid or missing token) |
| `404` | Not found |
| `405` | Method not allowed |
| `409` | Conflict (already exists, directory not empty) |
| `500` | Server error |

## Security

### Bearer Token

Set a strong, random token:

```php
define('BEARER_TOKEN', 'your-very-long-random-secure-token-here');
```

Generate one:
```bash
openssl rand -hex 32
```

### HTTPS

**Always use HTTPS in production.** The Bearer token is sent in clear text with every request.

### Path Traversal Protection

The API blocks `..` in paths. Additional validation ensures paths resolve correctly.

### Recommendations

- Use a long, random token (32+ characters)
- Restrict file permissions on the server
- Consider IP whitelisting at the web server level
- Log requests for audit trails

## Requirements

- PHP 7.4+ (uses `str_starts_with`, `str_ends_with`, `str_contains`)
- Read/write permissions for target directories

## License

MIT License — see [LICENSE](LICENSE) file.

## Contributing

Issues and pull requests welcome at the repository.

## Changelog

### v1.0.0

- Initial release
- Full CRUD operations for files and directories
- Line-based file patching
- OpenAPI 3.1.0 specification
- Bearer token authentication