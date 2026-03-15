<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$pdo = db();
cleanupExpiredUploads($pdo);

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (preg_match('#^/dl/([a-f0-9]+)/.+\.glb$#i', $pathInfo, $m)) {
    $token = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM uploads WHERE share_token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $upload = $stmt->fetch();

    if (!$upload || !is_file($upload['stored_path'])) {
        http_response_code(404);
        exit('File not found');
    }

    header('Content-Type: model/gltf-binary');
    header('Content-Length: ' . safeFileSize($upload['stored_path']));
    header('Content-Disposition: inline; filename="' . rawurlencode($upload['original_name']) . '"');
    header('Cache-Control: public, max-age=3600');
    readfile($upload['stored_path']);
    exit;
}

$action = $_GET['action'] ?? 'home';
$previewToken = trim((string) ($_GET['preview'] ?? ''));
$flash = pullFlash();
$user = currentUser($pdo);
$users = [];
$uploads = [];
$allUploads = [];
$selectedUpload = null;
$tab = $_GET['tab'] ?? 'models';

if (!in_array($tab, ['models', 'upload', 'users'], true)) {
    $tab = 'models';
}

if ($action === 'logout') {
    session_unset();
    session_destroy();
    session_start();
    flash('success', t('flash_logged_out'));
    header('Location: ' . actionUrl());
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $code = trim((string) ($_POST['code'] ?? ''));

    if (!isValidAccessCode($code)) {
        flash('error', t('flash_bad_code'));
        header('Location: ' . actionUrl());
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $code]);
    $foundUser = $stmt->fetch();

    if (!$foundUser) {
        flash('error', t('flash_not_found'));
        header('Location: ' . actionUrl());
        exit;
    }

    $_SESSION['user_id'] = $foundUser['id'];
    flash('success', t('flash_login_ok'));
    header('Location: ' . actionUrl());
    exit;
}

if ($action === 'create-user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin($pdo);
    verifyCsrf();

    $code = trim((string) ($_POST['new_code'] ?? ''));
    $uploadLimit = max(1, (int) ($_POST['upload_limit'] ?? MAX_UPLOADS_PER_USER));
    $shouldGenerate = isset($_POST['generate_code']);

    if ($shouldGenerate || $code === '') {
        do {
            $code = generateAccessCode();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE code = :code LIMIT 1');
            $stmt->execute(['code' => $code]);
            $exists = (bool) $stmt->fetch();
        } while ($exists);
    }

    if (!isValidAccessCode($code)) {
        flash('error', t('flash_code_format'));
        header('Location: ' . actionUrl('home', ['tab' => 'users']));
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $code]);

    if ($stmt->fetch()) {
        flash('error', t('flash_code_exists'));
        header('Location: ' . actionUrl('home', ['tab' => 'users']));
        exit;
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (code, role, upload_limit, created_at)
         VALUES (:code, "user", :upload_limit, :created_at)'
    );
    $insert->execute([
        'code' => $code,
        'upload_limit' => $uploadLimit,
        'created_at' => now(),
    ]);

    flash('success', t('flash_user_created', $code, $uploadLimit));
    header('Location: ' . actionUrl('home', ['tab' => 'users']));
    exit;
}

if ($action === 'delete-user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin($pdo);
    verifyCsrf();

    $userId = (int) ($_POST['user_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $targetUser = $stmt->fetch();

    if (!$targetUser || $targetUser['role'] !== 'user') {
        flash('error', t('flash_user_404'));
        header('Location: ' . actionUrl('home', ['tab' => 'users']));
        exit;
    }

    $filesStmt = $pdo->prepare('SELECT stored_path FROM uploads WHERE user_id = :user_id');
    $filesStmt->execute(['user_id' => $userId]);
    foreach ($filesStmt->fetchAll() as $file) {
        if (is_file($file['stored_path'])) {
            @unlink($file['stored_path']);
        }
    }

    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);

    flash('success', t('flash_user_deleted'));
    header('Location: ' . actionUrl('home', ['tab' => 'users']));
    exit;
}

if ($action === 'update-limit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin($pdo);
    verifyCsrf();

    $userId = (int) ($_POST['user_id'] ?? 0);
    $uploadLimit = max(1, (int) ($_POST['upload_limit'] ?? MAX_UPLOADS_PER_USER));

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $targetUser = $stmt->fetch();

    if (!$targetUser || $targetUser['role'] !== 'user') {
        flash('error', t('flash_limit_404'));
        header('Location: ' . actionUrl('home', ['tab' => 'users']));
        exit;
    }

    $update = $pdo->prepare('UPDATE users SET upload_limit = :upload_limit WHERE id = :id');
    $update->execute([
        'upload_limit' => $uploadLimit,
        'id' => $userId,
    ]);

    flash('success', t('flash_limit_ok', $uploadLimit));
    header('Location: ' . actionUrl('home', ['tab' => 'users']));
    exit;
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireLogin($pdo);
    verifyCsrf();

    $currentMonth = (new DateTimeImmutable())->format('Y-m');
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM uploads WHERE user_id = :user_id AND substr(uploaded_at, 1, 7) = :month'
    );
    $countStmt->execute(['user_id' => $user['id'], 'month' => $currentMonth]);
    $uploadCount = (int) $countStmt->fetchColumn();
    $userLimit = userUploadLimit($user);

    if ($uploadCount >= $userLimit) {
        flash('error', t('flash_limit_reached', $userLimit));
        header('Location: ' . actionUrl());
        exit;
    }

    if (!isset($_FILES['model'])) {
        flash('error', t('flash_no_file'));
        header('Location: ' . actionUrl());
        exit;
    }

    $file = $_FILES['model'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', t('flash_upload_err'));
        header('Location: ' . actionUrl());
        exit;
    }

    if (($file['size'] ?? 0) > MAX_FILE_SIZE) {
        flash('error', t('flash_too_big'));
        header('Location: ' . actionUrl());
        exit;
    }

    $originalName = (string) ($file['name'] ?? 'model.glb');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension !== 'glb') {
        flash('error', t('flash_glb_only'));
        header('Location: ' . actionUrl());
        exit;
    }

    $userDir = STORAGE_DIR . '/' . $user['id'];
    if (!is_dir($userDir)) {
        mkdir($userDir, 0777, true);
    }

    $storedName = bin2hex(random_bytes(16)) . '.glb';
    $storedPath = $userDir . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        flash('error', t('flash_save_err'));
        header('Location: ' . actionUrl());
        exit;
    }

    $uploadedAt = new DateTimeImmutable();
    $expiresAt = $uploadedAt->modify('+' . FILE_LIFETIME_DAYS . ' days');

    $insert = $pdo->prepare(
        'INSERT INTO uploads (
            user_id, original_name, stored_name, stored_path, share_token, uploaded_at, expires_at
        ) VALUES (
            :user_id, :original_name, :stored_name, :stored_path, :share_token, :uploaded_at, :expires_at
        )'
    );
    $shareToken = bin2hex(random_bytes(24));
    $insert->execute([
        'user_id' => $user['id'],
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'stored_path' => $storedPath,
        'share_token' => $shareToken,
        'uploaded_at' => $uploadedAt->format('Y-m-d H:i:s'),
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    flash('success', t('flash_uploaded'));
    $redirectParams = ['preview' => $shareToken];
    if ($user['role'] === 'admin') {
        $redirectParams['tab'] = 'upload';
    }
    header('Location: ' . actionUrl('home', $redirectParams));
    exit;
}

if ($action === 'delete-upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireLogin($pdo);
    verifyCsrf();

    $uploadId = (int) ($_POST['upload_id'] ?? 0);
    $params = ['id' => $uploadId];
    $sql = 'SELECT * FROM uploads WHERE id = :id';

    if ($user['role'] !== 'admin') {
        $sql .= ' AND user_id = :user_id';
        $params['user_id'] = $user['id'];
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $upload = $stmt->fetch();

    if (!$upload) {
        flash('error', t('flash_file_404'));
        header('Location: ' . actionUrl());
        exit;
    }

    if (is_file($upload['stored_path'])) {
        @unlink($upload['stored_path']);
    }

    $delete = $pdo->prepare('DELETE FROM uploads WHERE id = :id');
    $delete->execute(['id' => $uploadId]);

    $redirectTab = trim((string) ($_POST['redirect_tab'] ?? ''));
    $redirectParams = in_array($redirectTab, ['models', 'upload', 'users'], true) ? ['tab' => $redirectTab] : [];
    flash('success', t('flash_file_deleted'));
    header('Location: ' . actionUrl('home', $redirectParams));
    exit;
}

if ($action === 'file') {
    $user = requireLogin($pdo);
    $uploadId = (int) ($_GET['id'] ?? 0);
    $params = ['id' => $uploadId];
    $sql = 'SELECT * FROM uploads WHERE id = :id';

    if ($user['role'] !== 'admin') {
        $sql .= ' AND user_id = :user_id';
        $params['user_id'] = $user['id'];
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $upload = $stmt->fetch();

    if (!$upload || !is_file($upload['stored_path'])) {
        http_response_code(404);
        exit('File not found');
    }

    $mode = ($_GET['mode'] ?? 'download') === 'inline' ? 'inline' : 'download';

    header('Content-Type: model/gltf-binary');
    header('Content-Length: ' . safeFileSize($upload['stored_path']));
    header('Content-Disposition: ' . $mode . '; filename="' . rawurlencode($upload['original_name']) . '"');
    readfile($upload['stored_path']);
    exit;
}

if ($action === 'public-file') {
    $token = trim((string) ($_GET['token'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM uploads WHERE share_token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $upload = $stmt->fetch();

    if (!$upload || !is_file($upload['stored_path'])) {
        http_response_code(404);
        exit('File not found');
    }

    header('Content-Type: model/gltf-binary');
    header('Content-Length: ' . safeFileSize($upload['stored_path']));
    header('Content-Disposition: inline; filename="' . rawurlencode($upload['original_name']) . '"');
    header('Cache-Control: public, max-age=3600');
    readfile($upload['stored_path']);
    exit;
}

if ($user && $user['role'] === 'admin') {
    $currentMonth = (new DateTimeImmutable())->format('Y-m');

    $usersStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.code,
            u.role,
            u.upload_limit,
            u.created_at,
            COUNT(up.id) AS uploads_count,
            SUM(CASE WHEN substr(up.uploaded_at, 1, 7) = :month THEN 1 ELSE 0 END) AS monthly_uploads
        FROM users u
        LEFT JOIN uploads up ON up.user_id = u.id
        GROUP BY u.id
        ORDER BY CASE WHEN u.role = "admin" THEN 0 ELSE 1 END, u.id DESC'
    );
    $usersStmt->execute(['month' => $currentMonth]);
    $users = $usersStmt->fetchAll();

    $allUploads = $pdo->query(
        'SELECT
            up.*,
            u.code AS user_code
        FROM uploads up
        INNER JOIN users u ON u.id = up.user_id
        ORDER BY up.uploaded_at DESC'
    )->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT * FROM uploads WHERE user_id = :user_id ORDER BY uploaded_at DESC'
    );
    $stmt->execute(['user_id' => $user['id']]);
    $uploads = $stmt->fetchAll();
} elseif ($user) {
    $stmt = $pdo->prepare(
        'SELECT * FROM uploads WHERE user_id = :user_id ORDER BY uploaded_at DESC'
    );
    $stmt->execute(['user_id' => $user['id']]);
    $uploads = $stmt->fetchAll();
}

function fileUrl(array $upload): string
{
    return scriptName() . '/dl/' . $upload['share_token'] . '/' . rawurlencode($upload['original_name']);
}

function fullFileUrl(array $upload): string
{
    return baseUrl() . '/' . ltrim(fileUrl($upload), '/');
}

if ($user) {
    $previewList = $uploads;

    if ($previewToken !== '') {
        foreach ($previewList as $upload) {
            if (($upload['share_token'] ?? '') === $previewToken) {
                $selectedUpload = $upload;
                break;
            }
        }
    }

    if (!$selectedUpload && $previewList) {
        $selectedUpload = $previewList[0];
    }
}

$currentLang = lang();
$otherLang = $currentLang === 'ru' ? 'kk' : 'ru';
$otherLangLabel = $currentLang === 'ru' ? 'Қаз' : 'Рус';
?>
<!doctype html>
<html lang="<?= $currentLang === 'kk' ? 'kk' : 'ru' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('site_title')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script type="module" src="https://cdn.jsdelivr.net/npm/@google/model-viewer/dist/model-viewer.min.js"></script>
    <style>
        body {
            min-height: 100vh;
            background: #f5f7fb;
        }

        .hero-card {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: #fff;
        }

        .glass-card {
            background: #fff;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        model-viewer {
            width: 100%;
            height: 520px;
            background: #f8f9fa;
            border-radius: 1rem;
        }

        .sidebar-nav .nav-link {
            color: #495057;
            border-radius: .5rem;
            padding: .65rem 1rem;
            font-weight: 500;
            transition: all .15s ease;
        }

        .sidebar-nav .nav-link:hover {
            background: #e9ecef;
            color: #212529;
        }

        .sidebar-nav .nav-link.active {
            background: #0d6efd;
            color: #fff;
        }

        .sidebar-nav .nav-link .badge {
            font-size: .7rem;
        }

        @media (max-width: 991.98px) {
            .sidebar-nav {
                flex-direction: row !important;
                overflow-x: auto;
                flex-wrap: nowrap;
                gap: .25rem !important;
            }

            .sidebar-nav .nav-link {
                white-space: nowrap;
                font-size: .875rem;
                padding: .5rem .75rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4 py-lg-5 px-3 px-lg-4">
        <div class="card hero-card border-0 shadow-lg mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-lg-center">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge text-bg-light text-primary">Hand-Track</span>
                            <a href="?lang=<?= $otherLang ?>" class="badge text-bg-light text-dark text-decoration-none"><?= $otherLangLabel ?></a>
                        </div>
                        <h1 class="display-6 fw-bold mb-2"><?= h(t('app_title')) ?></h1>
                        <p class="mb-0 opacity-75"><?= h(t('app_subtitle')) ?></p>
                    </div>
                    <?php if ($user): ?>
                        <div class="text-lg-end">
                            <div class="mb-2">
                                <span class="badge rounded-pill text-bg-light text-dark"><?= $user['role'] === 'admin' ? h(t('admin')) : h(t('member')) ?></span>
                            </div>
                            <div class="fw-semibold mb-3"><?= h(t('code_label')) ?>: <?= h($user['code']) ?></div>
                            <a class="btn btn-outline-light" href="<?= h(actionUrl('logout')) ?>"><?= h(t('logout')) ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-danger' ?> shadow-sm" role="alert">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$user): ?>
            <div class="row justify-content-center">
                <div class="col-12 col-md-8 col-lg-5">
                    <div class="card glass-card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h2 class="h3 mb-3"><?= h(t('login_title')) ?></h2>
                            <p class="text-body-secondary mb-4"><?= h(t('login_desc')) ?></p>
                            <form method="post" action="<?= h(actionUrl('login')) ?>" class="vstack gap-3">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <div>
                                    <label for="code" class="form-label"><?= h(t('access_code')) ?></label>
                                    <input
                                        id="code"
                                        type="text"
                                        name="code"
                                        inputmode="numeric"
                                        class="form-control form-control-lg"
                                        maxlength="<?= ACCESS_CODE_LENGTH ?>"
                                        pattern="\d{3}-\d{3}-\d{3}"
                                        data-code-mask="true"
                                        placeholder="<?= h(t('code_placeholder')) ?>"
                                        required
                                    >
                                    <div class="form-text"><?= h(t('code_format_hint')) ?></div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-lg"><?= h(t('login_btn')) ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($user['role'] === 'admin'): ?>
            <?php
                $usersCount = count(array_filter($users, static fn(array $row): bool => $row['role'] === 'user'));
                $currentUploads = $uploads;
                $adminMonthly = monthlyUploadCount($pdo, $user['id']);
                $adminLimit = userUploadLimit($user);
            ?>
            <div class="row g-4">
                <div class="col-12 col-lg-3 col-xl-2">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-2">
                            <nav class="nav flex-lg-column sidebar-nav gap-1">
                                <a class="nav-link d-flex align-items-center gap-2 <?= $tab === 'models' ? 'active' : '' ?>"
                                   href="<?= h(actionUrl('home', ['tab' => 'models'])) ?>">
                                    <i class="bi bi-box"></i>
                                    <span><?= h(t('my_3d_models')) ?></span>
                                    <span class="badge <?= $tab === 'models' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?> ms-auto"><?= count($currentUploads) ?></span>
                                </a>
                                <a class="nav-link d-flex align-items-center gap-2 <?= $tab === 'upload' ? 'active' : '' ?>"
                                   href="<?= h(actionUrl('home', ['tab' => 'upload'])) ?>">
                                    <i class="bi bi-cloud-upload"></i>
                                    <span><?= h(t('upload_model')) ?></span>
                                </a>
                                <a class="nav-link d-flex align-items-center gap-2 <?= $tab === 'users' ? 'active' : '' ?>"
                                   href="<?= h(actionUrl('home', ['tab' => 'users'])) ?>">
                                    <i class="bi bi-people"></i>
                                    <span><?= h(t('users')) ?></span>
                                    <span class="badge <?= $tab === 'users' ? 'text-bg-light text-primary' : 'text-bg-secondary' ?> ms-auto"><?= $usersCount ?></span>
                                </a>
                            </nav>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-9 col-xl-10">
                    <?php if ($tab === 'models'): ?>
                        <div class="card glass-card border-0 shadow-sm mb-4">
                            <div class="card-body p-4">
                                <h2 class="h4 mb-3"><?= h(t('view_3d')) ?></h2>
                                <?php if ($selectedUpload): ?>
                                    <model-viewer src="<?= h(fileUrl($selectedUpload)) ?>" camera-controls auto-rotate shadow-intensity="1" exposure="1"></model-viewer>
                                <?php else: ?>
                                    <div class="border rounded-4 p-5 text-center text-body-secondary"><?= h(t('no_model_preview')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card glass-card border-0 shadow-sm">
                            <div class="card-body p-4">
                                <h2 class="h4 mb-3"><?= h(t('my_files')) ?></h2>
                                <?php if (!$currentUploads): ?>
                                    <div class="text-center text-body-secondary py-4"><?= h(t('no_files')) ?></div>
                                <?php else: ?>
                                    <div class="row g-3">
                                        <?php foreach ($currentUploads as $upload): ?>
                                            <div class="col-12 col-md-6 col-xl-4">
                                                <div class="card border h-100 <?= ($selectedUpload && $selectedUpload['id'] === $upload['id']) ? 'border-primary' : '' ?>">
                                                    <div class="card-body p-3">
                                                        <div class="d-flex align-items-start justify-content-between mb-1">
                                                            <div class="fw-semibold text-truncate me-2" title="<?= h($upload['original_name']) ?>"><?= h($upload['original_name']) ?></div>
                                                            <form method="post" action="<?= h(actionUrl('delete-upload')) ?>" class="flex-shrink-0">
                                                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                                <input type="hidden" name="upload_id" value="<?= (int) $upload['id'] ?>">
                                                                <input type="hidden" name="redirect_tab" value="models">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                                                            </form>
                                                        </div>
                                                        <div class="small text-body-secondary mb-2"><?= h(formatBytes(safeFileSize($upload['stored_path']))) ?> &middot; <?= h($upload['uploaded_at']) ?></div>
                                                        <div class="d-flex gap-2">
                                                            <a class="btn btn-sm btn-outline-primary flex-grow-1" href="<?= h(actionUrl('home', ['tab' => 'models', 'preview' => $upload['share_token']])) ?>"><i class="bi bi-eye me-1"></i><?= h(t('open')) ?></a>
                                                            <input type="text" class="d-none" id="admin-link-<?= (int) $upload['id'] ?>" readonly value="<?= h(fullFileUrl($upload)) ?>">
                                                            <button type="button" class="btn btn-sm btn-dark flex-grow-1" data-copy-target="#admin-link-<?= (int) $upload['id'] ?>"><i class="bi bi-clipboard me-1"></i><?= h(t('copy')) ?></button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($tab === 'upload'): ?>
                        <div class="row g-4">
                            <div class="col-12 <?= $selectedUpload ? 'col-lg-6' : '' ?>">
                                <div class="card glass-card border-0 shadow-sm h-100">
                                    <div class="card-body p-4">
                                        <h2 class="h4 mb-3"><?= h(t('upload_3d')) ?></h2>
                                        <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
                                            <i class="bi bi-info-circle"></i>
                                            <span>
                                                <?= h(t('uploaded_month')) ?> <strong><?= $adminMonthly ?></strong> <?= h(t('of')) ?> <strong><?= $adminLimit ?></strong>.
                                                <?= h(t('limit_resets')) ?>
                                            </span>
                                        </div>
                                        <form method="post" action="<?= h(actionUrl('upload')) ?>" enctype="multipart/form-data" class="vstack gap-3">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <div>
                                                <label for="model" class="form-label"><?= h(t('glb_label')) ?></label>
                                                <input id="model" type="file" name="model" accept=".glb,model/gltf-binary" class="form-control form-control-lg" required>
                                            </div>
                                            <div>
                                                <button type="submit" class="btn btn-primary btn-lg">
                                                    <i class="bi bi-cloud-upload me-2"></i><?= h(t('upload_btn')) ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php if ($selectedUpload): ?>
                                <div class="col-12 col-lg-6">
                                    <div class="card glass-card border-0 shadow-sm h-100">
                                        <div class="card-body p-4">
                                            <h3 class="h5 mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i><?= h(t('uploaded_file')) ?></h3>
                                            <model-viewer src="<?= h(fileUrl($selectedUpload)) ?>" camera-controls auto-rotate shadow-intensity="1" exposure="1" style="height: 220px; border-radius: .75rem;"></model-viewer>
                                            <div class="mt-3 vstack gap-2">
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-body-secondary small"><?= h(t('file_name')) ?></span>
                                                    <span class="fw-semibold small"><?= h($selectedUpload['original_name']) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-body-secondary small"><?= h(t('file_size')) ?></span>
                                                    <span class="fw-semibold small"><?= h(formatBytes(safeFileSize($selectedUpload['stored_path']))) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-body-secondary small"><?= h(t('uploaded_at')) ?></span>
                                                    <span class="fw-semibold small"><?= h($selectedUpload['uploaded_at']) ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-body-secondary small"><?= h(t('expires_at')) ?></span>
                                                    <span class="fw-semibold small"><?= h($selectedUpload['expires_at']) ?></span>
                                                </div>
                                                <hr class="my-1">
                                                <input type="text" class="d-none" id="upload-preview-link" readonly value="<?= h(fullFileUrl($selectedUpload)) ?>">
                                                <button type="button" class="btn btn-dark w-100" data-copy-target="#upload-preview-link">
                                                    <i class="bi bi-clipboard me-2"></i><?= h(t('copy_link')) ?>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($tab === 'users'): ?>
                        <div class="card glass-card border-0 shadow-sm mb-4">
                            <div class="card-body p-4">
                                <h2 class="h4 mb-3"><?= h(t('create_user')) ?></h2>
                                <form method="post" action="<?= h(actionUrl('create-user')) ?>" class="row g-3 align-items-end">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <div class="col-12 col-lg-4">
                                        <label class="form-label"><?= h(t('access_code')) ?></label>
                                        <input
                                            id="new_code"
                                            type="text"
                                            name="new_code"
                                            inputmode="numeric"
                                            class="form-control"
                                            maxlength="<?= ACCESS_CODE_LENGTH ?>"
                                            pattern="\d{3}-\d{3}-\d{3}"
                                            data-code-mask="true"
                                            placeholder="123-123-123"
                                        >
                                    </div>
                                    <div class="col-12 col-lg-3">
                                        <label class="form-label"><?= h(t('monthly_limit')) ?></label>
                                        <input
                                            id="upload_limit"
                                            type="number"
                                            name="upload_limit"
                                            class="form-control"
                                            min="1"
                                            value="<?= MAX_UPLOADS_PER_USER ?>"
                                            required
                                        >
                                    </div>
                                    <div class="col-12 col-lg-5">
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="submit" class="btn btn-primary"><?= h(t('create_btn')) ?></button>
                                            <button type="submit" name="generate_code" value="1" class="btn btn-outline-primary"><?= h(t('generate_code')) ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card glass-card border-0 shadow-sm">
                            <div class="card-body p-4">
                                <h2 class="h4 mb-3"><?= h(t('all_users')) ?></h2>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th><?= h(t('code_label')) ?></th>
                                                <th><?= h(t('total_files')) ?></th>
                                                <th><?= h(t('this_month')) ?></th>
                                                <th><?= h(t('limit_month')) ?></th>
                                                <th><?= h(t('change')) ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                $hasUsers = false;
                                                foreach ($users as $row):
                                                    if ($row['role'] !== 'user') { continue; }
                                                    $hasUsers = true;
                                                    $monthlyUsed = (int) $row['monthly_uploads'];
                                                    $limit = (int) $row['upload_limit'];
                                                    $pct = $limit > 0 ? min(100, (int) round($monthlyUsed / $limit * 100)) : 0;
                                            ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= h($row['code']) ?></td>
                                                    <td><?= (int) $row['uploads_count'] ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="progress flex-grow-1" style="height: 6px; min-width: 60px;">
                                                                <div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= $pct ?>%"></div>
                                                            </div>
                                                            <span class="small text-nowrap fw-medium"><?= $monthlyUsed ?> / <?= $limit ?></span>
                                                        </div>
                                                    </td>
                                                    <td><?= $limit ?></td>
                                                    <td>
                                                        <form method="post" action="<?= h(actionUrl('update-limit')) ?>" class="d-flex gap-2">
                                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                            <input type="number" name="upload_limit" class="form-control form-control-sm" min="1" value="<?= $limit ?>" required style="width: 80px;">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary"><?= h(t('save')) ?></button>
                                                        </form>
                                                    </td>
                                                    <td>
                                                        <form method="post" action="<?= h(actionUrl('delete-user')) ?>" onsubmit="return confirm('<?= h(t('confirm_delete', $row['code'])) ?>')">
                                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (!$hasUsers): ?>
                                                <tr><td colspan="6" class="text-center text-body-secondary py-4"><?= h(t('no_users')) ?></td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <?php $userMonthly = monthlyUploadCount($pdo, $user['id']); $userLimit = userUploadLimit($user); ?>
            <div class="row g-4 mb-4">
                <div class="col-12 <?= $selectedUpload ? 'col-lg-6' : '' ?>">
                    <div class="card glass-card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-3"><?= h(t('uploader')) ?></h2>
                            <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
                                <i class="bi bi-info-circle"></i>
                                <span><?= h(t('uploaded_month')) ?> <strong><?= $userMonthly ?></strong> <?= h(t('of')) ?> <strong><?= $userLimit ?></strong></span>
                            </div>
                            <form method="post" action="<?= h(actionUrl('upload')) ?>" enctype="multipart/form-data" class="vstack gap-3">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <div>
                                    <label for="model" class="form-label"><?= h(t('glb_label')) ?></label>
                                    <input id="model" type="file" name="model" accept=".glb,model/gltf-binary" class="form-control form-control-lg" required>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-lg w-100">
                                        <i class="bi bi-cloud-upload me-2"></i><?= h(t('upload_btn')) ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php if ($selectedUpload): ?>
                    <div class="col-12 col-lg-6">
                        <div class="card glass-card border-0 shadow-sm h-100">
                            <div class="card-body p-4">
                                <h3 class="h5 mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i><?= h(t('uploaded_file')) ?></h3>
                                <model-viewer src="<?= h(fileUrl($selectedUpload)) ?>" camera-controls auto-rotate shadow-intensity="1" exposure="1" style="height: 220px; border-radius: .75rem;"></model-viewer>
                                <div class="mt-3 vstack gap-2">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-body-secondary small"><?= h(t('file_name')) ?></span>
                                        <span class="fw-semibold small"><?= h($selectedUpload['original_name']) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-body-secondary small"><?= h(t('file_size')) ?></span>
                                        <span class="fw-semibold small"><?= h(formatBytes(safeFileSize($selectedUpload['stored_path']))) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-body-secondary small"><?= h(t('uploaded_at')) ?></span>
                                        <span class="fw-semibold small"><?= h($selectedUpload['uploaded_at']) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-body-secondary small"><?= h(t('expires_at')) ?></span>
                                        <span class="fw-semibold small"><?= h($selectedUpload['expires_at']) ?></span>
                                    </div>
                                    <hr class="my-1">
                                    <input type="text" class="d-none" id="user-preview-link" readonly value="<?= h(fullFileUrl($selectedUpload)) ?>">
                                    <button type="button" class="btn btn-dark w-100" data-copy-target="#user-preview-link">
                                        <i class="bi bi-clipboard me-2"></i><?= h(t('copy_link')) ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card glass-card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h4 mb-3"><?= h(t('my_files')) ?></h2>
                    <?php if (!$uploads): ?>
                        <div class="text-center text-body-secondary py-4"><?= h(t('no_user_files')) ?></div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($uploads as $upload): ?>
                                <div class="col-12 col-md-6 col-xl-4">
                                    <div class="card border h-100 <?= ($selectedUpload && $selectedUpload['id'] === $upload['id']) ? 'border-primary' : '' ?>">
                                        <div class="card-body p-3">
                                            <div class="d-flex align-items-start justify-content-between mb-1">
                                                <div class="fw-semibold text-truncate me-2" title="<?= h($upload['original_name']) ?>"><?= h($upload['original_name']) ?></div>
                                                <form method="post" action="<?= h(actionUrl('delete-upload')) ?>" class="flex-shrink-0">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                    <input type="hidden" name="upload_id" value="<?= (int) $upload['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </div>
                                            <div class="small text-body-secondary mb-2"><?= h(formatBytes(safeFileSize($upload['stored_path']))) ?> &middot; <?= h($upload['uploaded_at']) ?></div>
                                            <div class="d-flex gap-2">
                                                <a class="btn btn-sm btn-outline-primary flex-grow-1" href="<?= h(actionUrl('home', ['preview' => $upload['share_token']])) ?>"><i class="bi bi-eye me-1"></i><?= h(t('open')) ?></a>
                                                <input type="text" class="d-none" id="user-link-<?= (int) $upload['id'] ?>" readonly value="<?= h(fullFileUrl($upload)) ?>">
                                                <button type="button" class="btn btn-sm btn-dark flex-grow-1" data-copy-target="#user-link-<?= (int) $upload['id'] ?>"><i class="bi bi-clipboard me-1"></i><?= h(t('copy')) ?></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('[data-code-mask]').forEach((input) => {
            const applyCodeMask = () => {
                const digits = input.value.replace(/\D/g, '').slice(0, 9);
                const parts = [];

                if (digits.length > 0) {
                    parts.push(digits.slice(0, 3));
                }
                if (digits.length > 3) {
                    parts.push(digits.slice(3, 6));
                }
                if (digits.length > 6) {
                    parts.push(digits.slice(6, 9));
                }

                input.value = parts.join('-');
            };

            input.addEventListener('input', applyCodeMask);
            input.addEventListener('blur', applyCodeMask);
        });

        document.querySelectorAll('[data-copy-target]').forEach((button) => {
            button.addEventListener('click', async () => {
                const selector = button.getAttribute('data-copy-target');
                const input = selector ? document.querySelector(selector) : null;
                const url = input ? input.value : '';

                if (!url) {
                    return;
                }

                try {
                    await navigator.clipboard.writeText(url);
                    const original = button.innerHTML;
                    button.textContent = <?= json_encode(t('link_copied')) ?>;

                    setTimeout(() => {
                        button.innerHTML = original;
                    }, 1500);
                } catch (error) {
                    window.prompt(<?= json_encode(t('copy_manual')) ?>, url);
                }
            });
        });
    </script>
</body>
</html>
