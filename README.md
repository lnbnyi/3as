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
- **移动 T**：translation（x, y, z，米）
- **旋转 R°**：rotation 四元数转欧拉角（x°, y°, z°）
- **缩放 S**：scale（x, y, z）
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
- **光照 / 边缘框**：查看器默认配置（huagao3d 约定）

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
- 交付给查看器（如 huagao3d），读取 `annotations.nodes` 配置默认显隐/编辑权限
- 项目文档归档
- 版本控制（与 GLB 一起存储）

---

## 架构与扩展

### 技术栈

- **纯前端**：单 HTML 文件，ES 模块，无构建步骤
- **three.js 0.166.1**：复用 huagao3d/3ds-viewer 的 `vendor/` 目录
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
├── README.md           # 本文档
├── SPEC.md             # 数据结构与扩展规范
└── CHANGELOG.md        # 版本历史
```

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

## 与 huagao3d/3ds-viewer 的关系

**3AS** 是独立应用，但设计上与 huagao3d 查看器配套：

| 项 | 3AS | huagao3d |
|---|---|---|
| 定位 | 预处理 + 注释 | 查看 + 编辑 + 方案保存 |
| 输入 | 原始 GLB | GLB + 3AS 注释 JSON |
| 输出 | 注释 JSON | 用户方案 JSON |
| 用户 | 模型准备者 / 技术美术 | 最终用户 / 客户 |
| 运行时机 | 项目初始化 | 生产环境 |

**工作流**：
1. 技术美术用 3AS 预处理 GLB，标记默认显隐、节点别名、材质说明
2. 导出 `model.3as.json`
3. 开发者读取 JSON，配置 huagao3d 初始状态（`origTransforms`、`working`、部件面板显示名）
4. 最终用户在 huagao3d 中编辑、保存方案

**数据映射示例**：
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

// huagao3d 消费
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
