<?php
// POST /server/api/upload.php
// multipart/form-data:
//   glb  (file, 必填)  — GLTF Binary (.glb)
//   meta (text, 选填)  — 3AS 导出注释 JSON 字符串（ExportedData 结构，见 SPEC.md §1.1）
//   name (text, 选填)  — 项目显示名，缺省用上传文件名
//
// 成功响应（200）：
//   {"ok":true, "id":"<uuid>", "ownerToken":"<64位hex，仅本次返回>", "url":"<glb下载地址>"}
//
// owner token 只在这一次响应里返回明文，服务端只存它的 sha256 哈希（meta.json.ownerTokenHash）。
// 客户端要自己保管这个 token，之后修改/删除该项目都要带上它。

declare(strict_types=1);
require __DIR__ . '/_lib.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'method', 'only POST is allowed');
}

// ---- 1. 文件是否存在 / 上传阶段本身有没有出错 ----
if (!isset($_FILES['glb']) || !is_array($_FILES['glb'])) {
    json_fail(400, 'no-file', 'missing multipart field "glb"');
}
$file = $_FILES['glb'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    // UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE 说明 php.ini 或表单层面已经拒绝了超大文件
    json_fail(400, 'upload-error', 'upload error code ' . (string)$file['error']);
}
$tmpPath = $file['tmp_name'] ?? '';
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    json_fail(400, 'no-file', 'invalid upload');
}

// ---- 2. 大小上限：不能只信 php.ini 的 upload_max_filesize/post_max_size，代码层面再校验一次 ----
$sizeBytes = (int)($file['size'] ?? 0);
if ($sizeBytes <= 0) {
    json_fail(400, 'empty', 'uploaded file is empty');
}
if ($sizeBytes > MAX_UPLOAD_BYTES) {
    json_fail(413, 'too-large', 'file exceeds ' . (string)(MAX_UPLOAD_BYTES / 1024 / 1024) . 'MB limit');
}

// ---- 3. glTF magic bytes 校验：读文件头内容，不信 Content-Type / 扩展名 ----
if (!is_valid_glb_header($tmpPath)) {
    json_fail(415, 'bad-format', 'not a valid .glb (glTF binary magic/version check failed)');
}

// ---- 4. 可选注释 JSON（meta 字段） ----
$metaJsonRaw = null;
if (isset($_POST['meta']) && is_string($_POST['meta']) && $_POST['meta'] !== '') {
    if (strlen($_POST['meta']) > MAX_META_JSON_BYTES) {
        json_fail(413, 'meta-too-large', 'meta JSON exceeds size limit');
    }
    // 注意：故意不用 json_decode(..., true)（关联数组模式）。ExportedData 顶层是个 JSON
    // object，assoc 模式会把它和内部的空 object（比如 annotations:{}）都变成 PHP array，
    // 再 json_encode 回去时空 object 会被错误地写成 []，类型变了。用默认的 stdClass 模式
    // 保留 object/array 的区分，原样透传。
    $decoded = json_decode($_POST['meta']);
    if (!is_object($decoded)) {
        json_fail(400, 'bad-meta', 'meta field is not a valid JSON object');
    }
    $metaJsonRaw = $decoded;
}

// ---- 5. 项目显示名（选填，白名单式清洗，限长） ----
$name = '';
if (isset($_POST['name']) && is_string($_POST['name'])) {
    $name = trim($_POST['name']);
}
if ($name === '') {
    $name = is_string($file['name'] ?? null) ? basename((string)$file['name']) : '';
}
$name = mb_substr($name, 0, 128);

// ---- 6. 生成 ID / token，落盘 ----
$id = generate_uuid_v4();
$dir = project_dir($id);
if ($dir === null) {
    json_fail(500, 'internal', 'failed to resolve project dir');
}
if (is_dir($dir)) {
    // 理论上 UUID 碰撞概率极低，这里只是防御性处理
    json_fail(500, 'internal', 'id collision, retry');
}
if (!@mkdir($dir, 0775, true) || !@mkdir($dir . '/versions', 0775, true)) {
    json_fail(500, 'internal', 'failed to create project directory');
}

if (!move_uploaded_file($tmpPath, $dir . '/model.glb')) {
    rrmdir($dir);
    json_fail(500, 'write', 'failed to store uploaded file');
}

$ownerToken = generate_owner_token();
$ownerTokenHash = hash('sha256', $ownerToken);
$now = time();

$currentVersion = 0;
if ($metaJsonRaw !== null) {
    $currentVersion = 1;
    if (!write_json_locked($dir . '/versions/v1.3as.json', $metaJsonRaw)) {
        rrmdir($dir);
        json_fail(500, 'write', 'failed to store annotation JSON');
    }
}

$meta = [
    'id' => $id,
    'name' => $name,
    'createdAt' => $now,
    'updatedAt' => $now,
    'sizeBytes' => $sizeBytes,
    'ownerTokenHash' => $ownerTokenHash,
    'currentVersion' => $currentVersion,
];
if (!write_json_locked($dir . '/meta.json', $meta)) {
    rrmdir($dir);
    json_fail(500, 'write', 'failed to store project metadata');
}

json_ok([
    'id' => $id,
    'ownerToken' => $ownerToken, // 明文仅此一次
    'url' => glb_url($id),
]);
