<?php
// 3AS 后端共享工具函数（upload.php / projects.php 共用）
// 安全习惯延续 3ds-viewer 的 save.php / mods.php：字段白名单、正则校验 ID、LOCK_EX 防并发写坏。

declare(strict_types=1);

const PROJECTS_DIRNAME = 'projects';
const MAX_UPLOAD_BYTES = 200 * 1024 * 1024; // 200MB，SPEC.md §6.1 提到的阈值
const MAX_META_JSON_BYTES = 10 * 1024 * 1024; // 注释 JSON 上限，够用且防止滥用

function json_fail(int $code, string $err, string $msg = ''): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'err' => $err, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** projects/ 数据目录绝对路径（server/projects），确保存在 */
function projects_root(): string {
    $dir = __DIR__ . '/../' . PROJECTS_DIRNAME;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * 严格校验项目 ID 格式：标准 UUID（8-4-4-4-12 十六进制）。
 * 不合法直接返回空字符串，调用方必须检查空字符串并拒绝——防路径穿越，
 * 模式沿用 3ds-viewer mods.php 的 clean_author()。
 */
function clean_project_id(string $v): string {
    $v = trim($v);
    if (strlen($v) > 64) return '';
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
        return '';
    }
    return strtolower($v);
}

/** 生成 UUID v4（项目 ID 用，不可猜测） */
function generate_uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/** 生成 owner token 明文（32 字节随机 hex = 64 字符），只在生成那一刻返回给客户端 */
function generate_owner_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * 给定已校验过的 project id，返回其目录绝对路径。
 * 调用方必须先用 clean_project_id() 校验过 $id 非空，这里不再二次校验格式，
 * 但作为纵深防御仍会拒绝任何包含 '..' 或路径分隔符的输入。
 */
function project_dir(string $id): ?string {
    if ($id === '' || str_contains($id, '..') || str_contains($id, '/') || str_contains($id, '\\')) {
        return null;
    }
    return projects_root() . '/' . $id;
}

function read_meta(string $id): ?array {
    $dir = project_dir($id);
    if ($dir === null) return null;
    $file = $dir . '/meta.json';
    if (!is_file($file)) return null;
    $raw = file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** 写 JSON 到文件，LOCK_EX 防并发写坏，统一 UNESCAPED_UNICODE + PRETTY_PRINT */
function write_json_locked(string $path, $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/** 递归删除目录（删除项目用） */
function rrmdir(string $dir): bool {
    if (!is_dir($dir)) return false;
    $items = scandir($dir);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
}

/**
 * 站点根 URL 前缀：从当前脚本的 SCRIPT_NAME 反推出 server/ 这一级对应的 web 路径，
 * 这样部署在子目录（比如 /3as/）时生成的 URL 依然正确。
 * 例：SCRIPT_NAME = /3as/server/api/upload.php → 前缀 = /3as/server
 */
function server_base_url(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/server/api/x.php';
    // .../server/api/xxx.php -> 去掉最后两段 (api, xxx.php)
    $base = str_replace('\\', '/', dirname(dirname($script)));
    return rtrim($base, '/');
}

function glb_url(string $id): string {
    return server_base_url() . '/' . PROJECTS_DIRNAME . '/' . $id . '/model.glb';
}

/**
 * 读取文件头前 12 字节校验 glTF binary (.glb) magic bytes：
 * uint32 magic = 0x46546C67 (小端序，ASCII 即 "glTF")，uint32 version 紧随其后应为 2。
 * 只信文件头内容，不信 Content-Type / 扩展名。
 */
function is_valid_glb_header(string $tmpPath): bool {
    $fh = @fopen($tmpPath, 'rb');
    if ($fh === false) return false;
    $head = fread($fh, 12);
    fclose($fh);
    if ($head === false || strlen($head) < 12) return false;
    $parts = unpack('Vmagic/Vversion/Vlength', $head);
    if ($parts === false) return false;
    // 0x46546C67 小端序读出来的 uint32 恰好等于 ASCII "glTF" 按小端解释的值
    if ($parts['magic'] !== 0x46546C67) return false;
    if ((int)$parts['version'] !== 2) return false;
    return true;
}
