# 3AS 部署指南

当前版本：**Alpha 0.003a**（2026-08-08）。这份文档讲"怎么把 3as 放到一台真实服务器上能被别人访问"，跟 `README.md`「快速开始」里给开发者本机跑起来的那几行命令是同一件事的**生产环境版本**——本机测试和这里说的部署，用的是同一套架构（静态页面 + PHP 后端合并成同一个源），只是这次目标是长期对外提供服务，不是临时开发调试。

## 0. 先搞清楚要不要后端

3AS 分两层，**先决定你要哪一层**，决定了下面走哪条路径：

- **纯前端功能**：打开本地 GLB、编辑材质/贴图/节点/场景/多模型注释、视口拖拽 gizmo、导出注释 JSON、另存为 GLB（含解包成 glTF 分离格式）、相机视角——**这些全部不需要后端**，任何静态文件服务器都能跑，浏览器本地完成全部工作，不联网也行。
- **后端功能**：头部菜单「打开 GLB → 从服务器打开」「另存为 GLB → 保存到服务器」这两项，需要一个 PHP 后端（`server/` 目录）。

如果你只是想把 3as 当一个"本地/内网工具"发给同事用，走**方案 A（纯静态）**就够，5 分钟搞定，跳到第 1 节。如果需要"多人上传/共享项目"这个协作功能，走**方案 B（静态+PHP后端）**，跳到第 2 节。

---

## 1. 方案 A：纯静态部署（不需要后端）

### 1.1 需要上传到服务器的文件

只需要仓库根目录，**排除**以下几类（这些本来就是 `.gitignore` 排除掉、不会出现在 `git clone` 结果里的东西，正常走 git 部署天然不会带上）：

```
排除：
  _dev/              # 开发/测试脚本和临时产物，不是产品的一部分
  server/             # 只在方案B需要，纯静态部署完全不用它
  glb/*（除了 chengdu-huagao-0801.glb 那个案例样品）
  .git/               # 版本控制元数据，不需要上服务器
```

**保留**（这些就是产品本体）：

```
index.html            # 整个应用，唯一的 HTML 入口
vendor/                # three.js 0.166.1 本地化依赖（loaders/exporters/controls），必须跟着一起部署
glb/chengdu-huagao-0801.glb   # 内置示例模型，可选，不带也不影响功能
README.md / SPEC.md / CHANGELOG.md   # 文档，不影响运行，带不带都行
```

### 1.2 部署方式（任选一种）

3as 是纯静态文件（HTML + 本地化 ES 模块，无构建步骤），**任何能托管静态文件的地方都能跑**：

**a) Nginx**
```nginx
server {
    listen 80;
    server_name 3as.example.com;
    root /var/www/3as;
    index index.html;

    # ES 模块用 import map，浏览器要求 .js 文件的 MIME 类型正确
    location ~ \.js$ {
        default_type application/javascript;
    }
}
```

**b) Apache**（默认已经能正确识别 `.js` MIME 类型，通常不需要额外配置，直接把仓库内容放进 `DocumentRoot` 即可）

**c) 任何静态托管平台**（GitHub Pages / Cloudflare Pages / Vercel 静态项目 / 各类对象存储的静态网站托管）——上传第 1.1 节列出的保留文件即可，不需要构建命令，不需要 Node/npm。

**d) 内网快速分享**（不装 Nginx/Apache 也行）：
```bash
cd 3as
python -m http.server 8000
# 或
php -S 0.0.0.0:8000 -t .
```
局域网内其他人访问 `http://<你的内网IP>:8000` 即可，适合"发给同事临时用一下"这种场景，不适合长期对外服务。

### 1.3 验证清单

- [ ] 打开部署好的地址，看到 3AS 标题栏和 "Alpha 0.003a" 版本号
- [ ] 拖一个 `.glb` 文件进视口，能正常预处理并显示四个 Tab
- [ ] 材质 Tab 能编辑颜色，视口实时反映
- [ ] 「另存为 GLB ▾ → 本地文件」能正常下载
- [ ] 浏览器 F12 控制台没有 404（尤其检查 `vendor/` 下的 three.js 文件是不是都部署上去了——这是最常见的漏文件问题）

---

## 2. 方案 B：静态 + PHP 后端（支持「从服务器打开」「保存到服务器」）

### 2.1 环境要求

- **PHP 7.4 及以上**（代码没有用到 PHP 8 专属语法，7.4/8.x 都能跑，具体以你实际环境为准做一次冒烟测试）
- 不需要数据库——后端是纯文件存储（`server/projects/<uuid>/` 每个项目一个目录，`meta.json` + GLB 二进制 + 版本化的注释 JSON）
- 不需要 Composer/任何 PHP 包管理器，`server/api/*.php` 零第三方依赖

### 2.2 关键架构原则：静态页面和后端必须同源

**必须把 `index.html` 和 `server/` 部署在同一个域名下**（比如都在 `3as.example.com` 根目录，`server/api/upload.php` 对应 `3as.example.com/server/api/upload.php`）。这不是可选项——前端调用后端接口用的是相对路径 `fetch('server/api/...')`，天然假设同源，`server/api/*.php` 目前**没有内置 CORS 响应头**。如果你执意要把静态页面和 PHP 后端分开部署成两个不同域名/端口，需要自己在 `server/api/_lib.php` 里加 `Access-Control-Allow-Origin` 等响应头（本项目至今没有实现这层，见 `Doc/BACKEND-SPEC.md`），不建议这样做，直接同源部署更简单可靠。

### 2.3 目录结构（上传到服务器时保持一致）

```
3as/                          ← 网站根目录（或子目录，比如 example.com/3as/ 也可以，见下面「部署到子目录」）
  index.html
  vendor/
  server/
    api/
      _lib.php
      upload.php
      projects.php
    projects/                 ← 【重要】运行时生成，部署时需要手动创建这个空目录并给写权限
    .user.ini                 ← 部署到 Apache/PHP-FPM 时会被自动读取，调整上传大小限制用
```

### 2.4 部署步骤

**① 创建并授权数据目录**（`server/projects/` 在仓库里是空的，`.gitignore` 排除了里面的内容，部署时要确保这个目录存在且 PHP 进程有写权限）：

```bash
mkdir -p server/projects
chown -R www-data:www-data server/projects   # Apache/Nginx 常见运行用户，按你的实际环境调整
chmod 755 server/projects
```

**② 配置 Web 服务器**（PHP-FPM 场景，Nginx 示例）：

```nginx
server {
    listen 80;
    server_name 3as.example.com;
    root /var/www/3as;
    index index.html;

    location ~ \.js$ {
        default_type application/javascript;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # 按你实际的 PHP-FPM socket 路径改
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 防止直接列出 server/projects/ 目录内容（虽然文件名是 UUID 不容易猜，多一层保险无害）
    location ~ ^/server/projects/ {
        autoindex off;
    }
}
```

Apache（`.htaccess` 通常不需要额外配置，`mod_php` 或 `php-fpm` + `mod_proxy_fcgi` 默认就能跑 `.php` 文件；如果站点开了 `Options +Indexes`，建议在 `server/projects/` 下放一个空的 `index.html` 或用 `Options -Indexes` 关掉目录浏览）。

**③ `.user.ini` 生效确认**：这个文件调的是上传大小上限（默认 200MB，`upload.php` 代码层也会再校验一次，不是只靠这个文件），**只有 Apache/PHP-FPM 会自动读取它**，如果你用的是 `php -S` 内置开发服务器测试，这个文件不会生效（内置服务器不读 `.user.ini`），需要改用命令行 `-d` 参数：

```bash
php -d upload_max_filesize=200M -d post_max_size=210M -S 127.0.0.1:8000 -t .
```

生产环境（Apache/PHP-FPM）正常部署 `.user.ini` 就会自动生效，不需要额外配置——除非你的 PHP-FPM 池配置了 `user_ini.filename = ""` 关闭了这个功能，那种情况需要改成在 `php-fpm` 的 pool 配置文件里直接设 `php_admin_value[upload_max_filesize] = 200M`。

**④ 部署到子目录**（如果不是部署在域名根目录，比如 `example.com/tools/3as/`）：不需要改任何代码——`server/api/*.php` 的 GLB 下载 URL 是用 `dirname(dirname(SCRIPT_NAME))` 动态反推出来的相对路径，天然适配子目录部署（`Doc/BACKEND-SPEC.md` 已经验证过这一点）。

### 2.5 部署后验证清单

- [ ] 方案 A 的全部验证项（静态页面本身要先能跑）
- [ ] 头部「打开 GLB ▾」能看到「从服务器打开…」选项
- [ ] 「另存为 GLB ▾ → 保存到服务器…」——上传一个测试 GLB，能拿到项目 ID 和 64 位 Owner Token（**这个 token 只显示一次，弹窗会提示，测试时记得复制下来**）
- [ ] 「打开 GLB ▾ → 从服务器打开…」能看到刚上传的项目，点击能正常加载
- [ ] 检查 `server/projects/` 目录下真的生成了 `<uuid>/meta.json` + `<uuid>/model.glb`
- [ ] 用错误的 token 尝试删除项目，应该收到 403（用浏览器开发者工具直接改一下请求体里的 token 试，或用 curl：
  ```bash
  curl -X DELETE "https://3as.example.com/server/api/projects.php?id=<项目ID>" \
    -H "X-Owner-Token: 0000000000000000000000000000000000000000000000000000000000000000"
  # 期望：HTTP 403
  ```
- [ ] 上传一个改了后缀名的假 GLB（比如把 `.txt` 改成 `.glb`），应该被拒绝（HTTP 415）——验证 magic bytes 校验没被绕过
- [ ] 浏览器 F12 → Network，确认「保存到服务器」的响应体里**不包含** `ownerTokenHash` 或明文之外任何 token 相关字段的泄漏

### 2.6 安全说明（已实现的防护，部署时不需要额外配置，仅供了解）

后端在 `Doc/BACKEND-SPEC.md` 里有完整的安全检查清单和验证记录，部署时不需要你做额外配置，这里摘要列一下已经内置的防护，方便你判断够不够用：

| 防护项 | 实现方式 |
|---|---|
| 路径穿越 | 项目 ID 走 UUID 正则白名单校验，`../`/`..%2f` 等一律拒绝（400），不会拼出仓库外的文件路径 |
| 上传大小 | 代码层硬编码 200MB 上限，独立于 `.user.ini`/`php.ini` 配置——就算 ini 层配置错误放宽了限制，代码层仍会拦截 |
| 文件类型伪造 | 不信任文件扩展名/`Content-Type`，实际读文件头 12 字节校验 glTF magic bytes |
| 越权删除 | Owner token 只存 SHA-256 哈希，明文只在上传成功那一次响应里出现；删除接口用 `hash_equals()` 做防时序攻击的常量时间比较 |
| 并发写坏 | 全部 JSON 落盘操作走 `LOCK_EX` |

**没有实现、如果你的场景需要请自行加装的部分**（如实列出，不是隐瞒）：
- 没有身份认证/登录系统——任何知道 URL 的人都能上传新项目（`upload.php` 没有访问控制），如果不想对公网完全开放上传功能，建议在 Web 服务器层加一道 Basic Auth 或者只在内网/VPN 环境暴露这个后端
- 没有速率限制——理论上可以被脚本刷爆磁盘空间，公网部署建议在 Nginx 层加 `limit_req`
- Owner token 目前只在内存变量里（不写 localStorage），刷新页面/关浏览器就会丢失，用户必须当场复制保管，这是产品设计的既定范围（见 `Doc/EDITOR-SPEC.md` §10 实现记录），不是部署配置能解决的

---

## 3. 更新已部署的版本

3as 无构建步骤，更新就是"把新版本的文件覆盖旧的"：

```bash
git pull origin master        # 或者你自己的部署流程：rsync / scp / CI 自动同步
```

**唯一需要注意的**：`server/projects/` 目录（用户已上传的项目数据）**绝对不能被覆盖/清空**——如果你的部署流程是"整个目录删掉重新解压"这种粗暴方式，务必先把 `server/projects/` 单独备份出来，更新完再放回去；用 `git pull`/`rsync --exclude` 这类增量同步方式天然不会碰到这个目录（`.gitignore` 已经排除，`git pull` 不会动它）。

版本号目前手动维护在两处，发新版本时记得一起改：`index.html` 里 `<span class="ver">` 那行、`README.md` 底部「版本」那行、`CHANGELOG.md` 加一条新记录。

---

## 4. 回滚

纯前端文件回滚很简单——`git checkout <上一个版本的commit> -- index.html vendor/` 或者直接把旧版本文件重新部署上去。**后端数据格式目前没有做过破坏性变更**（`server/projects/` 里的数据结构从 #23 落地后一直没改过字段），回滚前端版本不会影响已存的后端项目数据；如果未来后端数据结构发生变更，届时需要额外确认新旧版本的数据兼容性，当前版本不涉及这个问题。
