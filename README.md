# 3AS · 3D Annotation System

**3D 模型预处理与注释系统**  
用于 GLB 模型的元数据提取、材质/贴图/网格块分析、注释标记与导出

---

## 概述

3AS（3D Annotation System）是一个浏览器内运行的 GLB 模型预处理工具，提供：

- **四张完整表格**：材质（PBR）、贴图（含格式/尺寸/字节数）、模型块（网格/顶点/TRS）、场景元数据
- **实时 3D 预览**：拖拽旋转查看模型，金色边框 + 网格辅助
- **注释持久化**：材质/贴图备注、节点别名/显隐/编辑权限、整档备注，存 localStorage，可导出 JSON
- **未来扩展**：上传端点、多文件项目管理、注释版本控制

**适用场景**：
- 为项目 GLB 编写元数据说明（材质命名、贴图用途、节点分组）
- 检查模型质量（顶点数、贴图格式、扩展支持）
- 准备查看器配置（默认显隐、中心点、包围盒）
- 交付注释 JSON 给开发团队或查看器

---

## 快速开始

### 本地运行

```bash
cd 3as
python3 -m http.server 8000
# 访问 http://localhost:8000
```

**依赖**：无需构建，纯静态 HTML + ES 模块，复用 `vendor/` 内的 three.js 0.166.1

### 加载模型

1. **拖拽**：将 `.glb` 文件拖入视窗中央虚线框
2. **打开按钮**：点击右上角「打开 GLB」选择文件
3. **示例模型**：点击「示例模型」加载 `chengdu-huagao-0801.glb`（需同级目录存在）

加载后：
- 左侧显示实时 3D 预览（可旋转/缩放）
- 右侧面板显示四个标签页：材质 / 贴图 / 模型块 / 场景
- 底部状态栏显示「预处理完成（已恢复历史注释）」或「预处理完成」

---

## 四张表说明

### 表1：材质（Materials）

**字段**：
- **#**：材质索引
- **名称**：glTF 材质名（未命名显示 `material_N`）
- **漫反射**：PBR baseColorFactor，显示色块 + HEX 值
- **金属 / 粗糙**：metallicFactor / roughnessFactor（0.00-1.00）
- **高光**：KHR_materials_specular.specularFactor（若扩展存在）
- **自发光**：emissiveFactor HEX + KHR_materials_emissive_strength 倍数
- **混合/面**：alphaMode（OPAQUE/MASK/BLEND）+ 单面/双面
- **贴图槽**：已使用的贴图槽（固色:img0 金属粗糙:img1 法线:img2 …）
- **用于节点**：反查该材质被哪些模型块（节点）引用——CAD 类导出常见材质名统一为 `fallback Material`、无贴图，仅靠名称和色块难以分辨用途时，靠这一列定位
- **备注**：可编辑文本框，输入材质用途说明

**用途**：
- 检查 PBR 参数是否合理（金属度/粗糙度典型值、自发光强度）
- 识别未命名材质，补充名称与用途
- 确认扩展支持（高光/自发光强度）

---

### 表2：贴图（Textures）

**字段**：
- **#**：图片索引（glTF images 数组）
- **名称**：图片名称（未命名显示 `image_N`）
- **格式**：MIME 类型（标准：image/png / image/jpeg；扩展：image/webp / image/ktx2）
- **尺寸**：异步解码得出（宽×高），解码失败显示「无法解码」
- **体积**：bufferView 字节数（KB）
- **被用于**：哪些材质的哪些槽使用了这张贴图（如 `mat0·固色 mat1·法线`）
- **备注**：可编辑文本框

**支持格式**：
- **标准**：PNG / JPEG（所有浏览器）
- **扩展**：WebP（EXT_texture_webp）/ KTX2（KHR_texture_basisu）——需查看器支持

**用途**：
- 检查贴图体积（过大贴图影响加载速度）
- 确认格式兼容性（WebP/KTX2 需显式声明扩展）
- 识别未使用贴图（`被用于` 为空）

---

### 表3：模型块（Nodes）

**仅显示含 mesh 的节点**（纯分组节点不列）

**字段**：
- **名称**：glTF 节点名（未命名显示 `node_N`）
- **属于**：父节点名（顶层节点显示 `—`）
- **顶点/三角**：所有 primitive 的顶点数与三角面数总和
- **通道**：顶点属性（POSITION NORMAL TEXCOORD_0 COLOR_0 …）
- **材质**：该节点各 primitive 引用的材质索引（色块+编号），一个节点可能挂多个材质 ID（3ds Max Multi/Sub-Object 材质按 ID 拆分 primitive 就是这种情况）
- **移动 T / 旋转 R° / 缩放 S**：**世界空间**变换（沿父链累乘后分解），不是节点自身的 local matrix——glTF 导出常把网格节点包一层无名父节点承载真实摆位，只看 local 值会看到恒等/占位数据
- **显示**：复选框，查看器默认是否显示该节点（注释字段）
- **允许**：复选框，查看器是否允许用户编辑该节点（注释字段）
- **备注名称**：别名（查看器 UI 显示名）
- **备注**：节点用途说明

**用途**：
- 标记哪些节点默认隐藏（如辅助几何体、碰撞盒）
- 标记哪些节点禁止编辑（如固定背景、标牌）
- 补充中文别名（glTF 节点名通常是英文或编号）
- 检查模型复杂度（顶点数过高需优化）

---

### 表4：场景（Scene）

**元数据 KV 表**：
- **文件名 / 文件大小**：原始 GLB 信息
- **glTF 版本**：通常 2.0
- **生成器**：导出工具（Blender、3ds Max、Maya、…）
- **包围盒尺寸**：整档 bounding box（mm）
- **默认中心点**：包围盒中心（mm）
- **材质 / 贴图 / 网格块**：数量统计
- **顶点 / 三角面**：全档总计
- **扩展使用 / 扩展必需**：glTF extensionsUsed / extensionsRequired
- **动画 / 蒙皮**：动画片段数 / 骨骼蒙皮数
- **默认相机**：查看器推荐的相机位置（根据包围盒计算）
- **光照 / 边缘框**：查看器默认配置（沿用 chengdu-huagao 案例的默认值，非外部产品约定）

**整档备注**：文本框，项目级说明（如"成都花稿 L6 2024-08-01 终稿"）

**用途**：
- 一览模型规模与复杂度
- 确认扩展依赖（部分扩展需 polyfill）
- 记录交付版本与备注

---

## 注释持久化

**存储方式**：localStorage，键名 `3as:<文件名>:<字节数>`

**存储内容**：
```json
{
  "file": "model.glb",
  "updated": 1722800000000,
  "nodes": {
    "Rectangle2133441908": {
      "visible": false,
      "allowEdit": false,
      "alias": "车位 box",
      "note": "默认隐藏的占位几何体"
    }
  },
  "matNotes": { "0": "主体金属材质", "1": "玻璃透明" },
  "texNotes": { "0": "固色贴图 2K", "1": "法线贴图 1K" },
  "sceneNote": "成都花稿 2024-08-01 终稿"
}
```

**自动保存**：任何输入框 `onchange` 触发保存，底部状态栏显示「注释已保存 14:32:15」

**恢复逻辑**：
- 重新打开**同一文件**（文件名 + 字节数匹配）时，自动恢复历史注释
- 状态栏显示「预处理完成（已恢复历史注释）」
- 如需清空注释，浏览器开发者工具 → Application → Local Storage → 删除对应键

---

## 导出 JSON

点击右上角「导出注释 JSON」，生成 `.3as.json` 文件：

```json
{
  "system": "3AS v1",
  "file": "model.glb",
  "exported": "2026-08-04T14:30:00.000Z",
  "scene": { "文件名": "...", "包围盒尺寸": "...", ... },
  "materials": [ { "i": 0, "name": "...", "baseColor": "#...", ... } ],
  "textures": [ { "i": 0, "name": "...", "mime": "...", "dims": "...", ... } ],
  "nodes": [ { "ni": 0, "name": "...", "verts": 1234, "T": [...], ... } ],
  "annotations": { "nodes": {...}, "matNotes": {...}, ... }
}
```

**用途**：
- 交付给查看/编辑场景（目前是 chengdu-huagao 案例，未来是 3AS 自身的查看模式），读取 `annotations.nodes` 配置默认显隐/编辑权限
- 项目文档归档
- 版本控制（与 GLB 一起存储）

---

## 3ds Max 导出建议（V-Ray 场景）

**背景**：3ds Max 自带的 glTF 导出器（File → Export → glTF，底层是 Autodesk ATF → GLTFConsumer）只认识 Physical Material / Standard Surface，**不认识 VRayMtl**。场景里的材质如果还是 V-Ray 材质，导出的 GLB 里每个材质都会摊成一个叫 `fallback Material` 的纯黑色兜底材质，没有金属度/粗糙度/贴图——3AS 项目里 `glb/0A_JIMMYCHOO_CD260804.glb` 这份实测样品就是这个状况（17 个材质全是 `fallback Material`，0 张贴图）。用 3AS 打开一份 GLB 后，如果材质表里名称清一色 `fallback Material`、漫反射清一色 `#000000`、贴图表是空的，基本就能确诊是这个问题，不是 3AS 解析错了。

**推荐先试的免费/原生路线**：
1. 场景里的 `VRayMtl` 先转换成 3ds Max 原生材质再导出。Autodesk 3ds Max **2023.2 起自带 Physical Material → glTF 的转换能力**、以及独立的 **glTF Material** 材质类型（Material/Map Browser → General → glTF Material），官方发布说明见下方链接。具体菜单路径请以你安装的 3ds Max 版本为准自行核实——这一步我没有 3ds Max 环境能替你跑一遍，只能给到 Autodesk 官方文档和演示视频的线索。
2. 场景里如果用了 V-Ray 的程序贴图（噪波、渐变、VRayDirt 等非 Bitmap 贴图），glTF Material 只接受 **Bitmap 贴图**作为输入，程序贴图需要先烘焙成贴图再接上去。
3. 转换后确认材质类型确实变成了 Physical Material 或 glTF Material（而不是还挂着 VRayMtl），再执行 File → Export → glTF(.gltf/.glb)。
4. 导出后拖进 3AS 复查：材质名称不再是 `fallback Material`、漫反射不再是纯黑、贴图表不再是空的，说明材质数据被正确读出来了。

**如果原生路线太繁琐**（材质数量多、程序贴图多、手动转换成本高），备选方案：
- **V-RayMax Converter PRO**（[ScriptSpot](https://www.scriptspot.com/3ds-max/scripts/v-raymax-converter-pro)）：付费脚本，专门做 V-Ray/Corona → glTF Material 批量转换，3ds Max 2023+ 支持。
- **RapidPipeline**（[docs](https://docs.rapidpipeline.com/docs/rapidpipeline-cloud-tutorials/dcc-import)）：付费云平台，上传含 V-Ray 材质的 Max 场景，自动转 PBR + 烘焙贴图，出 glTF/GLB/FBX/USDZ，带 QC 报告；但模型会离开本机上传到云端，和 3AS「本地处理」的定位不同，仅作为对照的成熟方案参考。
- **Khronos 官方 glTF 插件**（[GitHub](https://github.com/KhronosGroup/glTF-3ds-Max-Plugin)，2026-07 由 Khronos 出资开源，Apache 2.0）：主要补上「glTF 导回 3ds Max」的能力，方便导出后再拉回 Max 核对，不是材质转换工具。

**参考资料**：
- [glTF Material & Exporter — What's New in 3ds Max 2023（Autodesk 官方）](https://help.autodesk.com/view/3DSMAX/2023/ENU/?guid=GUID-EFBB037D-C4EB-42D2-9CE1-30FCAD483C31)
- [glTF Material 参数文档 — 3ds Max 2023（Autodesk 官方）](https://help.autodesk.com/view/3DSMAX/2023/ENU/?guid=GUID-7ABFB805-1D9F-417E-9C22-704BFDF160FA)
- [3ds Max 2023.2 — Physical material to glTF converter 演示视频](https://www.youtube.com/watch?v=vRhk08-a4o4)

---

## 架构与扩展

### 技术栈

- **纯前端**：单 HTML 文件，ES 模块，无构建步骤
- **three.js 0.166.1**：复用 `3ds-viewer`（chengdu-huagao 案例）的 `vendor/` 目录
- **importmap**：静态映射 three.js 模块路径
- **localStorage**：注释持久化，无需服务端

### 目录结构

```
3as/
├── index.html          # 主应用（HTML + CSS + JS 单文件）
├── vendor/             # 复用 3ds-viewer 的 three.js 本地化
│   ├── three.module.js
│   ├── loaders/GLTFLoader.js
│   ├── controls/OrbitControls.js
│   └── utils/...
├── glb/                # 本地测试样品（见下方 GLB 上传政策），大部分被 .gitignore 排除
├── README.md           # 本文档
├── SPEC.md             # 数据结构与扩展规范
└── CHANGELOG.md        # 版本历史
```

### GLB 上传政策

仓库是公开的，但 GLB 模型文件默认**不算公开可分享**——除 Chengduhuagao 案例（`chengdu-huagao-0801.glb`）外，`glb/` 目录下的其他模型默认视为 WIP / 客户监修状态，`.gitignore` 已排除，不会被 `git add` 进来。本地测试随便放文件进 `glb/` 没问题；如果确实需要把某个新样品提交进仓库共享，先跟负责人确认这份模型是否已经可以公开。HTML/CSS/JS 代码文件没有这个限制，正常提交。

### 未来扩展路径

1. **上传端点**（Phase 2）
   - POST `/3as/upload` 接收 GLB + JSON
   - 服务端存储 `projects/{uuid}/model.glb` + `meta.3as.json`
   - 返回项目 URL

2. **多文件项目管理**（Phase 3）
   - 一个项目包含多个 GLB（如多楼层、多配色方案）
   - 共享注释模板（材质名称库、节点命名规范）
   - 项目级配置（单位、坐标系、查看器参数）

3. **注释版本控制**（Phase 4）
   - 每次导出记录时间戳与操作者
   - 支持回溯与对比（diff 视图）

4. **团队协作**（Phase 5）
   - 多人同时编辑注释（WebSocket 同步）
   - 权限管理（只读/编辑/审核）

---

## 与 Chengduhuagao 案例（3ds-viewer）的关系

> 命名统一用拼音 **Chengduhuagao**（对应文件名 `chengdu-huagao-0801.glb`）。之前文档里出现的「huagao3d」是不准确的叫法——它暗示这是一个独立的查看器产品，实际不是，见下文。

**Chengduhuagao / `3ds-viewer` 目前只是一个不带通用加载功能的固定案例**：`main.js` 里 `MODEL_URL` 硬编码指向 `chengdu-huagao-0801.glb`，没有拖拽/上传任意 GLB 的能力，配合 `save.php`/`mods.php` 验证了「查看 + 编辑 + 方案保存」这一段体验，但只能用于这一个模型，**不是一个独立的通用查看器产品**。

**未来方向**：等这套「查看 + 编辑 + 保存」体验补上通用 GLB 加载能力，就应该**完全并入 3AS**，作为 3AS 自己的「查看/编辑模式」，而不是维持成两个各自独立、靠 JSON 交接的项目。3AS 的目标形态是覆盖「预处理注释 → 查看编辑 → 方案保存」全流程的单一系统；Chengduhuagao 只是验证查看/编辑体验的参考案例，UI 交互和数据结构可以复用，但不会作为独立产品线继续存在。

**现状 vs 目标形态**：
| 阶段 | 现状 | 目标（并入 3AS 后）|
|---|---|---|
| 预处理 + 注释 | 3AS 已实现（本项目） | 3AS 的第一段模式，不变 |
| 查看 + 编辑 + 方案保存 | Chengduhuagao 案例硬编码单一模型，`3ds-viewer/` 独立代码 | 3AS 内置的第二段模式，加载任意 GLB + 对应 3AS 注释 JSON |

**数据消费方式**（Chengduhuagao 案例现有代码里的写法，并入 3AS 后接口不变）：
```js
// 3AS 注释
{
  "nodes": {
    "Rectangle2133441908": {
      "visible": false,
      "alias": "车位 box",
      "note": "默认隐藏"
    }
  }
}

// 查看/编辑模式消费（现阶段是 Chengduhuagao 案例代码，未来直接是 3AS 自己）
const anno = await fetch('model.3as.json').then(r => r.json());
model.children.forEach(part => {
  const a = anno.annotations.nodes[part.name];
  if (a) {
    part.visible = a.visible;         // 默认显隐
    if (!a.allowEdit) part.userData.locked = true;  // 锁定编辑
    partDisplayName = () => a.alias || part.name;   // UI 显示名
  }
});
```

---

## FAQ

**Q1：为什么不支持 glTF（.gltf + bin + 贴图文件夹）？**  
A：GLB 是单文件自包含格式，便于拖拽与传输。glTF 分离格式需额外处理文件夹结构，Phase 2 再考虑。

**Q2：贴图尺寸「…」一直不出现？**  
A：异步解码需时间，等待 1-2 秒。若仍显示「无法解码」，贴图格式可能不受支持（如 KTX2 需 basis_universal.js）。

**Q3：注释丢失了怎么办？**  
A：localStorage 与浏览器绑定，清理缓存会丢失。建议定期「导出注释 JSON」备份。

**Q4：能否在手机上用？**  
A：布局支持窄屏（< 900px 时上下分栏），但大型 GLB 在移动端性能有限，推荐桌面端使用。

**Q5：支持哪些 glTF 扩展？**  
A：读取所有扩展信息并显示在「场景」表中，但只解析常见 PBR 扩展（KHR_materials_specular、KHR_materials_emissive_strength）。其他扩展（Draco、KTX2）会在表中标注，但不深度解析。

---

## 许可与贡献

**许可**：待定（建议 MIT）

**贡献指南**：见 `CONTRIBUTING.md`（待建立）

**问题反馈**：提交 issue 时请附带：
- 浏览器版本 + 操作系统
- GLB 文件大小与生成器
- 控制台错误截图

---

**版本**：v0.1.0 (2026-08-04)  
**作者**：Lin · Hermes Agent  
**项目地址**：`/mnt/c/Users/Lin/projects/3as`
