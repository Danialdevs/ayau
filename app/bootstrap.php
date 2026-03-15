<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const STORAGE_DIR = APP_ROOT . '/storage/uploads';
const DB_PATH = DATA_DIR . '/app.sqlite';
const LEGACY_ADMIN_CODE = '576576576';
const ADMIN_CODE = '576-576-576';
const ACCESS_CODE_LENGTH = 11;
const MAX_UPLOADS_PER_USER = 50;
const MAX_FILE_SIZE = 100 * 1024 * 1024;
const FILE_LIFETIME_DAYS = 30;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Qyzylorda');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0777, true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initializeDatabase($pdo);

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            role TEXT NOT NULL CHECK(role IN ("admin", "user")),
            upload_limit INTEGER NOT NULL DEFAULT 50,
            created_at TEXT NOT NULL
        )'
    );

    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $hasUploadLimit = false;

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'upload_limit') {
            $hasUploadLimit = true;
            break;
        }
    }

    if (!$hasUploadLimit) {
        $pdo->exec('ALTER TABLE users ADD COLUMN upload_limit INTEGER NOT NULL DEFAULT 50');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            stored_path TEXT NOT NULL,
            share_token TEXT NOT NULL UNIQUE,
            uploaded_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $uploadColumns = $pdo->query('PRAGMA table_info(uploads)')->fetchAll();
    $hasShareToken = false;

    foreach ($uploadColumns as $column) {
        if (($column['name'] ?? '') === 'share_token') {
            $hasShareToken = true;
            break;
        }
    }

    if (!$hasShareToken) {
        $pdo->exec('ALTER TABLE uploads ADD COLUMN share_token TEXT');

        $uploadsWithoutToken = $pdo->query('SELECT id FROM uploads WHERE share_token IS NULL OR share_token = ""')->fetchAll();
        $updateToken = $pdo->prepare('UPDATE uploads SET share_token = :share_token WHERE id = :id');

        foreach ($uploadsWithoutToken as $upload) {
            $updateToken->execute([
                'share_token' => bin2hex(random_bytes(24)),
                'id' => $upload['id'],
            ]);
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_uploads_share_token ON uploads(share_token)');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => ADMIN_CODE]);
    $adminUser = $stmt->fetch();

    if (!$adminUser) {
        $legacyStmt = $pdo->prepare('SELECT id FROM users WHERE code = :code LIMIT 1');
        $legacyStmt->execute(['code' => LEGACY_ADMIN_CODE]);
        $legacyAdminUser = $legacyStmt->fetch();

        if ($legacyAdminUser) {
            $pdo->prepare('UPDATE users SET code = :new_code WHERE id = :id')
                ->execute([
                    'new_code' => ADMIN_CODE,
                    'id' => $legacyAdminUser['id'],
                ]);
            $adminUser = $legacyAdminUser;
        }
    }

    if (!$adminUser) {
        $insert = $pdo->prepare(
            'INSERT INTO users (code, role, upload_limit, created_at)
             VALUES (:code, "admin", :upload_limit, :created_at)'
        );
        $insert->execute([
            'code' => ADMIN_CODE,
            'upload_limit' => MAX_UPLOADS_PER_USER,
            'created_at' => now(),
        ]);
    } else {
        $pdo->prepare('UPDATE users SET upload_limit = :upload_limit WHERE code = :code')
            ->execute([
                'upload_limit' => MAX_UPLOADS_PER_USER,
                'code' => ADMIN_CODE,
            ]);
    }
}

function now(): string
{
    return (new DateTimeImmutable())->format('Y-m-d H:i:s');
}

function cleanupExpiredUploads(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT id, stored_path FROM uploads WHERE expires_at <= :now');
    $stmt->execute(['now' => now()]);

    foreach ($stmt->fetchAll() as $upload) {
        if (is_file($upload['stored_path'])) {
            @unlink($upload['stored_path']);
        }
    }

    $delete = $pdo->prepare('DELETE FROM uploads WHERE expires_at <= :now');
    $delete->execute(['now' => now()]);
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF token mismatch');
    }
}

function currentUser(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function requireLogin(PDO $pdo): array
{
    $user = currentUser($pdo);

    if (!$user) {
        header('Location: /');
        exit;
    }

    return $user;
}

function requireAdmin(PDO $pdo): array
{
    $user = requireLogin($pdo);

    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Access denied');
    }

    return $user;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isValidAccessCode(string $code): bool
{
    return preg_match('/^\d{3}-\d{3}-\d{3}$/', $code) === 1;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    $size = (float) $bytes;

    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return number_format($size, $index === 0 ? 0 : 2, '.', ' ') . ' ' . $units[$index];
}

function safeFileSize(string $path): int
{
    return is_file($path) ? (int) filesize($path) : 0;
}

function userUploadLimit(array $user): int
{
    $limit = (int) ($user['upload_limit'] ?? MAX_UPLOADS_PER_USER);

    return max(1, $limit);
}

function monthlyUploadCount(PDO $pdo, int $userId): int
{
    $currentMonth = (new DateTimeImmutable())->format('Y-m');
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM uploads WHERE user_id = :user_id AND substr(uploaded_at, 1, 7) = :month'
    );
    $stmt->execute(['user_id' => $userId, 'month' => $currentMonth]);

    return (int) $stmt->fetchColumn();
}

function generateAccessCode(): string
{
    return sprintf(
        '%03d-%03d-%03d',
        random_int(0, 999),
        random_int(0, 999),
        random_int(0, 999)
    );
}

function scriptName(): string
{
    return $_SERVER['SCRIPT_NAME'] ?? '/index.php';
}

function actionUrl(string $action = 'home', array $params = []): string
{
    $query = $params;

    if ($action !== 'home') {
        $query['action'] = $action;
    }

    $qs = http_build_query($query);

    return scriptName() . ($qs !== '' ? '?' . $qs : '');
}

function baseUrl(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $scriptDir = str_replace('\\', '/', dirname(scriptName()));
    $scriptDir = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir;
}

function absoluteActionUrl(string $action = 'home', array $params = []): string
{
    return baseUrl() . '/' . ltrim(actionUrl($action, $params), '/');
}
