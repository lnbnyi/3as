# Editor / Viewer 数据契约

**状态**：草案 / 待评审，未开始实现
**背景**：确认了两页面架构——**editor**（3AS 本体，技术美术用，预处理+注释+编辑）和 **viewer**（Chengduhuagao 案例/3ds-viewer 演化而来，终端用户用，查看+个人方案）。两个页面必须读写同一份数据契约，不能各画各的。这份文档定这个契约，不写实现细节（实现细节回 `EDITOR-SPEC.md`/`BACKEND-SPEC.md`）。

---

## 一、两页面架构

| | editor（3AS） | viewer（3ds-viewer 演化） |
|---|---|---|
| 用户 | 技术美术/模型准备者 | 最终用户/客户 |
| 输入 | 原始 GLB | GLB + editor 产出的注释 JSON |
| 产出 | 「基线注释」（下面第二节） | 「个人方案」（下面第二节），带版本号 |
| 加载方式 | 本地拖拽/文件选择/（未来）从服务器打开 | **URL 带项目名/ID 打开服务器上的文件**，如 `viewer.html?id=<uuid>`（对应 `Doc/BACKEND-SPEC.md` §2.2 的 `GET /api/projects/<id>`） |

这不是"以后 viewer 并入 3AS 变成一个模式"——是**两个独立页面，同一套后端、同一套数据契约**。之前 `README.md`「与 Chengduhuagao 案例的关系」那节写的"未来并入 3AS 查看/编辑模式"这个方向要更新成这个新架构，回头记得改（这次先不改，等这份契约定下来一起改，避免改两遍）。

---

## 二、两层数据模型（这是最容易搞混的地方，务必分清楚）

**不是一份 JSON 到处用**，是两层，各自的写入方只有一个：

### 第一层：基线注释（editor 产出，viewer 只读）

对应 `SPEC.md` 现有的 `Annotations` 结构，**editor 是唯一写入方**，viewer 打开项目时读取，但不能改：

```typescript
interface NodeAnnotation {
  visible: boolean;
  allowEdit: boolean;         // 现有字段。语义要收紧，见下面「组锁定」
  alias: string;
  note: string;
  atomicGroup: boolean;       // 【新增】true = 这个节点（通常是分组节点）在 viewer 里必须整体选中/整体移动，
                               // 不能选中/操作它的某个子节点。「非常重要」——用户原话
  bbox?: BoundingBoxAnnotation;   // 【新增】对应 #9，见第三节
}
```

### 第二层：个人方案（viewer 产出，editor 不碰）

对应旧 3ds-viewer `mods/<author>.json` 的概念，**viewer 是唯一写入方**，多个终端用户各有一份，互不覆盖：

```typescript
interface UserScheme {
  version: number;            // 递增版本号，见第四节
  author: string;             // 未来接 BACKEND-SPEC.md 的 owner token 体系，现阶段先延续旧的自由字符串
  updatedAt: string;
  transforms: Record<string, { T: [number,number,number], R: [number,number,number] }>;
  // 键必须是「可选中单元」的 key——如果这个节点被基线注释标了 atomicGroup:true，
  // 这里的 key 就是这个组节点自己，不能是它的某个子节点。editor 产出的 atomicGroup 标记
  // 直接约束了 viewer 这里能写什么 key，两层数据不是互相独立的，是上层约束下层。
  measurementOrigin?: MeasurementBasepoint;  // 见第五节，用户可能会重设基点，这个是"用户在 viewer 里改的"，
                                              // 跟 editor 给的默认基点分开存。MeasurementBasepoint 类型定义
                                              // 见 SPEC.md 的 Annotations 接口小节（{name, position, zRotation}，
                                              // editor 端 2026-08-06 已实现，viewer 端这里仍是待实现的占位）
}
```

**旧 3ds-viewer 现状要如实指出**：现在这个"第二层"数据实际分裂在两个文件里——`state.json`（单一全局，含 `parts` 改名和 `settings`）+ `mods/<author>.json`（多人 transforms）。**这两个要合并成上面这一份 `UserScheme` 结构**，`alias` 覆盖（`state.json.parts`）以后也走 `UserScheme` 或者干脆读第一层的 `alias` 就够（如果 viewer 也允许用户自己改显示名，那是第二层的覆盖值，字段名待定，先记这个问题，不是这次要解决的，先把两个文件合一这件事定下来）。

---

## 三、组锁定（atomicGroup）——「非常重要」

**规则**：`atomicGroup: true` 的节点，在 viewer 里：
- 点击它的任意子节点 = 选中整个组（不是选中被点的那个子节点）
- 移动/旋转操作作用于整个组，不能单独动组里的某一件
- editor 自己内部预处理/标注时**不受这条限制**——技术美术在 3AS 里照样能展开这个组、给里面每个子节点单独挂备注/材质，`atomicGroup` 只约束 viewer 端最终用户能操作到什么粒度，不约束 editor 端的标注粒度

**editor 这边要做的**：模型块树（现有 #5/#20 的树状结构）每个分组节点要能勾选"锁定为整体"（对应 `atomicGroup`），这是一个新的 UI 字段，排进 todo。

---

## 四、版本号

用户要求"开发都是带版本号的"。两层数据分别有版本概念，不要混：

- **基线注释**：editor 每次导出，`exported` 时间戳已经有了（现有字段），但没有递增版本号——这次要加一个 `annotationVersion: number`，每次 editor 保存/导出到服务器时 +1。对应 `BACKEND-SPEC.md` §2.3 的版本历史，服务端按这个号存 `versions/v<N>.3as.json`。
- **个人方案**：`UserScheme.version`，viewer 每次用户保存自己的方案时 +1，各用户独立计数（不是全局一个号）。

---

## 五、测量基点：优先读 GLB 自带信息

用户明确要求：**默认测量原点尽量从 GLB 自己的数据里取，取不到才用 JSON 补**。对应 #10（测量基点系统，**2026-08-06 已实现**，见 `Doc/EDITOR-SPEC.md` §8 末尾实现记录），具体规则：

1. 优先级 1：GLB 场景本身如果有明确的原点约定（比如场景根节点变换、或者约定俗成的某个命名节点如 `Origin`/`_origin` 作为参考点——**这个约定具体是什么需要看实际拿到的 GLB 长什么样再定**，目前样品 `chengdu-huagao-0801.glb` 没有这种命名约定，待确认）
2. 优先级 2：GLB 的包围盒中心/地面中心（现有场景表已经在算的 `默认中心点`，这个是现成可用的兜底）
3. 优先级 3：都没有 → 走基线注释里 editor 手动设的基点（`SceneMetadata` 或新的注释字段，字段名待定）
4. viewer 端用户还能再基于上面任意一个结果自己重设（存进第二层 `UserScheme.measurementOrigin`，不覆盖基线）

**2026-08-06 回填（第七节「待确认」第 2 条的答案，实现时直接定下来，不等确认）**：

- **优先级 1 的具体约定**：场景里存在名字是 `Origin`/`_origin`/`origin`（大小写不敏感，正则 `/^_?origin$/i`）的节点，就取它的世界空间 T 当基点 `position`，世界空间竖直轴旋转分量当 `zRotation`。真实样品 `chengdu-huagao-0801.glb` 没有这种命名节点，走优先级 2（包围盒中心）；用手写合成的 glTF JSON（含一个 `Origin` 命名节点）单独验证过优先级 1 分支确实生效，见 `Doc/EDITOR-SPEC.md` §8 实现记录里的测试细节。
- **`zRotation` 具体绕哪根轴**（这条文档原文没写清楚，实现时一并定下来）：字段名字面沿用了「只绕 Z 轴」的说法，但本项目 GLTF/three.js 场景是 **Y-up**（`index.html` 里 `preprocess()` 摆位逻辑、`buildTables` 默认相机推荐值都拿 `size.y` 当高度轴——Y 是这个场景真正的竖直轴，X/Z 是地面两个水平轴）。「只绕竖直轴的朝向角」才是「建筑测量方位角」这个概念本该对应的东西——绕水平轴转是「歪头」不是「朝向」。判断契约原文作者是习惯了 CAD/BIM 常见的 Z-up 坐标系（Revit/AutoCAD 那一路）随手写的说法。所以 **`zRotation` 实际存取的是世界空间 Y 轴欧拉分量**，字段名保留 `zRotation` 只是跟这份文档、`Doc/EDITOR-SPEC.md` §8 的既有措辞保持字面一致，没有为了语义准确另外改名引发连锁的字段改名。GLB 原生约定探测、可视化 gizmo、节点「相对基点坐标」计算，三处统一用这个约定，内部没有「一半按 Y 一半按 Z」的不一致。
- **坐标系**：`position`/`zRotation` 用的是 3AS editor 内部「模型整体缩放+落地居中」归一化之后的场景坐标系（`nodeObjects.matrixWorld`），跟 `BoundingBoxAnnotation.center`（见第六节）、`SceneBboxOverride.center` 同一套坐标系，可以直接相减比较——这也是「节点相对基点坐标」这个概念成立的前提（基点和节点世界坐标必须在同一个坐标系里才谈得上"相对"）。

---

## 六、包围盒（Bounding Box）数据同步

对应 #9（**2026-08-06 已实现**，见 `Doc/EDITOR-SPEC.md` §7 末尾实现记录）。`NodeAnnotation.bbox` 这个字段（见第二节）装下：

```typescript
interface BoundingBoxAnnotation {
  rotationDeg: [number, number, number];  // 生成 OBB 时用的旋转角，默认读节点世界旋转
  size: [number, number, number];         // 包围盒尺寸
  center: [number, number, number];       // 包围盒中心（相对世界原点，不是相对测量基点）
}
```

`center` 字段这版实现前是"相对世界原点或相对测量基点，待定"——现在回填成确定的说法：**相对世界原点**，因为 #10（测量基点系统）还没做，实现时没有基点可相对；等 #10 做完如果要改成"相对基点"，是一次破坏性字段语义变更，需要同时改 editor 写入逻辑和这份契约，到时候再回来改这一条。

**2026-08-06 补充**：#10 测量基点系统已实现，但这条**没有跟着改**——`BoundingBoxAnnotation.center` 仍然是相对世界原点，不是相对基点，维持上面这段写下时的决定。「节点相对基点坐标」是 #10 单独新增的展示字段（`nodeAnno(name).basepointRef` 关联 + 界面上现算展示，不占用/不改写 `bbox.center` 这个已有字段），两者并存、互不覆盖。要不要把 `bbox.center` 改成相对基点是一次独立的破坏性变更，留到真有需要时再做。

场景整体包围盒的手动覆盖（`Doc/TODO.md` #9 第 6 点）不在这个 `BoundingBoxAnnotation` 结构里，是 `Annotations.sceneBbox: { manual, size, center }` 独立字段（同样是世界空间米制单位），定义见 `SPEC.md` 的 `Annotations` 接口。

editor 里生成/编辑的包围盒，要能原样体现在 viewer 里（viewer 至少要能显示，不一定要能编辑——具体 viewer 端包围盒可不可编辑，待确认，见下面第七节）。

---

## 七、待确认

1. ~~`atomicGroup` 具体 UI 长什么样~~ **2026-08-06 已回填（Doc/TODO.md #29）**：按原文倾向的方案做了——新增独立字段，没有跟 `allowEdit` 混。模型块树每个节点行新增第三个勾选框「允许选中」（跟已有的「显示」「允许编辑」同一行并排），**语义是 `atomicGroup` 的反面**：勾选＝允许单独选中/编辑（`atomicGroup:false`，默认态）；取消勾选＝这个节点被"冻结"，不能单独选中/编辑，只能作为它所属的组整体被操作（`atomicGroup:true`）。之所以在 UI 上呈现成"允许选中"而不是直接叫"组锁定"/"atomicGroup"，是因为面向技术美术的措辞里"允许 X"跟旁边"显示"「允许编辑」两个已有勾选框保持同一个语法（"允许 XX"），三个勾选框放在同一行时读起来是一套一致的正/负极性（打勾＝开放这项能力），比引入一个"锁定"这种极性相反的词、让用户在同一行里要在"打勾=开"和"打勾=关"之间切换心智模型更不容易读错。数据结构上，`atomicGroup` 跟 `visible`/`allowEdit` 存进同一个 `nodeAnno()` 记录对象（`SPEC.md` `NodeAnnotation` 接口新增字段，见该文档），默认值 `false`（不存在也视为 `false`，不影响旧注释 JSON 的兼容性——没有这个字段的老注释文件加载后，所有节点的「允许选中」勾选框默认是勾上的，行为等同于这个字段从来没出现过）。实现记录见 `Doc/EDITOR-SPEC.md` §16。
2. ~~测量基点的 GLB 原生约定具体是什么（命名节点？extras 字段？）~~ **2026-08-06 已回填**：`Origin`/`_origin`/`origin`（大小写不敏感）命名节点，具体规则见第五节「2026-08-06 回填」小节，实现记录见 `Doc/EDITOR-SPEC.md` §8。
3. viewer 端包围盒可不可编辑，还是只读展示
4. 旧 `state.json`/`mods/*.json` 到新 `UserScheme` 的迁移，是写个一次性脚本转旧数据，还是旧数据不迁移直接从新格式重新开始（3ds-viewer 现在的 mods 数据量不大，可能没必要迁移）

这些不阻塞先把契约定下来、排 todo，实现的时候顺着做就行，遇到再回来改这份文档。
