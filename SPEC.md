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
- `baseColorF`：glTF `pbrMetallicRoughness.baseColorFactor`（若缺省为 `[1,1,1]`）
- `emStr`：来自 `KHR_materials_emissive_strength.emissiveStrength`，标准值 1.0，扩展可 > 1
- `spec`：来自 `KHR_materials_specular.specularFactor`，仅当扩展存在时有值
- `tex`：人类可读的贴图槽摘要，格式 `<槽名>:<图片名>`，多个槽用空格分隔
- `usedByNodes`：从 `nodes[].mats` 反向聚合得到，用于在无贴图/材质名统一为 "fallback Material" 的 CAD 类导出（如 3ds Max ATF 导出）中定位材质对应的实际部件

**可编辑性（2026-08-05 起，材质编辑器上线；alphaMode/doubleSided 见 #18；贴图槽见 #16）**：这份 `Material` 接口本身没有新增字段——`baseColor`/`baseColorF`/`metal`/`rough`/`emissive`/`emStr`/`alpha`/`ds`/`tex` 现在全部是**运行时可变**的：材质编辑器（右侧「材质」Tab 的色块画廊 + 详情区）改动这些字段后，会同步写回 ① `gltf.parser.json`（内存变量 `raw`，也就是这份表格构建时读的原始数据结构）；② `matInstances`（材质索引 → 实际渲染用的 `THREE.Material` 实例集合，来自 `gltf.parser.associations` 反查，处理了 GLTFLoader 为顶点色/平滑法线等场景 clone 出材质变体的情况）。导出 `.3as.json`（`exportBtn`）时这几个字段反映的是**当前编辑后的值**，不是加载时的原始值；「另存为 GLB」的 `GLTFExporter` 序列化的是被同步过的 three.js 场景，`materials[i].pbrMetallicRoughness.baseColorFactor` 等字段在导出的 GLB 里同样是编辑后的新值。每个材质加载时会拍一份深拷贝快照（`tables.matOriginal`，不在导出 JSON 里）供「还原到原始值」用，不受会话内编辑次数影响。`alpha`（`OPAQUE`/`MASK`/`BLEND` 下拉框）/`ds`（双面开关）编辑规则照抄 `GLTFLoader.createMaterial()` 反向映射；`tex` 字段的变化来自贴图槽的上传/替换/移除操作（详见 §2.1 `KHR_texture_transform` 一行下方、README.md 表1「贴图槽编辑区」）——上传/替换会让 `tex` 摘要多出/换掉对应槽位那一段，移除会去掉。`usedByNodes` 仍是纯只读派生字段，编辑器不改它（由 `nodes[].mats` 反向聚合，不受材质编辑影响）。

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
- `dims`：原始 GLB 内嵌的贴图异步解码得出，导出时若仍为 `"…"` 表示解码未完成；本工具上传的贴图（见下）上传时就已经同步拿到尺寸，不会停留在 `"…"` 态
- `bytes`：原始 GLB 内嵌贴图来自 `bufferView.byteLength`；本工具上传的贴图来自 `File.size`（原始文件字节数，不是重新编码后的大小）
- `used`：反向关联，便于识别未使用贴图（值为 `"—"` 时表示无材质引用）

**贴图上传/替换/移除（2026-08-05 起，#16）**：这份 `Texture` 接口本身没有新增字段——材质详情编辑区的贴图槽上传/替换操作会往 `raw.images[]`/`raw.textures[]` 追加新记录（`raw.images[i]` 多出内部字段 `_local: true`/`_dims`/`_bytes` 标记这是本工具上传、非原始 GLB 内嵌的图片，`Texture` 表渲染时读 `_dims`/`_bytes` 而不是走 `bufferView` 异步解码这条路径；这几个 `_` 前缀字段不出现在导出的 `.3as.json` 里，纯内部簿记用）；`raw.images[i].uri` 存一份 `FileReader.readAsDataURL()` 读出的原始文件 data URI，供 3AS 自己的表格/预览一致性用，**不是**导出正确性依赖的数据（`GLTFExporter` 序列化贴图读的是 three.js `Texture.image`，不读这份 `uri`）。移除贴图只清空材质槽位引用，不删除 `raw.images`/`raw.textures` 里已有的条目（可能被其它槽位共享，也避免删除引发的索引重排）。

---

### 1.4 模型块表（Node）

```typescript
interface Node {
  ni: number;               // 节点索引（glTF nodes 数组）
  name: string;             // 节点名称
  pni: number | null;       // 父节点索引（glTF nodes 数组），根节点为 null
  parent: string;           // 父节点名称或 "—"（顶层节点）
  children: number[];       // 直接子节点索引数组（来自 glTF node.children），叶子节点为 []
  hasMesh: boolean;         // 是否为网格节点（false = 纯分组/变换容器节点，无 mesh）
  verts: number;            // 顶点数（所有 primitives 总和）；分组节点（hasMesh=false）恒为 0
  tris: number;             // 三角面数（indices / 3 或 POSITION / 3）；分组节点恒为 0
  attrs: string;            // 顶点属性列表，如 "POSITION NORMAL TEXCOORD_0"；分组节点为 ""
  mats: number[];           // 该节点（所有 primitives）引用的材质索引，去重升序；分组节点为 []
  T: number[];              // translation [x, y, z]，世界空间，单位米
  Rdeg: number[];           // rotation 转欧拉角 [x°, y°, z°]，世界空间
  S: number[];              // scale [x, y, z]，世界空间
}
```

**字段说明**：
- **从 Alpha 0.001a 起，`nodes` 数组包含 glTF `nodes` 里的全部节点，不再过滤纯分组节点**（无 `mesh`、只有 `children` 的变换容器节点）。之前版本用 `.filter(n.mesh !== undefined)` 把这类节点从表里剔除，README 曾写「仅显示含 mesh 的节点」，现已去掉这条限制——glTF 原生就用「没有 mesh 属性、只有 children 的 node」表达分组，详见 `Doc/EDITOR-SPEC.md` §1
- `hasMesh`：区分叶子网格节点（true）与纯分组节点（false）。**分组节点没有 primitives，所以 `verts`/`tris` 恒为 `0`、`attrs` 恒为 `""`、`mats` 恒为 `[]`**——这三/四个字段的类型没变（依然是 `number`/`string`/`number[]`，不会出现 `null`/`undefined`/`'—'` 这种越界值），消费端用 `hasMesh` 判断该不该展示这些字段，而不是靠字段本身是否为空值判断
- `pni`/`children`：新增字段，用节点索引直接表达树结构（`pni` 指向父节点索引，`children` 指向直接子节点索引数组），用于重建层级/渲染树状 UI；`parent`（父节点**名称**字符串）字段保留不变，向后兼容旧消费端
- `mats`：来自 `mesh.primitives[].material` 去重集合。CAD 类导出常见一个 mesh 挂多个 primitive、每个 primitive 各自绑定一个材质 ID（3ds Max 的 Multi/Sub-Object 材质拆分即是如此），此字段就是「材质编号」在节点层面的呈现
- `T/Rdeg/S`：**世界空间**变换——沿父链（`parentOf`）累乘所有祖先节点的 local matrix 后再分解。glTF 场景图常见「网格节点 + 无名父级壳」结构（网格节点自身 matrix 可能只是占位单位缩放，真实摆位烘焙在上一层父节点），若只读节点自身 local matrix，对这类文件会得到恒等/无意义的 TRS；v0.1.1 起改为世界空间以修正此问题。分组节点同样有意义——它是子树共享的变换基，算法不变
- `Rdeg`：四元数转 XYZ 欧拉角（弧度→度），便于人类阅读
- `attrs`：POSITION / NORMAL / TEXCOORD_0 / COLOR_0 / TANGENT / JOINTS_0 / WEIGHTS_0 等

**创建 Instance（2026-08-05 起）**：`Node` 接口本身没有新增字段——一个 Instance 就是一条普通的 `Node` 记录，`mesh` 引用（体现在 `hasMesh`/`mats`/`verts`/`tris` 这些字段上）跟原节点指向同一份底层 mesh 数据，`ni` 是新分配的索引，`name` 是原名加 `_instanceN` 后缀（避免跟 `nodeAnno` 的按名存储机制撞名）。写入链路：① `raw.nodes[]`（`gltf.parser.json`）新增节点记录，正确接入 `raw.scenes[i].nodes` 或父节点 `children[]`；② three.js 场景图（`model`）里新增对应的 `Object3D`/`Mesh`/`Group`，`geometry`/`material` 复用原节点引用的对象（不 clone 顶点/材质数据）。`rebuildNodeTable()`（从 `buildTables()` 拆出来的独立函数，只依赖 `raw`）负责创建后重算这张表。分组节点创建 Instance 时，子树里的每一层都会各自生成一条新的 `Node` 记录（保留原子树的层级粒度，不拍平成单个节点），详见 `Doc/EDITOR-SPEC.md` §6 实现记录。

**导出选中物体**：跟这份 JSON 表格无关的另一条导出路径——不是 `exportBtn`（导出注释 JSON）也不是「另存为 GLB」下拉菜单里的全量导出（`exportGlbBlob()`，Doc/TODO.md #21 起从原来的 `exportGlbBtn` 单一按钮重构成下拉菜单，核心打包逻辑抽成这个共用函数），是节点树每行的「⇩ 导出」按钮，直接把该节点对应的 `THREE.Object3D` 传给 `GLTFExporter.parse()`，产出一份只含这个子树依赖（mesh/material/贴图/accessor）的独立 `.glb` 文件，不产出 JSON。

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
  sceneBbox?: SceneBboxOverride;  // 场景整体包围盒手动覆盖（Doc/TODO.md #9），不存在或 manual=false 时查看器/导出用自动计算值
  basepoints?: MeasurementBasepoint[];  // 测量基点列表（Doc/TODO.md #10），见 Doc/EDITOR-VIEWER-CONTRACT.md 第五节，允许多个（如不同楼层各自的基点）
  defaultBasepointName?: string | null;  // 【2026-08-07 新增，Doc/TODO.md #37】用户手动指定的「默认基点」名字（对应
                             // basepoints[].name）。null/不存在＝没有手动指定，没关联具体基点的节点走原有的
                             // GLB 原生 Origin 节点/包围盒中心那套自动优先级兜底（basepoints[0]，规则见
                             // Doc/EDITOR-VIEWER-CONTRACT.md 第五节）；有值时这个值覆盖掉自动规则，用户有最终
                             // 决定权（哪怕场景里确实有 Origin 节点探测出来的基点，手动指定的也会盖过它）。
                             // 完整解析逻辑（含"GLB 原生 Origin 节点又被手动指定成别的默认基点"这种边界情况
                             // 怎么判断）见 Doc/EDITOR-SPEC.md §8 2026-08-07 实现记录。
  script?: ScriptEntry[];   // 操作脚本（Doc/TODO.md #13），按发生顺序追加的编辑动作记录，供「场景 ▾ → 模型 →
                             // 重放操作脚本」使用；查看器端不需要读这个字段（纯 3AS editor 内部机制），
                             // 见 Doc/EDITOR-SPEC.md §10
  cameraViews?: CameraView[];  // 【2026-08-08 新增，Doc/TODO.md #43】相机视角书签列表，每个模型各自一份
                             // （跟 basepoints 同一个存储层级），供视口左上角「相机视角」卷展栏增删改查，
                             // 见 Doc/EDITOR-SPEC.md §21.2
}

interface ScriptEntry {
  op: string;                // 操作类型：'editMaterial' | 'setUvTransform' | 'setNodeTransform' |
                              // 'createInstance' | 'uploadTexture' | 'removeTexture' | 'cleanupBatch' |
                              // 'reparentNode'（2026-08-06 Doc/TODO.md #29 新增，拖拽重新挂靠父节点）|
                              // 'addBasepoint' | 'addAutoBasepoint' | 'deleteBasepoint' | 'renameBasepoint' |
                              // 'setBasepointPos' | 'setBasepointRot' | 'setDefaultBasepoint' |
                              // 'clearDefaultBasepoint'（2026-08-07 Doc/TODO.md #37 新增八种，基点新增/生成/
                              // 删除/改名/编辑位置朝向/设默认/取消默认，见 Doc/EDITOR-SPEC.md §8）
  target: ScriptTarget | null;  // null 用于 cleanupBatch/addBasepoint/addAutoBasepoint/clearDefaultBasepoint
                              // （没有单一既有目标——addBasepoint 系是"创建新的"，参数在 params 里；
                              // clearDefaultBasepoint 是清空一个全局状态，不针对某个基点）
  params: Record<string, any>;  // 每种 op 各自的参数，字段随 op 变化，见 Doc/EDITOR-SPEC.md §10 表格；
                              // 'reparentNode' 的 params 额外带 { newParentName, newParentAtIndex }——
                              // 新父节点不是 ScriptTarget（target 字段记的是被拖节点本身），只是这次
                              // 操作的一个参数，解析方式跟 target.atIndex 同一套「hintIndex 优先，
                              // 找不到再按名字找第一个」策略，见 Doc/EDITOR-SPEC.md §16；
                              // 'addBasepoint' 的 params 是 { position, zRotation, namePrefix, source,
                              // associateNodeTarget? }（associateNodeTarget 只有中心点面板「以当前节点位置
                              // 新建基点」这个入口会带，重放时顺带把新基点关联回该节点）；'renameBasepoint'
                              // 是 { newName }；'setBasepointPos' 是 { axis, value }；'setBasepointRot'/
                              // 'setDefaultBasepoint' 是 { value }/{}；'clearDefaultBasepoint' 是 { prevName }
                              // （prevName 纯日志用途，重放不依赖它）
  ts: number;                // 记录时间戳（毫秒）
}

interface ScriptTarget {
  byName: string;            // 按名字匹配——节点用 raw.nodes[].name（缺省 fallback 'node_'+索引），
                              // 材质用 raw.materials[].name（缺省 fallback '材质 #'+索引），
                              // 基点用 anno.basepoints[].name（2026-08-07 Doc/TODO.md #37 新增第三种 kind）
  kind: 'node' | 'material' | 'basepoint';
  atIndex?: number;          // 记录那一刻目标所在的索引，仅当「同名候选之间的优先提示」用，不是主键——
                              // 见 Doc/EDITOR-SPEC.md §10 关于同名材质（真实样品所有材质原始都叫
                              // "fallback Material"）场景下如何避免误判的说明
}

interface NodeAnnotation {
  visible: boolean;         // 默认是否显示
  allowEdit: boolean;       // 是否允许用户编辑
  alias: string;            // 别名（查看器 UI 显示名）
  note: string;             // 备注说明
  bbox?: BoundingBoxAnnotation;  // 测量包围盒（OBB），见 Doc/EDITOR-VIEWER-CONTRACT.md 第六节，字段定义在那份契约文档
  basepointRef?: string;    // 关联的测量基点名字（对应 Annotations.basepoints[].name），不存在/空值＝不关联，
                             // 3AS editor 端约定不关联时相对测量数值 fallback 到 basepoints[0]（"场景默认基点"），
                             // 见 Doc/EDITOR-VIEWER-CONTRACT.md 第五节
  atomicGroup?: boolean;    // 【2026-08-06 新增，Doc/TODO.md #29】组锁定：true = 这个节点在 viewer 里必须
                             // 整体选中/整体移动，不能选中/操作它的某个子节点；默认 false（不存在也视为 false）。
                             // editor 自己内部预处理/标注时不受这条限制，只约束 viewer 端最终用户能操作到什么
                             // 粒度。editor 端 UI 落地成节点表「允许选中」勾选框——勾选＝atomicGroup:false，
                             // 取消勾选＝atomicGroup:true，语义互为反面，字段名和真实含义见
                             // Doc/EDITOR-VIEWER-CONTRACT.md 第三节「组锁定」+ 第七节「待确认」第 1 条回填
}

interface SceneBboxOverride {
  manual: boolean;                          // true=场景表显示/导出下面的 size/center；false=显示/导出自动计算值，size/center 仍保留（关闭手动开关不清空，方便下次重新打开时不是从 0 开始）
  size: [number, number, number];           // 手动指定的场景整体包围盒尺寸（米）
  center: [number, number, number];         // 手动指定的场景整体包围盒中心（米）
}

interface MeasurementBasepoint {
  name: string;              // 基点名字，同一份注释里唯一（节点 basepointRef 靠这个字符串识别关联的是哪个基点）
  position: [number, number, number];  // 基点世界坐标（米），跟 BoundingBoxAnnotation.center/SceneBboxOverride.center 同一套坐标系
  zRotation: number;          // 基点朝向（度）——只绕本场景竖直轴的方位角，不是完整三轴旋转，
                               // 对应建筑测量里的"方位角"概念；字段名沿用既有措辞，具体绕哪根轴见
                               // Doc/EDITOR-VIEWER-CONTRACT.md 第五节的实现回填说明
  source?: { kind: 'auto' | 'manual', priority?: 1 | 2, detail?: string };  // 【2026-08-06 Doc/TODO.md #30】
                               // 这个基点自己是怎么产生的（不是"它是不是当前默认"，两者分开，见下面
                               // resolvedReason 的说明）：kind==='auto' 是 computeDefaultBasepoint() 按
                               // GLB 原生约定探测出来的（priority 1=命中 Origin 节点，2=退化用包围盒中心），
                               // kind==='manual' 是用户手动新增的。纯展示/调试元数据，不参与坐标计算，
                               // editor 内部字段，2026-08-06 #30 完成时判断不需要写进这份 SPEC.md 契约
                               // （只有 editor 端 UI 读它），这次 #37 文档梳理时补记录进来，字段行为本身
                               // 没有变化。
  resolvedReason?: string;    // 【2026-08-07 Doc/TODO.md #37 新增，只读，只出现在导出的注释 JSON 里，
                               // 不是 editor 运行时 anno.basepoints[] 内存对象的常驻字段——导出时
                               // （buildExportJson()）现算现拼一份浅拷贝加上去，不持久化进 localStorage，
                               // 避免"存量字段是不是过期了"的问题】人类可读地说明"这个基点当前是不是
                               // 全局默认基点、如果是，靠哪条规则生效"：
                               //   · "GLB原生Origin节点" —— 当前默认基点，且没有用户手动指定覆盖，
                               //      是靠场景里 Origin/_origin/origin 命名节点探测出来的（对应
                               //      basepoints[0].source.priority===1）
                               //   · "场景包围盒中心（兜底）" —— 当前默认基点，没有手动指定覆盖，命中
                               //      优先级 2 兜底（包围盒中心），或者是旧注释 JSON 里没有 source 字段
                               //      的历史数据（无法证明是靠 Origin 节点探测的，保守归到这一类，不
                               //      编造虚假的"命中 Origin"结论）
                               //   · "用户手动指定为默认" —— Annotations.defaultBasepointName 精确等于
                               //      这个基点的名字（用户在下拉菜单里选过「设为默认基点」）
                               //   · "非当前默认基点" —— 除上面三种之外的全部其它基点（场景允许多个
                               //      基点，全局默认只有一个，其余的都落进这一类；即使这个基点自己的
                               //      source.priority===1，只要它不是当前生效的默认，也不会标"GLB原生
                               //      Origin节点"——这是决策记录"用户有最终决定权"在数据层面的体现，
                               //      判断逻辑/边界情况详见 Doc/EDITOR-SPEC.md §8 2026-08-07 实现记录）
}

interface CameraView {
  name: string;               // 视角名字，同一份注释里唯一（防重名规则跟 MeasurementBasepoint.name 同一套：
                               // 撞名自动加数字后缀，不静默覆盖），默认命名"视角"/"视角2"……
  position: [number, number, number];  // three.js camera.position（跟场景坐标系同一套，即 preprocess() 归一化后的坐标）
  target: [number, number, number];    // three.js controls.target（OrbitControls 的注视点）
  fov: number;                 // three.js camera.fov（角度，PerspectiveCamera 视野角）
  zoom: number;                // three.js camera.zoom（本项目 OrbitControls 未开 zoomToCursor，鼠标滚轮走
                               // dolly 改 position 不改 zoom，实际取值目前总是 1，但完整对应 camera.zoom 存取，
                               // 不假设这个字段用不上）
  up: [number, number, number]; // three.js camera.up——补的第五个字段，不是凭空发明：视口左上角「顶视」预设
                               // （frameViewportPreset('top')）会把 camera.up 从默认 (0,1,0) 改成 (0,0,-1)，
                               // OrbitControls 拖拽过程中不会自动纠正这个值，缺了它无法保证"应用视角后画面
                               // 精确复现保存时的样子"，详见 Doc/EDITOR-SPEC.md §21.2 实现记录
}
```

**字段说明**：
- `nodes`：仅记录有注释的节点，未注释的节点不出现在对象中
- `visible/allowEdit`：查看器初始化时读取，控制默认行为
- `alias`：优先级高于 glTF 原始 `name`，用于 UI 显示
- `matNotes/texNotes`：键是索引的**字符串形式**（JSON 对象键必须是字符串）
- `bbox`/`sceneBbox`：导出的 `scene['包围盒尺寸']`/`scene['默认中心点']` 两个展示字符串字段（mm，`SPEC.md` 场景表小节）始终反映当前生效值（自动或手动），查看器直接读这两个字符串就够，不需要自己判断 `sceneBbox.manual` 再挑源头
- `basepoints`/`basepointRef`：测量基点系统（Doc/TODO.md #10），GLB 原生约定探测规则、坐标系取舍、`zRotation` 具体绕哪根轴，都在 Doc/EDITOR-VIEWER-CONTRACT.md 第五节
- `defaultBasepointName`/`resolvedReason`：基点优先级下拉 + 导出解释字段（Doc/TODO.md #37），前者是运行时/持久化字段，后者只在导出 JSON 里现算，两者配合把"哪个基点是全局默认、为什么"这件事从纯代码 fallback 逻辑变成用户可见可控的东西，完整规则见 Doc/EDITOR-SPEC.md §8 2026-08-07 实现记录
- `script`：操作脚本记录 + 重放系统（Doc/TODO.md #13），纯 3AS editor 内部机制，查看器端可以忽略这个字段（不影响渲染/交互），完整设计和实现记录见 Doc/EDITOR-SPEC.md §10
- `cameraViews`：相机视角书签（Doc/TODO.md #43），每模型独立存储，新建/应用/重命名/删除四个操作对应视口左上角「相机视角」卷展栏，查看器端如果需要还原某个书签视角，直接把五个字段分别写回 `camera.position`/`controls.target`/`camera.fov`/`camera.zoom`/`camera.up`（`fov`/`zoom` 改完记得 `camera.updateProjectionMatrix()`），完整设计和实现记录见 Doc/EDITOR-SPEC.md §21.2

---

## 二、glTF 扩展支持矩阵

### 2.1 已解析扩展

| 扩展名 | 类型 | 字段 | 说明 |
|-------|------|------|------|
| `KHR_materials_specular` | 材质 | `specularFactor` | 高光强度，0-1 |
| `KHR_materials_emissive_strength` | 材质 | `emissiveStrength` | 自发光倍数，默认 1.0 |
| `KHR_texture_transform` | 材质·贴图槽 | `offset`/`scale`/`rotation` | 贴图 UV 移动/缩放/旋转。挂在「某材质的某贴图槽」这一层（如 `pbrMetallicRoughness.baseColorTexture.extensions.KHR_texture_transform`），不是贴图本身的属性——同一张贴图被不同材质/不同槽位引用时可以有各自独立的变换。读写完全交给 three.js `GLTFLoader`/`GLTFExporter`（映射到 `THREE.Texture.offset`/`.repeat`/`.rotation`/`.center`），3AS 不自己解析这段扩展 JSON，只做 UI + 属性读写 |

**消费方式**：
- 材质表直接展示 `spec` 和 `emStr` 字段
- 材质详情编辑区「贴图 UV 变换」分区：按材质+贴图槽分组，每组可编辑移动 X/Y、缩放 X/Y、旋转角，见 README.md 表1
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
