# 3AS 后端设计草案

**状态**：草案 / 第一轮范围已定，未开始实现
**背景**：用户要求自建 PHP 后端，「前端后端都完备」，「比 3ds-viewer 更全面」。这份文档先把"更全面"具体化成可执行范围，把 3ds-viewer 现有后端的能力边界摸清楚作为对照基线。

---

## 一、对照基线：3ds-viewer（Chengduhuagao 案例）现有后端到底做了什么

实测读了 `C:\Users\Lin\projects\3ds-viewer\save.php` 和 `mods.php` 源码，如实记录，不是猜的：

| 文件 | 做什么 | 不做什么 |
|---|---|---|
| `save.php` | 接收一份 JSON（部件改名 `parts` + 查看器设置 `settings`，字段白名单校验），**覆盖写入同目录下唯一一个 `state.json`** | 不支持多用户、不支持历史版本（每次覆盖）、不接收 GLB 文件本身 |
| `mods.php` | 按 `author`（一个无密码、正则校验格式的自由字符串"用户名"）分文件存 JSON（`mods/<author>.json`），支持 save/rename/delete，GET 不带 id 时列出所有人的方案摘要 | 没有真正的账号/密码认证（author 就是个字符串，谁都能填别人的名字覆盖别人的方案）、**不支持上传 GLB**（一直操作的是同一个写死的模型文件）、没有版本历史（save 就是覆盖）、没有项目概念（只有"一个模型 + N 份方案"，不是"N 个模型各自的项目"） |

**一句话总结基线**：3ds-viewer 后端能做的事情 = 给同一个固定模型存/读多人的部件配色/位置方案，仅此而已。存储是扁平 JSON 文件 + 简单白名单校验，没有认证、没有版本、没有多项目、没有文件上传。安全意识是有的（字段白名单、长度限制、`LOCK_EX` 防并发写坏），这套习惯值得延续。

---

## 二、3AS 后端要覆盖的范围（对应原 SPEC.md Phase 2-5 路线图，这次是把它具体化成可执行清单）

按优先级排（第一轮做到"上传/存储/多项目"这三块就已经比 3ds-viewer 全面很多——3ds-viewer 完全没有 GLB 上传能力）：

### 2.1 GLB 上传与存储（第一轮范围）
- `POST /api/upload` 接收 GLB 文件（+ 可选的 3AS 注释 JSON），校验文件类型（glTF magic bytes）、大小上限、存到 `projects/<uuid>/model.glb` + `meta.3as.json`
- 返回项目 ID / 访问 URL
- 存储方式：**第一轮用文件系统，不上数据库**——参照 3ds-viewer 已经验证过的"每个实体一个 JSON 文件"模式，够用且实现简单，数据库留到需要复杂查询（比如按用户搜索项目）时再引入

### 2.2 多项目管理（第一轮范围）
- `GET /api/projects` 列出所有项目（摘要：id、文件名、缩略图（可选，先不做）、创建/更新时间）
- `GET /api/projects/<id>` 取回某个项目的 GLB + 注释 JSON
- `DELETE /api/projects/<id>` 删除项目（要有权限校验，见下面认证部分）

### 2.3 版本历史（第二轮，先设计好数据结构，不强求第一轮就有完整 UI）
- 每次保存不覆盖，存成 `projects/<id>/versions/v<N>.3as.json`，`meta.json` 记当前指向哪个版本号
- 第一轮至少要把存储结构设计成"版本历史友好"（比如从一开始就用版本号命名文件，即使暂时只保留最新版本），避免以后要迁移数据结构

### 2.4 团队协作/权限（第三轮，第一轮只做最基础的）
- 第一轮：简单的"创建者 token"机制（上传时生成一个私密 token，之后改/删这个项目需要带这个 token，类似 3ds-viewer 的 author 字符串但升级成真正随机不可猜测的 token，不是自由文本用户名）
- 更完整的账号系统（用户名密码/OAuth）不在第一轮范围，先记录在这里，等有实际多人协作需求再做

---

## 三、技术方案

- **语言**：PHP（已指定，跟 3ds-viewer 保持技术栈一致，部署环境已经跑得动 PHP）
- **存储**：文件系统，目录结构：
  ```
  server/
    api/
      upload.php       # POST 上传 GLB + 注释
      projects.php     # GET 列表 / GET 单个 / DELETE
      save.php         # POST 保存注释（新版本）
    projects/           # 数据目录，不进 git（跟 glb/ 一样的政策）
      <uuid>/
        model.glb
        meta.json       # {name, created, updated, ownerToken(hash), currentVersion}
        versions/
          v1.3as.json
          v2.3as.json
  ```
- **安全（延续 3ds-viewer 已验证的习惯，不重新发明）**：
  - 文件大小上限（3ds-viewer save.php 用了 262144 字节的例子，GLB 显然要大得多，按实际需求定，比如 200MB，参考 SPEC.md 已经写过的"超大文件警告"阈值）
  - 上传内容校验 glTF magic bytes（`glTF` 开头 + version=2），拒绝非 GLB 文件冒充
  - 所有写操作用 `LOCK_EX` 防并发写坏（照抄 3ds-viewer 的做法）
  - 项目 ID 用不可猜测的 UUID，owner token 只存哈希不存明文（3ds-viewer 的 author 字符串是明文自由文本，这块要升级）
  - 路径拼接严格校验 ID 格式（正则白名单，防路径穿越——3ds-viewer 的 `clean_author()` 已经是这个模式，延续）

---

## 四、前端接入点（对应 #21 头部菜单重构，**已实现，2026-08-06**）

草案阶段设想的接口路径是 `/api/projects`（无 `.php`，隐含某种 URL 重写规则）；实际落地沿用 §六「实现记录」定下的真实文件名，前端直接调这两个：

- 「打开GLB」下拉菜单「从服务器打开」→ 调 `GET server/api/projects.php` 列表选择 → 选中后 `GET server/api/projects.php?id=<id>` 拉取详情（含 `glbUrl` + 当前版本注释 JSON）→ 再单独 `fetch(glbUrl)` 拉取 GLB 二进制 → 走前端既有的 `openBuf()`/`preprocess()` 流程渲染，不是另开一条加载路径。
- 「另存为GLB」下拉菜单「保存到服务器」→ 调 `POST server/api/upload.php`（multipart，字段 `glb`/`meta`/`name`）。**这一轮只做「新建项目」**，没有接草案里提到的「更新已有项目」这条分支——`upload.php` 本身也没有带 id 的更新接口（那是 §2.3 版本历史留的第二轮范围），所以每次点「保存到服务器」都是新建一个项目。

路径用相对路径（`server/api/...`），前提是静态页面和后端 API 部署在同一个源下——本轮采用「单一 PHP 内置服务器同时托管 3as 项目根目录（含 `index.html` 和 `server/`）」这个架构，天然同源，不需要 CORS。具体见 `README.md`「快速开始 → 后端服务器」一节和 `Doc/EDITOR-SPEC.md` §10 后半的实现记录。

owner token 处理：上传成功后前端只在内存变量（`currentServerProject`）里记一份，不做 localStorage 持久化——跨会话（刷新/关浏览器）token 就丢失，这是任务明确要求的范围限制，不是遗漏。UI 用一个不会自动消失的浮层把 ID/Token 展示给用户，提示这是唯一一次显示、需要用户自己复制保管。

---

## 五、待确认 / 第一轮不做的

- 真实账号系统（密码/OAuth）——先用 owner token 顶替
- 项目分享链接的访问权限粒度（公开/私有/仅链接可见）——先全部当"有 token 才能改，任何人都能看"处理，等有需求再细化
- 部署环境（服务器在哪、域名、HTTPS）——不在这份文档范围内，需要用户另外提供

---

## 六、实现记录（第一轮：§2.1 + §2.2，2026-08-05）

对应 `Doc/TODO.md` #23。新建文件：

```
server/
  api/
    _lib.php        # 共享工具：json_fail/json_ok、clean_project_id、UUID生成、
                     # owner token生成、glTF magic bytes校验、递归删目录、URL拼接
    upload.php       # POST 上传
    projects.php     # GET 列表/单个、DELETE
  projects/          # 数据目录，运行时生成，.gitignore 已排除（新增 server/projects/* 规则）
  .user.ini          # 部署到 Apache/PHP-FPM 时的 upload_max_filesize/post_max_size 等，
                      # php -S 内置服务器不读取此文件（测试时改用 -d 命令行参数）
```

**跟设计草案的几处落地细节**：
- `meta.json` 字段用了 `{id, name, createdAt, updatedAt, sizeBytes, ownerTokenHash, currentVersion}`，跟本文档 §三 草图里的 `{name, created, updated, ownerToken(hash), currentVersion}` 基本一致，字段名统一成 camelCase 并加了 `id`/`sizeBytes` 方便列表接口直接读。
- 上传时不传注释 JSON 是合法的（比如先占位传 GLB，以后再传注释）——这种情况下 `currentVersion` 落盘为 `0`，`GET ?id=` 时 `annotations` 字段返回 `null`，不强行生成一个空版本文件。
- token 传递方式：`DELETE` 支持 header `X-Owner-Token: <token>`，或请求体 JSON `{"token":"..."}`（header 优先）。用 `hash_equals()` 做常量时间比较，防时序攻击。
- GLB 下载 URL 用 `dirname(dirname(SCRIPT_NAME))` 反推出 `server/` 这一级对应的 web 路径再拼 `/projects/<id>/model.glb`，这样部署到子目录（比如 `/3as/`）时也不会拼错。

**实现中发现并修的一个坑**：PHP 的 `json_decode($raw, true)`（关联数组模式）没法区分 JSON 里的空 object `{}` 和空 array `[]`——都会变成 PHP 的空数组，`json_encode` 再写回去时空 object 会被错误序列化成 `[]`，类型变了（比如 `annotations: {}` 存盘后变成 `annotations: []`，前端如果按 object 处理会报错）。修法：注释 JSON（`upload.php` 收 `meta` 字段、`projects.php` 读 `versions/v<N>.3as.json`）一律不用 assoc 模式解码，用默认的 `stdClass` 模式（`json_decode($raw)` 不传 `true`），靠 `is_object()` 校验顶层是不是合法的 `ExportedData` 结构，这样 object/array 的区分全程原样透传，不会跑形。用真实场景验证过：`annotations:{}` 存盘和 API 返回都保持 `{}`，不会变成 `[]`。

**安全检查清单逐条验证结果**：

| 检查项 | 验证方式 | 结果 |
|---|---|---|
| ID/文件名参数正则白名单后才拼路径 | `clean_project_id()` 严格匹配标准 UUID 格式，不合法直接 400/空字符串，`project_dir()` 里另加了 `..`/`/`/`\` 的纵深防御检查；用 `?id=../../../../etc/passwd` 和 URL 编码的 `..%2f..%2f..%2fwindows` 分别打 GET 和 DELETE，两者均 400 拒绝，未触达文件系统操作 | 通过 |
| 上传大小限制不只依赖 php.ini | `upload.php` 里 `MAX_UPLOAD_BYTES` 常量（200MB）在代码层再判一次；用一个 `upload_max_filesize=300M` 的第二个测试服务器（ini 层放宽到超过代码常量）上传 220MB 文件，代码层依然返回 413 `too-large`，证明代码层校验不是摆设 | 通过 |
| glTF magic bytes 真读文件内容 | `is_valid_glb_header()` 用 `fread()` 读文件头 12 字节，`unpack('Vmagic/Vversion/...')` 校验 magic == `0x46546C67`（小端序即 ASCII "glTF"）且 version == 2；把一个纯文本文件改名 `fake.glb` 上传，返回 415 `bad-format`，未被扩展名/Content-Type 骗过 | 通过 |
| token 只存哈希，明文仅返回一次 | `meta.json` 只写 `ownerTokenHash`（sha256），`upload.php` 响应体里的 `ownerToken` 明文只在这一次 HTTP 响应出现；`projects.php` 的列表和详情接口手动核对过响应体不含任何 `token` 相关字段（脚本里 grep -qi token 断言) | 通过 |
| 所有写文件操作用 LOCK_EX | `write_json_locked()`统一走 `file_put_contents(..., LOCK_EX)`，`meta.json`/`versions/v*.3as.json` 全部经过这个函数；GLB 本体用 `move_uploaded_file()`（新建目录里的原子性落盘，不存在并发覆盖场景） | 通过（代码审查确认，未做并发压测） |
| JSON 响应统一 Content-Type | `json_fail()`/`json_ok()` 统一 `header('Content-Type: application/json; charset=utf-8')`，跟 3ds-viewer `save.php`/`mods.php` 写法一致 | 通过 |

**端到端测试**（`_dev/test-backend.sh`，Bash + curl，可重跑）：本机 `php -d upload_max_filesize=200M -d post_max_size=210M -S 127.0.0.1:18280 -t server/` 起服务，用真实样品 `C:\Users\Lin\projects\3ds-viewer\chengdu-huagao-0801.glb`（1.1MB）跑通：① 上传拿到 `id`+`ownerToken` ② 列表能看到、不含 token 字段 ③ 详情能拿到 `glbUrl`+`annotations` ④ 假 token 删除返回 403 且目录未被删 ⑤ 真 token 删除返回 200 且目录真的消失（`is_dir()` 复查+再 GET 返回 404）⑥ 纯文本改后缀 `.glb` 上传被 415 拒绝 ⑦ `../` 路径穿越 id 在 GET 和 DELETE 上均被 400 拒绝。13 项断言全通过，控制台/服务端日志无异常报错。

**留给 #21（前端菜单）和后续版本历史/DELETE 之外的 save.php（§2.3）的接口约定**：
- 上传成功响应：`{"ok":true,"id":"<uuid>","ownerToken":"<64位hex>","url":"<glb相对路径>"}`
- 列表：`{"ok":true,"projects":[{id,name,createdAt,updatedAt,sizeBytes}, ...]}`（按 `updatedAt` 降序）
- 详情：`{"ok":true,"project":{id,name,createdAt,updatedAt,sizeBytes,currentVersion,glbUrl,annotations}}`（`annotations` 可能是 `null`）
- 删除失败（token 不对）：`{"ok":false,"err":"forbidden","msg":"..."}`，HTTP 403
- 所有失败响应统一 `{"ok":false,"err":"<机器可读错误码>","msg":"<人类可读补充>"}`

**#21 前端接入结果（2026-08-06）**：上面这份接口约定被前端逐字照抄，没有另猜格式——字段名 `glb`/`meta`/`name`、成功响应结构、列表/详情/删除的字段命名，`index.html` 里的调用代码跟这份文档一一对应。真实联调（不是只读代码）确认：`POST server/api/upload.php` 上传真的落盘到 `server/projects/<uuid>/`（`model.glb`+`meta.json`+`versions/v1.3as.json`）、`GET server/api/projects.php` 列表/详情能读到刚上传的项目、`meta.json` 确认只存 `ownerTokenHash` 不存明文。详见 `Doc/EDITOR-SPEC.md` §10 实现记录（前端菜单部分）。
