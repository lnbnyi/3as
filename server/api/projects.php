<?php
// /server/api/projects.php
//   GET               → 列出所有项目摘要（不含任何 token 字段）
//   GET  ?id=<uuid>   → 单个项目详情：GLB 下载 URL + 当前版本注释 JSON（不含任何 token 字段）
//   DELETE ?id=<uuid> → 删除项目，需要校验 owner token（header X-Owner-Token 或请求体 JSON {"token":"..."}）
//
// ID 一律先过 clean_project_id() 正则白名单，不合法直接 400，绝不会拼进文件路径参与
// 文件系统操作——防路径穿越，模式沿用 3ds-viewer mods.php 的 clean_author()。

declare(strict_types=1);
require __DIR__ . '/_lib.php';

header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];

/** 项目摘要（列表用），绝不包含 ownerTokenHash 等 token 相关字段 */
function project_summary(array $meta): array {
    return [
        'id' => $meta['id'] ?? '',
        'name' => $meta['name'] ?? '',
        'createdAt' => $meta['createdAt'] ?? 0,
        'updatedAt' => $meta['updatedAt'] ?? 0,
        'sizeBytes' => $meta['sizeBytes'] ?? 0,
    ];
}

if ($method === 'GET') {
    $rawId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';

    if ($rawId === '') {
        // ---- 列表 ----
        $root = projects_root();
        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = clean_project_id(basename($dir));
            if ($id === '') continue; // 目录名不是合法 UUID 就跳过（防御性，理论上不会发生）
            $meta = read_meta($id);
            if ($meta === null) continue;
            $out[] = project_summary($meta);
        }
        usort($out, fn($a, $b) => ($b['updatedAt'] ?? 0) <=> ($a['updatedAt'] ?? 0));
        json_ok(['projects' => $out]);
    }

    // ---- 单个项目 ----
    $id = clean_project_id($rawId);
    if ($id === '') {
        json_fail(400, 'bad-id', 'invalid project id format');
    }
    $meta = read_meta($id);
    $dir = project_dir($id);
    if ($meta === null || $dir === null || !is_dir($dir)) {
        json_fail(404, 'not-found', 'project does not exist');
    }

    $annotations = null;
    $currentVersion = (int)($meta['currentVersion'] ?? 0);
    if ($currentVersion > 0) {
        $versionFile = $dir . '/versions/v' . $currentVersion . '.3as.json';
        if (is_file($versionFile)) {
            $raw = file_get_contents($versionFile);
            // 同 upload.php：不用 assoc 模式，保留 JSON object/array 类型区分（见 upload.php 注释）
            $decoded = $raw === false ? null : json_decode($raw);
            if (is_object($decoded)) $annotations = $decoded;
        }
    }

    json_ok([
        'project' => [
            'id' => $meta['id'] ?? $id,
            'name' => $meta['name'] ?? '',
            'createdAt' => $meta['createdAt'] ?? 0,
            'updatedAt' => $meta['updatedAt'] ?? 0,
            'sizeBytes' => $meta['sizeBytes'] ?? 0,
            'currentVersion' => $currentVersion,
            'glbUrl' => glb_url($id),
            'annotations' => $annotations,
        ],
    ]);
}

if ($method === 'DELETE') {
    $rawId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $id = clean_project_id($rawId);
    if ($id === '') {
        json_fail(400, 'bad-id', 'invalid project id format');
    }

    // token: 先看 header X-Owner-Token，没有再看请求体 JSON { "token": "..." }
    $token = '';
    $headerToken = $_SERVER['HTTP_X_OWNER_TOKEN'] ?? '';
    if (is_string($headerToken) && $headerToken !== '') {
        $token = trim($headerToken);
    } else {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '' && strlen($raw) <= 4096) {
            $body = json_decode($raw, true);
            if (is_array($body) && isset($body['token']) && is_string($body['token'])) {
                $token = trim($body['token']);
            }
        }
    }

    $meta = read_meta($id);
    $dir = project_dir($id);
    if ($meta === null || $dir === null || !is_dir($dir)) {
        json_fail(404, 'not-found', 'project does not exist');
    }

    $storedHash = $meta['ownerTokenHash'] ?? '';
    if ($token === '' || !is_string($storedHash) || $storedHash === '' ||
        !hash_equals($storedHash, hash('sha256', $token))) {
        json_fail(403, 'forbidden', 'owner token mismatch');
    }

    if (!rrmdir($dir)) {
        json_fail(500, 'write', 'failed to delete project directory');
    }
    json_ok(['id' => $id]);
}

json_fail(405, 'method', 'only GET/DELETE are allowed');
