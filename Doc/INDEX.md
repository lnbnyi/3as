# 3as/Doc 文件索引与规范

这个文件夹放**设计文档、todo、以及未来的调研/决策记录**。跟根目录的 `README.md`/`SPEC.md`/`CHANGELOG.md` 分工不同：根目录三份是「项目门面」文档（GitHub 打开仓库第一眼看到的东西，保持在根目录是惯例，不搬进来），`Doc/` 是内部工作文档。

## 当前文件

| 文件 | 用途 | 更新方式 |
|---|---|---|
| `INDEX.md` | 本文件，索引 + 命名规范 | 每次 Doc/ 里加新文件，回来这里加一行 |
| `EDITOR-SPEC.md` | Editor Mode（查看/编辑模式）设计草案 | 持续修订，不是一次性记录 |
| `TODO.md` | 对应 EDITOR-SPEC.md 的实现进度清单 | 做完一项勾一项，和 harness 内部的 TaskList 保持同步 |
| `BACKEND-SPEC.md` | 自建 PHP 后端设计草案（GLB 上传/多项目/版本历史），对照 3ds-viewer 现有 save.php/mods.php 基线定的范围 | 持续修订，不是一次性记录 |
| `EDITOR-VIEWER-CONTRACT.md` | editor(3AS)/viewer(3ds-viewer演化) 两页面架构的数据契约——两层数据模型、组锁定(atomicGroup)、版本号、测量基点优先级、包围盒同步 | 持续修订，不是一次性记录 |
| `2026-08-05-viewer-capability-mobile-comparison.md` | Babylon.js/model-viewer/Sketchfab 能力与手机端开销细化对比（延伸 EDITOR-SPEC.md 第 12 节） | 一次性记录，不追加修订 |
| `2026-08-05-glb-topology-question.md` | GLB 是否保留贴图之外的拓扑信息、重新拓扑代价调研 | 一次性记录，不追加修订 |
| `2026-08-05-viewer-comparison-table.html` | Babylon.js/model-viewer/three.js 三方对比表（可视化版，本地双击直接用浏览器打开），是上面那份 viewer 对比笔记的延伸——把 Sketchfab 换成了 three.js | 一次性记录，不追加修订；同内容也发布在 claude.ai Artifact |
| `2026-08-05-ui-review-panel-density.html` | huashu-design 评审：头部按钮排 + 材质/模型块表面板密度，含真实截图对照、折叠改进方案（本地双击直接用浏览器打开） | 一次性记录，不追加修订；同内容也发布在 claude.ai Artifact |
| `2026-08-05-ui-review-v2-loaded-state.html` | huashu-design 评审 V2：加载模型后的可见性/实用性/CSS 统一性复查（延续第一版视觉语言） | 一次性记录，不追加修订；同内容也发布在 claude.ai Artifact |

## 惯例：开发/测试产物放 `_dev/`，不放 `Doc/`

2026-08-05 起：Playwright 测试脚本、验证用的截图、合成的测试 GLB 这类东西，放项目根目录的 `_dev/`（已加进 `.gitignore`，不进仓库），不要堆进 `Doc/`——`Doc/` 只放设计文档/todo/调研笔记这三类，混进测试产物会让这个索引表失去意义。`Doc/EDITOR-SPEC.md`/`Doc/TODO.md` 里提到"测试脚本见 `Doc/xxx.js`"这种历史记录，路径可能会因为清理挪到 `_dev/` 而过期，发现的话顺手改一下路径。

## 惯例：临时性的调研/提问，之后都记录在这里

2026-08-05 起：以后遇到的一次性调研需求（比如"帮我查一下 X 支持到什么程度"），不再只是聊天里回答完就算，统一按上面「命名规范」写成 `YYYY-MM-DD-标题.md` 放进 `Doc/`，并回来这张索引表里加一行。目的是让这些零散提问也能留下可以再翻的记录，不会随对话翻篇就丢了。

## 命名规范

**两类文件，两种命名方式：**

1. **持续演进的规范/设计文档**（比如 `EDITOR-SPEC.md`、`TODO.md`）——纯功能性文件名，不带日期，因为它们会被反复修订，日期没有意义。
2. **一次性的调研笔记/决策记录/事故复盘**（比如"某次真机测试发现了什么问题"这种写完就不太会再改的东西）——用 `YYYY-MM-DD-标题.md`，日期在前，方便按时间线翻。

新文件先想清楚自己是哪一类，别混着来。

## 和根目录文档的关系

- `README.md` / `SPEC.md` / `CHANGELOG.md`：留在根目录，面向使用者/贡献者，讲「这个项目是什么、怎么用、数据结构长什么样、版本历史」
- `Doc/`：面向做这个项目的人（现在主要是我们俩），讲「正在设计什么、还没做完什么、当初为什么这么决定」
- `EDITOR-SPEC.md` 里的设计一旦真正落地实现，对应的用户可见部分（新表格字段、新按钮、新导出格式）要同步补回 `README.md`/`SPEC.md`，不能只留在 `Doc/` 里——`Doc/` 记的是过程，根目录三份记的是现状。
