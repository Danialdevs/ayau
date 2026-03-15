<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const STORAGE_DIR = APP_ROOT . '/storage/uploads';
const DB_PATH = DATA_DIR . '/app.sqlite';
const LEGACY_ADMIN_CODE = '576576576';
const ADMIN_CODE = '849-371-562';
const ACCESS_CODE_LENGTH = 11;
const MAX_UPLOADS_PER_USER = 50;
const MAX_FILE_SIZE = 100 * 1024 * 1024;
const FILE_LIFETIME_DAYS = 30;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Qyzylorda');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ru', 'kk'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

const TRANSLATIONS = [
    'ru' => [
        'site_title' => '3D загрузчик Аяулым Махан',
        'app_title' => '3D загрузчик',
        'app_subtitle' => 'Аяулым Махан',
        'admin' => 'Администратор',
        'member' => 'Участник',
        'code_label' => 'Код',
        'logout' => 'Выйти',
        'login_title' => 'Вход по коду',
        'login_desc' => 'Введите ваш код доступа.',
        'access_code' => 'Код доступа',
        'code_placeholder' => 'Например: 123-123-123',
        'code_format_hint' => 'Код должен быть в формате 123-123-123.',
        'login_btn' => 'Войти',
        'my_3d_models' => 'Мои 3D модели',
        'upload_model' => 'Загрузить модель',
        'users' => 'Пользователи',
        'view_3d' => 'Просмотр 3D',
        'no_model_preview' => 'Нет модели для просмотра',
        'my_files' => 'Мои файлы',
        'no_files' => 'Пока нет файлов',
        'open' => 'Открыть',
        'copy' => 'Копировать',
        'upload_3d' => 'Загрузить 3D модель',
        'uploaded_month' => 'Загружено в этом месяце:',
        'of' => 'из',
        'limit_resets' => 'Лимит обновится 1 числа следующего месяца.',
        'glb_label' => 'GLB-файл (до 100 MB)',
        'upload_btn' => 'Загрузить',
        'uploaded_file' => 'Загруженный файл',
        'file_name' => 'Название',
        'file_size' => 'Размер',
        'uploaded_at' => 'Загружено',
        'expires_at' => 'Удалится',
        'copy_link' => 'Скопировать ссылку',
        'create_user' => 'Создать пользователя',
        'monthly_limit' => 'Лимит в месяц',
        'create_btn' => 'Создать',
        'generate_code' => 'Сгенерировать код',
        'all_users' => 'Все пользователи',
        'total_files' => 'Всего файлов',
        'this_month' => 'В этом месяце',
        'limit_month' => 'Лимит/мес',
        'change' => 'Изменить',
        'save' => 'Сохранить',
        'no_users' => 'Пока нет пользователей',
        'uploader' => 'Загрузчик',
        'no_user_files' => 'У вас пока нет файлов.',
        'link_copied' => 'Сілтеме көшірілді',
        'copy_manual' => 'Скопируйте ссылку вручную:',
        'flash_logged_out' => 'Вы вышли из системы.',
        'flash_bad_code' => 'Введите код в формате 123-123-123.',
        'flash_not_found' => 'Код не найден.',
        'flash_login_ok' => 'Вход выполнен.',
        'flash_code_format' => 'Код должен быть в формате 123-123-123.',
        'flash_code_exists' => 'Такой код уже существует.',
        'flash_user_created' => 'Пользователь создан. Код доступа: %s. Лимит в месяц: %d.',
        'flash_user_404' => 'Пользователь не найден или его нельзя удалить.',
        'flash_user_deleted' => 'Пользователь и все его файлы удалены.',
        'flash_limit_404' => 'Пользователь для изменения лимита не найден.',
        'flash_limit_ok' => 'Месячный лимит обновлен: %d загрузок в месяц.',
        'flash_limit_reached' => 'Достигнут месячный лимит: максимум %d загрузок в месяц.',
        'flash_no_file' => 'Файл не был передан.',
        'flash_upload_err' => 'Ошибка загрузки файла.',
        'flash_too_big' => 'Файл слишком большой. Допустимо до 100 MB.',
        'flash_glb_only' => 'Разрешены только файлы формата .glb.',
        'flash_save_err' => 'Не удалось сохранить файл.',
        'flash_uploaded' => 'Файл загружен. Он будет храниться 30 дней.',
        'flash_file_404' => 'Файл не найден.',
        'flash_file_deleted' => 'Файл удален.',
        'confirm_delete' => 'Удалить пользователя %s и все его файлы?',
    ],
    'kk' => [
        'site_title' => '3D жүктеуші Аяулым Махан',
        'app_title' => '3D жүктеуші',
        'app_subtitle' => 'Аяулым Махан',
        'admin' => 'Әкімші',
        'member' => 'Қатысушы',
        'code_label' => 'Код',
        'logout' => 'Шығу',
        'login_title' => 'Кодпен кіру',
        'login_desc' => 'Кіру кодыңызды енгізіңіз.',
        'access_code' => 'Кіру коды',
        'code_placeholder' => 'Мысалы: 123-123-123',
        'code_format_hint' => 'Код 123-123-123 форматында болуы керек.',
        'login_btn' => 'Кіру',
        'my_3d_models' => 'Менің 3D модельдерім',
        'upload_model' => 'Модель жүктеу',
        'users' => 'Пайдаланушылар',
        'view_3d' => '3D көру',
        'no_model_preview' => 'Көрсетуге модель жоқ',
        'my_files' => 'Менің файлдарым',
        'no_files' => 'Әзірге файлдар жоқ',
        'open' => 'Ашу',
        'copy' => 'Көшіру',
        'upload_3d' => '3D модель жүктеу',
        'uploaded_month' => 'Осы айда жүктелді:',
        'of' => 'ішінен',
        'limit_resets' => 'Лимит келесі айдың 1-інде жаңарады.',
        'glb_label' => 'GLB-файл (100 MB дейін)',
        'upload_btn' => 'Жүктеу',
        'uploaded_file' => 'Жүктелген файл',
        'file_name' => 'Атауы',
        'file_size' => 'Өлшемі',
        'uploaded_at' => 'Жүктелді',
        'expires_at' => 'Жойылады',
        'copy_link' => 'Сілтемені көшіру',
        'create_user' => 'Пайдаланушы құру',
        'monthly_limit' => 'Айлық лимит',
        'create_btn' => 'Құру',
        'generate_code' => 'Код генерациялау',
        'all_users' => 'Барлық пайдаланушылар',
        'total_files' => 'Барлық файлдар',
        'this_month' => 'Осы айда',
        'limit_month' => 'Лимит/ай',
        'change' => 'Өзгерту',
        'save' => 'Сақтау',
        'no_users' => 'Әзірге пайдаланушылар жоқ',
        'uploader' => 'Жүктеуші',
        'no_user_files' => 'Сізде әзірге файлдар жоқ.',
        'link_copied' => 'Сілтеме көшірілді',
        'copy_manual' => 'Сілтемені қолмен көшіріңіз:',
        'flash_logged_out' => 'Сіз жүйеден шықтыңыз.',
        'flash_bad_code' => 'Кодты 123-123-123 форматында енгізіңіз.',
        'flash_not_found' => 'Код табылмады.',
        'flash_login_ok' => 'Сәтті кірдіңіз.',
        'flash_code_format' => 'Код 123-123-123 форматында болуы керек.',
        'flash_code_exists' => 'Мұндай код бұрыннан бар.',
        'flash_user_created' => 'Пайдаланушы құрылды. Кіру коды: %s. Айлық лимит: %d.',
        'flash_user_404' => 'Пайдаланушы табылмады немесе жою мүмкін емес.',
        'flash_user_deleted' => 'Пайдаланушы және барлық файлдары жойылды.',
        'flash_limit_404' => 'Лимитті өзгерту үшін пайдаланушы табылмады.',
        'flash_limit_ok' => 'Айлық лимит жаңартылды: айына %d жүктеу.',
        'flash_limit_reached' => 'Айлық лимитке жеттіңіз: айына ең көбі %d жүктеу.',
        'flash_no_file' => 'Файл жіберілмеді.',
        'flash_upload_err' => 'Файлды жүктеу қатесі.',
        'flash_too_big' => 'Файл тым үлкен. 100 MB дейін рұқсат етіледі.',
        'flash_glb_only' => 'Тек .glb форматындағы файлдар рұқсат етіледі.',
        'flash_save_err' => 'Файлды сақтау мүмкін болмады.',
        'flash_uploaded' => 'Файл жүктелді. 30 күн сақталады.',
        'flash_file_404' => 'Файл табылмады.',
        'flash_file_deleted' => 'Файл жойылды.',
        'confirm_delete' => '%s пайдаланушыны және барлық файлдарын жою керек пе?',
    ],
];

function lang(): string
{
    return $_SESSION['lang'] ?? 'ru';
}

function t(string $key, mixed ...$args): string
{
    $text = TRANSLATIONS[lang()][$key] ?? TRANSLATIONS['ru'][$key] ?? $key;
    return $args ? sprintf($text, ...$args) : $text;
}

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
