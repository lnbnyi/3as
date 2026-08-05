# 3AS 数据结构与扩展规范

**版本**：v1.0  
**发布日期**：2026-08-04

---

## 一、导出 JSON 格式规范

### 1.1 顶层结构

```typescript
interface ExportedData {
  system: string;           // "3AS v1"
  file: string;             // 原始文件名
  exported: string;         // ISO 8601 时间戳
  scene: SceneMetadata;     // 场景元数据
  materials: Material[];    // 材质表
  textures: Texture[];      // 贴图表
  nodes: Node[];            // 模型块表
  annotations: Annotations; // 注释对象
}
```

### 1.2 材质表（Material）

```typescript
interface Material {
  i: number;                // 材质索引
  name: string;             // 材质名称
  baseColor: string;        // HEX 颜色，如 "#a0b0c0"
  baseColorF: number[];     // RGB 三元组 [r, g, b]，范围 0-1
  metal: string;            // 金属度，如 "1.00" 或 "—"
  rough: string;            // 粗糙度，如 "0.50"
  emissive: string;         // 自发光颜色 HEX
  emStr: string;            // 自发光强度倍数，如 "2.00" 或 "—"
  spec: string;             // 高光因子，如 "0.50" 或 "—"
  alpha: string;            // 混合模式："OPAQUE" | "MASK" | "BLEND"
  ds: string;               // 面："单面" | "双面"
  tex: string;              // 使用的贴图槽，如 "固色:img0 法线:img2"
  usedByNodes: string[];    // 反查：哪些节点（网格块）引用了该材质
}
```

**字段说明**：
- `baseColorF`：原始 glTF `pbrMetallicRoughness.baseColorFactor`（若缺省为 `[1,1,1]`）
- `emStr`：来自 `KHR_materials_emissive_strength.emissiveStrength`，标准值 1.0，扩展可 > 1
- `spec`：来自 `KHR_materials_specular.specularFactor`，仅当扩展存在时有值
- `tex`：人类可读的贴图槽摘要，格式 `<槽名>:<图片名>`，多个槽用空格分隔
- `usedByNodes`：从 `nodes[].mats` 反向聚合得到，用于在无贴图/材质名统一为 "fallback Material" 的 CAD 类导出（如 3ds Max ATF 导出）中定位材质对应的实际部件

---

### 1.3 贴图表（Texture）

```typescript
interface Texture {
  i: number;                // 图片索引（glTF images 数组）
  name: string;             // 图片名称
  mime: string;             // MIME 类型："image/png" | "image/jpeg" | "image/webp" | ...
  bytes: number;            // 字节数（来自 bufferView.byteLength）
  dims: string;             // 尺寸，如 "2048×2048" 或 "…"（解码中）或 "无法解码"
  used: string;             // 被哪些材质的哪些槽使用，格式 "mat0·固色 mat1·法线"
}
```

**字段说明**：
- `dims`：异步解码得出，导出时若仍为 `"…"` 表示解码未完成
- `used`：反向关联，便于识别未使用贴图（值为 `"—"` 时表示无材质引用）

---

### 1.4 模型块表（Node）

```typescript
interface Node {
  ni: number;               // 节点索引（glTF nodes 数组）
  name: string;             // 节点名称
  parent: string;           // 父节点名称或 "—"
  verts: number;            // 顶点数（所有 primitives 总和）
  tris: number;             // 三角面数（indices / 3 或 POSITION / 3）
  attrs: string;            // 顶点属性列表，如 "POSITION NORMAL TEXCOORD_0"
  mats: number[];           // 该节点（所有 primitives）引用的材质索引，去重升序
  T: number[];              // translation [x, y, z]，世界空间，单位米
  Rdeg: number[];           // rotation 转欧拉角 [x°, y°, z°]，世界空间
  S: number[];              // scale [x, y, z]，世界空间
}
```

**字段说明**：
- `mats`：来自 `mesh.primitives[].material` 去重集合。CAD 类导出常见一个 mesh 挂多个 primitive、每个 primitive 各自绑定一个材质 ID（3ds Max 的 Multi/Sub-Object 材质拆分即是如此），此字段就是「材质编号」在节点层面的呈现
- `T/Rdeg/S`：**世界空间**变换——沿父链（`parentOf`）累乘所有祖先节点的 local matrix 后再分解。glTF 场景图常见「网格节点 + 无名父级壳」结构（网格节点自身 matrix 可能只是占位单位缩放，真实摆位烘焙在上一层父节点），若只读节点自身 local matrix，对这类文件会得到恒等/无意义的 TRS；v0.1.1 起改为世界空间以修正此问题
- `Rdeg`：四元数转 XYZ 欧拉角（弧度→度），便于人类阅读
- `attrs`：POSITION / NORMAL / TEXCOORD_0 / COLOR_0 / TANGENT / JOINTS_0 / WEIGHTS_0 等

---

### 1.5 场景元数据（SceneMetadata）

```typescript
interface SceneMetadata {
  "文件名": string;
  "文件大小": string;          // 如 "1.23 MB"
  "glTF 版本": string;         // 如 "2.0"
  "生成器": string;            // 如 "Blender 3.6.0"
  "包围盒尺寸": string;        // 如 "9800 × 7500 × 8000 mm"
  "默认中心点": string;        // 如 "0, 4000, 0 mm"
  "材质 / 贴图 / 网格块": string; // 如 "12 / 8 / 45"
  "顶点 / 三角面": string;     // 如 "123456 / 98765"
  "扩展使用": string;          // extensionsUsed 逗号分隔，无则 "无"
  "扩展必需": string;          // extensionsRequired，无则 "无"
  "动画 / 蒙皮": string;       // 如 "2 / 1"
  "默认相机": string;          // 如 "pos(0, 3.5, 8.0) target(0, 3.2, 0)"
  "光照": string;              // 查看器默认光照配置
  "边缘框": string;            // 查看器默认边框配置
}
```

**字段说明**：
- KV 表设计，便于人类阅读与查看器消费
- `默认相机`：根据包围盒计算的推荐相机位置（查看器可覆盖）
- `光照/边缘框`：查看器约定，沿用 Chengduhuagao 案例的默认值

---

### 1.6 注释对象（Annotations）

```typescript
interface Annotations {
  file: string;             // 文件名
  updated: number;          // 最后修改时间戳（毫秒）
  nodes: Record<string, NodeAnnotation>;  // 节点注释，键为节点名
  matNotes: Record<string, string>;       // 材质备注，键为材质索引字符串
  texNotes: Record<string, string>;       // 贴图备注，键为图片索引字符串
  sceneNote: string;        // 整档备注
}

interface NodeAnnotation {
  visible: boolean;         // 默认是否显示
  allowEdit: boolean;       // 是否允许用户编辑
  alias: string;            // 别名（查看器 UI 显示名）
  note: string;             // 备注说明
}
```

**字段说明**：
- `nodes`：仅记录有注释的节点，未注释的节点不出现在对象中
- `visible/allowEdit`：查看器初始化时读取，控制默认行为
- `alias`：优先级高于 glTF 原始 `name`，用于 UI 显示
- `matNotes/texNotes`：键是索引的**字符串形式**（JSON 对象键必须是字符串）

---

## 二、glTF 扩展支持矩阵

### 2.1 已解析扩展

| 扩展名 | 类型 | 字段 | 说明 |
|-------|------|------|------|
| `KHR_materials_specular` | 材质 | `specularFactor` | 高光强度，0-1 |
| `KHR_materials_emissive_strength` | 材质 | `emissiveStrength` | 自发光倍数，默认 1.0 |

**消费方式**：
- 材质表直接展示 `spec` 和 `emStr` 字段
- 查看器需支持这些扩展才能正确渲染

### 2.2 已识别但未解析扩展

| 扩展名 | 类型 | 说明 |
|-------|------|------|
| `KHR_draco_mesh_compression` | 网格 | Draco 压缩，需 decoder |
| `KHR_texture_basisu` | 贴图 | KTX2 格式，需 basis_universal.js |
| `EXT_texture_webp` | 贴图 | WebP 格式，浏览器原生或 polyfill |
| `KHR_mesh_quantization` | 网格 | 顶点属性量化 |
| `KHR_materials_unlit` | 材质 | 无光照材质 |
| `KHR_materials_pbrSpecularGlossiness` | 材质 | 旧版 PBR 工作流 |

**显示方式**：
- 场景元数据表中的「扩展使用」和「扩展必需」字段
- 贴图表中 WebP/KTX2 格式标记为非标准格式

### 2.3 扩展兼容性建议

**标准贴图**：PNG / JPEG——所有浏览器与查看器支持  
**扩展贴图**：
- WebP：Chrome/Edge/Opera 原生，Safari 14+，需声明 `EXT_texture_webp`
- KTX2：需 three.js KTX2Loader + basis_universal.wasm，需声明 `KHR_texture_basisu`

**压缩网格**：
- Draco：three.js DRACOLoader + draco_decoder.wasm，大幅减小文件体积
- 量化：`KHR_mesh_quantization` 减小顶点数据精度，配合 Draco 使用

**建议**：
- 生产环境 GLB：Draco + PNG/JPEG（最大兼容性）
- 高性能场景：Draco + KTX2（需 polyfill）
- 预处理时检查「扩展必需」，若包含浏览器不支持的扩展，提前警告

---

## 三、查看器集成规范

### 3.1 读取注释 JSON

```js
// 1. 加载 GLB
const gltf = await new GLTFLoader().loadAsync('model.glb');
const model = gltf.scene;

// 2. 加载注释
const anno = await fetch('model.3as.json').then(r => r.json());
const nodeAnno = anno.annotations.nodes;

// 3. 应用注释
model.traverse(obj => {
  if (obj.isMesh) {
    const a = nodeAnno[obj.name];
    if (a) {
      obj.visible = a.visible;                  // 默认显隐
      if (!a.allowEdit) obj.userData.locked = true; // 锁定编辑
      obj.userData.alias = a.alias || obj.name; // UI 显示名
      obj.userData.note = a.note;               // 工具提示
    }
  }
});
```

### 3.2 材质/贴图元数据消费

```js
// 材质表：用于材质编辑器或调试面板
const matList = anno.materials.map(m => ({
  name: m.name,
  baseColor: m.baseColor,
  metal: parseFloat(m.metal),
  rough: parseFloat(m.rough),
  note: anno.annotations.matNotes[m.i] || '',
}));

// 贴图表：用于资产管理器
const texList = anno.textures.map(t => ({
  name: t.name,
  size: t.bytes,
  dims: t.dims,
  format: t.mime,
  note: anno.annotations.texNotes[t.i] || '',
}));
```

### 3.3 场景元数据消费

```js
// 包围盒与相机初始化
const sceneData = anno.scene;
const bbox = parseFloat(sceneData["包围盒尺寸"].split('×')[0]); // 粗略解析
const camStr = sceneData["默认相机"]; // "pos(0, 3.5, 8.0) target(0, 3.2, 0)"
const [posMatch, tarMatch] = [
  /pos\(([\d\.\-\,\s]+)\)/.exec(camStr),
  /target\(([\d\.\-\,\s]+)\)/.exec(camStr),
];
if (posMatch && tarMatch) {
  const pos = posMatch[1].split(',').map(parseFloat);
  const tar = tarMatch[1].split(',').map(parseFloat);
  camera.position.set(...pos);
  controls.target.set(...tar);
}
```

---

## 四、扩展路径与版本控制

### 4.1 Phase 2：上传端点

**接口定义**：
```
POST /3as/upload
Content-Type: multipart/form-data

files:
  - model: GLB 文件
  - meta: 3as.json 文件（可选）

响应：
{
  "ok": true,
  "projectId": "uuid-v4",
  "url": "/3as/projects/uuid-v4"
}
```

**服务端存储**：
```
projects/
  <uuid>/
    model.glb
    meta.3as.json
    thumbnail.png   # 自动生成缩略图
    versions/       # 历史版本
      v1.3as.json
      v2.3as.json
```

**前端集成**：
- 新增「上传项目」按钮
- 成功后跳转到项目页面（含分享链接）

### 4.2 Phase 3：多文件项目

**项目结构扩展**：
```json
{
  "project": {
    "id": "uuid",
    "name": "成都花稿 L6",
    "created": "2026-08-04T12:00:00Z",
    "models": [
      { "file": "floor1.glb", "meta": "floor1.3as.json", "label": "一层" },
      { "file": "floor2.glb", "meta": "floor2.3as.json", "label": "二层" }
    ],
    "sharedTemplates": {
      "materialNaming": { "金属": "metal_*", "玻璃": "glass_*" },
      "nodeConventions": { "隐藏前缀": "_hidden_", "锁定前缀": "_locked_" }
    }
  }
}
```

**UI 变化**：
- 左侧文件树，切换模型
- 共享模板库，批量应用命名规范

### 4.3 Phase 4：注释版本控制

**版本对象**：
```json
{
  "version": 2,
  "timestamp": "2026-08-04T14:30:00Z",
  "author": "lin@example.com",
  "changes": [
    { "op": "add", "path": "/nodes/Rectangle2133441908/alias", "value": "车位 box" },
    { "op": "replace", "path": "/matNotes/0", "value": "主体金属材质（修订）" }
  ],
  "annotations": { /* 完整注释快照 */ }
}
```

**Diff 视图**：
- 并排对比两个版本
- 高亮变化字段
- 支持回滚

### 4.4 Phase 5：团队协作

**WebSocket 同步协议**：
```json
{
  "type": "annotation_update",
  "projectId": "uuid",
  "user": "user@example.com",
  "path": "/nodes/Rectangle2133441908/alias",
  "value": "车位 box",
  "timestamp": 1722800000000
}
```

**冲突解决**：
- OT（Operational Transformation）或 CRDT
- 最后写入胜（Last-Write-Wins）+ 冲突标记

**权限模型**：
- `viewer`：只读
- `editor`：编辑注释
- `reviewer`：审核与锁定
- `admin`：删除与归档

---

## 五、数据迁移与兼容性

### 5.1 版本兼容

**当前版本**：`3AS v1`  
**向后兼容承诺**：
- `annotations` 结构不破坏性变更
- 新增字段向后兼容（旧查看器忽略新字段）
- 字段废弃需提前一个大版本警告

**版本升级示例**：
```js
function migrate(data) {
  if (data.system === '3AS v1') {
    // v1 → v2：新增 `nodeAnno.category` 字段
    data.system = '3AS v2';
    Object.values(data.annotations.nodes).forEach(a => {
      if (!a.category) a.category = 'general';
    });
  }
  return data;
}
```

### 5.2 数据校验

**JSON Schema**（建议）：
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["system", "file", "exported", "annotations"],
  "properties": {
    "system": { "type": "string", "const": "3AS v1" },
    "file": { "type": "string" },
    "exported": { "type": "string", "format": "date-time" },
    "annotations": {
      "type": "object",
      "required": ["nodes", "matNotes", "texNotes", "sceneNote"],
      "properties": {
        "nodes": { "type": "object" },
        "matNotes": { "type": "object" },
        "texNotes": { "type": "object" },
        "sceneNote": { "type": "string" }
      }
    }
  }
}
```

**运行时校验**（导入时）：
```js
function validate(data) {
  if (!data.system || !data.system.startsWith('3AS')) {
    throw new Error('不是有效的 3AS JSON');
  }
  if (!data.annotations || typeof data.annotations.nodes !== 'object') {
    throw new Error('缺少 annotations.nodes');
  }
  return true;
}
```

---

## 六、性能与优化建议

### 6.1 大文件处理

**问题**：100MB+ GLB 在浏览器内解析慢  
**优化**：
- 进度反馈：显示「解析中… X%」
- Web Worker：GLTFLoader 在 Worker 线程解析（three.js r150+ 支持）
- 流式加载：分块读取 GLB（需自定义 loader）

### 6.2 贴图尺寸异步解码

**当前实现**：`createImageBitmap` 逐张解码  
**优化**：
- 限制并发数（最多 4 张同时解码）
- 优先解码用户可见的贴图（当前标签页）
- 缓存解码结果（Blob URL）

### 6.3 localStorage 容量限制

**浏览器限制**：5-10MB（不同浏览器/域名）  
**当前风险**：单个 GLB 注释约 10-50KB，可存 100+ 个文件  
**超限处理**：
- LRU 淘汰：删除最久未访问的注释
- 压缩存储：`pako.deflate` + Base64
- IndexedDB 迁移（Phase 2）

---

## 七、安全与隐私

### 7.1 数据隐私

- **本地处理**：GLB 文件与注释仅在浏览器内处理，不上传服务器（除非用户主动上传）
- **localStorage 作用域**：按域名隔离，其他网站无法读取
- **导出 JSON**：由用户主动触发，明确知情

### 7.2 恶意 GLB 防护

**风险**：
- 超大文件（> 500MB）导致浏览器崩溃
- 恶意 glTF 扩展注入脚本（理论风险，glTF 无脚本字段）

**防护措施**：
- 文件大小检查：拖拽时检查 `file.size`，> 200MB 警告
- 解析超时：`Promise.race` 设置 30 秒超时
- CSP（Content Security Policy）：禁止 inline script

### 7.3 XSS 防护

**风险点**：
- 材质名称、节点名称、备注字段包含 HTML 标签
- 示例：`<img src=x onerror=alert(1)>`

**防护措施**：
- 渲染时转义：`esc()` 函数替换 `<>&"`
- 用户输入 `value` 而非 `innerHTML`
- 导出 JSON 前无需转义（JSON 本身安全）

---

## 八、测试与质量保证

### 8.1 测试矩阵

| 测试类型 | 工具 | 覆盖项 |
|---------|------|--------|
| 单元测试 | Jest | `esc()` / `hex()` / `num()` 工具函数 |
| 解析测试 | Playwright | 加载示例 GLB，检查表格行数 |
| 注释持久化 | Playwright | 写入备注→刷新→验证恢复 |
| 兼容性测试 | BrowserStack | Chrome / Firefox / Safari / Edge |
| 性能测试 | Lighthouse | 首屏加载 < 2s，解析 10MB GLB < 5s |

### 8.2 回归测试用例

**标准 GLB**：
- `chengdu-huagao-0801.glb`（1.1MB，12 材质，8 贴图，45 节点）
- 验证：材质表 12 行，贴图表 8 行，节点表 45 行

**扩展 GLB**：
- Draco 压缩 + KTX2 贴图 + 高光扩展
- 验证：场景表显示扩展名，材质表显示 `spec` 值

**异常 GLB**：
- 无材质 GLB（纯线框）
- 无贴图 GLB（纯色材质）
- 验证：不崩溃，表格显示 `—`

---

## 九、文档与培训

### 9.1 用户文档

- [README.md](./README.md)：快速开始、四张表说明、FAQ
- [SPEC.md](./SPEC.md)：本文档，技术规范
- [CHANGELOG.md](./CHANGELOG.md)：版本历史

### 9.2 开发者文档

- **API 文档**：JSDocs 注释 + 自动生成（待 Phase 2）
- **架构图**：Mermaid 流程图（待补充）
- **贡献指南**：代码风格、提交规范、PR 模板

### 9.3 培训材料

- **视频教程**：5 分钟快速上手（待录制）
- **案例库**：典型 GLB 的注释示例（如建筑、产品、角色）
- **最佳实践**：命名规范、注释粒度、导出时机

---

## 十、附录

### A. glTF 2.0 参考

- 官方规范：https://registry.khronos.org/glTF/specs/2.0/glTF-2.0.html
- 扩展列表：https://github.com/KhronosGroup/glTF/tree/main/extensions
- 验证工具：https://github.khronos.org/glTF-Validator/

### B. three.js GLTFLoader 限制

- 不支持 glTF 1.0
- 部分扩展需额外 loader（Draco / KTX2 / MeshOpt）
- `matrix` 分解精度有限（极端缩放/剪切变换可能误差）

### C. 术语表

| 术语 | 英文 | 说明 |
|-----|------|------|
| 材质 | Material | PBR 材质，定义表面光学属性 |
| 贴图 | Texture / Image | 2D 图片，映射到几何体表面 |
| 模型块 | Node | glTF 场景图节点，含 mesh/camera/light |
| 网格 | Mesh | 几何体（顶点+索引）+ 材质 |
| 基元 | Primitive | mesh 的子单元，单一材质+绘制模式 |
| 包围盒 | Bounding Box | 包围模型的最小长方体 |
| TRS | Translation-Rotation-Scale | 节点变换三要素 |

---

**维护者**：Lin  
**最后更新**：2026-08-04  
**下一版本计划**：v1.1（Phase 2 上传端点）预计 2026-09
