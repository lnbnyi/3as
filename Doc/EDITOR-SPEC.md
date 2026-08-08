# 3AS Editor Mode 设计草案

**状态**：草案 / 待评审，未开始实现
**对应版本**：Alpha 0.001a 之后
**背景**：上一阶段把 3AS 与 Chengduhuagao 案例（`3ds-viewer/`）的关系纠正为——Chengduhuagao 现在只是一个硬编码单模型、不带通用加载能力的案例；一旦「查看 + 编辑 + 方案保存」这段体验补上通用加载能力，就应该整个并入 3AS，成为 3AS 自己的「查看/编辑模式」，不再是两个独立项目。本文档就是这个「查看/编辑模式」的具体功能规格，把口述需求整理成可执行的设计。

**怎么读这份文档**：每一节标题后面括号里的编号对应你口述时的编号，方便对照。第一版（2026-08-05）留过 5 个「待确认」点，已经在当天全部确认完，见第 11 节的决策记录；现在文档里不再有阻塞项。

**配套文件**：实现进度对照 [`Doc/TODO.md`](./TODO.md)，文档组织规范见 [`Doc/INDEX.md`](./INDEX.md)。

---

## 0. 版本号规范

从这次开始用 `Alpha 0.0XXa` 序列（当前 **Alpha 0.001a**），不再用 SemVer 的 v0.1.x 写法。之前的 v0.1.0/v0.1.1 历史条目保留在 CHANGELOG 里不改。

---

## 1. 技术前提核对：glTF 原生支不支持组/层级/Instance

这是第 4、6 点里你直接问我的问题，先给结论，后面的设计都建立在这个结论上：

| 概念 | glTF 原生支持吗 | 说明 |
|---|---|---|
| **层级关系** | ✅ 原生支持 | `scene.nodes` 通过 `node.children` 组成树，3AS 现有代码里 `parentOf` 映射已经在用这个结构（上一版 TRS 世界空间修复就是沿着这棵树累乘） |
| **组** | ✅ 原生支持，但不是独立类型 | glTF 没有「group」这个专门类型——任何**没有 `mesh` 属性、只有 `children` 的 node** 本身就是一个组（纯变换容器）。3AS 现在的问题是：节点表**过滤掉了**这类节点（README 原话：「仅显示含 mesh 的节点，纯分组节点不列」），第 6 点要求组要能在模型块表里展示和编辑，就是要把这条过滤去掉 |
| **副本 / Instance** | ✅ 原生支持 | glTF 的 `mesh` 是索引引用，**多个 node 引用同一个 mesh 索引**就是原生的「实例化」——不复制顶点/索引数据，只是多一条 node 记录（transform + 引用）。three.js 的 GLTFLoader 加载后，这些 node 会变成共享同一个 `BufferGeometry` 的多个 `Object3D`，正好符合你说的「减小文件体积」的目的 |

**结论**：不需要发明额外的自定义结构或 glTF 扩展来记录「这些物体是一组」「这个是那个的副本」——glTF 自己的 node 树 + mesh 引用已经能完整表达。3AS 要做的是：
1. 把节点表从「只显示 mesh 节点」升级成「显示完整节点树，含纯分组节点」——**已完成**（TODO #5，2026-08-05）：`tables.node` 不再过滤，「模型块」Tab 改成可折叠树状表格，`SPEC.md`/`README.md` 已同步
2. 编辑器里「创建 Instance」这个操作，本质就是「新建一个 node，引用一个已存在的 mesh 索引」——不是设计新格式，是设计这个创建操作的 UI 和写入逻辑（TODO #6，未开始）

---

## 2. UI/图标参考：modelviewer.dev/editor（对应第 1 点）

看了 `modelviewer.dev/editor/`（Google 官方 `<model-viewer>` 编辑器），布局和图标语言记录如下，供 3AS 后续 UI 设计直接借用，不用重新发明：

**整体布局**：左侧大面积 3D 视口（拖拽 GLB 进来），右侧一条竖直图标栏 + 下方可折叠的手风琴面板（`<model-viewer> snippet` / `File Manager` / `Mobile View` / `Best Practices` 这种分组），跟 3AS 现有「左视口 + 右侧栏 Tab」的骨架其实是同一个思路，不用推倒重来。

**图标语言**（用的是 Material Symbols 字体，图标靠名字识别，这套词汇可以直接抄）：

| 图标名 | 用途 | 3AS 里对应什么 |
|---|---|---|
| `save_alt` / `file_download` | 导出/下载 | 「另存为 GLB」按钮 |
| `file_upload` | 替换资源（GLB/HDR/贴图） | 替换贴图、导入新 GLB |
| `undo` | **每个可编辑字段旁边单独一个「还原到原始值」图标**——这个模式很值得抄 | 材质/UV/坐标编辑器里每个字段配一个 revert |
| `create`（铅笔） | 重命名/编辑 | 节点别名编辑、材质命名 |
| `delete`（垃圾桶） | 删除 | 删除包围盒、删除 Instance |
| `add_circle` | 新建 | 创建 Instance、创建包围盒 |
| `color_lens`（调色板） | 材质面板入口 | 材质编辑器 Tab |
| `search` | 搜索/定位 | 大节点树场景下按名称搜节点 |
| `photo_camera` | 截图/缩略图 | 场景截图（如果以后要做项目缩略图） |
| `import_export` | 导入导出面板入口 | 场景菜单 |

**已确认**：换成图标化（不再是现在的纯文本按钮），**每个图标必须配鼠标悬浮 tooltip 注释文字**——图标本身可能看不出具体含义，tooltip 是必须项不是可选项。具体用 Material Symbols 字体、emoji 还是手绘 SVG 留到实际做 UI 时再挑（不影响现在排 todo），但方向是图标+悬浮说明，不是图标独自上阵。

**实现记录（2026-08-06，#28）**：

- **选型**：手绘内联 `<svg>`，不是 Material Symbols 字体（会引入外部字体文件依赖，违反项目"纯前端单文件无构建步骤无外部依赖"的一贯原则，见本节开头 modelviewer.dev 参考里也提醒了这点）、不是 emoji（emoji 字形跨系统/字体渲染差异大，且颜色不跟随 CSS `color`，没法用同一套 `button`/`button.on`/`button:hover` 规则统一管理选中态配色）。四个图标全部 `viewBox="0 0 20 20"`、`stroke="currentColor"`（线条部分）+ 少数填充点用 `fill="currentColor"`，**没有写任何单独的图标配色 CSS**——`button`/`button.on`/`button:hover` 三条既有规则本来就管 `color`，`currentColor` 天然继承，选中态变成 `--accent` 金色、未选中态 `--ink-dim` 灰、hover 变 `--ink` 完全是免费拿到的，不用额外写 `.on svg{...}` 这种重复规则。
- **四个图标语义**：材质→调色板轮廓+4个填充色点（`fill` 圆点，暗示"多种颜色"）；贴图→经典相片图标（圆形+山形折线，图片查看器通用语言）；模型块→等距六边形轮廓+内部三条棱线（立方体的标准线稿画法）；场景→四角取景器括号+中心圆点（取景框=囊括整个场景的意思，跟立方体图标刻意拉开形状差异，不会认错）。
- **tooltip**：用浏览器原生 `title` 属性，值直接是原来的中文文案（材质/贴图/模型块/场景），不做自定义 tooltip 组件——原生 `title` 已经满足"必须有悬浮说明"这条硬性要求，没有必要为此多引入一套定位/延时/消失逻辑的自制组件。
- **点击切换逻辑未动**：`data-tab` 属性、`renderTab()` 里 `document.querySelectorAll('#tabs button').forEach(b => b.classList.toggle('on', ...))`、`document.querySelectorAll('#tabs button').forEach(b => b.onclick = () => renderTab(b.dataset.tab))` 三处都没有改一个字——图标化只换了按钮内部的可见内容（文字→svg），按钮本身的 DOM 结构（标签、属性、事件绑定方式）完全不变，行为不可能受影响。
- **`#settingsBtn`（⚙ emoji）一并换成同风格 SVG 齿轮图标**：理由是原来的 emoji 齿轮不跟随 `color`/hover 状态变化（emoji 字形自带系统配色，CSS `color` 管不到），换成 `stroke="currentColor"` 的线条齿轮后跟四个 Tab 图标是完全统一的一套视觉语言，hover/交互反馈也统一了。风险很低（`title` 属性没变，点击绑定的是 `#settingsBtn` 这个 ID 不是内部内容，一并换不会破坏任何东西），所以直接做了，没有作为需要用户决定的取舍项。
- **验证**（`_dev/test-todo28-tab-icons.js`，Playwright，21 项断言全 PASS）：四个 tab 按钮均含内联 `<svg>` 且不再有裸文字、`title` 精确等于对应中文名、默认材质 tab 保持选中态、切换到其它三个 tab 时 `.on` 类精确跟随（每次只有一个）且 `#tables` 内容正常渲染（证明点击切换功能没被破坏）；`settingsBtn` 含 `<svg>` 且 `title` 未变，点击后设置面板仍能正常打开；选中态和未选中态两个按钮的 computed `color` 值不同（视觉区分成立的量化证据，非目测）；控制台全程 0 报错。真实样品 `chengdu-huagao-0801.glb` 加载后四个 tab 分别截图，图标在暗色主题下清晰可辨、选中态金色/未选中态灰色对比明显。测试脚本：`_dev/test-todo28-tab-icons.js`（可重跑复查）。截图：`_dev/shots/todo28-00` 至 `todo28-07`。

---

## 3. 「另存为 GLB」（对应第 1 点）

3AS 目前只能导出注释 JSON，不能导出编辑后的 GLB 本身。要加：

- 头部按钮排新增「另存为 GLB」（图标参考上面 `save_alt`）
- 技术方案：引入 `GLTFExporter`（three.js 官方导出器，跟现在 `vendor/loaders/GLTFLoader.js` 是同一个 examples/jsm 家族），需要新增 `vendor/exporters/GLTFExporter.js`
- 导出时把内存里当前的 three.js scene graph（已经应用了材质清理、坐标归一化等编辑）重新序列化成 GLB，而不是照抄原始文件
- **贴图必须内嵌进 GLB 二进制**（你已经确认这条，补充说明为什么可以放心做）：这不是需要额外声明支持的扩展，是 GLB 容器格式本身的基础能力——`image` 既可以用 `uri` 指外部文件，也可以用 `bufferView` 直接指向同一个二进制文件里的一段数据，后者就是「贴图打包进 GLB」。`GLTFExporter` 用 `binary: true` 导出时默认就是这个行为，不用额外配置，所有遵循 glTF 2.0 规范的查看器都认

---

## 4. 设置按钮 + 附加信息（对应第 2 点）

- 左上角新增「设置」图标按钮（位置：现在 header 左边是 `3AS` 标题，设置按钮放标题左侧或右侧紧挨着）
- 加载模型后展示「附加信息」——**已确认做成开放式/可扩展面板**，不是一次性定死的固定字段列表，具体卡片随后续需要陆续加。实现上做成「信息卡片」布局（一张卡片一类信息），方便往里插新卡片而不用改整体结构。目前已经明确要有的一张卡片：
  - **兼容性卡片**：这份 GLB 用到的 glTF 扩展，在主流查看器（three.js / Babylon.js / Google model-viewer / Sketchfab）里的支持情况——detail 见第 12 节「glTF 扩展兼容性」。这条是为了回应你说的「不知道线上的 glTF 加载器都支持到什么级别」，与其我替你判断，不如把这个判断做成模型加载后就能看到的常驻信息
  - 其他卡片（原始文件 hash、解析耗时、GLTFLoader 警告等）留待后续需要时再加

**实现记录（2026-08-06，#3）**：

- **入口**：header 左侧新增齿轮图标按钮 `#settingsBtn`（`.icon-btn`，单字符窄内边距，跟其它文字按钮尺寸区分开），放在 `3AS` 标题左侧、紧贴 header 最左边——放左边是因为右侧已经排了「打开 GLB / 示例模型 / 导出注释 JSON / 另存为 GLB / 日志」五个按钮，硬塞第六个会跟现有元素挤，左边只有标题，空间宽松。
- **面板外壳**：`#settingsOverlay`/`#settingsPanel`，跟材质清理菜单（§5）、包围盒面板（§7 末）同一套「居中浮层，`position:fixed;inset:0` 半透明背景 + 点击背景关闭」模式，视觉上（暗色 `--panel2` 背景、`box-shadow`、面板头部 `标题+关闭按钮`）保持一致。跟那两个面板的关键差别：那两个面板内容基本静态或表单驱动，这个面板内容依赖运行时数据（有没有模型、模型用了什么扩展），所以做成动态渲染——`renderSettingsPanel()` 在 `#settingsBtn` 点击时调用一次，跟 `renderLogPanel()`（§13）「打开时/数据变化时重新渲染」是同一套模式，不是像材质清理面板那样一次性写死的静态 DOM。
- **开放式卡片布局**：`#settingsBody` 里塞若干 `.info-card`（标题条 + 内容区），要加新卡片只需要在 `renderSettingsPanel()` 里再拼一段 `<div class="info-card">…</div>`，不用改整体结构、不用改 CSS。这次只实现了一张卡片。
- **空状态**：`raw`（模块级变量，`null` 直到模型加载完成，见「数据模型」小节）为空时，面板直接显示「尚未加载模型」提示文案，不报错、不留空白——`raw` 已经是全文件唯一的「模型是否已加载」权威判定（其它入口如材质清理面板的 `if (!anno) return` 也是同一个判定思路，这里对齐）。
- **兼容性卡片数据结构**（`EXT_COMPAT_DB`）：模块级常量对象，key 是扩展标识符字符串（如 `KHR_texture_transform`），value 是 `{ label, usageNote, support, extraNote }`——`support` 是 `{ threejs, babylonjs, modelviewer, sketchfab }` 四键对象，每个值是 `{ level, note }`（`level` ∈ `yes`/`no`/`partial`/`unknown`/`na`，`note` 是 hover title 里的补充说明）。数据完全照抄本文档第 12 节已经调研过的表格内容，**不是运行时探测，也没有联网现查**。收录的 5 个扩展：`KHR_texture_transform`（三个查看器里 three.js/Babylon.js 标 `yes`，model-viewer/Sketchfab 第 12 节没提到，如实标 `unknown`）、`EXT_mesh_gpu_instancing`（`support` 字段是 `null`——第 12 节对这个扩展的结论是「3AS 不会用到，不用查」，跟「查了但都不支持」是两回事，`null` 时卡片不画支持矩阵表格，只显示 `usageNote`/`extraNote` 说明为什么不用关心）、`KHR_materials_variants`（Babylon.js/model-viewer 标 `yes`，three.js 第 12 节原文这一行没提到、如实标 `unknown`，不是「three.js 不支持」）、`KHR_materials_specular`/`KHR_materials_emissive_strength`（第 12 节原文只说「广泛支持」没有逐查看器细分，四个查看器都标 `yes` 但 `note` 里注明来源是笼统结论）。**数据边界如实反映**：第 12 节调研压根没有对 Sketchfab 做过逐扩展调研，凡是遇到这种「矩阵没覆盖到」的组合，一律标 `unknown`（渲染成「未查到数据」），不替代调研本身做判断、不编造支持情况。
- **渲染逻辑**（`renderCompatCard()`/`renderExtRow()`）：读 `raw.extensionsUsed || []`；为空 → 「无相关扩展」；非空但没有一个在 `EXT_COMPAT_DB` 里 → 顶部加一行「未使用已知扩展兼容性数据覆盖的扩展」再列出这些扩展名；非空且部分/全部命中 → 逐个扩展渲染一行（`.ext-row`），命中的显示 label + 用途说明 + 四查看器支持徽章网格（`.support-badge` 按 `level` 着色：`yes` 用 `--ok` 绿、`no` 用 `--bad` 红、`partial`/其它用 `--accent`/`--ink-dim`）+ 补充说明；没命中的显示扩展名 + 「不在已调研范围内，暂无支持情况数据」兜底文案，不是简单跳过或报错。
- **验证**（`_dev/test-settings-panel.js`，Playwright，13 项断言全 PASS）：① 未加载模型点设置按钮，确认显示「尚未加载模型」且没有渲染任何 `.info-card`；② 加载真实样品 `chengdu-huagao-0801.glb`（无 `extensionsUsed`），确认显示「无相关扩展」；③ 手写合成 glTF JSON（`_dev/gen-ext-compat-gltf.js`，`extensionsUsed: ['KHR_texture_transform', 'EXT_totally_made_up_extension']`，一个已收录一个纯虚构未收录），确认渲染出 2 行扩展记录——已知扩展正确显示 label「贴图 UV 变换」+ 4 个查看器徽章（其中 three.js/Babylon.js 显示「支持」、Sketchfab 如实显示「未查到数据」而不是编造），未知扩展正确显示扩展名 + 兜底文案；④ 控制台全程 0 报错。截图：`_dev/shots/settings-00`（空状态）、`settings-01`（真实样品无扩展）、`settings-02`（合成文件已知+未知扩展混合）。

---

## 5. 材质清理菜单（对应第 3 点 a-d）

这四条直接对应上一版发现的真问题：3ds Max 的 V-Ray 材质导出后变成纯黑 `fallback Material`，这套工具就是用来批量清理这种材质的：

| 选项 | 行为 |
|---|---|
| **a. Fallback 材质自动重命名** | 把 `fallback Material` / `material_N` 这类占位名，按用途（比如引用它的节点名、或该材质在场景里第几次出现）自动生成一个能看懂的名字 |
| **b. 黑色材质→随机浅色** | 材质 `baseColorFactor` 是 `(0,0,0)` 时，替换成 RGB 三通道各自在 200-254 之间随机取值（整体偏白），让原本分不清的黑色材质在视口里能用肉眼区分开 |
| **c. 黑色材质→指定统一色** | 同样只对 `(0,0,0)` 材质生效，但不随机，统一换成一个指定色（默认 `200,200,200`） |
| **d. 缩放归一化** | 把节点 `scale` 烘焙进网格顶点数据（vertices × scale），写回后节点 `scale` 变成 `(1,1,1)`，但外观（世界空间位置/大小）不变——这是常见的「Apply Scale / Freeze Transform」操作 |

b/c 二选一（互斥），a/d 可以独立开关。这四个操作都要进操作日志（见第 10 节），因为它们会实际改写材质/几何数据。

**实现记录（2026-08-05，#4）**：

- **入口**：材质 Tab 画廊工具栏新增「🧹 清理」按钮，打开一个居中弹层面板（`#cleanupOverlay`/`#cleanupPanel`，静态 DOM，不在 `renderTab('mat')` 的动态 `innerHTML` 里，只需绑一次事件）。面板里 a 是勾选框，b/c 是同一组 radio（含「不处理黑色材质」默认项）+ c 旁边一个颜色选择器改默认色，d 是勾选框，底部一个「应用」按钮——**勾选框本身不触发任何写回**，只有点「应用」才真正批量执行，避免误触（Doc/TODO.md #4 需求原文明确要求）。
- **a. Fallback 占位名判定**（`isPlaceholderMatName`）：没有名字，或者名字匹配 `/^fallback[ _]?material$/i`、`/^material_\d+$/i` 这两条正则（容忍大小写、下划线/空格混排，V-Ray 导出常见 `"fallback Material"` 这种写法）。**重命名生成规则**（`generatePlaceholderRename`）：用 `tables.mat[i].usedByNodes` 反查引用该材质的节点名——单个引用者取「节点名 + 材质」，多个引用者取「第一个引用者 + 等N个部件」，零引用（孤立材质）保底用「未引用材质_索引」；同批操作内如果生成的名字撞车（比如两个材质被同一节点的不同 primitive 引用），追加 `#2`/`#3` 序号避重，不覆盖已经生成的名字。写回时 three.js 侧同步改 `Material.name`——**这一步是必须的，不是为了好看**：`GLTFExporter` 写导出材质名读的是 three.js 实例的 `.name` 属性，不是 `raw`，只改 `raw.materials[i].name` 的话导出的 GLB 材质名不会变。
- **b/c. 黑色判定**（`isBlackMaterial`）：只认「显式写了 `baseColorFactor` 且三通道都 < 0.05」——`baseColorFactor` 缺省时 glTF 规范默认白色 `[1,1,1,1]`，不算黑色，不会被误伤。**b/c 共用同一条颜色转换路径**（`new THREE.Color(hexString)`，sRGB 显示值→three.js 线性工作色彩空间），跟材质详情编辑区手动挑色（`#mdBaseColor` 颜色选择器）完全一致，避免随机浅色和指定色在色彩空间处理上出现不该有的色差；随机浅色（`randomLightHex`）三通道各自独立在 200-254（0-255 显示值域）取整数随机值。写回复用既有的 `setMatField(i,'baseColor',...)`，跟材质编辑器单字段编辑走同一条双写路径，不重复发明逻辑。
- **d. 缩放归一化**（`cleanupNormalizeScale`）：遍历 `raw.nodes[]`，对 `matrix`（`decompose()` 取缩放分量）或 `scale` 字段判定非单位缩放（阈值 1e-6）的节点：纯分组节点（`n.mesh === undefined`）没有自己的顶点数据可烘焙，这一轮明确跳过、在结果反馈里如实报告跳过数量，不假装处理了（要正确处理需要把缩放级联分配进子节点局部变换，是明显更大的一块工作，不在这次任务范围内）。带 mesh 的节点：`collectOwnMeshesForNode()` 沿用 `createInstance`（§6）已建立的 `isNodeLevelObject()` 判定，只收集「自己拥有」的 Mesh（含多 primitive 节点在 three.js 里内部包装出的 Group 底下的全部 primitive，不误吞真实子节点的几何体），逐个网格：`geometry.clone()` 出独立副本（**必须 clone，不是性能优化选项**——实测踩到一个隐蔽的坑：§6.1 材质高亮基础设施的叠加/描边层是「共享命中网格的 geometry」，如果被归一化的节点网格恰好是当前高亮命中的材质，原地改顶点数据会让仍在场景里的高亮叠加层一起被改变，而高亮层自己的 `matrixWorld` 是选中那一刻的静态快照不会重算，两者一乘表现为高亮层「双重缩放」缩没了——改成一律 clone 后这类问题从根上不存在）→ 顶点坐标按 `pos*scale` 分量相乘 → 法线按缩放矩阵的逆转置（对角阵的逆转置就是逐分量取倒数）变换再归一化，避免非等比缩放下法线歪掉、光照不对 → `computeBoundingBox`/`computeBoundingSphere` 重算。写回：`matrix` 型节点重新 `compose(T,Q,单位S)`，`T/R/S` 型节点直接把 `scale` 字段写 `[1,1,1]`，three.js `Object3D.scale` 同步置 1；`Object3D` 本身不 clone，只有它挂的 geometry 被换成新克隆体。
- **数学依据**：`Matrix4.compose(T,Q,S) = T·R·S`，局部顶点 `v` 的世界变换是 `T·R·(S·v)`；把 `v` 换成 `v'=S·v`（分量各自相乘）、矩阵里的 `S` 换成单位阵，`T·R·I·(S·v) = T·R·S·v`，结果和原来完全一致，不管节点当时有没有旋转、转了多少度都成立——因为 `S` 是在旋转之前、在顶点自己的局部坐标系里生效的分量缩放。
- **应用入口**（`runMaterialCleanup`）：按当前勾选组合依次跑选中的操作，跑完拼一条量化结果文本（如「已重命名 17 个材质，16 个黑色材质已改色（随机浅色）」）写状态栏 + 记一条 `info` 级操作日志（第 13 节日志系统），再按需刷新材质表/节点表（`rebuildNodeTable()` 只有 d 跑过才调用，避免不必要的重算打断用户当前选中状态）。
- **验证**：真实样品 `chengdu-huagao-0801.glb`（17 材质，16 个纯黑 fallback + 1 个非黑的浅绿材质 #16，14 个节点 scale 非 `(1,1,1)`）。① a+b 组合：全部材质改名且互不重复、16 个黑色材质变浅色（三通道线性值均 > 0.5）且互不相同（验证真随机）、非黑材质 #16 颜色未被误改；three.js `Material` 实例名字/颜色跟 `raw` 逐值同步；「另存为 GLB」解析 JSON chunk 确认导出材质数精确 17、材质名单跟画廊显示一致、原黑色材质导出后都不是纯黑。② 单独测 c（改默认色为 `#3388ff`）：名字未被动（没勾 a）、全部黑色材质变成同一颜色且精确匹配指定色的 sRGB→线性转换值、非黑材质未被误改。③ 单独测 d：选一个 scale `[0.001,0.001,0.001]` 的网格节点，归一化前后世界空间包围盒（尺寸、中心）逐值比对，差异量级在 1e-8~1e-9（浮点误差范围内，视觉上完全一致）；节点自身局部 scale 精确变成 `(1,1,1)`；节点表世界空间缩放列因为父级壳节点自身缩放未处理而不会显示 `(1,1,1)`，这是预期行为（分组节点跳过范围之内），已在测试注释里如实记录不是 bug；「另存为 GLB」解析确认目标节点导出后局部 scale 精确等于 `(1,1,1)`。④ 控制台全程 0 报错。测试脚本：`_dev/test-cleanup-menu.js`（Playwright，可重跑复查），截图 `_dev/shots/cleanup-00` 到 `cleanup-05`。

### 5.1 追加：单色贴图检测 → 转纯色（体积优化，用户 2026-08-05 提出，先记录未排期）

**背景**：用户实测反馈——贴图表里有些贴图「无法解码」、尺寸读不出来（这是已知限制，`README.md` FAQ 已经写过：KTX2 等格式没接 `basis_universal.js` polyfill 就是会这样，不是新 bug）；同时观察到不少能正常解码的贴图其实**整张就是纯色/近似纯色**——用一整张图片存一个颜色，纯属浪费体积。

**提议**：材质清理菜单加一个新选项（暂定 e，具体排序等实现时定）——**检测贴图是否为单色**（采样图片像素，比如抽样网格点或者直接读小尺寸缩略图算方差，方差低于阈值就判定为"近似单色"），是的话：
- 提示用户"这张贴图是纯色（约 #xxxxxx），要不要转成材质纯色，去掉贴图引用"
- 确认后：把这个颜色写进对应材质槽的 factor 字段（比如 baseColor 槽转成 `baseColorFactor`），移除贴图引用（`raw.materials[i]` 对应槽位清空 + three.js Material 对应 `xxxMap` 属性设 `null`），如果这张贴图没有被其他材质/槽位引用了，`raw.images`/`raw.textures` 里对应条目也可以一并清理（减小最终导出体积）
- 只对**能正常解码**的贴图做这个检测——解码失败的贴图（KTX2 等）没法读像素，检测不出来，这部分维持现状不处理

**跟 #16（贴图上传/替换/删除）的关系**：#16 已经做完材质面板里"添加/替换/删除贴图、显示贴图缩略图"这套基础设施（2026-08-05），这条单色检测是在这个基础设施之上再加一步"自动分析+建议移除"，不是重新做一遍添加/替换/删除。

---

## 6. 模型块升级：层级/组/Instance（对应第 4、6 点）

- 节点表从表格升级为**树状展示**，展开纯分组节点（第 1 节已确认这些节点原生就存在，只是之前被过滤掉）
- 层级、组、Instance 都可以挂注释（复用现有 `nodeAnno` 机制，不用为组/Instance 单独设计注释结构）
- **创建 Instance**：选中一个已有节点 → 「创建 Instance」→ 在场景任意位置新建一个节点，引用同一个 `mesh` 索引
  - **已确认方案 ii（保留颗粒度）**：如果选中的是一个「组」（带子节点的子树），Instance 是一棵结构相同的子树（node 数量跟原树一样），树里每个叶子节点各自引用原树对应叶子的 mesh 索引——几何体数据不重复（已经达到减小体积的目的），子节点粒度也保留（每个子节点仍可单独编辑材质/显隐），代价是 node 数量会变多（node 本身很轻，几乎不占体积，这个代价可以接受）
- **导出选中物体**（第 4 点）：从选中节点开始遍历子树，收集用到的所有 `mesh`/`material`/`texture`/`accessor`/`bufferView`，只把这些依赖打进新 GLB，其余无关数据裁掉

**追加（2026-08-05 用户反馈，排进 #20，2026-08-06 已实现）**：这份样品（以及 3ds Max 这类导出流程）几乎每个网格叶子节点都被单独包了一层「只有 1 个孩子」的组节点（见第 1 节的结构说明）。树状表格现在是「组行 + 子行」各占一整行，对这种大量单子节点组的场景非常费垂直空间。UI 层面要把「只有唯一一个子节点的组」合并显示成一行（不单独占一行展示这个中间层），glTF 底层数据结构不用变，纯粹是显示层的折叠优化。实现记录见下方 §6.2（连带完成了 huashu-design 评审提的「模型块表上下分栏」整体重构，两者是同一次改动）。

**实现记录（2026-08-05，#6）**：

- **入口**：节点树（模型块 Tab）每一行新增两个操作按钮——「⧉ Instance」「⇩ 导出」，点击即对该行节点生效，没有另外做一套「选中状态」UI（跟画廊点击选材质、chip 点击跳转节点比，这次直接绑定到具体行更简单，效果等价）。
- **创建 Instance 的双写实现**，跟材质编辑器（§7）同一套模式：
  1. **raw.nodes[] 写入**：递归函数沿 `raw.nodes[ni].children` 走，逐层新建节点 JSON——叶子节点 `mesh` 原样引用原索引，不新建 mesh/geometry；分组节点递归建同构子树。**子树里每一个新建节点（不只是根）都单独过一次 `_instanceN` 避重名**，不是只处理顶层——`nodeAnno` 按 `node.name` 存注释，子树内部节点如果原样保留原名一样会撞名（CHANGELOG v0.1.1 已知限制），这一点在做之前就想到了，不是事后修的。
  2. **three.js 场景图写入**：不能直接 `origObject3D.clone(true)` 整体深拷贝——多 primitive 网格节点（比如 `L6`，4 个 primitive）在 three.js 里会多一层 `Group` 包装真正的 primitive 子 `Mesh`，这层包装*不*对应任何 raw 子节点；如果节点自身还有真实的 raw `children`（更少见但合法），`clone(true)` 会把「内部包装层」和「真实子节点」一起递归下去，没法跟 raw 端的子树结构对齐。解决办法是查 `gltf.parser.associations` 的 `info.nodes` 字段区分：有 `.nodes` 值的子对象对应真实 raw 子节点（交给外层递归单独重建），没有的子对象是 loader 内部包装（`shallowMeshCloneObj3D()` 原样带走）。`Object3D.clone(false)`（浅拷贝，不递归子节点）对 `THREE.Mesh` 一样保留 `geometry`/`material` 引用不复制（`Mesh.copy()` 只赋引用），所以从头到尾没有 `BufferGeometry`/`Material` 被复制，只新建了 `Object3D`/`Mesh`/`Group` 壳子。
  3. **接入场景图**：查 `tables.node` 里已经算好的 `pni`（父节点索引）——有父节点就 `raw.nodes[parentNi].children.push(newIndex)` + three.js 父对象 `.add()`；没有父节点（顶层节点）就 `raw.scenes[i].nodes.push(newIndex)` + `model.add()`（`model` = `gltf.scene`，跟顶层节点列表结构对应）。
  4. **新建对象要补登记进 `gltf.parser.associations`**（`{ nodes: newIndex }`），不然「对 Instance 再创建 Instance」这种连续操作时，上面第 2 步的 `isNodeLevelObject()` 判断会失真（新建节点不在 associations 里，会被误判成「内部包装」）。
  5. `tables.node` 的重建抽成独立的 `rebuildNodeTable()` 函数（原来是 `buildTables()` 里内联的一段），只依赖 `raw`，不依赖 `gltf`/`buf`/`box`，创建 Instance 后单独调用它就能刷新节点表，不用把整个 `buildTables()`（含材质/贴图表、`matInstances` 反查）都跑一遍，也就不会打断用户当前的材质选中状态。
  6. **摆放位置目前是固定偏移**：新根节点的局部 `translation`（或 `matrix` 分解后的平移分量）加 X+2 米，仅仅是为了在视口里能用肉眼区分出这是两个独立的物体，**没有任何可视化摆放交互**（拖拽/gizmo 之类），等 §7 提到的节点移动/旋转编辑功能做出来之后再考虑把这里接上去。
- **导出选中物体**：`GLTFExporter.parse(选中节点的 Object3D, ..., { binary: true })`——**实测证实第 4 点最初设想的"手动遍历子树收集依赖"这一步不需要写**，`GLTFExporter` 自己只序列化传入根节点能到达的 materials/meshes/accessors/images，用真实样品验证：整档 17 材质，导出单个叶子节点（引用 1 个材质）后解析 JSON chunk `materials.length === 1`；导出一个挂 4-primitive 网格的分组节点后 `materials.length === 4`（精确等于该网格 4 个 primitive 各自用的材质数，没有多也没有少）。因此**没有再写任何手动依赖收集代码**，导出按钮直接把 `model`（另存为 GLB）换成 `nodeObjects.get(ni)`（导出选中）即可。附带发现一个不影响正确性、但要如实说明的现象：GLTFExporter 序列化多 primitive 网格节点时，会把每个 primitive 拆成一份独立的 glTF `mesh`（一份原始文件里 4 个 primitive 共享的 `mesh` 定义，导出后变成 4 个各 1 个 primitive 的 `mesh` 条目）——这是 three.js 把「一个多 primitive glTF mesh」在场景图里表示成「一个 Group 包 4 个独立 Mesh」这个已知行为的自然结果，`materials`/`accessors` 依赖范围依然精确，不存在数据泄漏或冗余几何/材质。
- **验证**：真实样品 `chengdu-huagao-0801.glb`（28 节点，17 材质，0 贴图）。叶子节点 `PArc864`（`mesh: 2`）创建 Instance 后新节点 `PArc864_instance1` 的 `mesh` 索引跟原节点完全一致；给新 Instance 挂别名后，原节点的别名注释没被覆盖（Playwright 直接读两条独立的 `nodeAnno` 记录确认）。分组节点 `node_3`（无名字，回退显示名 `node_3`，子节点是 4-primitive 网格 `L6`）创建 Instance 后：新增节点数（2）精确等于原子树节点总数（2），新子树 DFS 出的 `mesh` 索引序列 `[null, 1]` 跟原子树完全一致。「另存为 GLB」下载后解析 JSON chunk 确认两个新节点都在导出结果的 `nodes[]` 里；整档文件从 1145028 字节变成 999200 字节（**变小了，没有变大**——加了 3 个新 node 记录但没有一份新的顶点/索引数据，证明几何数据确实没被复制，体积变化是 GLTFExporter 重新打包时的正常差异）。导出选中：见上一条的 materials 数量精确匹配。视口截图确认两次创建后场景里多出偏移后的几何体（`L6` 是一段可读的文字造型 mesh，Instance 副本清晰可见地出现在原模型包围盒之外偏移的位置）。控制台全程 0 报错。测试脚本：`_dev/test-instance-export.js`（Playwright，可重跑复查）。

### 6.1 选中高亮基础设施（对应 TODO #15，2026-08-05 完成）

节点树/材质画廊现在点击选中不只是「改 UI 状态」，还要在视口里给出可见反馈——这是后续 #9（包围盒 gizmo）、#17（移动/旋转编辑）都要用到的基础设施，这里先把「选中态怎么表示、怎么在视口画出来、怎么跟导出隔离」这套机制立好。

**两套独立的高亮，职责不同、视觉不同、状态互不清除**：

| | 触发方式 | 视觉 | 实现 |
|---|---|---|---|
| **节点选中** | 模型块树点行（名字格或整行，折叠三角/操作按钮/输入框除外）；材质详情「用于节点」chip 点击 | 青色 `#39d6ff` 包围框（`THREE.Box3Helper`），跟场景整体金色边框（`--accent` 系）区分；树行同步加背景高亮 + 左侧色条 | `Box3.setFromObject(obj)` 求包围盒——分组节点这样求出来天然是整个子树的合并包围盒（遍历全部后代几何体），不用额外写子树合并逻辑 |
| **材质选中** | 材质画廊点击色块卡片 | 命中网格叠加一层品红 `#ff3fd6` 发光色块（`MeshBasicMaterial` + `AdditiveBlending`），不用包围框——故意跟节点选中视觉区分开，避免用户分不清"这是节点选中还是材质选中" | `matInstances[i]` 反查该材质的全部 `THREE.Material` 实例 → `model.traverse` 找出 `mesh.material` 命中这些实例的全部网格（一个材质可能被多个网格引用，全部高亮）→ 每个命中网格生成一个共享其 `geometry`、复制其 `matrixWorld` 的叠加 `Mesh` |

**状态管理决策**：两套高亮各自独立（`selectedNodeIdx`/`nodeSelectHelper` 一组，`matHighlightGroup` 一组），互不清除——先选材质（网格高亮）再选节点（包围框），两种高亮同时挂在视口里，这是刻意的设计取舍（用户反馈原话：「材质高亮和节点选中是两个独立状态，互不清除，除非用户主动切换同类型的选中」）。同类型内部切换才互斥：选中另一个节点/再点同一行，旧的节点框才会先消失、新的才出现，不会叠加残留；材质同理。

**导出安全性**：不用"改完再清理"这种脆弱模式。`Box3Helper` 和材质高亮的叠加 `Mesh` 全部只 `scene.add()`，**不挂进 `model` 子树**——`GLTFExporter.parse(model, ...)`（另存为 GLB）和 `GLTFExporter.parse(nodeObjects.get(ni), ...)`（导出选中节点）都只序列化传入根节点能到达的对象，高亮对象天然在这两条导出路径之外，不需要额外的"导出前清理/导出后恢复"逻辑。材质高亮也**不修改 `material.emissive`/`.color` 等任何持久字段**（叠加层是完全独立的 `Mesh` + 独立 `MeshBasicMaterial`），所以连"记原始值再还原"这一步都省了——用真实样品验证过，选中材质高亮开着的状态下导出 GLB，解析 JSON chunk 确认材质数量、`emissiveFactor`/`baseColorFactor` 都跟高亮前完全一致，导出材质名单里没有混入任何高亮相关的材质。

**清理时机**：新模型加载（`buildTables()` 开头调用 `resetHighlights()`）会清空两套高亮状态——避免残留 `nodeSelectHelper`/`matHighlightGroup` 指向已经不在场景里的旧 `model` 对象（引用悬空但不报错，纯粹是视觉上会挂着一个指向虚空的框）。

**验证**：真实样品 `chengdu-huagao-0801.glb`。点叶子节点 `PArc864` 出现包围框，切换到另一叶子节点旧框消失新框出现（场景里 `__nodeSelectHelper` 数量全程为 1，不残留），再点同一行取消选中；选中分组节点 `node_1`（子节点是文字 mesh「Prof. Jimmy Choo」）确认包围框框住的是子树合并范围。材质 #1（探测得知被 `L6` + `Yeang Keat OBE` 两个部件共用，样品全部材质同名 `fallback Material` 不能按名字找，改成动态查「被 ≥2 节点引用」的材质定位）选中后视口两个部件同时出现品红高亮，命中网格数 = 2；切换到另一材质后场景里 `__matHighlight` 组数量为 1（旧高亮已清干净，不残留）。点材质详情「用于节点」chip（`L6`）：确认跳转到模型块 Tab、目标行闪烁、**并且**节点包围框也正确出现在 `L6` 周围，同时材质高亮仍然挂着（两套高亮共存，符合设计）。导出 GLB 解析 JSON chunk：`materials.length` 跟高亮前一致（17），高亮材质对应的 `emissiveFactor`/`baseColorFactor` 跟高亮前的快照逐字节相同，材质名单里没有品红高亮材质混入。控制台全程 0 报错。测试脚本：`_dev/test-highlight.js`（Playwright，可重跑复查）。调试钩子：`window.__debugScene`/`window.__debugHighlightState()`（暴露 `selectedNodeIdx`/`selectedMat`/两套高亮是否存在及命中数量，供测试脚本直接读内部状态，不只是肉眼看截图）。

**后续修复（TODO #24，2026-08-05）**：huashu-design 评审V2 指出 `#39d6ff`/`#ff3fd6` 是硬编码在 CSS 规则 (`tr.node-sel`) 和 JS 常量 (`NODE_SEL_COLOR`/`MAT_HL_COLOR`) 两处的重复声明，没并入 `:root` 主题变量体系。改成 JS 数字常量（three.js Color 需要数字，天然是唯一权威来源）在启动时通过 `document.documentElement.style.setProperty()` 写入 `--select`/`--mat-hl` 这两个 CSS 自定义属性，`tr.node-sel` 规则改用 `var(--select)`，`:root` 里保留的 `#39d6ff`/`#ff3fd6` 只是占位值（会被 JS 覆盖，注释里已标注不要在这改）。验证：改完重跑节点选中截图，青色包围框/行高亮视觉效果跟改之前完全一致，没有回归。

**后续改进（TODO #25，2026-08-05）**：这轮做了三件事——材质画廊空白处取消选择、高亮色统一改黄色系、材质高亮撞色兜底。

1. **材质画廊点空白处取消选择**：先确认现状——原来材质卡片的 `onclick` 只有「选中」逻辑（`selectedMat = +card.dataset.matcard`），**没有「再点一次取消选中」这种 toggle 行为**（跟节点树行的 `toggleNodeSelection()` 不一样），这次没有额外补这个 toggle（不在这轮任务范围内，只是如实记录现状）。新加的是 `.mat-gallery` 容器本身的 `onclick`：事件冒泡到容器时用 `e.target.closest('.mat-card')` 判断点的是不是卡片本体——是卡片直接 `return`（卡片自己的 onclick 已经处理过，不重复响应），不是卡片（网格里卡片间/下方的留白，或容器本身）才 `selectedMat = null` + `applyMatHighlight(null)` + `renderTab('mat')`。`.mat-detail` 详情编辑区在 DOM 结构上是 `.mat-gallery` 的**兄弟节点**、不在它内部，所以点详情区里任意输入框/按钮都不会冒泡到这个容器，不会被误判成"点空白处"清空正在编辑的材质。

2. **高亮色改统一黄色系**：`NODE_SEL_COLOR`/`MAT_HL_COLOR` 两个常量都改成 `0xffe600`（同一个值，不再是青色 `#39d6ff` vs 品红 `#ff3fd6` 两种色相）——用户明确要求「两者都用黄色，靠线框包围盒 vs 网格表面叠加发光这两种不同呈现方式区分，色相相同也分得清」。选 `#ffe600` 而不是任务里另一个候选 `#ffcc00`：算了两者跟 `--accent`（`#c8a35f`，色相约 39°）的色相差——`#ffe600` 色相约 58°（差 19°），`#ffcc00` 色相约 48°（只差 9°），`#ffe600` 在色相维度上跟场景整体金色边框拉得更开，同时亮度/饱和度都远高于 `--accent`（偏暗偏土黄），暗色背景 `--bg:#141414` 下辨识度更好。CSS `:root` 里 `--select`/`--mat-hl` 的占位值同步改成 `#ffe600`（真正生效值仍由 JS 在启动时写回，占位值只是给读代码的人看，注释已更新说明）；另外 `tr.node-sel` 规则里还有一处直接硬编码的 `rgba(57,214,255,.12)`（行背景色，没走 CSS 变量），这次一并改成 `rgba(255,230,0,.12)`。

3. **材质高亮撞色兜底**（这轮最容易做错的一条）：如果被高亮材质本身就是黄色，`AdditiveBlending` 叠加同色只会让它「看起来稍微亮一点」，不够醒目。采用**组合方案**（描边 + 呼吸动画），两个都做，理由见下：
   - **轮廓描边**（主力方案）：经典的「法线外扩 + 只画背面（`THREE.BackSide`）」轮廓技巧——每个命中网格额外生成一个 `THREE.ShaderMaterial` 描边 Mesh，顶点着色器沿法线方向外推 `boundingSphere.radius * 0.025`（按各网格大小自适应描边粗细，不是固定世界空间宽度），只渲染背面，配合原始正面网格挡住内部，在剪影边缘露出一圈**纯白 `0xffffff`**、不透明、不参与任何颜色混合的描边线。选纯白而不是「接近纯白但仍是暖色调」，是因为要保证跟任何底色（包括恰好也是黄色的材质）都存在可判定的色相/色度差，不依赖「叠加色相跟材质色相不同」这个假设——纯白色的 R=G=B，只要材质不是恰好也是中性灰，肉眼和像素级对比都能分辨。这是主力方案，因为它是**唯一一个完全不依赖颜色对比**的手段（下面的呼吸动画依赖时间/运动感知，仍然是同一种「盯着看」的视觉通道）。
   - **呼吸透明度动画**（辅助方案）：`matHighlightMaterial.opacity` 在 `anim()` 渲染循环里按 `0.42 + 0.36 * (0.5 + 0.5*sin(t*3.4))` 周期起伏（约 0.5 圈/秒），不是固定值。用「运动」而不是纯静态颜色去吸引注意——运动线索不受颜色是否撞色影响，就算眼睛第一时间没抓到色差，呼吸的闪烁感也会被余光注意到。选它而不是"叠加改成接近纯白"这个任务里提到的第三个候选方案：因为叠加层改白会丢失"材质高亮=黄色系"这个已经跟节点选中统一好的视觉语言（相当于又引入第三种颜色），所以没采用，改用不影响颜色语义的运动线索。
   - **实现细节**：`getMatOutlineMaterial(width)` 每个命中网格各建一个独立 `ShaderMaterial` 实例（描边粗细跟各自网格大小相关，不能像 `matHighlightMaterial` 那样单例复用），`clearMatHighlight()` 里逐个 `dispose()`（跳过共享的 `matHighlightMaterial`）。`matHighlightMaterial` 声明的位置从「选中高亮基础设施」那一整块里提前挪到了脚本更靠前的位置（紧跟 `let gridHelper = null, frameHelper = null, model = null;` 之后）——因为 `anim()` 是立即执行的 IIFE，第一帧在脚本执行到那一行时就同步跑了，如果 `matHighlightMaterial` 还是声明在它后面的 `let`，会命中暂时性死区（TDZ，`Cannot access 'matHighlightMaterial' before initialization`），这是实现过程中踩到、当场修掉的一个坑，其余高亮状态（`matHighlightGroup`/`selectedNodeIdx`/`nodeSelectHelper`）不在 `anim()` 里用到，不需要一起挪。调试钩子 `window.__debugHighlightState()` 新增 `matHighlightRimCount` 区分「命中网格数」（`matHighlightMeshCount`，只数发光叠加层）跟「描边层数」，避免这轮新增的描边层把原有的「命中 N 个网格」这类校验污染成 2N；另外因为描边材质是每帧不变但每个实例独立创建，模块作用域的 `NODE_SEL_COLOR`/`MAT_HL_COLOR`/`OUTLINE_COLOR` 也补了 `window.__debugColors` 暴露（原来只有 CSS 变量能从页面外读到，JS 常量本身在 ES module 作用域里外部读不到）。
   - **验证（合成撞色场景）**：手写合成了一份自包含立方体 glTF JSON（`_dev/gen-yellow-clash-gltf.js` → `_dev/test-yellow-clash.gltf.json`，24 顶点 flat-shading 单位立方体 + base64 内嵌 buffer，不依赖二进制 GLB/canvas/npm 包，沿用 `gen-checker-gltf.js` 同样的自包含 glTF 手法），两个材质：材质0「撞色测试」`baseColorFactor` 用 sRGB `#ffdd00`（转线性空间后 `[1, 0.723, 0, 1]`）——跟高亮黄 `#ffe600` 肉眼几乎分不出来；材质1「控制组」深蓝灰，跟高亮色反差很大做对照。用 Playwright（`_dev/test-yellow-highlight.js`）：
     - 选中撞色材质后，用手写的极简 PNG 解码器（`_dev/png-diff-utils.js`，纯 Node zlib，逐 scanline 反 PNG filter，不依赖任何 npm 包）解出高亮前后两张视口截图的像素数据算差异：**6.32% 的像素发生明显变化**（单通道差值 > 12 才计入，阈值故意设得不算低），平均绝对色差 1.3-1.5/255（含大片未变化的背景，稀释了均值，但变化像素占比证明高亮确实可辨，不是"看起来没变"）
     - 逐像素采样确认背景（`~(20,20,20)`，跟场景 `0x141414` 背景色吻合）到描边到材质表面这段过渡：`(208,255,255) → (255,255,255)`（纯白，跟背景/材质色都有明确区分）`→ (198,187,61)`（材质本身叠加高亮后的颜色，蓝通道被材质的低蓝值压低，明显跟纯白描边不同）——证明描边层渲染出的确实是独立于材质色相的纯白，不是"看起来是更亮的黄"
     - **对照实验**：撞色材质（`changedPixelRatio` 6.32%）和控制组深蓝材质（同样 6.32%）的高亮可见度**几乎完全一致**——证明这套方案（描边+呼吸）让高亮的可见度不随材质底色变化而衰减，撞色场景不比正常场景更难看出
     - 呼吸动画佐证：同一选中状态下间隔 500ms 两张截图仍有 6.5% 像素变化（透明度插值导致颜色微调），证明动画确实在跑，不是静态帧
     - 真实样品 `chengdu-huagao-0801.glb` 材质 #1（`L6` + `Yeang Keat OBE` 共用，baseColor 纯黑 `#000000`）选中后裁剪截图 + 像素采样：找到纯白 `(255,255,255)` 像素（尽管该样品文字网格笔画很细，描边本身很窄，只占极少数像素，但确实存在，且用 `blue通道>150` 的像素计数排除了跟黄色叠加层混淆的可能）
     - 画廊空白处取消选择：真实样品（17 张卡片）和合成场景（2 张卡片）都测了，点 `.mat-gallery` 容器内没有卡片覆盖的区域，`selectedMat` 归 `null`、`hasMatHighlight` 变 `false`、`.mat-detail` 从 DOM 里消失，且不影响同时存在的节点选中（`hasNodeHelper` 仍为 `true`，两套高亮独立性没被破坏）
     - 导出 GLB 解析 JSON chunk：撞色材质导出后 `baseColorFactor` 跟高亮前逐字节相同，导出材质数精确等于原始 2 个、名单精确匹配，没有混入高亮/描边过程中新建的材质
     - 三者共存截图（节点黄色包围框 + 材质黄色叠加/白描边 + 场景整体金色 `--accent` 边框）：`_dev/shots/25-02-node-and-mat-coexist-yellow.png`，人工确认三者虽然同处黄色系但线框/表面叠加/边框三种不同呈现方式清晰可辨
     - 控制台全程 0 报错；顺带重跑了之前几轮的回归测试（`test-highlight.js`/`test-mat-editor.js`/`test-uv-editor.js`/`test-instance-export.js`）确认这轮改动（尤其是 `matHighlightMaterial` 声明位置调整）没有引入回归，全部 PASS
   - 测试脚本：`_dev/test-yellow-highlight.js`（主测试）+ `_dev/gen-yellow-clash-gltf.js`（合成撞色 glTF 生成器）+ `_dev/png-diff-utils.js`（PNG 解码+像素差异工具，可被后续任务复用）。

**复查（2026-08-08，Round 2/3 UI 重排 + 多模型架构批次 #31-41 全部完成后）**：这两条功能（材质画廊点空白处取消选择、材质高亮撞色兜底）是用户最早提过的要求，#31-41 这一大批任务做完后重跑 `_dev/test-yellow-highlight.js` 复查，**应用本身的这两个功能都还正常工作**，但测试脚本本身在两处因为后续任务的合理改动而过期，已经修好并确认全量 PASS（`ALL PASS`，控制台 0 报错）：
- **`clickGalleryBlank()` 选点逻辑漏判视口边界**：#32（2026-08-07）把材质面板改成「详情在上/画廊在下」之后，画廊被往下推，`.mat-gallery` 的候选空白点（原逻辑取右下角/下边中点）在常见桌面视口高度下经常落到可视区域以外——`document.elementFromPoint()` 在视口外的坐标上返回 `null`，原判断 `!!(el && el.closest('.mat-card'))` 把 `el===null` 误判成"不是卡片＝空白可点"，实际点下去落空，`selectedMat` 自然不会被清空。**这不是应用功能坏了**，是测试脚本的候选点算法没考虑"画廊本身可能比视口还高，需要滚动才能完全看见"这件事——补了视口边界检查 + 滚动到底部兜底两层修复。
- **`.mat-card` 点击顺带触发 #26 大图预览弹窗**：#26（2026-08-06）之后，点材质色块（`.swatch`）在选中材质的同时会弹出全屏大图预览浮层（`#texPreviewOverlay`），这个测试脚本写于 #26 之前一直没跟着更新，选材质→立刻点空白处这个操作序列，第二次点击其实被浮层挡住了（浮层 `position:fixed;inset:0`，没关就会拦截后续所有点击）。**这也不是应用功能坏了**，是真实用户在这个场景下本来就需要先关掉预览浮层才能继续操作面板其它部分——这是 #26 的既定设计（点色块=选中+预览一起触发，为的是关闭预览后材质详情区已经跳好，不用再点一次），符合弹窗类交互的常规预期。补了 `closeTexPreviewIfOpen()` 在每次"点卡片选中→之后还要点面板别处"的地方先关掉浮层，顺带也把脚本里两处引用 #21（2026-08-06）已经废弃的旧按钮 ID `#exportGlbBtn` 的地方改成现在的下拉菜单入口 `#saveGlbMenuBtn`→`#saveGlbLocalBtn`（这类"旧选择器"问题在这批任务其它测试脚本的完成报告里反复出现过，之前几轮都选择"不顺手修，不在任务范围内"如实记录带过；这次因为要验证的正是这条端到端流程本身，不修就没法把测试真正跑通，所以处理了）。
- 另外 `.mat-detail` 的"是否存在"判断也顺手对齐了 #32 引入的空状态占位设计——未选中材质时不再是 DOM 里完全没有 `.mat-detail`，而是固定渲染一个 `.mat-detail.mat-detail-empty` 占位（带 ⓘ 说明入口，见 §7「空状态处理」段），断言改成检查"已选中材质的详情区"（`.mat-detail:not(.mat-detail-empty)`）数量为 0，而不是 `.mat-detail` 整体数量为 0。
- 复查结论：**材质画廊点空白处取消选择、材质高亮撞色兜底（白描边+呼吸动画）这两条功能，在 #31-41 全部完成后依然按原设计正确工作**，问题完全出在测试脚本本身没跟着后续几轮任务的合理改动同步更新，不是功能退化。测试脚本：`_dev/test-yellow-highlight.js`（已修复，可重跑复查）。

### 6.2 模型块表上下分栏 + 单子节点组合并显示（对应 TODO #20，2026-08-06 完成）

**背景**：两个来源的需求汇成一次改动——① huashu-design 评审 `Doc/2026-08-05-ui-review-panel-density.html` §02「核心列常驻+次级信息展开」，模型块树当时跟材质表一样有「一行塞太多列」的密度问题；② §6 末尾「追加」提到的单子节点组合并折叠需求。两者放一起做是因为合并显示天然需要一个「点开看这一行到底代表哪条链」的地方，而这正好就是分栏后新增的详情区。

**上方：选中节点详情区**（`renderNodeDetail()`）：
- 没有选中节点（`selectedNodeIdx === null`）时渲染 `.node-detail-empty` 提示「点一行查看详情」；`selectedNodeIdx` 指向的节点在 `tables.node` 里已经找不到（结构变化后的边界情况，比如所在子树刚被别的操作动过）时渲染「找不到该节点」提示——两种情况都不会让面板整体崩掉或留空白，也不会误渲染出半截详情。
- 选中态展示：标题（节点名 + `#ni`）、属于（父节点名）、顶点/三角、通道、材质（色块+编号，复用材质画廊同一套色块渲染）、移动 T / 旋转 R°（世界空间，并排两列）、缩放 S、相对基点坐标（只读，fallback 到场景默认基点）；如果这一行是合并产物，标题下方多一条 `.node-merge-note` 说明条，见下方「合并行注释挂载点」。
- 四个操作入口从原来每行末尾的操作列整体挪进这里：⧉ Instance / ⇩ 导出 / ⬚ 包围盒 / ✥ 移动/旋转，行为跟挪之前完全一致（同一批处理函数 `createInstance`/`exportSelectedNode`/`openBBoxPanel`/`openTransformPanel`，只是触发按钮的位置从行内换成详情区，`data-detail-*` 系列属性绑定），已生成包围盒的节点按钮同样保留 `.has-bbox` 青色标记。

**下方：精简列表**：表头从原来的 15 列（名称/属于/顶点三角/通道/材质/移动T/旋转R/缩放S/显示/允许/备注名称/备注/关联基点/相对基点坐标/操作）精简到 7 列——**名称**（树状缩进+折叠三角+「组」标签+「⊂合并」标签）、**材质**、**显示**、**允许**、**备注名称**、**备注**、**关联基点**。去掉的「属于」「顶点/三角」「通道」「移动T/旋转R°/缩放S」「相对基点坐标」这几项只挪到上方详情区展示，数据本身没有丢，字段来源、计算方式都没变。

**单子节点组合并显示**（`walk()`，模型块 Tab 的 DFS 建行逻辑）：纯包装组（`hasMesh===false` 且 `children.length===1`）不单独 push 一行，直接递归跳到它唯一的子节点继续走、`depth` 不增加（视觉上「消失」），直到遇到真正有内容的节点（网格叶子，或者 0 个/≥2 个子节点的组）才落地成一行——这一行既代表这个终点节点本身，也代表它头顶那整条被跳过的包装组链（`node_3 → L6` 这种 2 层链、也支持任意长度的连续链，比如 3 层 `OuterWrap → InnerWrap → DeepLeaf`）。多子节点组（`children.length >= 2`）维持原展开方式不变，组和它的每个子节点各自占一行——这是 §6/§6.1 已有的树状展示行为，这次没有改动。

**合并行注释挂载点判断逻辑**（`resolveNodeRowTarget()`，本次实现的核心难点）：合并行的「显示/允许/备注名称/备注/关联基点」这几项注释是 `nodeAnno(name)` 按节点名字存的同一条记录，一行代表一整条链之后，写回目标该是链顶部的包装组还是链末端的终点节点？规则：
- **默认挂终点节点**——它是这一行「实际代表」的东西（通常是真正的网格），大多数场景下最符合直觉。
- **例外**：如果链上任意一层包装组已经有非默认注释内容（`nodeAnnoHasOverride()`：`alias`/`note` 非空，或 `visible`/`allowEdit` 被改成非默认值，或 `basepointRef` 有值），说明用户之前特意在这个组的层级做过标注，改成挂载到「最外层、已经有注释」的那个组，尊重已有的标注、不会因为这次改版把已有标注挪去别的节点，也不会让用户明明在组上标注过、这次编辑却悄悄写去了叶子（两条记录分裂，用户后面找不到备注去哪了）。多个祖先都有注释的情况下优先最外层——语义上更接近「用户在更大范围做的标记」。
- **不走这套重定向的例外**：包围盒（bbox）和移动/旋转（`raw.nodes` 变换）这两类操作继续直接对 `rec.ni`（这一行的终点节点）生效——它们本来就是「针对具体某个节点」的操作（包围盒要有实际几何体、变换要改某个节点自己的 local matrix），跟「给整个合并行起名字/打勾」性质不同。
- 合并行在列表里带一个「⊂合并」标签（hover 提示完整链路和挂载节点），详情区的 `.node-merge-note` 说明条把同样的信息用完整句子说一遍（链路 + 挂载于哪个节点 + 为什么挂在那）。

**验证**：真实样品 `chengdu-huagao-0801.glb`（28 节点=14 组+14叶子，全部 14 个组都是单子节点组）：列表从改前 28 行精简到 14 行，全部 14 行都带「⊂合并」标签，表头精确等于 7 个核心字段；选中合并行 `node_3 → L6`，详情区标题显示链终点「L6」、合并说明条正确列出链路和挂载点、属于/顶点三角/通道/材质/T/R/S/相对基点全部字段正确显示、材质色块数精确等于 4（L6 是 4-primitive 网格）；四个操作按钮全部可点开且面板标题正确显示节点名（不是挂载点名）；生成的包围盒精确写进 `anno.nodes["L6"].bbox`（节点自己，不是 `node_3`）；给合并行填别名，`data-nalias` 挂载目标 = "L6"（因为 `node_3` 当时没有独立注释）、`anno.nodes["L6"].alias` 精确写入、`anno.nodes["node_3"]` 未被误写；导出注释 JSON 解析确认 `annotations.nodes["L6"].alias` 精确等于填的值、`node_3` 没被意外写入。合成文件 `_dev/gen-merge-test-gltf.js` → `test-merge.glb`（9 节点：`BranchGroup` 挂 `LeafA`/`LeafB` 两个子节点 + 3 层单子节点链 `OuterWrap→InnerWrap→DeepLeaf` + 标准 2 层单子节点链 `SimpleWrap→SimpleLeaf`）验证：合并后总行数精确等于 5（`BranchGroup`+`LeafA`+`LeafB`+`DeepLeaf`+`SimpleLeaf`），`BranchGroup` 自己单独占一行且没有合并标签（多子节点组维持展开，不受这次改动影响）、`LeafA`/`LeafB` 各自占一行，3 层链合并成一行显示为链终点 `DeepLeaf`；默认（链上没有任何祖先有注释）挂载点精确等于链终点 `DeepLeaf`（`ancestors.length===2`）；手动给最外层 `OuterWrap` 造一条已有注释后，重渲染确认挂载点改成 `OuterWrap`（DOM 上 `data-nalias` 属性、备注名称输入框回填的值都同步验证）。两组场景全程控制台 0 报错。重跑既有回归测试 `test-instance-export.js`/`test-bbox.js`/`test-basepoints.js`/`test-highlight.js`/`test-mat-editor.js` 确认这次改动没有破坏 Instance/包围盒/基点/高亮/材质编辑相关功能，全部 PASS。截图对比（1440×900 视口，节点树 Tab）：改前 `_dev/shots/todo20-00-before.png`（15 列表头，28 行，密度拥挤）vs 改后 `_dev/shots/todo20-01-after-empty.png`（空选中态）/`todo20-02-after-detail-open.png`（选中合并行，详情区展开+视口青色包围盒线框同步可见）/`todo20-03-synthetic-multichild-and-chain.png`（合成文件多子节点组+链合并共存）。测试脚本：`_dev/test-node-detail-panel.js`（Playwright，45 项断言全部 PASS，可重跑复查）。调试钩子：`window.__debugNodeDetail`（暴露 `resolveNodeRowTarget`/`nodeAnnoHasOverride`/`renderNodeDetail`/`createInstance`/`exportSelectedNode`/`setNodeSelection`/`toggleNodeSelection`，供测试脚本直接调用核心函数而不只是操作 DOM）。

**跟 `SPEC.md` 导出结构的关系**：这次改动纯粹是编辑器 UI 展示层的调整——`Node`/`NodeAnnotation` 接口本身没有新增或改名字段，`nodeAnno(name)` 仍然是按节点名字存一条记录，合并行只是在写回时把目标名字从「行对应的 `ni`」换算成「`resolveNodeRowTarget()` 解出的挂载点名字」，导出的注释 JSON 结构不受影响，`SPEC.md` 不需要同步改动。

### 6.3 材质画廊工具栏：视图切换 + 贴图缩略图 + 视口取色 + 拖拽指定材质（对应 TODO #19，2026-08-06 完成）

**开工前检查**：先搜了「视图切换」/`eyedropper`/「取色」/「拖拽指定」/`mat-toolbar`/`list-view` 等关键词，`index.html` 里只有 `.mat-toolbar`（§5 材质清理菜单已经在用的工具条容器类）和视口原有的「拖 GLB 文件进来加载」`dragover`/`drop` 监听器，没有任何跟本任务四个子功能相关的残留代码，是干净状态、从零实现。

**基础设施：`raycastMeshAt(clientX, clientY)`**——「视口取色」和「拖拽指定材质」两个子功能共用同一条查找路径，也是这次实现里最值得记录的一段：从屏幕坐标（`MouseEvent.clientX/Y`）转 NDC 坐标，`THREE.Raycaster.setFromCamera` 发射射线，只在 `model.children` 里递归求交（不搜整个 `scene`）——这一步顺带白嫖了 §6.1 定下的既有约定：节点选中框/材质高亮叠加层/材质高亮描边/包围盒线框/基点标记全部只 `scene.add()`、不进 `model` 子树，所以射线天然不会打到这些辅助可视化对象上，不需要额外过滤。命中一个 `THREE.Mesh` 之后，用 `gltf.parser.associations.get(hitMesh)` 反查——这是 `three.js` `GLTFLoader` 官方暴露的对象→glTF 索引反查表，创建 Instance（§6）、材质编辑器（§7）反查全部引用实例都在用同一张表——对于单 primitive 网格节点，`hitMesh` 本身的 associations 同时含 `{meshes, primitives, nodes}` 三个字段（GLTFLoader 内部把 mesh 和 node 合并成同一个 Object3D）；多 primitive 网格节点则是 `hitMesh` 只带 `{meshes, primitives}`（父级 Group 才带 `{meshes}`，但 Raycaster 命中的一定是叶子 Mesh 不是 Group，所以从命中对象直接就能拿到 `{meshes, primitives}`，不用额外往上找父节点）。拿到 `meshIndex`/`primIndex` 后查 `raw.meshes[meshIndex].primitives[primIndex].material` 就是这个网格「当前」的材质索引——取色用这个索引去定位画廊卡片，拖拽指定用来跟目标材质索引对比、判断要不要真的写回。

**子功能1：块视图 / 列表视图切换**——模块级变量 `matGalleryView`（`'grid'` | `'list'`，不做 `localStorage` 持久化，任务明确说了这次范围不用做那么细）。两种视图共用同一个 `matCardHtml(m, view)` 生成函数，DOM 结构基本一致（只是 `view==='list'` 时多渲染一行 `.mparams` 关键参数条），靠外层 `.mat-gallery` 容器加不加 `.list-view` 类切 CSS 布局（网格 `grid-template-columns` vs `flex-direction: column` + 卡片内部横排），不用维护两套模板、也不用改点击/拖拽等事件绑定逻辑（都是对 `.mat-card` 元素绑定，跟视图无关）。

**子功能2：贴图缩略图**——色块卡片如果这个材质的 `baseColor` 槽有贴图，优先显示贴图的实际缩略图而不是纯色块。直接复用 #16 阶段已经写好的 `getSlotTexture(i, slotKey)`（查 `matInstances` 里这个材质当前实际渲染实例的贴图属性）+ `textureThumbDataUrl(tex, size)`（`THREE.Texture.image` 画到 canvas 转 dataURL，`createImageBitmap` 解出来的 `ImageBitmap` 和原生贴图的 `HTMLImageElement` 两种来源一致对待），没有另外写一套解码逻辑，只是把这两个函数从「贴图槽编辑区专用」扩展成画廊卡片也在用。样品 `chengdu-huagao-0801.glb` 本身 0 贴图，用 #16 已有的贴图上传功能给某个材质的 `baseColor` 槽传一张测试图造出「有贴图的材质」来测这条路径。**已知简化**：这是「显示这张贴图长什么样」，不是把贴图纹理和 `baseColorFactor` 相乘算出 glTF 语义上真正的最终着色结果——两者在 glTF 里是相乘关系，画廊卡片这个展示优先级更高的贴图纹理本身，不做实时着色计算，跟任务里「不喧宾夺主」的产品判断一致（卡片是快速浏览用的，不是渲染预览）。

**子功能3：多色材质角标**——色块右上角一个 9px 小圆点（`.swatch-badge`），露出这个材质除了主色（`baseColor`，即色块本身）之外的另一个「非默认」颜色。这次只认自发光：`matSecondaryColorHex(m)` 判定 `m.emissive`（`matRowFrom` 已经产出的十六进制串，跟 `hex()` 函数「显式写了才不是 `—`」的既有语义一致）非 `—` 且非黑 `#000000` 才算「非默认」，跟 baseColor 重复的主色不会拿来当角标（否则每个非黑材质都会长一个跟主体几乎同色的圆点，没有信息量）。**实时性坑**：材质详情区的字段编辑走「`oninput` 只手动 patch 具体 DOM 节点，不整块 `renderTab`」这条既有模式（避免滑块拖拽/取色器操作中丢失焦点），但一开始只顾着照抄 `baseColor` 那段patch画廊色块背景的写法，漏了同步 patch 角标——用测试脚本才发现「设完 emissive 之后立即查 DOM，角标还没出现，要等下一次完整 `renderTab('mat')`（比如切视图）才补上」。修复：`#mdEmissive` 的 `oninput`/`onchange` 里额外加一段手动创建/更新/移除 `.swatch-badge` 子节点的逻辑，跟 patch 色块背景同一段代码里做，不用等下次整体重渲染。块视图、列表视图共用同一个 `matCardHtml()`，角标天然两边都有，不用分别处理。

**子功能4：视口取色（eyedropper）+ 拖拽指定材质**——这轮实现里工作量最大、也是任务要求优先级最高的一对：

- **取色**：`#matPickBtn`「◎ 取色」按钮切换 `pickModeActive` 状态，进入时 `#viewport` 加 `.picking` 类（光标变十字）、按钮高亮、状态栏文案提示。真正的点击判定在 `viewport` 的 `pointerdown`/`pointerup` 监听器上，不是简单挂个 `click`——`OrbitControls` 本身也是靠鼠标按下-移动-抬起实现旋转视角的，如果只听 `click`，用户在拾取模式下想先转个角度看清楚再点，一样会误触发取色。做法是记录 `pointerdown` 时的屏幕坐标，`pointerup` 时算跟按下坐标的欧氏距离，超过 5px 判定成「转视角」直接放弃，不超过才真正调 `raycastMeshAt` + 定位。命中材质后：`selectedMat` 设成命中的材质索引、`applyMatHighlight()` 触发视口高亮、`renderTab('mat')` 强制切到材质 Tab（这是「点网格→找到材质」这条反向操作的落点，就该展开材质详情面板），并 `scrollIntoView` + 一次性 `.flash-card` CSS 动画（黄色背景从半透明淡出，`@keyframes matCardFlash`）让卡片在一堆卡片里视觉上跳出来。取色成功或者用户主动再点一次「取色」按钮 / 按 `Esc` 都会退出拾取模式（`Esc` 分支加进了 §10 场景菜单已经建立的全局 `keydown` 监听器里，不另开一个）。

- **拖拽指定材质**：材质卡片 `draggable="true"`，`dragstart` 把材质索引记进模块级变量 `draggedMatIndex`（同进程内直接读变量，比解析 `dataTransfer` 字符串简单可靠，`dataTransfer.setData` 仍然顺手存了一份防万一但不是主要读取路径）。视口原有的 `dragover`/`dragleave`/`drop` 三个监听器（原本只处理「拖 `.glb` 文件进来加载」）改成先判断 `draggedMatIndex !== null`——是内部卡片拖拽就走新逻辑（`dragover` 时持续 `raycastMeshAt` 当前坐标、用命中的 mesh 调 `setDragHover()` 画悬停提示；不加原有的 `.drag` 文件提示类，避免用户以为拖材质卡片也能触发换模型），不是就还走原来的文件判断逻辑，两条路径共用同一组监听器、靠一个变量分岔，不是新开一套独立监听器。

  **写回两处（`assignMaterialToMesh(materialIndex, hit)`）**：① `hit.meshObj.material = newMat`——`newMat` 直接是 `matInstances.get(materialIndex)` 里已有的共享 `THREE.Material` 实例（`Set.values().next().value` 取任意一个，因为材质编辑器改这个材质时是遍历整个 `Set` 一起改的，同一个材质索引对应的所有实例语义完全一致，取哪个都一样），不新建 `clone`——这是刻意的设计取舍：直接复用共享实例意味着以后再编辑这个材质（改颜色/贴图），这个刚被指派的网格也会跟着联动变化，跟项目里其它地方「`matInstances` 是共享实例、编辑一处处处生效」的既有假设保持一致，不会有「拖过去的材质其实是个不会同步更新的静态快照」这种反直觉行为。这一步是「另存为 GLB」真正读的数据源。② `raw.meshes[meshIndex].primitives[primIndex].material = materialIndex`——`GLTFExporter` 序列化时不读这份 `raw` JSON（读的是①改过的 three.js 场景），这一步纯粹是为了材质表「用于节点」反查、模型块表材质列、注释 JSON 导出这几处二次渲染跟编辑状态保持一致，跟 `uploadMatTexture`（#16）的「双写」注释是同一个道理。写回之后调 `rebuildNodeTable()`（节点「材质」列和材质「用于节点」反查都是从 `primitive.material` 派生的，必须重算）+ 如果当前有材质选中就重新 `applyMatHighlight()`（命中集合可能因为这次改动变化）。

  **拖拽悬停反馈**：`setDragHover(meshObj)`——复用 §6.1「撞色兜底」里 `getMatOutlineMaterial()` 那套「法线外扩+只画背面」白色轮廓描边手法，但只描当前悬停的这一个网格、**不叠加发光呼吸层**（那是完整材质高亮的强度，用在这里对「短暂路过提示一下」这种场景太抢眼），视觉语言上仍然认得出是同一套「命中反馈」体系。

  **已知范围限制（如实记录，不隐瞒）**：如果 glTF 原生 instancing——多个 node 引用同一个 `mesh` 索引——拖拽只会改被拖拽命中的那一个 three.js `Mesh` 实例的 `.material`，`raw.meshes[meshIndex].primitives[primIndex].material` 的改动在 glTF 语义上其实是「这份共享 mesh 数据的材质变了」，理论上应该所有引用同一个 mesh 索引的其它节点也跟着变，但这次没有为这个场景专门写「找出所有共享同一 mesh 索引的其它 `THREE.Mesh` 实例、一起改」的逻辑——真实样品 `chengdu-huagao-0801.glb` 14 个网格节点各自对应独立的 mesh 索引，没有这种共享实例的情况，这次没有为一个当前用不上验证的场景过度设计，留给以后有真实需求时再扩展（`GLTFLoader._getNodeRef()` 在这种共享场景下会 `.clone()` 出新的 `Mesh` 对象但共享同一份 `gltf.parser.associations` 映射，扩展时从这里入手）。

**测试**：真实样品 `chengdu-huagao-0801.glb`（17 材质，14 网格节点）。视图切换：默认块视图，点列表视图按钮后 `.mat-gallery` 加 `list-view` 类、卡片 `flex-direction` 变 `row`、17 张卡片全部带关键参数条，切回块视图类消失，两种视图截图对比。贴图缩略图：材质 #1 上传一张测试 PNG 到 `baseColor` 槽后，画廊卡片精确出现 `<img class="swatch-img">` 且 `src` 是合法 `data:image/` URL（上传前是 0）。多色角标：材质 #0（原始纯黑 fallback）先用材质编辑器改 `baseColor` 成红、再改 `emissive` 成蓝，改完立即（不切视图/不等下次渲染）出现角标，`raw.materials[0].emissiveFactor` 确认真的非默认值，块视图列表视图各截图一张确认都有。取色：用已知节点「Rectangle2133441908」（对应材质 #16，无遮挡）的世界包围盒中心投影到当前相机的屏幕坐标（`window.__debugGallery.screenPositionOfNode(ni)`，真实 `camera`/`renderer` 状态算出来的投影，不是伪造坐标——模型形状不规则，视口正中心不一定真的有几何体，直接猜坐标不稳定），Playwright 在该坐标做真实的 `mouse.down`+`mouse.up`（不是直接调内部函数模拟点击），点击后 `selectedMat` 精确等于预测命中的材质索引 16、自动切到材质 Tab、对应卡片 `.sel` 类、视口高亮触发、拾取模式自动退出。拖拽：把材质 #0（此时已经是红底蓝自发光）拖到刚才取色定位到的同一个网格（材质 #16 所在的网格）上——`mouse.down` 在卡片位置、`mouse.move` 若干步到目标坐标（含目标点停留触发 `dragover` 悬停）、`mouse.up`；拖拽悬停阶段截图确认目标网格出现白色轮廓描边；松手后同一坐标 `raycastMeshAt` 命中的材质索引变成 0（不再是 16）、`raw.meshes[13].primitives[0].material === 0`、命中 mesh 对象的 `.material` 精确等于 `matInstances.get(0)` 里的共享实例（不是新 clone）；视口截图对比拖拽前（浅绿盒子）/拖拽后（红蓝盒子）肉眼可见外观真的变了；「另存为 GLB」导出后解析 JSON chunk，按节点名 `Rectangle2133441908` 找到导出文件里对应的 mesh/primitive，材质名字精确等于「fallback Material」（材质 #0 的名字）、`baseColorFactor` 精确匹配（跟内存里编辑后的 `raw.materials[0]` 逐分量一致，容差 1e-4）。控制台全程 0 报错。重跑既有回归测试 `test-highlight.js`/`test-mat-editor.js`/`test-texture-upload.js`/`test-scene-menu.js`/`test-node-detail-panel.js` 确认这轮改动（尤其是共用了视口 `dragover`/`drop`/新增 `pointerdown`/`pointerup`/`keydown` 分支这几处touch 到既有代码路径的地方）没有破坏既有功能，全部 PASS。测试脚本：`_dev/test-gallery-toolbar.js`（Playwright，35 项断言全部 PASS，可重跑复查）。截图：`_dev/shots/gallery19-00` 至 `gallery19-07`。调试钩子：`window.__debugGallery`（暴露 `raycastMeshAt`/`assignMaterialToMesh`/`matSecondaryColorHex`/`enterPickMode`/`exitPickMode`/`togglePickMode`/`locateMaterialFromPick`/`screenPositionOfNode` 以及 `matGalleryView`/`pickModeActive`/`draggedMatIndex` 三个只读 getter，供测试脚本直接调核心函数、读模块级状态，不用只靠 DOM/截图肉眼判断）。

### 6.4 模型块 Tab 打磨：3 行结构恢复 + 说明收纳 + 材质色块对齐 + 子节点箭头 + Instance 改名（对应 TODO #36，§17.2，2026-08-07 完成）

**开工前排查**：搜了一遍 `index.html` 有没有本任务相关的残留实现——「折叠三角」`▸/▾`（`.tree-toggle`）和「点击行折叠/展开子树」的状态管理（`collapsedNodes`）在 #20/#29 就已经做好并且在正常工作，这次任务第 4 点（子节点展开/折叠箭头）**不是从零实现**，只是在既有基础上做视觉打磨（字号从 9px 提到 10.5px、hover 时加圆角背景块，让它看起来更像"可点的按钮"），复用了原有的状态管理，一行没有重新发明折叠机制。其余 5 项（3 行结构/ⓘ菜单/材质对齐/Instance改名/去掉导出按钮）搜索确认没有残留实现，从零做。

**1. 列表行 3 行结构恢复**：核实现状后确认 §16.2（#29）定的"三行方案"在 DOM 结构层面其实已经是 3 个 `.node-info-row`（材质/勾选框/别名+关联基点+备注），但**视觉上会呈现成 4 行**——第三行 `.node-info-meta` 塞了别名输入框（120px）+ 关联基点下拉框（约 140px）+ 备注输入框（`flex:1`），三者总宽度在常见面板宽度下会触发 `flex-wrap:wrap`，备注输入框被挤到第二条视觉行，DOM 里仍然是同一个 `.node-info-row` 但肉眼看就是 4 行——真实样品截图验证过这个现象（改动前行高 107px，把备注移出后降到 77px）。**修复**：把「备注」输入框从列表第三行整个移除，改到详情区 `renderNodeDetail()` 新增一个 `<div class="mf-row">备注 + <input></div>`，跟别名/关联基点走同一个 `targetName`（`resolveNodeRowTarget()` 解析出来的挂载点，合并行场景下保持跟别名/关联基点一致的写回目标）——`data-nnote` 属性名和 `el.querySelectorAll('input[data-nnote]')` 的 `onchange` 绑定逻辑完全没变，只是元素挪了位置，同一段绑定代码天然适配。表头文字同步从「别名·关联基点·备注」改成「别名·关联基点」。

**2. 详情区顶部说明文字收进 ⓘ 菜单**：复制 `#matHelpOverlay`（#32）/`#texHelpOverlay`（#35）同款组件，新增 `#nodeHelpOverlay`/`#nodeHelpPanel`/`#nodeHelpBody`（外壳/CSS 逐字节照抄，只换了 `node` 前缀和内容），把原来常驻在列表上方的一整段长句子（"节点树共N个节点·56个网格块+56个分组·只包一个子节点的分组自动合并显示成一行…"）拆成 4 段人话塞进浮层，列表上方只留一行短计数「节点树共 N 个节点 · M 个网格块 + K 个分组」。`renderNodeDetail()` 的两个分支（`selectedNodeIdx === null` 空状态 / 选中态）都各自渲染一份 `.mdh` + `#nodeHelpBtn`——这是跟 #32/#35 同款约定：说明入口任何时候都点得到，不会因为没选中节点就连帮助入口一起消失（任务原文明确要求"未选中节点时ⓘ图标依然要能点"）。

**3. 材质色块对齐——选了「固定最大宽度 + 横向可滚动」，不是「收起成N个材质▾」**：`.node-info-mats`（列表）加 `max-width:190px; flex-wrap:nowrap; overflow-x:auto`，`.mf-mats-scroll`（详情区，`max-width:260px`）同一个思路。**选型理由**：色块本身已经是紧凑的图标+编号（不像文字动辄十几个字符），横向滚动不需要额外的展开/收起状态管理（不用为每一行单独维护"是否展开"这个新状态，`collapsedNodes` 那套折叠状态管理不需要照搬一份），超出时右侧留一点被裁切的色块边缘就足够提示"还有更多，可以滚"；对比"收起成 N个材质▾"方案，那个需要点开才能看到具体是哪几个材质，日常"扫一眼这一行大概用了几个材质"这个高频诉求反而变麻烦了。**验证**：真实样品 13 行材质数量从 1 到 5 不等，改动前行宽随材质数量变化（长短不一），改动后全部精确等于 190px（`Set` 去重后只有一个宽度值）；用 20 个色块做压力测试（真实样品最多只有 5 个，人工构造更极端场景验证鲁棒性）确认 `scrollWidth`(706px) > `clientWidth`(190px)，`overflow-x:auto` 计算样式生效，行高不会被撑高。

**4. 子节点展开/折叠箭头视觉打磨**：如上所述，折叠机制（`collapsedNodes`/`walk()`）和箭头字符本身（▸/▾）#20/#29 就有，这次只加大字号+hover 背景块，复用既有状态管理一个字没改。用合成文件 `_dev/test-merge.glb`（`BranchGroup` 有 `LeafA`/`LeafB` 两个真实子节点，是本仓库里少数会触发"多子节点组"分支的测试样品——`画稿飞扬v2.glb` 真实样品的全部 13 个组节点都是单子节点组，合并后没有一行带箭头，验证不到这条路径）验证：展开态 5 行，箭头 `▾`；点击折叠后 3 行，箭头变 `▸`；再点一次恢复 5 行。

**5. Instance 改名「样例复制」**：搜了全文件「Instance」关键词，逐条判断改不改——用户可见的界面文案全改（按钮文字「⧉ Instance」→「⧉ 样例复制」、状态栏提示、`logEntry` 日志标题、`pushUndo` 撤销栈提示、`SCRIPT_OP_LABEL.createInstance`、场景菜单"重放操作脚本"按钮的 `title` 提示、设置面板 `EXT_mesh_gpu_instancing` 兼容性说明的 `usageNote`——这条是搜索时才发现的，藏在「设置·附加信息」浮层里的扩展兼容性说明文字，同样是用户看得到的界面文案，一起改了）；代码内部标识符不改（`createInstance()` 函数名、`matInstances` 模块级变量、`gltf.parser.associations` 反查、纯代码注释里提到"创建 Instance"这个概念的地方）——任务原文明确说了这类不用改，改了反而增加不必要的 diff。

**6. 移除详情区「导出」按钮**：确认 #34 新增的视口工具条 `#modeExportBtn` 内部调用的就是同一个 `exportSelectedNode(ni)` 函数（`data-action="export"` 分支，见 §19.7），覆盖的是完全相同的场景（对当前选中节点导出为独立 GLB）——去重顺利，没有发现功能差异，详情区 `[data-detail-exportnode]` 按钮和它的 `onclick` 绑定整段删掉，`exportSelectedNode` 函数本身、`#modeExportBtn` 都一行没动。详情区操作按钮从 3 个（Instance/导出/包围盒）精确剩 2 个（样例复制/包围盒）。

**开工前提到的已知遗留问题排查结果（`.node-detail` 缩放列选择器）**：确认问题属实，根因是 #29（2026-08-06）把详情区"缩放 S"从只读 `<span class="mono">`（挂在 `.mf-row` 里）改成了内联可编辑的三个 `<input id="ndSx/Sy/Sz">`（挂在 `.trs-edit-row > .trs-group` 里，不再是 `.mf-row`）——`_dev/test-cleanup-menu.js` 的 `readDetailScaleS()` helper 还在用旧选择器 `.node-detail .mf-row .mono` 找"缩放 S"这一行，字段结构变了之后这个选择器连"这一行"本身都找不到（`.mf-label` 文本比对失败，因为"缩放 S"现在挂在 `.trs-group` 不是 `.mf-row` 下面），不是这次任务引入的新 DOM 结构，是 #29 遗留、#32 完成报告里已经提前发现并记录、这次顺手确认+修的。**修复范围只在测试脚本**：`readDetailScaleS()` 改成读 `#ndSx`/`#ndSy`/`#ndSz` 三个输入框的 `.value` 拼成字符串，不改 `index.html` 任何代码（这次任务本身没有重写 `.trs-edit-row` 结构，问题不属于"这次任务顺带自然解决"的情况，需要单独修）。验证：独立探测脚本确认旧选择器在当前代码下确实返回"找不到该行"，新选择器能正确读到值且在触发"缩放归一化"批量清理操作后前后数值确实发生变化（`0.001, 0.001, 0.001` → `1, 1, 1`），修复有效。`test-cleanup-menu.js` 其余部分（重命名/浅色/指定色/GLB 导出核对）不受影响。

**验证**：真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`（13 块，全部单子节点链）+ 合成文件 `_dev/test-merge.glb`（多子节点组场景）。测试脚本 `_dev/test-todo36-nodetab.js`（Playwright，35 项断言全部 PASS）覆盖：① 空状态 ⓘ 图标存在可点、说明只留一句「点一行查看详情」；② 列表行 DOM 断言精确 3 个 `.node-info-row`、顺序精确是材质/勾选框/别名+关联基点、行内不再有备注输入框、全部行高度一致（证明没有意外换行）；③ 选中态详情区含备注输入框、不含导出按钮、样例复制按钮文案精确等于「⧉ 样例复制」、操作按钮数精确等于 2；备注编辑后正确写回 `nodeAnno().note`；④ 视口工具条 `#modeExportBtn` 触发下载，验证覆盖同一场景；⑤ 材质色块宽度跨不同材质数量的行精确一致、超出时 `overflow-x:auto` 生效；⑥ 全文搜索确认不再出现「Instance」（含 `[title]` 属性遍历），场景菜单「重放操作脚本」提示文字确认已改，实际触发一次「样例复制」操作后页面上出现「样例复制」字样、仍不出现「Instance」；⑦ 展开/折叠箭头在合成多子节点文件上行为正确（5→3→5 行，箭头 ▾/▸ 切换）；⑧ 控制台全程 0 报错。重跑既有回归测试：`_dev/test-todo29.js`（44 项 PASS，含 1 项因本任务而更新的断言——把"第三行含备注输入框"改成"列表无备注输入框+详情区有"，反映的是这次任务的预期行为变化，不是回归）、`_dev/test-todo33-gizmo.js`（54 项 PASS）、`_dev/test-todo34-actions.js`（50 项 PASS）、`_dev/test-bbox.js`（全 PASS）、`_dev/test-basepoints.js`（全 PASS）确认没有破坏拖拽重挂靠/T-R-S内联编辑/gizmo/视口工具条动作组/包围盒/基点相关功能；`_dev/test-instance-export.js` 跑到「另存为 GLB」步骤撞上 #21/#22 已经记录过的 `#exportGlbBtn` 旧按钮 ID 问题（这次任务之前就存在，`Doc/TODO.md` #29/#32/#34 完成报告都独立撞到过、记录过，跟这次改动无关），撞上之前 Instance/样例复制创建、mesh 索引校验、子树克隆等核心逻辑全部正常通过。截图：`_dev/shots-36/00` 至 `06b`（含空状态、选中态三行列表、ⓘ 说明浮层、展开/折叠箭头前后对比、材质色块对齐压力测试）。

---

## 7. 材质编辑器 + 贴图 UV 编辑 + 测量包围盒（对应第 7、8 点）

**材质编辑器**：现在材质表只能加文字备注，这里要做成能真正**编辑并写回**——baseColor/金属度/粗糙度/自发光/透明度改了之后同步更新 three.js 材质（视口实时看到变化）和底层 JSON（另存为 GLB 时生效）。

**贴图 UV 变换**：3D 软件里常见的贴图「移动/缩放/旋转」，对应 glTF 的 `KHR_texture_transform` 扩展（`offset`/`scale`/`rotation`）。SPEC.md 现有的「已识别但未解析扩展」列表里还没有这一项，要补上，而且这次不只是「识别」，是要**编辑**。

**测量包围盒**（第 8 点，也就是英文 bounding box）：
- 用户选中一个物体，视口里有个按钮「生成包围盒」
- 默认按该节点的世界空间旋转角 R°（沿用上一版已经改好的世界空间 TRS）生成一个**定向包围盒**（OBB，不是简单轴对齐的 AABB），旋转值也可以手动改，改完重新生成
- 可以删除已生成的包围盒
- 场景整体的包围盒（现有场景表里已有）也要支持手动改变，不只是自动按 bbox 计算

这一节工作量最大：材质编辑、UV 编辑、包围盒创建，都需要在 3D 视口里做可视化交互（gizmo 拖拽），不只是表格填数值——目前 3AS 还没有任何视口内交互层，这是全新的子系统。

**实现记录（材质编辑器部分，2026-08-05，#7）**：材质编辑做完了，UV 编辑（`KHR_texture_transform`，#8）和包围盒（#9）当时还没开始，见下面 #9 独立实现记录。

**实现记录（贴图 UV 变换部分，2026-08-05，#8）**：

- **不用自己解析扩展**：`vendor/loaders/GLTFLoader.js`/`vendor/exporters/GLTFExporter.js`（three.js 官方库）已经完整读写 `KHR_texture_transform`——加载时自动转成 `THREE.Texture` 的 `.offset`/`.repeat`/`.rotation`，导出时非默认值自动写回扩展 JSON。3AS 只做 UI + 属性读写，跟自己写的 `raw.materials[i]` 同步逻辑一致。
- **粒度确认**：UV 变换是「材质+贴图槽」级别的（glTF 定义在 `pbrMetallicRoughness.baseColorTexture.extensions.KHR_texture_transform` 这一层），不是贴图 image 级别——材质详情编辑区按 5 个可能的贴图槽（固色/金属粗糙/法线/AO/自发光）分组渲染，只渲染该材质实际用到的槽位，没有任何贴图槽的材质显示「此材质没有贴图槽，无 UV 变换可编辑」（不报错不留白）。
- **共享贴图对象的坑（关键）**：读 `vendor/loaders/GLTFLoader.js` 的 `parser.assignTexture()`/`GLTFTextureTransformExtension.extendTexture()` 确认——一张贴图（`textures[]` 下标）在没有各自 `KHR_texture_transform` 之前，会被多个材质/多个槽位共享同一个 `THREE.Texture` 对象实例（只有源文件里 mapDef 自带 transform 时才 `.clone()`）。直接改 `.offset`/`.repeat`/`.rotation` 会连带改到其它引用同一贴图的材质/槽位。解决办法：`ensureIndependentTexture(matIndex, slotKey, threeProps, insts)` 在「本材质本槽位」第一次被编辑时才 `texture.clone()` 一份独立实例，之后缓存复用（`uvTexCache`，新模型加载时重置），没编辑过的槽位继续安全共享原始对象。
- **金属粗糙槽的导出坑**：读 `vendor/exporters/GLTFExporter.js` 的 `buildMetalRoughTexture()` 确认——只有 `material.metalnessMap === material.roughnessMap`（同一对象引用）时才会原样保留 UV 变换直接写回；如果是两个不同对象，导出会触发它自己的「合并重打包」生成一张新 canvas 贴图（变换归零），编辑的 UV 值就在导出这一步丢失。所以 `metallicRoughness` 槽的 clone 会同时赋给 `metalnessMap` 和 `roughnessMap` 两个属性（同一个对象），规避这个坑。
- **UI**：材质详情区「贴图槽」只读行下面新增「贴图 UV 变换」分区，每个用到的槽位一组，移动 X/Y（-2~2）、缩放 X/Y（0.1~5，默认 1）、旋转（0-360°，UI 角度内部转弧度）各一行滑动条，沿用 `.mf-row`/`.mf-revert` 现成风格，每个字段独立还原按钮；「还原此材质」一键还原也覆盖 UV 变换。
- **写回链路**：① `raw.materials[i]` 对应槽位 `mapDef.extensions.KHR_texture_transform`（全部为默认值时删掉这个扩展字段，不往导出结果塞无意义数据）；② 上面 clone 出来的 `THREE.Texture` 实例的 `.offset`/`.repeat`/`.rotation`，`needsUpdate = true` + `material.needsUpdate = true` 让视口实时重绘（实测三个字段单独改都会立即生效，不需要额外调用 `updateMatrix()`——`Texture.matrixAutoUpdate` 默认 true，渲染时自动重算 UV 矩阵）。
- **验证**：手写合成了一份自包含棋盘格贴图 glTF JSON（`_dev/gen-checker-gltf.js` 生成 `_dev/test-uv-checker.glb`，纯 Node 手写 PNG 编码器 + 内嵌 base64，不依赖 canvas/npm 包），2 个材质：材质0 只有固色槽（用于视觉验证）、材质1 固色+自发光槽共用同一张贴图（用于验证槽位独立性）。用 Playwright（`_dev/test-uv-editor.js`）：
  - 移动：偏移整整 1 格（0.125 UV）让棋盘格红蓝色块完全对调，裁切截图前后对比确认色块位置真的翻转了（`_dev/shots/20-offset-crop-before.png` / `21-offset-crop-after.png`）
  - 缩放：0.375 让 8x8 格子变成肉眼可数的 3x3 大格子，截图对比（`_dev/shots/13-uv-viewport-after-scale.png`，对照 `10-uv-baseline.png`）
  - 旋转：在放大的格子基础上转 45°，方块变菱形，截图对比（`_dev/shots/14-uv-viewport-after-rotation.png`）
  - 槽位独立性：材质1 的固色槽和自发光槽设成不同的移动/缩放/旋转值，读 three.js 实例确认 `material.map !== material.emissiveMap`（各自独立 clone）且属性值不同；导出 GLB 解析 JSON chunk 确认 `materials[1].pbrMetallicRoughness.baseColorTexture.extensions.KHR_texture_transform` 和 `materials[1].emissiveTexture.extensions.KHR_texture_transform` 数值不同且都跟 UI 设的值吻合
  - 导出校验：材质0 设移动 `[0.5,0]`、缩放 `[3,3]`，导出 GLB 解析 JSON chunk 确认 `materials[0].pbrMetallicRoughness.baseColorTexture.extensions.KHR_texture_transform` 精确等于 `{"offset":[0.5,0],"scale":[3,3]}`（旋转还原过为 0，正确地没有出现在导出结果里，验证了「全默认值时删扩展字段」的逻辑）；`extensionsUsed` 正确包含 `KHR_texture_transform`
  - 还原按钮：单独还原 rotation 字段，确认 three.js texture 实例的 `.rotation` 真的回到 0
  - 真实样品 `chengdu-huagao-0801.glb`（0 贴图）：确认材质详情区不渲染任何 `.uv-slot`，改显示提示文案「此材质没有贴图槽，无 UV 变换可编辑」，不崩溃不报错
  - 控制台 0 报错。（过程中遇到一次 headless Chromium 的瞬时 WebGL 上下文问题——DOM/滑块数值都正确渲染，只有 canvas 画面是浏览器自己的「丢失上下文」占位图标，重跑一次后消失，属于 Playwright/SwiftShader 偶发的环境抖动，不是代码问题，同一状态重复验证多次数据始终一致）
  - 测试脚本：`_dev/test-uv-editor.js`（Playwright，可重跑复查），依赖 `_dev/gen-checker-gltf.js` 先生成测试用 `_dev/test-uv-checker.glb`
- **调试钩子**：为了让测试脚本能直接读 three.js 内部状态（比只读 DOM 数值更能证明写回链路真通到材质/贴图实例），`buildTables()` 末尾新增 `window.__debugRaw`/`window.__debugMatInstances` 暴露，不影响正常使用。

- **顺带解决了 UI 评审提的密度问题**：材质表原来是 11 列的宽表格，塞进 460px 面板后「备注」列被裁切到视口外看不见（见 `Doc/2026-08-05-ui-review-panel-density.html` §02）。这次改成两栏：上栏是网格布局的**色块画廊**（色块+材质名+索引号 `#N`，点击选中），下栏是选中后展开的**详情编辑区**（不受横向空间挤压），外加一块**用于节点反查区**（chip 形式，点击可跳转到「模型块」Tab 并高亮定位）。
- **可编辑字段**：漫反射（颜色选择器）、金属度/粗糙度（滑动条）、自发光颜色（颜色选择器）、自发光强度（滑动条，对应 `KHR_materials_emissive_strength`）。透明度/混合模式保持只读展示（按文档"不强制"的口径，没做成可编辑）。
- **还原机制**：每个字段旁一个「⟲」单独还原按钮，详情区顶部一个「⟲ 还原此材质」一键还原全部字段；原始值取自模型加载时立即拍的深拷贝快照（`tables.matOriginal`），不受本次会话编辑次数影响，也不进导出 JSON。
- **写回链路**：① 用 `gltf.parser.associations`（GLTFLoader 官方暴露的对象→glTF索引反查表）反查出材质索引对应的全部 `THREE.Material` 实例——包括 GLTFLoader 因顶点色/平滑法线等场景 clone 出的变体（clone 时 association 会被一并继承，见 `assignFinalMaterial`），逐个改属性（`.color`/`.metalness`/`.roughness`/`.emissive`/`.emissiveIntensity`），保证共享材质的多个网格块一起变；② 同步改 `raw.materials[i]`（`gltf.parser.json`）。实测确认「另存为 GLB」用的 `GLTFExporter.processMaterial()` 读的是 three.js Material 属性（`material.color.toArray()`/`.metalness`/`.roughness` 等），不是 `raw`——所以①才是导出正确性的关键，②是为了材质表二次渲染、注释 JSON 导出跟编辑状态保持一致。
- **验证**：用真实样品 `chengdu-huagao-0801.glb`（17 材质）。材质 #0「fallback Material」原始 `baseColorFactor` 是 `[0,0,0,1]`（纯黑，这套工具本来就是为了解决这类 V-Ray 导出黑材质问题的）；把漫反射改成 `#ff2020` 后，视口里挂这个材质的「Prof.Jimmy Choo」网格块从黑变红，截图确认（`_dev/shots/03-viewport-before.png` / `04-viewport-after.png`）；点「另存为 GLB」下载后手动解析 GLB 的 JSON chunk，`materials[0].pbrMetallicRoughness.baseColorFactor` 变成 `[1, 0.0144, 0.0144, 1]`（sRGB `#ff2020` 转线性空间的正确值，红通道满、绿蓝通道极低），证明写回链路真通了，不是只有视觉上看着变了。点「用于节点」chip 跳转到模型块 Tab，行被正确高亮定位，反查数据没丢。1440px 视口下截图确认色块画廊布局不再有列被裁切出视口。控制台 0 报错。测试脚本：`_dev/test-mat-editor.js`（Playwright，可重跑复查）。

**实现记录（透明/双面可编辑 + 滑动条布局收紧，2026-08-05，#18）**：

- **alphaMode/doubleSided 从"只读展示"改成可编辑**——之前那行字面意思上写着"只读展示"四个字，huashu-design 评审已经指出这种暴露开发状态的文案不专业，这轮直接删掉，两个字段本身也改成了真正可编辑。
- **映射规则不自己发明，照抄 three.js 官方 `vendor/loaders/GLTFLoader.js` 的 `createMaterial()`**（约行 3571-3596）反着做——GLTFLoader 加载时怎么把 glTF 的 `alphaMode`/`doubleSided` 转成 three.js Material 属性，编辑时就怎么反向写回：
  - `doubleSided: true` → `material.side = THREE.DoubleSide`；`false`（或字段不存在）→ `THREE.FrontSide`（GLTFLoader 从不显式设置这个分支，是 three.js Material 的默认值，不是 `BackSide`）
  - `alphaMode: 'BLEND'` → `material.transparent = true` + `material.depthWrite = false`（GLTFLoader 源码注释直接引用了 three.js issue #17706，半透明面不关 `depthWrite` 会有常见的自遮挡渲染问题，这里原样保留这个副作用）
  - `alphaMode: 'MASK'` → `material.transparent = false` + `material.alphaTest = materialDef.alphaCutoff ?? 0.5`（0.5 是 glTF 规范里 `alphaCutoff` 字段的官方默认值，不是随手拍的数）
  - `alphaMode: 'OPAQUE'`（或字段不存在）→ `transparent = false` + `alphaTest = 0`
  - `depthWrite`/`alphaTest` 本身不是 glTF 字段，只是 three.js 内部渲染状态，不写进 `raw`；`raw.materials[i].alphaMode`/`.doubleSided` 照常写回，用途跟其它字段一样——只是为了材质表二次渲染、注释 JSON 导出跟编辑状态保持一致，不是导出正确性的关键。
- **导出正确性的关键在 three.js 侧、不在 `raw`**——反查 `vendor/exporters/GLTFExporter.js`（约行 1589-1606）确认 `GLTFExporter.processMaterial()` 导出时是**反过来重新推导**：`material.transparent` 为真才写 `alphaMode: 'BLEND'`，否则 `material.alphaTest > 0` 才写 `'MASK'` + `alphaCutoff`，都不满足就不写 `alphaMode` 字段（隐式 `OPAQUE`，符合 glTF 规范"该字段可省略"的口径）；`material.side === DoubleSide` 才写 `doubleSided: true`。这跟材质其它字段（baseColor/金属度等）的既有结论一致——只要上面的属性映射设对了 three.js Material 实例，导出自动就是对的，`raw` 那份写回纯粹是为了 UI 展示层的数据一致性。
- **UI**：详情编辑区原来"透明/面"那一整行（两个只读 `.tag` 加一行"只读展示"提示文案）拆成两个可编辑控件——**透明模式**是 `<select>`（`OPAQUE`/`MASK`/`BLEND` 三个选项，为了跟旁边滑动条控件同一栏宽度不挤爆，选项文案只用英文缩写，完整含义放 `title` 属性 hover 提示），**双面**是 checkbox + 文字读数（"单面"/"双面"，checkbox 本体不够醒目，配一小段动态文字）。新增 `select` 元素的暗色主题 CSS（跟现有 `input[type="text"]`/`input[type="color"]` 同一变量体系，之前项目里没有任何下拉框，这是第一个）。
- **还原**：`revertMatField`/`revertMaterialAll` 补上 `alphaMode`/`doubleSided` 两个分支，原始值直接读 `tables.matOriginal[i]`（模型加载时的整份材质 JSON 深拷贝快照，`alphaMode`/`doubleSided` 字段本来就在里面，不用额外拍快照）。

**滑动条布局收紧**：

- 现状是"金属度"/"粗糙度"/"自发光"/"自发光强度"/（现在新增的）"透明模式"/"双面"六个概念性字段各占一整行（`.mf-row`：label 一行宽度 + 滑块占满剩余空间 + 数值 + 还原按钮）。改法是**两两并排**，不改控件类型本身（滑动条还是滑动条，颜色选择器还是颜色选择器，新增的下拉框/checkbox 也保持原生控件）——新增 `.mf-row-pair`（flex 容器，两个 `.mf-cell` 各占一半宽度）+ `.mf-cell`（比 `.mf-row` 更窄的 label/数值列宽：label 62px→42px，数值列 48px→32px，否则两栏各自宽度不够，滑块会被挤到没有可拖拽的空间）。三组配对：金属度+粗糙度、自发光+自发光强度（label 缩短成"强度"，同一行已经有"自发光"三个字做上下文，不会看不懂）、透明模式+双面。
- **效果**：6 个概念字段原来占 6 行（含头部 `.mdh` 和"漫反射"共 8 行内容），现在漫反射独占一行（颜色选择器不适合硬凑并排，保持原样）+ 3 个配对行 = 4 行，用真实样品对比同一个材质详情区（`_dev/shots/01-detail-panel-compact.png` 对比旧版 `Doc/shots/02-detail-open.png`，行间距一致的情况下少了 2 行的纵向占用），且新版本"透明模式"/"双面"从只读 tag 变成了真正可交互控件，字段信息量不降反升。
- **验证**：Playwright（`_dev/test-alpha-doubleside.js`）用真实样品 `chengdu-huagao-0801.glb`：
  - 材质详情区文案确认不再包含"只读展示"四个字
  - 材质 #1（"fallback Material"，纯黑，被 `L6`/`Yeang Keat OBE` 两个节点共用）：UI 切 alphaMode 到 BLEND，读 three.js 实例确认 `transparent:true, depthWrite:false, alphaTest:0`；切回 OPAQUE 确认 `transparent:false, depthWrite:true, alphaTest:0`；切 MASK 确认 `transparent:false, alphaTest:0.5`（原始材质无 `alphaCutoff` 字段，走 0.5 默认值分支）
  - 材质 #1 切 doubleSided 勾选/取消，读 three.js 实例 `material.side` 数值确认从 `0`（`THREE.FrontSide`）变成 `2`（`THREE.DoubleSide`，用暴露的 `window.__debugTHREE.DoubleSide` 常量比对，不是硬编码数字猜的），取消后变回 `0`
  - **视觉透明度验证换了目标材质**：材质 #1 所在的 `L6`/`Yeang Keat OBE` 网格从测试用的默认机位看被同场景另一块黑色招牌（"Prof Jimmy Choo"）挡住、且材质本身纯黑跟近黑视口背景撞色，就算真的变透明肉眼也分不出（属性层面已经用上面的 three.js 读数验证过，只是不适合做视觉截图）；换成材质 #16（视口里唯一无遮挡、正对镜头的浅绿色盒子，用于节点 `Rectangle2133441908`），用调试钩子把它的 `opacity` 压到 0.15（模拟一个有透明通道的材质——`baseColorFactor` alpha 本来就是这样映射到 three.js `opacity` 的，见本节前半"贴图 UV 变换"之前的既有记录），切 OPAQUE 截图（`_dev/shots/07-opacity015-opaque-mode.png`，box 仍是实心不透明——因为 `transparent:false` 时 renderer 忽略 `opacity`，这本身是 GLTFLoader 既有行为的正确复刻，不是 bug）；切 BLEND 截图（`08-opacity015-blend-mode.png`，box 明显变暗且能透出后方地板网格线，肉眼可辨的半透明效果）；截图前先取消材质选中（点画廊空白处），去掉 TODO #25 那层黄色高亮描边叠加，避免选中态的呼吸透明度动画干扰纯净的材质渲染效果对比
  - 「另存为 GLB」导出并解析 JSON chunk：材质 #1 设成 BLEND + 双面后，导出结果 `materials[1].alphaMode === 'BLEND'`、`materials[1].doubleSided === true`，跟原始 GLB 里 `materials[1].alphaMode`/`.doubleSided` 均为 `undefined`（原样品是隐式 OPAQUE + 单面）形成对照，证明写回链路（three.js 属性 → `GLTFExporter.processMaterial()` 反推）真的通到导出文件
  - 单独还原 `alphaMode`/`doubleSided` 两个字段，UI 读数确认分别回到 `OPAQUE`/`false`（原样品的原始状态）
  - 布局收紧：材质详情区截图（`01-detail-panel-compact.png`）跟 #7 阶段留存的 `Doc/shots/02-detail-open.png`（改动前的旧布局）对比，确认同样的字段信息在更少行数内放下
  - 控制台全程 0 报错
  - 测试脚本：`_dev/test-alpha-doubleside.js`（Playwright，可重跑复查）
- **调试钩子**：新增 `window.__debugTHREE`（暴露 `import * as THREE from 'three'` 的模块引用本身），供测试脚本读 `THREE.DoubleSide`/`THREE.FrontSide` 等常量跟 `material.side` 实测值做精确比对，不用在测试脚本里硬编码猜数字；跟既有 `__debugRaw`/`__debugMatInstances`/`__debugScene` 同一用途、同一暴露方式。

**实现记录（材质贴图上传/替换/移除，2026-08-05，#16）**：

- **UI 改造**：#8 阶段的「贴图 UV 变换」分区（`renderUvSlot`/`renderUvSection`）从「只渲染已有贴图的槽位」改成**5 个可能槽位（固色/金属粗糙/法线/AO/自发光）永远全部渲染**——不再有「此材质没有贴图槽，无 UV 变换可编辑」这种整体降级文案，因为现在任何槽位不管有没有贴图都能操作。每个槽位头部新增一行：缩略图（有贴图时显示，`.tex-thumb`；没有时显示占位 `.tex-thumb-empty`「无」）+「上传贴图」/「替换贴图」按钮（文案随该槽位当前有没有贴图切换）+「移除贴图」按钮（只在有贴图时出现）+ 一个隐藏的 `<input type=file accept="image/*">`。UV 变换滑动条组只在该槽位当前确实有贴图（`getUvDef(i,slotKey)` 非空）时才继续渲染在下方，没贴图的槽位只有上传按钮这一行，逻辑不变，只是不再决定整个 section 显示与否。
- **解码手法复用现成的 `createImageBitmap`**：不引入 `THREE.TextureLoader`/`new Image()` 等新方式——`buildTables()` 里贴图表异步解贴图尺寸本来就在用 `createImageBitmap(new Blob([...]))`，`uploadMatTexture()` 原样复用同一个浏览器 API（`createImageBitmap(file)`，`File` 本身就是合法的 `ImageBitmapSource`），解出的 `ImageBitmap` 直接作为 `new THREE.Texture(bitmap).image`。
- **glTF 贴图既有约定照抄，不是随手拍的默认值**：新贴图设 `flipY = false`（glTF 贴图 UV 原点跟 three.js 默认相反，`vendor/loaders/GLTFLoader.js` `loadTexture()` 里对所有 glTF 贴图都设这个）、`wrapS = wrapT = THREE.RepeatWrapping`（glTF 采样器默认环绕模式）；固色/自发光槽额外设 `colorSpace = THREE.SRGBColorSpace`（这两个槽位是给人眼看的颜色贴图，`assignTexture()` 调用处传的就是 `SRGBColorSpace`），金属粗糙/法线/AO 保持 three.js 默认（线性，不设置）——不这样处理的话，颜色贴图会在渲染管线里少一次 sRGB→linear 转换，看起来偏灰偏暗，跟原生 `GLTFLoader` 加载的贴图效果不一致。
- **金属粗糙槽踩过的坑照旧规避**：`slot.threeProps.forEach(p => { mat[p] = tex; })` 天然让 `metalnessMap`/`roughnessMap` 指向同一个新建 `Texture` 对象（不是分别 new 两份），沿用 #8 阶段发现的 `GLTFExporter.buildMetalRoughTexture()` 坑——两者不同引用会触发导出端的合并重打包。
- **UV 变换缓存（`uvTexCache`）联动**：新上传的贴图天然是独立对象（没有别的材质/槽位引用过），上传成功后直接 `uvTexCache.set(i+'|'+slotKey, tex)`，后续这个槽位的 UV 滑动条编辑（`ensureIndependentTexture`）不会再触发一次不必要的 clone。**替换贴图时如果原槽位已经设过 `KHR_texture_transform`，新贴图会继承同一份变换值**（读旧 `mapDef.extensions`，写进新 `mapDef` 再 `applyUvToThree()` 重新应用一遍到新 texture 实例）——语义上「这个槽位怎么摆贴图」跟「贴的是哪张图」是两件事，换图不应该把已经调好的位移/缩放/旋转清零。
- **写回链路（跟前面材质字段、UV 变换同一套模式）**：① three.js 侧——`matInstances.get(i)` 反查全部实际渲染实例，`slot.threeProps` 对应属性统一赋成新 `Texture`，`material.needsUpdate = true`；这是「另存为 GLB」（`GLTFExporter.processTexture()`/`processImage()` 读的是 `Texture.image`）和视口实时渲染的真正数据源。② raw 侧——`raw.images[]` 新增一条（`{name, mimeType, uri: <FileReader data URI>, _local:true, _dims, _bytes}`，`_` 前缀字段不进最终导出 JSON，只是内部簿记）、`raw.textures[]` 新增一条 `{source: imgIndex}`、`raw.materials[i]` 对应槽位（用新增的 `UV_SLOTS[].setJson` setter，跟已有的 `jsonPath` getter 成对）指向新纹理索引。`raw` 这边纯粹是为了材质表「贴图槽」摘要字段、贴图表二次渲染、注释 JSON 导出跟编辑状态保持一致，不是导出正确性关键——已经用真实样品验证过（见下方）。
- **移除贴图**：清空 three.js 材质对应属性（`= null`）+ `uvTexCache.delete()` + `raw` 侧用 `setJson(m, null)` 删掉 mapDef 引用。不删除 `raw.images`/`raw.textures` 里已经存在的条目本身——可能被其它槽位/材质共享，删除要处理索引位移，没必要（照抄原始 GLB 数据从不做垃圾回收的既有做法）。
- **贴图表联动**：#8 阶段贴图表构建逻辑（原来直接写在 `buildTables()` 里，含一次性的异步 `createImageBitmap` 解尺寸）抽成独立的 `rebuildTexTable()` 函数（跟 `rebuildNodeTable()` 同一个抽出理由：上传贴图改了 `raw.images`/`raw.textures` 后要重算这张表，不需要整个 `buildTables()` 重跑一遍）。新增模块级 `currentBuf`（原来 `buf` 只是 `buildTables()` 的局部参数，贴图表原本只在模型加载那一刻用一次，现在上传贴图会在会话中途触发 `rebuildTexTable()`，需要在模块作用域留一份原始 GLB 的 `ArrayBuffer` 供原始内嵌贴图的 bufferView 异步解码复用）。本工具自己上传的贴图行不需要这条异步路径——尺寸在 `uploadMatTexture()` 里 `createImageBitmap` 时就已经同步拿到，存在 `raw.images[i]._dims`，`rebuildTexTable()` 优先读这个字段。
- **验证**：真实样品 `chengdu-huagao-0801.glb`（17 材质、0 贴图，`_dev/test-texture-upload.js`）：
  - 材质 #16（浅绿盒子，#18 阶段视觉验证也用的这个材质，无遮挡正对默认机位）：baseColor 槽上传前按钮文案「上传贴图」+ 无缩略图；上传 `_dev/gen-test-textures.js` 生成的红白棋盘格测试图后按钮变「替换贴图」+ 缩略图非空 data URI；three.js `material.map` 实测 `{colorSpace:'srgb', flipY:false, w:32,h:32}`，`raw.materials[16].pbrMetallicRoughness.baseColorTexture.index` 正确指向新 `raw.textures[0]`→`raw.images[0]`（`name: test-tex-1-red.png`, `_dims: "32×32"`, 有 `uri`）
  - **发现并记录了一个真实样品本身的几何限制**：探测确认这份样品全部 21 个 primitive 都没有 `TEXCOORD_0`（本来就是不需要贴图的纯色 CAD 导出），上传贴图后因为没有 UV，视口只能看到贴图某个角落对应的单一纯色（肉眼看是换了个颜色，不是花纹），这是样品的几何限制不是功能问题。为了满足"肉眼确认贴图纹理/图案生效"这条验收标准，额外写了 `_dev/gen-empty-slot-gltf.js` 生成一个有真实 UV 展开、材质原本没有任何贴图槽的合成四边形（`_dev/test-empty-slot.glb`），上传同一张棋盘格图后截图对比：上传前是纯灰平面，上传后清晰的红白棋盘格图案铺满整个面（`_dev/shots/35-uvquad-before.png` / `36-uvquad-after.png`），证明贴图真的贴上去了、UV 采样正确
  - 「另存为 GLB」（`binary:true`）下载后手写 GLB chunk 解析（读 JSON chunk 长度定位 BIN chunk，不是只信 `uri`）：`materials[16].pbrMetallicRoughness.baseColorTexture` 存在且 `texCoord:0`；对应 `images[]` 条目**有 `bufferView`（63）、没有 `uri`**，证明贴图数据真的被 `GLTFExporter` 重新编码进了 BIN 二进制 chunk，不是外部文件引用；从 BIN chunk 里按 `bufferView.byteOffset`/`byteLength` 精确切出这段字节，头 8 字节匹配 PNG 魔数 `89 50 4E 47 0D 0A 1A 0A`，证明切出来的确实是完整有效的图片数据，不是碰巧长度对上
  - 替换测试：同一槽位第二次上传另一张图（绿黑棋盘格），three.js `material.map.uuid` 前后确认不同（不是叠加、也不是没生效），按钮文案仍是「替换贴图」
  - 移除测试：`material.map` 确认变回 `null`，按钮文案变回「上传贴图」，缩略图数量归零
  - 贴图表（「贴图」Tab）确认新上传的贴图出现在列表里，尺寸/格式/被用于字段都正确（`32×32`、`image/png`、`fallback Material·固色`）
  - 控制台全程 0 报错；额外重跑三个既有回归测试（`test-mat-editor.js`/`test-uv-editor.js`/`test-alpha-doubleside.js`）确认这轮改动没有破坏材质编辑/UV 变换/透明双面这几项已有功能——`test-uv-editor.js` 里几处"槽位数应为 1/2/0"的旧断言输出从这轮起会显示"实际 5"，这是 #16 把「贴图槽固定渲染 5 个」这个新设计带来的**预期行为变化**（旧断言写在 #8 阶段，当时槽位数等于「有贴图的槽位数」），不是回归——UV 变换本身的数值读写（offset/scale/rotation/独立 clone/导出 KHR_texture_transform 写回/还原）在同一次重跑里全部验证通过，跟这轮改动前逐字节一致
  - 测试脚本：`_dev/test-texture-upload.js`（Playwright，可重跑复查），依赖 `_dev/gen-test-textures.js`（生成两张对比测试图）和 `_dev/gen-empty-slot-gltf.js`（生成带 UV 的合成测试网格）

**实现记录（测量包围盒 OBB 创建/编辑/删除，2026-08-06，#9）**：

- **数据结构完全对齐 `Doc/EDITOR-VIEWER-CONTRACT.md` 第六节**：`BoundingBoxAnnotation { rotationDeg, size, center }`，直接挂在 `nodeAnno(name).bbox`（跟材质/贴图注释同一套 `anno` 对象），导出注释 JSON（`exportBtn`）自然带出去，没有另外发明字段名。契约文档原来 `center` 字段注释写"相对世界原点或相对测量基点，待定"——这次实现按世界原点计算（#10 测量基点系统还没做，没有基点可相对），已经回填契约文档改成确定的说法，不再留"待定"。
- **入口**：模型块树每行「操作」列新增第三个按钮「⬚ 包围盒」（跟已有的「⧉ Instance」「⇩ 导出」并排）。没有 bbox 时点击=按节点默认朝向生成一个并直接打开编辑面板；已有 bbox 时点击=直接打开编辑面板（不重新生成，不然每次点都把用户手动调过的旋转角冲掉）。已生成的节点，按钮描边/文字变成包围盒线框同一个青色（`.node-op.has-bbox`），列表扫一眼就知道哪些节点有包围盒。
- **OBB 算法**（`computeOBB(ni, rotationDeg)`）：给定一个旋转角 R（默认调 `getNodeWorldRotationDeg()`，读 `nodeWorldMatrix()` 分解出的节点**世界空间**旋转，不是节点自身 local 旋转——这点任务里特别强调过，容易和节点表「旋转 R°」列的含义搞混，这里两处用的是同一个世界旋转值，UI 上不会对不上）。三步标准手法：① 节点子树全部网格顶点（three.js 场景图里 `obj.traverse` 读 `BufferGeometry.attributes.position`，`applyMatrix4(o.matrixWorld)` 转到世界空间）再乘 R 的逆旋转（纯旋转矩阵，逆等于转置，没有平移分量，这一步是把整坨点云绕原点转回"跟 R 对齐"的局部空间，对不在原点的节点一样成立）；② 在这个对齐后的局部空间里求普通 AABB（min/max）——因为点云已经转到跟 R 同向的坐标系了，这里的 min/max 差值就是 OBB 沿着自己局部轴的真实尺寸；③ 局部 AABB 中心再乘回 R（正向旋转）变换回世界空间就是最终中心，尺寸不需要再变换（本来就是沿 OBB 自己局部轴的量纲，跟朝向无关）。分组节点：`obj.traverse` 天然遍历整个子树的全部网格，不需要额外处理"是不是分组节点"这个分支。
- **视觉**：`BoxGeometry`+`EdgesGeometry` 只画 12 条棱边（不是整个盒子的三角面线框化），`LineDashedMaterial` 虚线（`dashSize`/`gapSize` 按包围盒对角线量级自适应，避免小盒子上虚线段比盒子还长看起来像实线），只 `scene.add()` 不进 `model` 子树——跟节点选中框/材质高亮同一条"导出路径天然排除，不需要导出前清理"的路线。
- **颜色取舍**（对应任务要求"不要跟节点选中黄色框/场景整体金色边框/材质高亮混淆"）：`BBOX_COLOR = 0x39d6ff` 青色。这个青色是 2026-08-05 #25 之前节点选中曾经用过的颜色，那次改动把节点选中和材质高亮都统一成了黄色系（`#ffe600`），空出这个色相，这里复用，不是巧合撞色——跟节点选中黄实线框、材质高亮黄色叠加、场景整体金色边框（`0x6b5a3a`，偏暗偏土黄）四种视觉方式（线框虚线 vs 线框实线 vs 表面叠加发光 vs 线框实线）+ 颜色（青 vs 黄 vs 黄 vs 暗金）双重区分，肉眼不会混。
- **编辑**：面板（`#bboxOverlay`/`#bboxPanel`，跟材质清理菜单同一套"居中浮层"视觉模式）三个数值输入框（X/Y/Z 度数），改完 `onchange` 直接重新跑一遍 `computeOBB` 用新旋转角重算 size/center、`nodeAnno().bbox` 整体替换、`syncBBoxHelpers()` 重画线框（旧的先 `clearBBoxHelpers()` 清掉再画新的，不会叠加残留）。另有一个「重置为节点世界旋转角」按钮，把三个输入框都改回 `getNodeWorldRotationDeg()` 的当前值后直接调用同一个 `applyBBoxRotationFromInputs()` 立即重算写回，不需要用户再手动触发一次 change。
- **删除**：`delete nodeAnno(rec.name).bbox` + `syncBBoxHelpers()` 重画（该节点的线框自然消失，因为 `syncBBoxHelpers()` 只画 `anno.nodes` 里带 `bbox` 字段的节点）+ 节点行按钮 `has-bbox` 类摘掉。
- **持久化/生命周期**：`syncBBoxHelpers()` 在 `buildTables()` 末尾（模型加载/重新加载时）调用一次，把 `anno.nodes` 里所有带 `bbox` 的节点线框重新画出来（不止当前编辑面板打开的那一个——包围盒是持久注释数据的可视化，不是"选中态"，同一时间可以有多个节点各自挂着包围盒）；`resetHighlights()`（切模型时调用）里新增 `clearBBoxHelpers()`，避免残留指向已销毁旧 `model` 对象的野指针。
- **场景整体包围盒手动覆盖**（任务第 6 点）：新增 `anno.sceneBbox = { manual, size, center }`（`SceneBboxOverride`，字段定义补进了 `SPEC.md` 的 `Annotations` 接口）。「场景」Tab 新增一个 checkbox「手动指定」：勾选后原本只读的"包围盒尺寸"/"默认中心点"两行变成 6 个可编辑数字输入框（尺寸 XYZ + 中心 XYZ，单位米，不是原来只读展示用的 mm 整数格式，方便精确输入）；取消勾选后变回显示 `tables.sceneAutoBbox` 算出来的自动值，`manual` 字段本身仍留着上次手动填的 size/center（不清空，下次重新勾选不用从 0 开始）。`updateSceneBboxDisplay()` 按 `anno.sceneBbox.manual` 把 `tables.scene['包围盒尺寸']`/`['默认中心点']` 这两个导出用的字符串字段同步成当前生效值（自动或手动）——`exportBtn` 直接序列化 `tables.scene`，不会重新算一遍，所以这两个字符串必须在渲染/编辑的同时保持最新，不能只是 DOM 显示对了、导出 JSON 还是旧值。
- **验证**（真实样品 `chengdu-huagao-0801.glb`）：Playwright 脚本 `_dev/test-bbox.js`，全部 30 个断言 PASS：① 选中一个有非零世界旋转角的节点（`node_1`，`0.6°, -7.4°, 0.1°`），生成包围盒，验证线框对象确实在 `scene.children` 里且 `helper.quaternion` 跟节点世界旋转角一致（`angleTo` 误差 <1e-4 rad，不是简单轴对齐盒子）；② 手动改 Z 轴到 45°，验证 size/center 真的重新计算（数值前后不同）且线框数量仍然只有 1 个（没有叠加残留）；「重置为节点世界旋转角」验证 Z 读数确实从 45 变回原始值；③ 删除，验证线框数量归零、节点行按钮 `has-bbox` 类摘掉；④ 重新生成后导出注释 JSON，解析下载的文件确认该节点注释对象里 `bbox.rotationDeg`/`.size`/`.center` 都是长度 3 的数组，字段名跟契约文档一致；⑤ 场景整体包围盒：默认自动模式显示 mm 格式（如 `8000mm × 6519mm × 6124mm`），勾选「手动指定」填入 `尺寸X=12.345` `中心Y=3.21`，导出 JSON 确认 `scene['包围盒尺寸']` 含 `12345`（12.345m 换算 mm）、`scene['默认中心点']` 含 `3210`、`annotations.sceneBbox.manual === true`；取消勾选后确认场景表文本精确恢复成勾选前的原始自动值（不是残留手动值当只读展示）；⑥ 全程控制台 0 报错。截图：`_dev/shots/bbox-00-before.png` 到 `bbox-05-scene-manual-override.png`，线框视觉上贴着旋转过的文字网格倾斜对齐（不是正对世界坐标轴的直立盒子），肉眼可确认。
- **调试钩子**：`window.__debugBBox = { bboxHelpers, getNodeWorldRotationDeg, computeOBB, generateBBox, openBBoxPanel, syncBBoxHelpers }`，跟既有 `__debugRaw`/`__debugScene`/`__debugTHREE` 同一用途、同一暴露方式，供测试脚本直接读内部状态而不是只能猜 DOM。
- **上一轮代理留下的代码**：这次任务开始时发现 `index.html` 里这部分功能（UI 面板、CSS、`computeOBB`/`buildBBoxHelper`/`syncBBoxHelpers`/`generateBBox`/`openBBoxPanel` 等全部核心函数、场景整体包围盒手动覆盖的完整读写逻辑）已经写完，连测试脚本 `_dev/test-bbox.js` 和 6 张截图都已经生成，只是 `Doc/TODO.md`/`EDITOR-SPEC.md`/`EDITOR-VIEWER-CONTRACT.md`/`SPEC.md`/`README.md` 的文档同步没做完、`TODO.md` 里 #9 还是未勾选状态，应该是文档收尾阶段被打断。逐行读完这部分代码（算法注释详尽、边界条件处理到位——比如空分组节点没有网格顶点时 `computeOBB` 正确返回 `null` 并给状态栏+日志提示，不是静默失败）后判断**代码质量合格、直接复用，不重做**；重新完整跑了一遍 `_dev/test-bbox.js`（30 个断言全部 PASS，含控制台 0 报错），确认代码在当前 `index.html` 状态下仍然正确工作，然后只补上了文档同步这部分。

**实现记录（材质编辑器重构：贴图槽合并进属性行 + 折叠卷展栏 + 单一还原按钮，2026-08-06，#27）**：

用户反馈原来的布局「贴图槽和下方重复了」——固色/金属度/粗糙度/自发光这些属性在详情区上半段各占一行，贴图槽（含同样是这些属性对应的贴图版本）又在页面最下方整块独立重复一遍，两处看起来像是同一件事说了两次。这次把两者合并。

- **贴图槽合并规则**：`baseColor`（漫反射行）、`metallicRoughness`（金属度+粗糙度两行共享）、`emissive`（自发光颜色行）这三个 `UV_SLOTS` 条目**有对应的 flat 数值属性可以合并**，在那一行末尾加一个 `.tex-toggle` 图标按钮（`texToggleBtn(i, slotKey)`），点击展开/收起紧跟在这一行（或这一对 `.mf-row-pair`）下面的 `.tex-inline` 区块，内容就是原来的 `renderUvSlot(i, slot)`（缩略图+上传/替换/移除+UV变换滑动条），没有另外写一套精简版控件。`normal`/`occlusion` 两个槽位没有 flat 属性可以合并，放进一个默认收起的 `<details class="mat-more-tex">` 卷展栏，内容同样是 `renderUvSlot`，原生 `<details>` 处理显隐，内容一直在 DOM 里（不是"收起时不渲染"）。
- **展开状态**：新增模块级 `matUiExpand = new Map()`（key 是 `matIndex+'|'+slotKey`，含 `'extra'` 这个特殊 key 对应折叠卷展栏本身），跟 `uvTexCache` 一样在 `buildTables()` 里随新模型加载重置，不进 `anno`、不持久化。`isSlotExpanded(i, slotKey)` 是唯一的读取入口。**金属度/粗糙度两个入口按钮共享同一个 `matIndex+'|metallicRoughness'` key**——点开任一个都是同一份展开状态、同一份 `.tex-inline` 内容，因为 glTF 层面本来就是同一张贴图，UI 上拆成两行入口纯粹是方便从任一行发现，不能因为 UI 拆成两行就在数据层/状态层跟着拆成两份（这条正是任务原文提醒过的坑，#8 阶段 `metalnessMap`/`roughnessMap` 必须共享同一个 clone 那条也是同一个道理的另一种体现，这次没有重蹈覆辙）。
- **折叠卷展栏 `<details>` 状态持久**：原生 `toggle` 事件里把 `moreDetails.open` 写回 `matUiExpand.set(i+'|extra', ...)`，不是靠 JS 主动控制显隐——这样即使用户在其它字段编辑触发了整块 `renderTab('mat')` 重渲染（比如切走再切回同一个材质），卷展栏也能记住上次是展开还是收起，不会每次重渲染都被冲回默认收起状态。
- **"还原"按钮语义变更（关键设计决策）**：原来材质编辑区每个字段、每个 UV 变换字段各自有一个「⟲ 还原到原始值」按钮（`revertMatField`/`revertUvField`/`revertMaterialAll`，读 `tables.matOriginal[i]` 快照），这次**全部删除**，只在面板顶部（`.mdh` 里，跟标题同一行，`margin-left:auto` 推到最右）留一个「⟲ 还原 (N)」，N 是这个材质当前记录在案的可撤销步数，N=0 时 `disabled`。任务允许"可以循环调用 #22 撤销栈的撤销逻辑"，这次直接采用了这个思路，语义从"回到模型刚加载时的原始值"变成了"撤销这个材质最近的编辑步骤"——**这是一处需要用户知晓的设计取舍**：如果一个字段被编辑了很多次、又跟别的材质/节点操作交替进行，撤销栈（8 步深，全局共享）可能已经把这个材质更早的编辑步骤挤出去了，这种情况下点「还原」不会回到"最初始"的值，只会回到"撤销栈里还记得的最早一步"。选择这个方案而不是保留原来"精确回到加载时原始值"的方案，是因为任务原文明确提示了这个方向、且这样能天然获得"操作步数"这个数字（撤销栈条目数），不需要另外发明一套计数机制。
- **"操作步数"具体怎么数**：`pushUndo(label, undoFn, meta)` 新增第三个可选参数 `meta`，材质字段编辑（`commitMatFieldGesture`）、UV 变换（`commitUvFieldGesture`）、贴图上传/替换（`uploadMatTexture`）、贴图移除（`removeMatTexture`）、alphaMode/doubleSided 的 `onchange` 处理——这六类会推材质相关撤销记录的调用点，全部在调用 `pushUndo` 时传 `{ matIndex: i }`。`materialUndoIndices(i)` 扫一遍 `undoStack`，筛出 `e.meta && e.meta.matIndex === i` 的条目下标；`materialUndoCount(i)` 就是这个数组的长度，也就是按钮上显示的 N。材质清理菜单批量操作（`runMaterialCleanup`）、节点变换、创建 Instance、拖拽重新挂靠这几类 `pushUndo` 调用点都没有传 `meta`，天然不会被计入任何材质的步数——清理菜单是跨材质的批量操作，撤销它会牵连其它材质，不属于"这一个材质的编辑步骤"，符合任务要求的范围。
  - **开发过程中真实踩到的坑（不是假设的边界情况）**：第一版实现图省事，没有加 `meta` 参数，而是直接从 `pushUndo` 已有的 `label` 文本里做子串匹配（`label.includes('材质「'+name+'」')`，`Doc/TODO.md` 原文给的建议之一）。用真实样品 `chengdu-huagao-0801.glb` 测试时完全跑不对——这份样品全部 17 个材质原始名字都叫同一个「fallback Material」（V-Ray 导出的通病，`Doc/EDITOR-SPEC.md` §5 记录过，§10 `#13` 操作脚本重放系统那次任务已经在完全不同的场景下踩过一次同一个坑、当时靠 `target.atIndex` 兜底修复的）——按名字子串匹配会把全部 17 个材质的撤销记录当成同一个材质的，编辑材质 B 之后材质 A 的「还原 (N)」计数器也会跟着涨。发现后改成 `pushUndo` 显式传材质**索引**（`meta.matIndex`，数字，不受材质是否重名影响）而不是名字，问题精确修复。这次直接采用索引方案，代码里没有保留按名字匹配的版本。
- **"还原此材质"具体怎么只撤销这个材质相关的部分**：`revertMaterialSteps(i)` 循环调用——每次重新跑一遍 `materialUndoIndices(i)`（不缓存/不手动维护偏移量，栈最多 8 条，重新扫一遍代价可忽略），取数组最后一个（栈里最新的、还属于这个材质的一条）、`undoStack.splice(idx, 1)` 摘除、执行它的 `undoFn()`，直到再也筛不出属于这个材质的条目。**必须按时间倒序（从新到旧）**，不能反过来——同一个字段被连续编辑多次时，每条 `undoFn` 记的是"这一步编辑之前"的值，只有按时间倒序依次撤销才能正确一路退回最早状态，顺序反了会跳过中间值直接错位（这跟 #22 全局撤销栈"栈"这个数据结构本身的性质是一致的，`revertMaterialSteps` 只是在这个全局栈里做了一次"过滤后的多步弹栈"，没有引入新的撤销语义）。`splice` 摘除的只是命中材质的下标，栈里其它材质/节点的条目相对顺序不变，互不影响——这也是为什么真实测试里"材质 B 还原后，材质 A 的步数计数器丝毫不受影响"能够精确成立的原因。
- **UI 联动细节**：`updateMatRevertButton(i)` 在六类写回点各自的 `pushUndo` 之后手动调用，只 patch 这一个按钮的 `textContent`/`disabled`/`title`，不整块 `renderTab('mat')`（延续项目里"拖拽手势中不能整块重渲染，否则丢失滑块抓取"的既有约束）；全局撤销（`performUndo()`，状态栏「撤销」按钮/Ctrl+Z）如果弹出的条目恰好属于当前材质详情面板正打开的材质，也会同步调用 `updateMatRevertButton(selectedMat)`，两套撤销入口（材质面板内「还原」/ 全局状态栏「撤销」）共享同一个底层栈，不会互相显示不同步的数字。点「还原」按钮本身（`revertMaterialSteps` 内部可能改动 baseColor/金属度/粗糙度/自发光/透明/双面/贴图/UV 任意组合）之后走的是全量 `renderTab('mat')` 重渲染，因为点这个按钮不是拖拽手势，不存在丢失滑块抓取的顾虑。
- **验证**：真实样品 `chengdu-huagao-0801.glb`（17 材质，全部同名「fallback Material」，专门用来验证按索引不按名字这条关键正确性）+ `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`（20 材质、9 张真实贴图，验证已有贴图的槽位默认收起状态下入口按钮就带 `.has-tex` 圆点标记、点开直接显示已有贴图缩略图和"替换贴图"文案、点缩略图仍能触发 #26 大图预览）。核心场景全部覆盖：新布局截图（贴图入口内联、折叠卷展栏默认收起）；展开某属性的贴图入口→上传/替换贴图→视口 three.js 实例（`material.map`/`normalMap` 等）确认生效；金属度/粗糙度两个入口按钮 `.on` 状态同步、展开的是同一份 `.tex-inline`；连续编辑 baseColor 贴图/金属度/粗糙度/自发光/法线贴图五步，「还原 (N)」步数从 1 精确递增到 5；切到另一个材质独立编辑一步，两个材质的步数互不干扰（材质 B 编辑/还原时材质 A 仍然是 5 不受影响）；点「还原」后该材质贴图/数值精确回到编辑前状态、步数归零、按钮 `disabled`；#26 大图预览（画廊色块点击 + 详情贴图槽缩略图点击）两个入口都确认没有被这次重构破坏；控制台全程 0 报错。测试脚本：`_dev/test-todo27-matpanel.js`（真实样品，34 项断言全部 PASS）+ `_dev/test-todo27-realtex.js`（真实带贴图样品，8 项断言全部 PASS）。截图：`_dev/shots/todo27-00` 至 `todo27-08`（`09` 没生成——真实带贴图样品里没有法线贴图材质，测试脚本正确走了跳过分支，见该分支内的 console.log）。
- **既有回归测试重跑**：`_dev/test-uv-editor.js`/`_dev/test-texture-upload.js`/`_dev/test-mat-editor.js`/`_dev/test-alpha-doubleside.js`/`_dev/test-undo-status.js` 五个，全部重跑到 PASS（控制台 0 报错）。这几个脚本原样跑会在多处失败，逐一修复且如实记录改动原因，不是掩盖问题：① 这几个脚本原来直接 `page.click('.mat-card[data-matcard="N"]')` 选中材质，块视图下色块（`.swatch`）占卡片绝大部分面积，点击命中色块会连带触发 #26 大图预览弹窗挡住后续点击——这是 #26 完成时就已经记录在案的既有连带行为（`Doc/EDITOR-SPEC.md` §15），不是这次引入的新问题，改成点 `.mname`（材质名字文本）子元素规避；② baseColor/metallicRoughness/emissive 的上传/UV变换控件现在默认收起，需要先点 `.tex-toggle` 展开才能找到里面的 `input[type=file]`/`.uv-range` 等元素，这是这次改动**符合预期的行为变化**，测试脚本相应地在交互前加一次展开点击；③ 每字段独立还原按钮（`.mf-revert[data-revert=...]`/`.mf-revert[data-uvrevert=...]`）已经按设计整个移除，测试脚本里验证"还原"的部分相应改成点新的 `.mdRevertAll`（或者等价的状态栏 `#undoBtn` 全局撤销，两者共享同一个底层栈）；④ 顺手修了这几个脚本里更早就存在、跟这次改动无关的遗留问题——`#exportGlbBtn` 是 #21 就已经改成下拉菜单（`#saveGlbMenuBtn` → `#saveGlbLocalBtn`）的旧选择器，`Doc/TODO.md` #22/#25/#29 的实现记录都提过这个问题、当时都选择不顺手修，这次因为要让这几个脚本重新完整跑通，一并修掉了。`_dev/test-highlight.js`、`_dev/test-gallery-toolbar.js` 等其它同样受 #26 连带行为影响、但跟这次材质编辑器重构没有直接关系的脚本，如实记录同一个问题、这次没有逐个都修（超出这次任务范围）。

**实现记录（材质面板重排：详情上/列表下 + 说明收进 ⓘ 菜单，2026-08-07，#32）**：

对应 `Doc/2026-08-06-material-panel-redesign.html` §02——用户截图标注指出材质 Tab 跟模型块 Tab（#20 已经定的「选中详情常驻区 + 下方精简列表」骨架）顺序不一致，材质 Tab 还是老的「画廊在上、详情在下」，这次把它倒过来对齐。

- **开工前检查残留实现**：搜了「详情上」「列表下」「说明收进」「matHelp」等关键词，`index.html` 里全部无匹配，只有 #31（同一批任务里刚做完的图标尺寸/滑动条颜色）的改动，材质面板顺序/说明入口没有被动过，确认是干净状态、从零实现。
- **DOM/模板顺序真的对调，不是只挪 CSS**：`renderTab('mat')` 里原来 `el.innerHTML` 拼接顺序是「`.mat-toolbar`（含三行常驻说明文字）→ `.mat-gallery` → 条件性的 `renderMatDetail()`」，改成「`renderMatDetail()`/`renderMatDetailEmpty()`（永远渲染一个，不再是条件性为空）→ `.mat-toolbar`（瘦身后）→ `.mat-gallery`」——这是模板字符串本身的拼接顺序变了，不是靠 CSS `order`/`flex-direction: column-reverse` 这类只改视觉不改 DOM 顺序的取巧写法，Tab 键盘导航/读屏顺序跟着一起对了。`.mat-detail` 的 CSS 分隔线从 `border-top` 改成 `border-bottom`（原来是「贴着上面的画廊」，现在应该是「贴着下面的画廊」）。
- **说明文字收进 ⓘ 菜单**：原来常驻的「共N个材质·PBR金属度/粗糙度工作流·点击色块编辑·拖卡片到视口网格上可重新指派材质」这行字，从 `.mat-toolbar` 里整个删掉，工具条只留一行短的材质计数（`共 N 个材质` + 有 `KHR_materials_specular` 扩展时追加的「含高光扩展」提示，这条动态判断逻辑原样保留没有跟着文字一起丢）。完整说明挪进新的 `#matHelpOverlay`/`#matHelpPanel` 居中浮层——**照抄** `Doc/EDITOR-SPEC.md` §8（测量基点系统）已有的 `#bpHelpOverlay`/`#bpHelpPanel` 那套外壳（同样的 `position:fixed;inset:0` 背景 + 居中卡片 + 头部「标题+关闭按钮」+ 正文 `<p>` 段落），没有发明新组件，只是从 `bp-` 前缀换成 `mat-`/`matHelp` 命名、hover 强调色从 `--basepoint`（橙）换成材质 Tab 自己在用的 `--accent`（金）。触发入口是详情区标题栏（`.mdh`）右侧一个 `.mat-help-icon`（ⓘ 圆圈图标，14px，跟 `.bp-help-icon` 同一套视觉参数），`margin-left:auto` 让它不管旁边有没有「还原 (N)」按钮都能被独立推到这一行最右侧——不依赖 `.mdRevertAll` 已有的 `margin-left:auto` 规则，两者各自成立，DOM 顺序上 ⓘ 排在还原按钮前面，视觉上两个都贴在右边缘。浮层内容（材质是 PBR 工作流是什么/详情区编辑当前选中材质/画廊点击切换+拖拽指定材质/取色+清理按钮简述）参考旧的常驻说明文字扩写成四段完整句子，不是照抄原文一字不改。
- **空状态处理（原来没有考虑过的场景）**：原来的实现里，没有选中材质时（`selectedMat === null`，比如用户点画廊空白处主动取消选择——见 §6.1 后续改进 #25）`renderMatDetail()` 根本不会被调用，画廊上方是空的、原来常驻的说明文字兜底撑住了这块空间。这次说明文字挪进 ⓘ 之后，如果详情区完全为空会出现「常驻区没有任何内容、ⓘ 图标本身也消失」的问题——新增 `renderMatDetailEmpty()`，无论是「没有选中材质」还是「模型压根没有材质」都渲染一个 `.mat-detail.mat-detail-empty` 占位块，标题栏一样带 ⓘ（保证任何时候都能点开说明，不会因为没选材质就连帮助入口一起消失），正文是「点击下方色块查看/编辑材质」（真的没有材质时改成「此模型没有材质」）。**默认自动选中第一个材质的既有逻辑没有动**（`buildTables()` 里 `selectedMat = tables.mat.length ? 0 : null`，#7 阶段就有），只是确认了这次顺序对调之后它仍然生效——模型刚加载完，`selectedMat` 已经是 `0`，详情区第一次渲染直接走 `renderMatDetail()` 分支，不会经过空状态。
- **画廊工具栏/清理菜单入口功能不变，只是位置跟着画廊一起往下挪**：`.gallery-toolbar-actions`（视图切换/取色/清理三个按钮，#19/#4 做的）内部结构、`data-view`/`#matPickBtn`/`#cleanupBtn` 的 `onclick` 绑定代码一行没动，只是这个 `.mat-toolbar` 块在模板字符串里的位置从最上面挪到了 `renderMatDetail`/`renderMatDetailEmpty` 之后、`.mat-gallery` 之前——`el.querySelectorAll(...)`/`$('cleanupBtn')` 这些绑定代码是在 `el.innerHTML` 整体赋值完之后才执行的，不关心 DOM 内部先后顺序，天然不受这次调整影响。
- **验证**：Playwright + 真实样品 `画稿飞扬v2.glb`（Desktop 路径，20 材质），`_dev/test-todo32-matpanel-reorder.js`，28 项断言全 PASS：① 加载后详情区自动展开非空态、标题带材质名字（默认选中第一个材质的既有逻辑确认仍生效）；② **DOM 结构断言**——`#tables` 容器 `children` 数组里详情区元素下标精确小于画廊元素下标（不只是 `getBoundingClientRect().top` 视觉纵坐标更小，两种判据都测了，前者证明真的是 DOM 顺序变了不是只有 CSS 视觉顺序变了）；③ 工具条文字不再包含"点击色块编辑"/"拖卡片到视口"这些旧长文案，但仍然显示材质计数；④ ⓘ 图标存在，点击后浮层展开、内容含 PBR/拖拽/取色/清理四个关键词，点关闭按钮/点外部背景两种方式都能收起；⑤ 点画廊空白处取消选择后详情区变成空状态，空状态下 ⓘ 依然可点开浮层（新增的空状态兜底逻辑），重新点色块后详情区恢复正常；⑥ 视图切换/取色按钮/清理按钮三个入口都还在且可点，切列表视图 CSS 类正确切换，清理菜单浮层能正常打开关闭；⑦ 控制台全程 0 报错。另外单独起了一个改动前（`git show HEAD:index.html`，独立端口）的对照截图（`_dev/shots-32/99-BEFORE-mat-tables-cropped.png`，画廊在上 3 行说明文字 + 详情在下）跟改动后（`_dev/shots-32/01-mat-tables-cropped.png`，详情在上+ⓘ + 画廊在下）肉眼对比确认顺序真的反过来了。补充验证了取色/拖拽指定材质（#19）这两条画廊工具栏核心链路在重排之后仍然正确工作——`_dev/test-todo32-spotcheck-gallery-core.js`（9 项断言全 PASS）：真实鼠标点击视口网格进入取色模式后 `selectedMat` 精确切到命中材质、详情区（现在在最上面）标题同步跳转到命中材质名字；`assignMaterialToMesh`（拖拽指定材质最终调用的同一个函数）直接调用验证 `raw.meshes[].primitives[].material` 精确写回、three.js Mesh 实例的 `.material` 精确等于共享实例（不是新 clone）。
- **既有回归测试重跑（如实记录，含撞到的已知无关问题）**：`_dev/test-mat-editor.js` 全部通过（材质编辑写回/用于节点反查/GLB 导出核对，无异常输出）；`_dev/test-gallery-toolbar.js` 原样跑在「块/列表视图切换」「多色角标」两组断言全部 PASS 之后，撞上 #26/#27 已经记录在案、**跟这次改动无关**的既有问题（`page.click('.mat-card[data-matcard=...]')` 命中色块联带弹出大图预览挡住后续点击；贴图槽默认收起要先点 `.tex-toggle` 才能找到 `input[type=file]`），如实记录、这次没有顺手修（超出范围，前面已经确认过的部分 + 独立写的 spotcheck 脚本已经覆盖了核心链路）；`_dev/test-cleanup-menu.js` 原样跑撞上 #21/#22 已经记录在案的 `#exportGlbBtn` 旧选择器问题（下拉菜单化后应该先点 `#saveGlbMenuBtn` 再点 `#saveGlbLocalBtn`），临时打了个补丁副本（未提交进正式测试脚本）验证到底——25/26 条断言 PASS，唯一一条 FAIL 是读 `.node-detail .mf-row` 里「缩放 S」这个 `.mono` 文本节点，但 #29（2026-08-06）已经把节点详情区的 T/R/S 从只读文本改成了内联可编辑的 `input#ndSx` 等控件，这个选择器读不到东西完全在预期之内——**这条 FAIL 落在模型块 Tab（#36 的范围），不是材质 Tab，也不是这次 #32 引入的问题**，跟 a/b/c/d 四个清理选项本身的写回正确性（重命名、随机浅色、指定色、缩放归一化+GLB导出验证）无关，那几条断言全部 PASS。
- **测试脚本**：`_dev/test-todo32-matpanel-reorder.js`（主验证，28 项断言）、`_dev/test-todo32-spotcheck-gallery-core.js`（取色+拖拽指定材质核心链路补充验证，9 项断言）、`_dev/test-todo32-initial-load.js`（冷启动无模型时控制台 0 报错）。截图：`_dev/shots-32/00` 至 `04`，含改前对照图 `99-BEFORE-mat-tables-cropped.png`。
- **没有碰的部分（明确排除，如实记录）**：模型块 Tab（`.node-detail`）本身结构没有改动，虽然设计文档 §02 结尾提了一句「模型块 Tab 现在选中前的空状态提示也是一大段说明文字，可以顺手一起处理」，但 harness 任务描述明确写了「不改模型块 Tab（那是 #36 的范围）」，这次没有动它，`.node-detail-empty` 还是原来的「点一行查看详情」一句话（它本来就已经很短，不是三行说明文字那种问题）。贴图 Tab（#35）、场景 Tab（#37）、视口新工具条（#33/#34）均未涉及。

**实现记录（贴图 Tab 重建：详情上/列表下 + 删除/改名/替换 + 材质跳转联动，2026-08-07，#35）**：

对应 §17.1（口述需求原文）+ §17.4 决策记录第 4 点（贴图删除遇到多材质共享的确认方案）。跟 #32 材质面板重排同一个骨架规则（详情常驻区在上、精简列表在下），这次轮到贴图 Tab。

- **开工前检查残留实现**：搜了 `selectedTex`/`texHelp`/`tex-detail`/`renameTexImage` 等关键词，`index.html` 里全部无匹配，只有 #16/#26/#27 已经打好底子的既有函数（`UV_SLOTS`/`getSlotTexture`/`uploadMatTexture`/`removeMatTexture`/`rebuildTexTable`/`texRowThumbHtml`），贴图 Tab 本身的详情/列表结构、改名/删除、材质跳转联动都还是干净状态，确认从零实现，不是接手半成品。
- **DOM 拼接顺序真的对调，不是只改 CSS**：`renderTab('tex')` 从「一行说明文字 + 单张 8 列扁平表格」改成「`renderTexDetail()`/`renderTexDetailEmpty()`（永远渲染一个，模式跟 #32 的 `renderMatDetail`/`renderMatDetailEmpty` 一致）→ 一行短计数 → 精简成 3 列（缩略图/名称/尺寸）的列表」，模板字符串本身的拼接顺序变了。新增模块级 `selectedTex`（贴图表：当前选中的 `raw.images[]` 下标），`buildTables()` 里 `rebuildTexTable()` 之后跟 `selectedMat` 同一个理由默认选中第一张贴图（`tables.tex.length ? tables.tex[0].i : null`），详情区不留空。
- **详情区内容**：大图预览优先用「有材质当前引用它」这条路径（免解码，复用 `getSlotTexture(t.refs[0].mi, t.refs[0].slotKey)`，画质也最高），没有引用退回 `raw.images[i].uri`（本工具自己上传的贴图或原始 glTF 就是 uri 内嵌），都没有则显示「无法预览」占位（不现场做异步 BIN chunk 解码——那是大图预览弹窗 `openImagePreviewByIndex` 按需做的事，详情区缩略图走同步渲染路径）；格式/尺寸/体积三个只读字段直接读 `tables.tex` 里 `rebuildTexTable()` 已经算好的 `mime`/`dims`/`bytes`；「被哪些材质引用」chip 列表直接用 `t.refs`（`rebuildTexTable()` 里已经在算的 `{mi, slotKey}` 清单，没有另外反查一遍）。
- **说明文字收进 ⓘ 菜单**：原来常驻的「共N张贴图·支持格式...·点击缩略图查看大图」整行删掉，列表只留一行短计数「共 N 张贴图」；完整说明挪进 `#texHelpOverlay`/`#texHelpPanel`——**照抄** `#matHelpOverlay`（#32）/`#bpHelpOverlay`（§8）同一套「居中浮层+点关闭/点外部背景都能关」外壳，命名前缀换成 `tex-`/`texHelp`，没有发明新组件。空状态（`renderTexDetailEmpty()`，没有选中贴图或模型压根没有贴图）标题栏一样带 ⓘ，任何时候都点得到说明，跟 #32 的空状态处理是同一个理由。
- **改名**：新增 `renameTexImage(ti, newName)`，只改 `raw.images[i].name`（跟节点表「别名」同一套「简单文本输入框 + onchange 写回」模式）。这个字段是纯展示用途——`GLTFExporter.processImage()` 导出时会重新生成一个符合规范的 name，不读这份 `raw.images[i].name`，所以改这个字段不影响导出正确性，性质上更接近节点「别名」/材质「备注」这类纯展示字段，不是跟 baseColor 那种"改了会影响导出结果"的字段同一类。接入了全局撤销栈（`pushUndo`），**没有接入操作脚本重放系统（§10）**——脚本重放的 `target.kind` 目前只有 `'material'`/`'node'` 两种（`resolveScriptTargetIndex` 硬编码的二选一分支），贴图改名的目标是 `raw.images[]` 下标，要接入第三种 kind 需要同时改 `resolveScriptTargetIndex`/`scriptEntryDescribe` 等好几处代码，这次任务没有要求，如实记录这是范围内特意没做的部分，不是遗漏（对比之下下面「替换」「删除」复用的 `uploadMatTexture`/`removeMatTexture` 本来就是按材质索引记录脚本的，天然兼容现有系统，不需要扩展）。
- **替换**：新增 `replaceTexImageViaFirstRef(ti, file)`，入口是「选中的贴图索引」，通过 `t.refs[0]`（第一个引用它的材质+槽位）反查转发调用 `uploadMatTexture(ref.mi, ref.slotKey, file)`（#16 已有函数，一行没改，撤销/操作脚本记录天然是它内部已经在做的事）。**没有被任何材质引用时按钮 disabled**（`title`="未被引用，无法替换：这张贴图没有被任何材质使用，不知道要替换哪个材质槽位"）——因为 `uploadMatTexture` 签名是按"材质索引+槽位 key"设计的，一张孤儿贴图不知道该替换哪个槽位，这是任务原文点名要求处理的边界情况。**验证中发现的行为特性（不是 bug，如实记录）**：`uploadMatTexture` 内部总是新增一条 `raw.images`/`raw.textures` 记录再让材质槽位重新指向新索引，不是原地覆盖字节——所以从贴图 Tab 点「替换」之后，原来那张贴图会变成 0 引用的孤儿记录（列表行数 +1），继续留在列表里，符合项目一贯"不做索引位移垃圾回收"的既有原则，但意味着"替换"在贴图 Tab 的语境下观感上更像"新增一张替换用的贴图，旧的那张失去引用"，不是真正意义上的原地替换——这是复用 #16 既有函数的自然结果，没有为了让语义更贴合"替换"这个词而改写 `uploadMatTexture` 本身（改了会影响材质编辑器那边已经验证过的行为）。
- **删除**：新增 `openTexDeleteConfirm(ti)`/执行时机在确认按钮 onclick 里内联——先用 `t.refs` 列出全部引用清单（`refsSnapshot`，点击「删除」时立即拷贝一份快照，因为循环调用 `removeMatTexture` 时内部会重新 `rebuildTexTable()`，如果还在读同一个正在被改写的数组会有遍历错位风险），弹二次确认浮层（`#texDeleteOverlay`/`#texDeletePanel`，跟材质清理菜单/包围盒面板同一套「居中浮层」外壳，`.tex-delete-list` 逐行列出「材质名 · 槽位」）。**没有被任何材质引用时按钮同样 disabled**（`title`="此贴图未被任何材质引用，没有可清空的引用"）。确认后对 `refsSnapshot` 里每一处引用调用 `removeMatTexture(ref.mi, ref.slotKey)`（#16 已有函数，一行没改，`Doc/EDITOR-SPEC.md` §17.4 决策记录第 4 点"允许删，弹二次确认列出受影响材质"的方案在这里精确落地），循环结束后 `rebuildTexTable()` + `renderTab('tex')` 切回贴图 Tab——**必须手动切回**，因为 `removeMatTexture` 内部结尾硬编码 `renderTab('mat')`（#16 时代就有的既有行为，从材质编辑器调用时理所当然，从贴图 Tab 批量调用时需要在循环结束后自己切回来，这是复用现成函数时唯一需要注意的细节，函数本身没有改）。**如实记录一个直接复用带来的撤销粒度特性**：全局撤销栈（Ctrl+Z）里会因为这次批量删除出现 N 条独立的「移除贴图」记录（N = 受影响材质槽位数），不是单条原子撤销——要完整撤销这次删除需要连续撤销 N 次，这是直接复用 `removeMatTexture` 既有单槽位撤销粒度的自然结果，没有为了做成原子操作而重新包一层，符合任务"直接复用不重写"的要求。
- **从材质编辑器跳转到贴图（§17.1 第 1 点）**：`renderUvSlot(i, slot)` 缩略图/上传按钮那一行，新增 `getSlotImageIndex(i, slotKey)`（走 `mapDef.index -> raw.textures[].source` 这条 raw JSON 链，不是反查 three.js 实例——因为要拿到的是贴图 Tab 用的同一种"`raw.images[]` 下标"身份，才能跳过去精确选中定位），只在该槽位当前确实有贴图（`cur` 非空）时才渲染「⇥ 贴图」按钮。点击调用新增的 `jumpToTex(ti)`：选中 + 切到贴图 Tab + `requestAnimationFrame` 里滚动定位 + 闪烁详情区（复用材质画廊卡片 `.flash-card`/`matCardFlash` 这个现成的一次性强调动画）+ 闪烁对应列表行（复用节点树 `.flash-row` 这个现成动画）——跟 `jumpToNode`（§6.1）「跳转+定位+高亮」是同一类模式，这次贴图方向照抄，没有新发明交互。反方向（贴图详情「被哪些材质引用」chip 跳回材质 Tab）新增对称的 `jumpToMat(mi)`，同样复用 `.flash-card`。
- **验证**：本机开发服务器（`php -S 127.0.0.1:18244`）+ Playwright + 真实样品 `画稿飞扬v2.glb`（Desktop 路径，9 张贴图，任务指定样品），`_dev/test-todo35-textab.js`，43 项断言全部 PASS：① 详情区默认展开非空态，**DOM 结构断言**（`#tables` 容器 `children` 数组里详情区下标精确小于列表 `<table class="tex-list-table">` 的下标，不只是视觉纵坐标）、列表精简成 3 列、行数=9；② ⓘ 说明浮层开关两种方式（点关闭按钮/点外部背景）都验证；③ 点列表行选中，详情区标题同步切换；④ 详情区格式/尺寸/体积/改名输入框内容精确核对；⑤ 替换：找一张有引用的贴图上传新文件后 `raw.images` 长度精确 +1、通过 `matInstances` 反查确认 three.js 材质实例的 `.map` 贴图对象 `uuid` 确实变了（不是没生效也不是新建了同 uuid 对象）；⑥ 改名：`raw.images[i].name` 精确写回 + 列表行名称同步刷新 + 全局撤销按钮点击后精确回退到改名前的名字；⑦ 删除：确认框列出的受影响材质数量精确等于实际引用数（真实核对，不是读 UI 数字自证）、点「取消」验证引用数不变（没有误删）、点「确认删除」后验证 raw 层引用清空 + three.js 材质实例对应属性精确置 `null` + 自动切回贴图 Tab + 该贴图删除后替换/删除按钮均变 `disabled`；⑧ 从材质编辑器点「⇥ 贴图」跳转，验证切到贴图 Tab + 详情区选中的正是目标贴图（按名字精确核对）+ 对应列表行处于选中态；⑨ 贴图详情「被哪些材质引用」chip 反向跳转材质 Tab，验证材质详情区选中的正是目标材质；⑩ 未被引用贴图（前面替换/删除测试产生的孤儿记录）的替换/删除按钮均 `disabled` 且 `title` 文案精确核对；控制台全程 0 报错。截图：`_dev/shots-35/00` 至 `06`（含材质编辑器里「⇥ 贴图」按钮外观、贴图 Tab 详情区+列表整体布局、删除二次确认浮层、ⓘ 说明浮层、跳转命中后的闪烁高亮）。
- **既有回归测试重跑**：`_dev/test-texture-upload.js`（#16，贴图上传/替换/移除核心链路，含真实 GLB 导出 BIN chunk 校验）、`_dev/test-mat-editor.js`（#7，材质编辑写回+用于节点反查+导出核对）、`_dev/test-todo27-matpanel.js`（#27，贴图槽内联入口+还原步数机制）、`_dev/test-todo32-matpanel-reorder.js`+`_dev/test-todo32-spotcheck-gallery-core.js`（#32，材质面板顺序+取色/拖拽指定材质核心链路）全部重跑到位，逐字节对照均无异常输出，控制台全程 0 报错，确认这次改动没有破坏材质编辑器既有功能（贴图 Tab 这次唯一动到材质 Tab 侧的代码只有 `renderUvSlot` 新增的跳转按钮渲染 + 对应的一处 querySelectorAll 绑定，属于纯增量，不影响既有渲染/写回路径）。
- **调试钩子**：没有新增专用调试钩子——复用既有的 `window.__debugRaw`/`window.__debugMatInstances`（#7/#16 就有），测试脚本里贴图引用关系（`refs`）改用跟被测代码相同的算法在 Node 侧独立重新扫一遍 `raw.materials`/`raw.textures` 交叉核对（不信任 UI 上显示的数字自证），够用，没有必要为了这次任务单独暴露一个新的 `window.__debugTex`。
- **没有做的部分（明确排除，如实记录）**：改名/替换/删除没有接入 §10 操作脚本重放系统里的"新增 op 类型"（改名完全没接入脚本系统，原因见上；替换/删除因为复用 `uploadMatTexture`/`removeMatTexture` 天然带上了 `uploadTexture`/`removeTexture` 这两个已有 op，不需要新增）；贴图列表没有做批量多选删除（任务原文是"删除"单数，针对单张贴图设计）；孤儿贴图（0 引用）目前只能停留在列表里，没有做真正的物理清理/索引重排功能（跟 #16 移除贴图从不做垃圾回收是同一个既有原则，这次没有引入新行为）。

---

## 8. 测量基点系统（对应第 9、10 点）

- **基点**：`{ position: [x, y, z], zRotation: 角度 }`，用你说的 `r=30°` 这种写法表示朝向（只绕 Z 轴，即建筑平面图里常见的「方位角」，不是完整三轴旋转）
- 场景里可以创建/重新设置基点
- 每个节点可以在模型块表里关联一个基点编号——关联后，该节点的测量数值就相对这个基点计算，而不是相对场景原点
- **这条强依赖世界空间坐标计算**——跟上一版 TRS 世界空间修复是同一条技术路线的延续，复用现有的 `worldMatrix()` 工具函数（`index.html` 里已经有这个从上次修复留下的函数，可以直接复用/扩展）

**实现记录（2026-08-06，`Doc/TODO.md` #10）**：

- **数据结构**：基点 `{ name, position:[x,y,z], zRotation }`，存进场景级注释 `anno.basepoints`（数组，允许多个——不同楼层/分区各自一个基点，对齐任务原文"允许多个比较灵活"的要求）。节点关联存 `nodeAnno(name).basepointRef`（存基点的 `name` 字符串，不是数组下标——下标会因为中间插删别的基点错位）。数据结构定义补进了 `SPEC.md` 的 `Annotations` 接口（`MeasurementBasepoint` 类型）。
- **GLB 原生约定（这次任务要求直接定下来，不等确认）**：优先级 1 找场景里名字是 `Origin`/`_origin`/`origin`（大小写不敏感，`/^_?origin$/i`）的节点，命中就取它的世界空间 T 当 `position`、世界空间 Y 轴欧拉分量当 `zRotation`；真实样品 `chengdu-huagao-0801.glb` 没有这种命名节点，走优先级 2——用现有场景表已经在算的包围盒中心（`tables.sceneAutoBbox.center`）当 `position`，`zRotation` 默认 0；都没有（优先级 3）则由用户在「场景」Tab 的基点管理区域手动新增/编辑。回填进了 `Doc/EDITOR-VIEWER-CONTRACT.md` 第五节和第七节「待确认」第 2 条。
- **`zRotation` 绕哪根轴的取舍**：字段名字面沿用了「只绕 Z 轴」的说法，但本项目 glTF/three.js 场景是 **Y-up**（`preprocess()` 摆位逻辑、默认相机推荐值都拿 `size.y` 当高度轴）——「只绕竖直轴的朝向角」才是「建筑测量方位角」这个概念该对应的东西，绕水平轴转是「歪头」不是「朝向」。判断原始措辞是习惯了 CAD/BIM 常见 Z-up 坐标系随手写的。所以 `zRotation` 实际存取/可视化/参与计算的都是世界空间 **Y 轴**分量，字段名保留 `zRotation` 只是跟文档既有措辞保持字面一致，没有借这次改动顺手改字段名引发连锁修改。GLB 原生约定探测、可视化 gizmo、节点「相对基点坐标」计算，三处统一这套约定，内部不会一半按 Y 一半按 Z。详细理由见 `Doc/EDITOR-VIEWER-CONTRACT.md` 第五节「2026-08-06 回填」小节。
- **坐标系取舍**：`position`/节点世界坐标都复用 `nodeObjects`（three.js 场景图对象，`matrixWorld` 已含 `preprocess()` 里「模型整体缩放+落地居中」的归一化变换），不是复用 `nodeWorldMatrix()`（那份是 GLB 原始坐标系，没有归一化偏移/缩放，节点表 T/R/S 列用的是这份）。理由：包围盒（§7 后半 `computeOBB`）的 size/center 本来就是拿 `nodeObjects.matrixWorld` 算的，`EDITOR-VIEWER-CONTRACT.md` 第六节说 bbox.center「相对世界原点」指的就是这套归一化后的场景坐标系——基点要能跟 bbox.center 直接相减比较，必须用同一套坐标系，所以这里跟 `computeOBB`/`setNodeSelection` 一样用 `nodeObjects`。旋转分量在两套坐标系下是同一个值（`preprocess()` 只给 model 加了缩放和平移，没加旋转），所以 `getNodeWorldRotationDeg()` 复用 `nodeWorldMatrix()` 求旋转角不受影响、不冲突。
- **可视化标记**：橙色 `BASEPOINT_COLOR = 0xff9d2e`（跟节点选中/材质高亮黄 `#ffe600`、包围盒青 `#39d6ff`、场景整体金色边框都拉开色相，五种视觉元素互不混淆）。标记本体三件套：① `THREE.AxesHelper` 标准三轴参照（用户熟悉的 three.js 通用红=X绿=Y蓝=Z视觉语言）；② 沿局部 +X 的 `THREE.ArrowHelper`，橙色加粗强调"朝向"（AxesHelper 的 X 轴本身较细容易被忽略）；③ 贴地的半透明橙色圆环，标出 position 在地面的投影位置。整组只对外层 `Group` 做一次 Y 轴旋转（`zRotation`），只 `scene.add()` 不进 `model` 子树——跟节点选中框/材质高亮/包围盒线框同一条「导出路径天然排除，不需要导出前清理」的路线。标记尺寸按场景整体包围盒最大边长自适应（`basepointGizmoScale()`，夹在 [0.15, 2.5] 米），大场景标记看得见、小场景标记不会盖住模型。
- **场景 Tab UI**：新增「测量基点」管理区域，每个基点一行内联可编辑（名字/位置 XYZ/朝向°/删除按钮），不用像材质清理菜单那样搞「勾选+批量应用」的模式——基点数量通常很少（几个到十几个）。两个操作按钮：「＋ 新增基点」（手动新建，起点用场景包围盒中心方便新建完立刻在视口看见）、「⟲ 生成默认基点」（复用 `computeDefaultBasepoint()` 同一份探测逻辑重新按优先级 1/2 生成一条，追加不覆盖，方便跟手动改过的对比）。改名有级联：所有指向旧名字的 `nodeAnno().basepointRef` 同步改成新名字，撞名自动加数字后缀避重不静默覆盖。删除有级联：清掉所有指向被删基点的节点关联。
- **模型块树 UI**：节点表新增两列——「关联基点」（下拉框，选项含全部基点 + 「不关联，用默认」）、「相对基点坐标」（只读展示，`effectiveBasepointForNode()` 决定用哪个基点：显式关联就用那个，留空 fallback 到场景第一个基点，不显示"—"，这样每一行始终有一个直观数值，属于任务原文"留空=不关联/用场景默认基点"两种解释里选的后一种）。
- **相对基点坐标计算**：`computeRelativeToBasepoint(ni, bp)`——节点世界坐标（`nodeObjects.matrixWorld` 取 T）先减基点 `position`（平移对齐），再乘基点朝向的逆旋转（`Quaternion.setFromEuler(Euler(0, -zRotation, 0, 'XYZ'))`）——是「把点变换到 R 的局部空间」的标准做法，跟 `computeOBB` 里「乘 R 的逆旋转」对齐局部空间那一步同一个数学操作，只是换成基点的 T/R，没有另起一套变换逻辑，复用的正是任务要求的 `worldMatrix()`/`nodeObjects` 这条既有世界空间计算路线。
- **验证**（真实样品 `chengdu-huagao-0801.glb` + 手写合成 Origin 节点 glTF JSON）：Playwright 脚本 `_dev/test-basepoints.js`，全部断言 PASS：① 真实样品确认没有 `Origin`/`_origin`/`origin` 命名节点，默认基点精确等于 `tables.sceneAutoBbox.center`（优先级 2），视口截图确认橙色标记出现在包围盒中心；② 新建基点手动改 position/zRotation 后视口标记位置/朝向四元数跟着重算（跟 `zRotation` 对应的世界 Y 轴旋转角误差 <1e-6 rad），改名级联生效；③ 节点关联新基点后，「相对基点坐标」数值用**跟被测函数完全独立的手写三角公式**（不复用 `computeRelativeToBasepoint` 自己的实现）重新算一遍，逐分量精确匹配；另外验证了"基点直接设在节点世界坐标上、zRotation=0"这个简单场景下相对坐标精确为 `[0,0,0]`（平移抵消的手动验算）；取消关联后正确 fallback 到场景第一个基点，UI 列也确实渲染出带单位的数字，不是只有内部状态对；④ 手写合成 glTF JSON（含一个大小写混合的 `Origin` 命名节点，局部 T=[3,0.5,-2]、绕 Y 轴 30°）单独验证优先级 1：`findOriginNodeIdx()` 正确命中、`zRotation` 精确等于 30°（构造时写入的已知值）、`position` 通过独立反查该节点 `matrixWorld` 的另一条代码路径交叉核对完全一致（不是凑巧对上）；UI「⟲ 生成默认基点」按钮手动触发同样正确命中 Origin 节点、重名自动改成「默认基点2」；⑤ 导出注释 JSON 解析确认 `annotations.basepoints` 数组、`annotations.nodes[name].basepointRef` 字段都在，字段形状跟契约一致；⑥ 全程控制台 0 报错。测试脚本：`_dev/test-basepoints.js`（可重跑复查），依赖 `_dev/gen-origin-node-gltf.js`（生成合成 Origin 节点样品）。截图：`_dev/shots/bp-00` 到 `bp-04`。
- **调试钩子**：`window.__debugBasepoints = { findOriginNodeIdx, computeDefaultBasepoint, basepointByName, effectiveBasepointForNode, computeRelativeToBasepoint, addBasepoint, addAutoBasepoint, deleteBasepoint, updateBasepointName, updateBasepointPos, updateBasepointRot, syncBasepointHelpers, basepointHelpers, anno, tables, BASEPOINT_COLOR }`，跟既有 `__debugBBox`/`__debugCleanup` 同一用途、同一暴露方式。

**实现记录（2026-08-06，`Doc/TODO.md` #30，基点列表标注优先级来源 + 说明入口）**：

- **来源标注数据结构**：基点对象新增可选字段 `source`——自动生成的（`computeDefaultBasepoint()` 产出，不管是模型首次加载的种子还是「⟲ 生成默认基点」按钮追加的）带 `{ kind: 'auto', priority: 1|2, detail: string }`（`detail` 是给日志/调试用的补充说明，比如命中的具体节点名，UI 标注文案不直接用它，另有 `basepointSourceLabel()` 生成的固定文案）；用户点「＋新增基点」手动创建的带 `{ kind: 'manual' }`。这次任务之前保存的旧注释 JSON 里的基点没有 `source` 字段——加载时不回填、不去猜它当初是自动种子还是手动加的，`basepointSourceLabel()` 统一把它归进「手动创建」这一类展示（详见该函数注释：反正只有「自动/非自动」两类可标，旧数据没有优先级信息，落进"非自动"不会产生虚假的优先级文案，比瞎猜一个更安全）。`SPEC.md` 的 `MeasurementBasepoint` 接口未同步这个字段——`source` 是纯展示元数据，不参与任何坐标计算，也不是 viewer 端需要读的东西，判断不需要动契约。
- **标注文案**：`basepointSourceLabel(bp)` 统一生成——优先级 1 显示「默认（优先级1：GLB内Origin节点）」、优先级 2 显示「默认（优先级2：包围盒中心，未找到Origin节点）」，都带橙色（`--basepoint`）边框徽标样式（`.bp-source-tag.auto`）；手动创建显示「手动创建」，纯灰色小字不带醒目边框（`.bp-source-tag.manual`，其实就是不加任何强调色，继承 `.bp-source-tag` 基础样式）——两种视觉上一眼能分辨（有色边框徽标 vs 纯文字），不用逐字读文案。标签是 `.bp-row` 内第一个 flex 子元素，靠 `flex:0 0 100%` 让它在原本 `flex-wrap` 的一行输入框上方独占一行，没有另外包一层容器、没有改动 `.bp-row` 现有的 flex 布局逻辑。
- **说明入口**：「测量基点」标题栏右侧新增 ⓘ 图标按钮（`#bpHelpBtn`），点击弹出跟设置面板/材质清理菜单同一套「居中浮层 + 点外部背景关闭 + 关闭按钮」外壳（`#bpHelpOverlay`/`#bpHelpPanel`）。内容是浓缩过的说明文字（4 句话讲清楚基点是干什么用的 + 三级优先级怎么运作 + 列表标签怎么看），**不是照抄 `EDITOR-VIEWER-CONTRACT.md` 第五节的技术性措辞**（那份文档写给开发者看，讲了坐标系细节/字段名历史沿革这些用户不需要关心的东西）。静态内容不用像设置面板那样「打开时动态渲染」，直接写死在 HTML 里，一次性事件绑定（跟 `#settingsOverlay` 一次性绑定同一处代码块），只有触发按钮 `#bpHelpBtn` 本身在「场景」Tab 每次 `renderTab('scene')` 时要重新绑定（这个按钮是动态渲染内容的一部分）。
- **验证**（真实样品 `chengdu-huagao-0801.glb` + 复用 #10 阶段的合成 Origin 节点 glTF）：Playwright 脚本 `_dev/test-todo30-basepoint-source.js`，21 项断言全 PASS：① 真实样品默认基点 `source.kind==='auto'`/`priority===2`，`basepointSourceLabel()` 输出文案精确匹配，UI 上 `.bp-source-tag` 文字/样式类跟函数输出逐一核对一致；② 点 ⓘ 图标弹窗展开，说明文字含「Origin」「包围盒」两个关键规则点、长度 293 字符（验证过是浓缩版本，不是照抄整段文档），点外部背景关闭、点「关闭」按钮关闭两种方式都测到；③ 新增手动基点 `source.kind==='manual'`，标注「手动创建」，UI 样式类跟自动基点的 `auto` 类不同；④ 合成 Origin 节点样品验证优先级 1 分支标注「默认（优先级1：GLB内Origin节点）」精确匹配、`source.detail` 里带了命中的具体节点名；⑤ 全程控制台 0 报错。另重跑既有回归测试 `_dev/test-basepoints.js`（#10 阶段验证脚本，含新的 `source` 字段后全部既有断言依然 PASS，没有破坏原有基点增删改/视口标记/相对坐标计算/导出 JSON 功能）。测试脚本：`_dev/test-todo30-basepoint-source.js`（可重跑复查，复用 `_dev/gen-origin-node-gltf.js`/`_dev/test-origin-node.glb`）。截图：`_dev/shots/todo30-00` 至 `todo30-04`。`window.__debugBasepoints` 新增 `basepointSourceLabel` 供测试脚本调用。

**实现记录（2026-08-07，`Doc/TODO.md` #37，场景 Tab 重排：元数据卷展栏 + 包围盒/基准点独立分区 + 基点优先级下拉，§17.3 + §17.4 决策记录第5点）**：

**开工前检查**：先搜了一遍 `resolvedReason`/`defaultBasepointName`/`scene-section`/`bp-default-sel` 等关键词，`index.html`/`Doc/TODO.md` #37 都没有任何残留实现痕迹，确认是干净状态，从零实现（任务描述里提到之前一次代理中途被打断，排查结论一致：没有留下代码）。

**1. 元数据卷展栏**：`.tt` + `.kv` 那段常驻展示的字段（文件名/文件大小/gLTF 版本/生成器/材质贴图网格块统计/顶点三角面/扩展使用/扩展必需/动画蒙皮/光照/边缘框/默认相机）整段包进原生 `<details class="scene-meta-details" id="sceneMetaDetails">`——跟 #27 `.mat-more-tex`（法线/AO 贴图槽折叠）同一个模式，`▸`/`▾` 箭头随 `[open]` 属性切换纯 CSS 做，不用 JS 手写切 class。默认折叠（`sceneMetaExpanded` 模块级变量初值 `false`），`toggle` 事件监听把开合状态写回这个变量，下次因为其它字段编辑（比如新增基点）触发的 `renderTab('scene')` 能保持用户上次的选择，不会每次重渲染都被冲回默认折叠——完全复用 #27 处理 `matUiExpand` 那段"重渲染不冲状态"的手法，这次是场景 Tab 单一开关，不需要 `Map`，一个布尔变量够用。折叠时标题行摘要 `sceneMetaSummary()`：`glTF <版本号> · <N 个扩展 / 无扩展> · <N 个动画 / 无动画>`，三段信息按 `tables.scene` 当前数据现算（不是写死字符串），选这三项是因为它们是"一眼判断这个 GLB 大概什么状态"最核心的几项（版本号决定格式兼容性、扩展决定查看器兼不兼容、动画决定要不要接播放逻辑），文件名/大小已经在头部 `#fileInfo` 常驻显示，不需要在摘要里重复。真实样品验证摘要精确等于 `glTF 2.0 · 无扩展 · 无动画`。

**2. 包围盒独立分区**：原有的"手动指定/自动计算"开关 + 尺寸/中心点数值渲染逻辑一行没改，外面套一层 `.scene-section`（新 CSS 类，`border:1px solid var(--line)` + `margin` 跟上下内容分开），标题栏 `.scene-section-head` 显示「包围盒」+ 右侧「已手动覆盖/自动计算」状态文字（原来是 `.tt` 标题行文字里带的后缀，这次挪进分区标题栏）。

**3. 基准点独立分区**：基点列表/新增按钮/生成默认按钮同样套 `.scene-section`。原来行内的 `.bp-source-tag`（橙色来源标签）整个移除（渲染层面，函数 `basepointSourceLabel()` 本身没删，供调试/`window.__debugBasepoints` 保留），原来场景 Tab 里那段常驻的「GLB 原生约定：场景里有 Origin/_origin/origin 命名节点……」长文字说明也整段挪进 `#bpHelpOverlay` 说明弹窗（#30 已有的 ⓘ 图标+居中浮层组件，这次追加了内容，外壳没改）——弹窗因此从 293 字符涨到 603 字符，仍然是"浓缩过的说明"，不是照抄 `EDITOR-VIEWER-CONTRACT.md` 第五节的技术性原文（那份文档单第五节就有上千字，讲了坐标系/字段名历史沿革这些用户不需要关心的细节）。

**4. 基点优先级下拉（核心新功能）——两层"优先级"要分清楚，这是整个任务最容易搞混的地方**：
- **第一层（GLB 原生约定的三级优先级，#10/#30 已有，这次没改）**：决定"首次加载模型时怎么算出一颗默认基点种子"——① 场景里有 `Origin`/`_origin`/`origin` 命名节点 → 取它的 T/朝向；② 没有 → 用包围盒中心、朝向 0°；③ 都没有 → 用户手动新增。这套规则只在**模型第一次加载**（`old.basepoints` 不存在）或用户手动点「⟲ 生成默认基点」时触发一次，产出的基点带 `source: {kind, priority}` 标注自己是怎么来的，是**过去时**、写一次就不再变。
- **第二层（这次新增的"谁是当前默认基点"）**：`anno.defaultBasepointName`（`string | null`）+ `resolveDefaultBasepoint()`——决定"没有显式关联具体基点的节点，相对测量数值该用哪一个基点算"这个问题，是**现在时**、随时可能因为用户操作而改变。规则：① `defaultBasepointName` 有值且指向的基点还存在 → 用这个（用户手动指定，最高优先级，"用户有最终决定权"）；② 否则退回 `basepoints[0]`（数组第一个，#37 之前硬编码的老规则，保持向后兼容）。`effectiveBasepointForNode()`（节点相对基点坐标 fallback 用的那个函数，#8/#20/#34 三处 UI 共用）从直接读 `basepoints[0]` 改成读 `resolveDefaultBasepoint().bp`，函数内部没有手动指定时自己退回 `basepoints[0]`，所以 #37 之前"留空关联 fallback 到场景第一个基点"这条行为对没有用过这个新功能的用户完全不变（`_dev/test-basepoints.js` 全部既有断言不改代码直接复用，PASS）。
- **UI**：每个基点行右侧一个 `<select class="bp-default-sel" data-bpdefault="${bi}">`，两个选项——`非默认基点` / 动态文案（是当前默认时显示 `✓ 当前默认（<resolvedReason 文案>）`，不是时显示 `设为默认基点`）。选中值本身就编码了"这一行现在是不是默认、如果是靠哪条规则"，不需要另外的文字提示。`is-default` CSS 类给当前默认那一行的下拉框橙色描边，一眼能看出场景里哪个基点在生效。选「设为默认基点」调 `setDefaultBasepoint(idx)`，选回「非默认基点」调 `clearDefaultBasepointOverride()`（只有这一个入口能清空 `defaultBasepointName`，回到自动规则）。
- **验证（真实样品 `画稿飞扬v2.glb`）**：新增第二个基点、手动改 position 让它跟第一个明显不同、下拉菜单选「设为默认基点」——`anno.defaultBasepointName` 精确写入新基点名字，`resolveDefaultBasepoint()` 返回新基点；挑一个未显式关联基点的节点，`effectiveBasepointForNode()` 在设默认前后分别 fallback 到不同的基点，`computeRelativeToBasepoint()` 算出的相对坐标确实不同（不是凑巧一样），证明"关联该基点的节点相对坐标计算跟着变"这条真的生效，不是只有 `defaultBasepointName` 这个内部字段变了但没有下游影响。

**5. 基点操作接入撤销栈（§13）+ 操作脚本（§10）——这是这次任务里工作量最大的一块，第 3 点提到的"技术复杂度较高"主要指这里**：

**接入点选择**：严格照抄 §13/§10 已有六类调用点的模式——记录挂在"用户操作入口"函数体内（`addBasepoint`/`addAutoBasepoint`/`addBasepointAtNode`/`deleteBasepoint`/`updateBasepointName`/`updateBasepointPos`/`updateBasepointRot`/`setDefaultBasepoint`/`clearDefaultBasepointOverride` 这九个函数），不挂在被多处共用的底层写回帮手 `createBasepoint()` 内部。原因是一个真实的设计考量，不是随手选的：`createBasepoint()` 被 `addBasepoint`/`addAutoBasepoint`/`addBasepointAtNode`（#34 中心点面板"以当前节点位置新建基点"）三处以不同的"完整操作语义"共用，`addBasepointAtNode` 除了创建基点还要多做一步"关联到当前节点"——如果撤销/脚本记录挂在 `createBasepoint()` 内部，这一步关联就没人记，撤销会把基点删了但留下一个指向已删除基点名字的死引用（真实会发生的 bug，不是假设）。所以撤销/脚本记录放在三个"操作入口"各自的函数体里，`addBasepointAtNode` 把"创建+关联"打包成**一条**撤销记录，撤销时把两步一起精确复原——这跟 §10 "材质清理菜单三个子操作合并成一条撤销记录"是同一个设计原则（一次用户手势产生的复合操作，撤销/脚本也应该是一条，不是拆散成好几条让用户自己收拾）。

**`updateBasepointPos`/`updateBasepointRot`/`updateBasepointName` 是「场景」Tab 基点行 + #34 中心点面板四个输入框共用的同一份写回函数**（§19.7 已经确认过这一点），这次撤销/脚本记录只在这一处接入，两个 UI 入口自然都享受到——这也是任务要求里明确点出的"不需要在#34那边重复接入"，实测 `_dev/test-todo34-actions.js`（#34 回归测试）50 项断言全 PASS，中心点面板的新建/关联/编辑功能完全没受影响。

**撤销粒度**：每个数字输入框/文字输入框单独一次 `onchange`（失焦提交）算一步，跟 §13 记录的"节点移动/旋转编辑"、"UV变换"同一个粒度规则；`updateBasepointPos`/`updateBasepointRot` 新增了"数值没变就不产生空的撤销/脚本记录"的短路判断（`oldValue === newValue` 直接 `refreshBasepointUI()` 返回，不推撤销栈也不 `recordScriptOp`）——避免用户点了输入框又没改数值直接失焦时，撤销栈/脚本被无意义的空记录污染。

**级联恢复（撤销时最容易漏的地方）**：
- `deleteBasepoint(idx)`：删除前拍三份快照——被删基点的深拷贝（`JSON.parse(JSON.stringify(bp))`）、所有 `basepointRef` 指向它的节点名单、它删除前是不是 `defaultBasepointName`；撤销时三者一起精确恢复（`splice` 插回原索引位置、逐个节点 `basepointRef` 写回、`defaultBasepointName` 写回）。原本已有的"删除级联清空节点关联"这一步这次**新增**了第二条级联："如果被删的基点正好是当前手动指定的默认基点，`defaultBasepointName` 也要清空"，不然会留下一个指向已删除基点名字的死引用（`resolveDefaultBasepoint()` 内部虽然有 `basepointByName(anno.defaultBasepointName)` 找不到时退回 `basepoints[0]` 的兜底，不会崩，但 `defaultBasepointName` 这个字段本身会一直脏着指向不存在的名字，直到下次手动清空——数据层面不干净，这次一并修了）。
- `updateBasepointName(idx, newName)`：改名要做的级联从"节点 `basepointRef` 同步改名"扩展到第三种——`defaultBasepointName` 如果正好指向这个基点的旧名字，也要同步改成新名字，不然"手动指定的默认基点"这个覆盖会在改名后失效（`resolveDefaultBasepoint()` 找不到旧名字对应的基点，退回自动规则），产品行为上是个真实 bug，不是理论边界情况。撤销时这条级联也要精确逆转（改名前是这个基点当默认才需要恢复，不是这个基点当默认时不碰 `defaultBasepointName`）。

**脚本记录格式**（跟 §10 已有格式风格一致，`op` 是能看懂的字符串，粒度跟撤销栈对齐）：`addBasepoint`（`target:null`，`params:{position,zRotation,namePrefix,source,associateNodeTarget?}`——`associateNodeTarget` 只有 `addBasepointAtNode` 这个入口会带）、`addAutoBasepoint`（`target:null`，`params:{}`——重放时不是回放记录下来的旧值，是重新调用一遍 GLB 原生约定探测逻辑，是"用新数据重新跑一遍逻辑"的语义，跟 `cleanupBatch` 同一种设计取舍，因为这个操作本来的意义就是"3ds Max 重新导出后可能有了新的 Origin 节点，重新探测一次"）、`deleteBasepoint`/`renameBasepoint`/`setBasepointPos`/`setBasepointRot`/`setDefaultBasepoint`（`target:{byName,kind:'basepoint',atIndex}`，`ScriptTarget.kind` 新增第三种取值 `'basepoint'`，`SPEC.md` 同步）、`clearDefaultBasepoint`（`target:null`，全局状态清空，没有单一目标）。`resolveScriptTargetIndex()`/`findBasepointIndexByName()`/`computeScriptMissingGroups()`/`currentTargetNameOptions()`/`renderScriptReplayPanel()`（差异梳理 UI）全部泛化成同时支持三种 `kind`（原来只有 `'node'|'material'` 两种），新增 `scriptKindLabel(kind)` 统一三处重复的"按 kind 转中文标签"逻辑。`executeScriptOp()` 新增八个 `case` 分支，全部直接调用对应的操作入口函数本身（`createBasepoint`/`addAutoBasepoint`/`deleteBasepoint`/`updateBasepointName`/`updateBasepointPos`/`updateBasepointRot`/`setDefaultBasepoint`/`clearDefaultBasepointOverride`），`scriptReplaying` 标记包一层防止重放动作自己又被录进脚本——跟既有 `createInstance`/`runMaterialCleanup` 等四个函数同一个防重复录制模式，没有另起一套"重放专用"写回逻辑。

**验证**（`_dev/test-todo37-scenetab.js`，真实样品 `画稿飞扬v2.glb`，82 项断言全 PASS）：新增/编辑位置/编辑朝向/改名/设默认/删除六类操作逐一验证——① 撤销：每类操作后立即调 `window.__debugUndo.performUndo()`，`anno.basepoints`（含改名/删除的级联部分：节点 `basepointRef`、`defaultBasepointName`）精确恢复到操作前状态，逐字段核对不是只看"数量对不对"；② 脚本：每类操作后 `anno.script` 精确 +1（不多不少），`op`/`target`/`params` 逐字段核对（不是只看长度），撤销**不会**删除/新增脚本记录（脚本是永久历史，六类操作全部验证完之后脚本总长度精确等于"初始长度+6"）；③ 重放能重现：单独构造一段"新增→改名→改位置→改朝向→设默认"的干净序列（5 类操作、7 条脚本记录），记下这个基点的最终状态（position/zRotation/`defaultBasepointName`），手动把这个基点从 `anno.basepoints` 里删掉、`defaultBasepointName` 清空（模拟"这段脚本还没被应用过"，`anno.script` 本身不动），调用 `runScriptReplay(new Set())`（跟差异梳理面板"没有缺失目标"分支走的是完全相同的重放执行代码路径），重放后的基点 position/zRotation/`defaultBasepointName` 跟回滚前记录的值逐分量精确核对一致，且重放本身不会往 `anno.script` 里追加新记录（`scriptReplaying` 标记生效，脚本长度重放前后不变）。

**6. 导出 JSON 的 `resolvedReason` 字段——判断逻辑 + 关键边界情况**：

`buildExportJson()` 导出时给每个基点算一个 `resolvedReason`（浅拷贝加字段，不写回 `anno.basepoints[]` 本身，不持久化——"导出时算出来，不是用户填的表单字段"，这条任务要求逐字落实）。四选一（不是任务描述字面说的"三选一"，如实记录这个跟字面描述的差异，理由见下面）：

- `"GLB原生Origin节点"` / `"场景包围盒中心（兜底）"` / `"用户手动指定为默认"`——这三个精确对应任务要求的三个字符串，只用在**当前正在生效的那一个默认基点**身上（`resolveDefaultBasepoint()` 判定出的那一个，全局唯一）。
- `"非当前默认基点"`——第四种，用在场景里其它全部**不是**当前默认的基点身上。**这是实现过程中必须做出的一个判断，不是漏看了任务要求**：任务允许多基点（决策记录第5点第一条确认过），但"全局默认基点"这个概念定义上只有一个；给不是默认的基点也强行套上"GLB原生Origin节点"这三个选项之一，是在编造一个不成立的结论（比如一个基点自己的 `source.priority===1`，但它已经不是当前默认了，还标"GLB原生Origin节点"会让人误以为它还在生效）。所以做了"是不是当前默认"和"这个基点自己是怎么来的（`source` 字段）"两件事分开的判断，`resolvedReason` 回答的始终是前者。

**关键边界情况（报告里明确要求讲清楚的那条）**：GLB 场景里确实有原生 Origin 节点，模型第一次加载时 `computeDefaultBasepoint()` 已经把它探测出来种进 `basepoints[0]`（`source.priority===1`，这是**过去时**的历史事实，此后不会再变）；用户后来在**另一个**基点（比如手动新增的"二楼基点"）的下拉菜单里选了「设为默认基点」——此时 `anno.defaultBasepointName` 指向"二楼基点"，`resolveDefaultBasepoint()` 按"手动指定优先级最高"直接返回"二楼基点"，不再看 `basepoints[0]` 的 `source` 是什么。结果：
- 「二楼基点」`resolvedReason = "用户手动指定为默认"`；
- 那个原本靠 Origin 节点探测出来的 `basepoints[0]`，**它自己的 `source.priority` 仍然是 1**（这个字段本身没有、也不应该被这次"设默认"操作改动——它确实还是靠 Origin 节点探测出来的，这个历史事实不会因为不再是默认而改变），但它的 `resolvedReason` 变成 `"非当前默认基点"`——**不会**因为自己 `source` 字段没变就误报成还在生效成默认，那样会让读导出 JSON 的人（或者以后接 viewer 端的开发者）误以为查看器应该继续把它当默认基点用。

这正是"用户有最终决定权"这条决策要求在数据层面的准确体现：手动指定完全覆盖 GLB 原生探测结果，不是两者取某种折中，也不是"标记两个都是默认之一"这种模糊处理。验证：用合成 Origin 节点样品（`_dev/test-origin-node.glb`）复现这个精确场景——① 没有手动覆盖时 `basepointResolvedReasonForIndex(0)` 精确等于 `"GLB原生Origin节点"`；② 新增第二个基点手动设默认后，第一个基点（Origin 节点那个）的 `source.priority` 用独立读取核对**确实还是 1**（证明这次操作没有偷偷改动它），但 `resolvedReason` 变成 `"非当前默认基点"`；③ 导出 JSON 解析，两个基点各自的 `resolvedReason` 用**跟被测函数完全独立**的判断逻辑在测试脚本里重新写一遍（不调用 `basepointResolvedReasonForIndex`，直接读 `annotations.defaultBasepointName` + 每个基点自己的 `name`/`source` 字段现场判断）逐一核对精确一致。

**这次没做的部分（如实列出）**：视口橙色基点标记目前不区分"是不是当前默认"（`buildBasepointHelper()` 一行没改，全部基点标记视觉上完全一样），只有场景 Tab 下拉框的 `.is-default` 橙色描边能看出哪个是默认——如果以后需要在 3D 视口里也能一眼分辨默认基点（比如给默认基点的标记加个额外的视觉强调），需要另外扩展 `buildBasepointHelper()`，这次任务范围内没有做，属于任务描述之外的锦上添花，没有被要求。

**测试脚本**：`_dev/test-todo37-scenetab.js`（Playwright，82 项断言全 PASS，可重跑复查，覆盖上面①-⑥ 全部验收点）。另更新了 `_dev/test-todo30-basepoint-source.js`（原来断言读 `.bp-source-tag`，这次这个元素不再渲染，改成读新的 `.bp-default-sel` 下拉框当前选中项文字；说明弹窗长度阈值从 500 字符放宽到 900——弹窗内容这次任务要求变长了，不是意外膨胀），更新后全部断言仍然 PASS。重跑既有回归测试：`_dev/test-basepoints.js`（全 PASS，`effectiveBasepointForNode` 改用 `resolveDefaultBasepoint()` 后 fallback 行为对没用过新功能的用户完全不变）、`_dev/test-bbox.js`（全 PASS，包围盒分区改动只是套了个外壳，功能代码没动）、`_dev/test-undo-status.js`（29 项全 PASS）、`_dev/test-todo34-actions.js`（50 项全 PASS，中心点面板不受影响）。`_dev/test-script-replay.js` 重跑时在"点击材质卡片打开清理菜单"这一步撞上一个**跟这次改动完全无关的预置问题**——`#texPreviewOverlay`（#26 大图预览弹窗，点材质色块会顺带弹出预览挡住后续点击，`Doc/EDITOR-SPEC.md` §16.1/§19.6 已经反复记录过这个已知连带效应）拦截了 `#cleanupBtn` 的点击；用 `git stash` 切回改动前的 `index.html` 重跑同一个测试脚本、命中同一个卡点，交叉确认这不是这次改动引入的回归（这次任务的改动范围是场景 Tab + 基点相关函数，完全没碰材质画廊/预览弹窗的任何代码）；在撞上这个卡点之前，脚本已经验证过的"材质字段编辑记进脚本"这部分断言全部正常 PASS。这次任务自己的脚本重放验证（基点部分）改用 `test-todo37-scenetab.js` 里直接调用 `runScriptReplay()`/手动状态回滚的方式独立完成（见上面第 5 点），没有依赖这条撞上已知问题的旧测试路径。截图：`_dev/shots-37/00`（元数据展开）、`01`（分区独立）、`02`（第二基点设默认）。调试钩子：`window.__debugBasepoints` 新增 `resolveDefaultBasepoint`/`basepointResolvedReasonForIndex`/`setDefaultBasepoint`/`clearDefaultBasepointOverride`/`findBasepointIndexByName`。

---

## 9. UI 风格规范（对应第 10 点后半）

所有滑动条（range slider）统一用暗色风格，跟现有 `--bg: #141414` 系配色保持一致（现有 CSS 里 `input[type="text"]`/`input[type="checkbox"]` 已经有暗色样式，`input[type="range"]` 需要补上同一套变量体系的样式，包括 WebKit/Firefox 的私有滑块伪元素）。

**实现记录（#11，2026-08-06）**：审查了当前全应用范围内所有 `input[type="range"]`：
- 材质详情面板：金属度 `#mdMetal`、粗糙度 `#mdRough`、自发光强度 `#mdEmInt`（三个都在 `.mf-row-pair`/`.mf-cell` 布局里）
- 每个材质每个贴图槽的 UV 变换（`KHR_texture_transform`）：移动X/移动Y/缩放X/缩放Y/旋转，`class="uv-range"`，逐槽位动态生成
- 材质清理菜单核对过——只有 checkbox/radio/color，没有 range 滑动条，不适用
- 包围盒面板、基点面板核对过——数值输入都是 `input[type="number"]`，没有 range 滑动条，不适用

结论：CSS 里 `input[type="range"]` 是裸类型选择器（不带任何 class 限定），全文件唯一一处该规则（`<style>` 只有一处），所有滑动条天然共享同一套 `--bg`/`--panel2`/`--accent` 变量体系，没有发现游离在外用浏览器默认亮色滑块的遗漏点。用 Playwright 对真实样品 `chengdu-huagao-0801.glb`（材质详情区）+ 合成棋盘格样品（UV 变换区，因为真实样品没有贴图槽）逐个截图核对，轨道色 `#2a2a2a`、滑块金色 `var(--accent)`、尺寸 12px 圆形全部一致，控制台无报错。

顺带补的一处小遗漏：滑动条原本没有 hover/focus 交互反馈（`input[type="number"]`/`select` 都有 `:focus{border-color:var(--accent)}`，range 没有对应状态），加了 `:hover`/`:focus` 描边 + 滑块外圈高亮环（`box-shadow: 0 0 0 3px rgba(200,163,95,.25)`，WebKit/Firefox 各写一份伪元素选择器），跟其余表单控件的交互语言保持一致。

**实现记录（#31 滑动条黑色化，2026-08-07，对应 `Doc/2026-08-06-material-panel-redesign.html` §03）**：轨道色 `#2a2a2a` → `#0c0c0c`，比面板背景 `#141414` 更深但没深到纯黑跟背景融为一体分不清边界（设计方案的理由）。CSS 里这个值出现 3 处（`input[type="range"]` 基础规则、`::-webkit-slider-runnable-track`、`::-moz-range-track`），三处都是同一条裸类型选择器规则块内，一次改完，全应用范围天然生效（#11 已经确认过是唯一一处 `<style>`）。滑块金色 `var(--accent)`、hover/focus 描边逻辑都没动。用 Playwright + 真实样品 `画稿飞扬v2.glb` 验证：材质详情区（金属度/粗糙度/自发光强度）+ 材质详情里展开贴图槽后的 UV 变换滑动条（`.uv-range`，这次样品里第 6 个材质刚好带 baseColor 贴图，不用再靠合成棋盘格样品）都实测 `getComputedStyle(inp, '::-webkit-slider-runnable-track').backgroundColor === 'rgb(12, 12, 12)'`，`--accent` 变量值维持 `#c8a35f` 不变。测试脚本：`_dev/test-icon-slider-31.js`。

**实现记录（#31 图标尺寸放大，2026-08-07，对应 `Doc/2026-08-06-material-panel-redesign.html` §01）**：材质/贴图/模型块/场景四个 Tab 按钮（`data-tab="mat"/"tex"/"node"/"scene"`）的内联 SVG 图标，`width`/`height` 从 18 提到 24，`stroke-width` 从 1.4 提到 1.5，`viewBox`/`path` 数据（图标形状本身）完全不动。`#tabs button` 的 `padding` 从 `9px 0` 同步减到 `6px 0`，让按钮总高度维持改动前实测的 36px 不变（18→24 多出 6px，上下各减 3px 抵消）。材质工具条上的「清理」按钮的图标其实是 emoji 字符 `🧹`（不是 SVG，没有"线宽"概念，`stroke-width` 这条参数对它不适用，如实记录这个跟四个 Tab 图标的差异）：把 emoji 包进 `<span class="btn-icon">`（`font-size:24px; line-height:1`），按钮改 `display:inline-flex; align-items:center` 让图标和"清理"文字各自按高度居中，垂直 `padding` 从基础 `button` 规则的 `6px` 减到 `2.8px`，抵消图标放大量，总高度维持改动前实测的 31.59375px（±1px 内）不变。验证：Playwright + `画稿飞扬v2.glb`，`getBoundingClientRect()` 实测四个 Tab 图标 `24×24px`、`stroke-width` 属性值 `1.5`、按钮高度 `36px`；清理按钮图标 `font-size` 计算值 `24px`、按钮高度 `31.59375px`（跟改动前基线一致）；`viewBox` 属性确认未被改动（图标形状没变）。改动前/改动后并列截图对比（`git show HEAD:index.html` 起一个独立端口的"改动前"服务器）：`_dev/shots-31/cmp-before-*.png` vs `cmp-after-*.png`。控制台全程 0 报错。测试脚本同上 `_dev/test-icon-slider-31.js`（34 项断言全过）。

---

## 10. 场景菜单 + 操作脚本重放系统（对应第 11 点）——本次最大的架构性功能

**场景菜单**：新增一个「场景」菜单入口（区别于现有头部那一排平铺按钮），里面包含「模型」子菜单，可以「重新载入 GLB」（从原始文件重新解析，但不清空已经录制的操作脚本）。

**操作脚本（核心设计）**：把所有编辑动作——移动、旋转、删除、材质变化、坐标归零、包裹框创建/删除、场景原点重设、Instance 创建、层级关系变化——都记录成一份「脚本」，可以在「重新载入 GLB」之后重新按顺序跑一遍。

这本质上是一个**命令模式（Command Pattern）的操作日志**，用途是：3ds Max 那边模型改了重新导出一版新 GLB 后，不用把之前在 3AS 里做的所有清理/标注动作重新手工做一遍，直接「重放脚本」。

**草拟的脚本记录格式**（待评审，不是定稿）：
```json
{
  "script": [
    { "op": "renameFallbackMaterials", "params": {} },
    { "op": "replaceBlackMaterial", "params": { "mode": "random", "min": 200, "max": 254 } },
    { "op": "applyScale", "target": { "byName": "L6" } },
    { "op": "setNodeTransform", "target": { "byName": "PArc864" }, "params": { "T": [26.29, 4.25, 15.4] } },
    { "op": "createInstance", "target": { "byName": "Rectangle2133441907" }, "params": { "at": [10, 0, 0] } }
  ]
}
```

**已确认：重放匹配策略 + 差异梳理 UI**

基础匹配用「按名称匹配」：3ds Max 重新导出后，节点名字大概率不变（这次样品里节点名是 `L6`、`PArc864` 这种，通常稳定），但也可能变（比如加了新部件导致自动编号偏移）。在此基础上，重放前先做一次**新旧模型节点差异梳理**，不是匹配完直接默默应用或默默跳过：

1. 对比重新载入的 GLB 和上一版的节点列表，算出「新增了哪些节点」「消失了哪些节点」
2. 对**消失的节点**（原本有脚本操作/注释挂在它身上），弹出清单，每一条让用户三选一：
   - **保留**：先把这条操作/注释记着，不丢，等以后同名节点出现再自动接上
   - **放弃**：确认这个部件真的没了，删掉相关的操作/注释
   - **让别的节点/物体应用**：手动把这条操作/注释重新指定到当前树里的另一个节点上（应对「改了名字」或「结构调整、原本的功能分到别的节点上了」这类情况）
3. 对**新增的节点**，只做提示（这些是全新部件，没有历史操作可继承，正常走一遍新节点的默认注释流程即可）

**已确认：脚本存储位置**——合并进现有 `annotations` 导出 JSON，加一个 `script` 字段，不单独开文件（少一个文件要管，也方便脚本和当时的注释状态对得上时间点）。

**实现记录（2026-08-06，#12，只做了场景菜单前半——「模型」子菜单 + 重新载入 GLB，操作脚本重放系统本身仍是 #13，未做）**：

- Header 最左侧新增独立下拉菜单 `#sceneMenuWrap`/`#sceneMenuBtn`（「场景 ▾」）/`#sceneMenuDropdown`，跟右侧那一排平铺按钮（打开GLB/示例模型/导出注释JSON/另存为GLB/日志）区分开——点击展开而不是直接触发动作。菜单里目前只有「模型」一个分组（`.menu-group-label`），组内一项「重新载入 GLB」（`#reloadGlbBtn`）。交互上跟现有材质清理菜单/设置面板同一套「点击外部区域收起」惯例（`document` 级 `click` 监听 + `closest` 判断点击目标是否在菜单容器内），额外补了 ESC 收起（`keydown` 监听）——这是现有两个居中浮层都没有的交互，因为下拉菜单跟居中模态浮层的心智模型不完全一样，这次新加的菜单体系顺手把 ESC 也补齐。
- 「重新载入 GLB」点击后的行为严格按任务要求做成最简单版本：`closeSceneMenu()` 收起菜单 + 触发隐藏的 `#fileInput` 原生文件选择框，选中文件后走的是**已有**的 `#fileInput.onchange` 处理器（`openBuf(await f.arrayBuffer(), f.name)` → `preprocess()`），没有另外写一套加载逻辑，也没有做任何「保留当前注释/差异对比」的特殊处理——选中文件后就是完全清空当前状态重新走一遍预处理，等同于点「打开 GLB」按钮，只是入口位置换了。完整的新旧模型节点差异梳理/操作脚本重放是 #13 的范围，不在这次任务内。
- **实现过程中发现并修复了一个真实浏览器坑**：用 Playwright 实测确认，原生 `<input type="file">` 重新选中「路径和内容都跟上次一样」的文件时**不会**触发 `change` 事件——不仅路径+内容完全相同时不触发，连「同路径但内容字节确实被改过」（模拟 3ds Max 覆盖导出同一路径）这种情况在测试环境（Playwright 驱动的 Chromium）里也没有触发。这正好命中「重新载入 GLB」最主要的目标场景（用户改完模型覆盖导出到同一路径）——如果不修，用户点了「重新载入 GLB」选完文件感觉像完全没反应。修复方式是标准做法：弹出文件对话框前先 `$('fileInput').value = ''`，清空后浏览器认为下一次选择总是「新的」，无论路径/内容是否相同都可靠触发 `change`。这个坑是 `#fileInput` 本身的问题，不是场景菜单独有的，所以「打开 GLB」按钮（`#openBtn`）也一并做了同样的修复。
- 验证：用真实样品 `chengdu-huagao-0801.glb` 正常加载一次 → 编辑材质 #0 baseColor（黑色 `[0,0,0,1]` 改成红色 `#ff2020`）→ 走「场景」菜单→「模型」→「重新载入 GLB」选**同一个文件**：`window.__debugRaw.materials[0].pbrMetallicRoughness.baseColorFactor` 精确恢复到原始值 `[0,0,0,1]`（不是保留编辑后的红色），材质数/贴图数/节点块数三项统计值都重新走了一遍 `buildTables()`、跟首次加载完全一致，`fileInfo` 文案正确刷新；菜单展开/点外部收起/ESC 收起三种交互都测到；重跑既有回归测试 `test-cleanup-menu.js`（材质清理菜单，全部 PASS）、`test-bbox.js`（包围盒，全部 PASS）、`test-basepoints.js`（测量基点，全部 PASS）确认 `fileInput.value` 清空这个改动没有破坏其它依赖文件加载流程的既有功能；控制台全程 0 报错。测试脚本：`_dev/test-scene-menu.js`（Playwright，可重跑复查，全部 PASS）。截图：`_dev/shots/scenemenu-00` 至 `scenemenu-03`。

**实现记录（2026-08-06，#21，头部菜单重构 + 接通真实后端）**：

对应 `Doc/TODO.md` #21，接口约定照抄 `Doc/BACKEND-SPEC.md` §四/§六（#23 已完成的 `server/api/upload.php`/`projects.php`），不自己猜格式。**开工前先检查了有没有残留实现**——搜了「下拉菜单」「从服务器打开」「保存到服务器」等关键词，`index.html` 里只有 #12 场景菜单自己的实现，没有跟本任务相关的半成品代码，从零开始。

- **移除「示例模型」按钮**：`#sampleBtn` 连同它的下载逻辑（试探 `../3ds-viewer/`、`./glb/`、`./` 三个候选路径的那段 `fetch` 循环）整个删掉，不留占位、不留死代码。
- **下拉菜单交互基础设施重构**：#12 场景菜单原本是给「场景」这一个菜单单独手写的 `closeSceneMenu()`/`openSceneMenu()` 一对函数 + 一套 `document` 级 `click`/`keydown` 监听器。这次新增「打开GLB」「另存为GLB」两个同款交互的菜单后，三份几乎一样的代码、三套互不知道彼此状态的独立监听器没有必要，抽成通用的 `registerMenu(wrapId, btnId, dropdownId)`：登记一个菜单的三个 DOM 元素，返回 `{open, close}`，模块级 `registeredMenus` 数组让唯一一套 `document` 监听器对所有登记过的菜单统一生效——点外部关闭该菜单、ESC 关闭全部展开的菜单，行为跟 #12 原版逐条一致；额外补了「展开一个菜单时顺带收起其它已展开的菜单」（原版只有一个菜单不存在这个场景，三菜单并存后顺理成章的细节）。对应地 CSS 也从 `#sceneMenuWrap`/`#sceneMenuBtn.open`/`#sceneMenuDropdown` 三个 ID 专属选择器改成共享类 `.menu-wrap`/`.menu-btn.open`/`.menu-dropdown`（ID 仍保留给 JS `registerMenu()` 用），「打开GLB」「另存为GLB」在 header 靠右，下拉展开方向从左对齐改成右对齐（`#openMenuDropdown, #saveGlbMenuDropdown { left:auto; right:0 }`）避免超出视口。
- **「打开 GLB ▾」**：「本地文件」（`#openLocalBtn`）是原来 `#openBtn` 的行为原样保留（含 value 先清空再 `.click()` 那个坑的修复）。「从服务器打开…」（`#openServerBtn`）点击后弹出 `#serverProjectsOverlay` 面板（跟设置面板同一套居中浮层外壳），`GET server/api/projects.php` 拉列表渲染成可点击的行（项目名+更新时间+大小）；点选后 `GET server/api/projects.php?id=<id>` 拿详情、再单独 `fetch(glbUrl)` 拉 GLB 二进制，走**现有** `openBuf()`/`preprocess()` 流程渲染，不另写加载逻辑。注释 JSON 的接入没有在 `openBuf()` 之后手动拼接/覆盖 `anno`（那样容易漏掉 `buildTables()` 内部依赖 `anno` 副作用的部分，比如 `syncBBoxHelpers()`/`syncBasepointHelpers()`）——而是在调用 `openBuf()` 之前，把服务器返回的注释 JSON 按 `openBuf()` 内部本来就会用的 `fileKey`（文件名+字节数）规则写进 `localStorage`，让 `openBuf()` 原有的「恢复历史注释」路径（`anno = loadAnno()`）自然读到，全程复用一条代码路径。
- **「另存为 GLB ▾」**：GLTFExporter 打包逻辑抽成共用的 `exportGlbBlob()`（把原来 `exportGlbBtn` 回调式的 `exporter.parse(model, onDone, onError, {binary:true})` 包一层 `Promise`），供「本地文件」下载和「保存到服务器」上传共用，不是两套分叉实现。「本地文件」（`#saveGlbLocalBtn`）文案、状态栏消息、下载文件名规则都跟原来的 `#exportGlbBtn` 一致。「保存到服务器…」（`#saveGlbServerBtn`）用 `exportGlbBlob()` 生成的 GLB + `buildExportJson()`（从原 `#exportBtn` 里也抽出来的共用函数）生成的注释 JSON，一起 `multipart/form-data` POST 给 `server/api/upload.php`（字段名 `glb`/`meta`/`name`，逐字照抄 `upload.php` 头部注释的接口约定）。**这一轮范围只做「新建项目」**——`upload.php` 只有新建这一种操作，没有带 id 的更新接口，所以每次点「保存到服务器」都会新建一个项目，不会覆盖上一次上传的那个（这是 `Doc/TODO.md` #21 任务原文明确的范围限制）。
- **上传结果展示（token 保管）**：上传成功后拿到的 `ownerToken`（64 位 hex，服务端只存哈希，明文只在这次 HTTP 响应里出现一次）用一个不会自动消失的居中浮层 `#uploadResultOverlay` 展示（区别于 `status()` 状态栏一闪而过），明确提示「下面的项目 ID 和 Token 只在这里显示这一次，请立即复制保管」，`<code>` 元素 `user-select:all` 点一下就能全选，另配「复制」按钮（`navigator.clipboard`，失败时降级提示手动选中复制）。**这次范围内 token 只存在会话内存变量 `currentServerProject` 里，不做 localStorage 持久化**——跨会话（刷新页面/关浏览器）token 就丢失，是任务明确允许的范围限制，不是遗漏。
- **架构决策：静态页面 + 后端合并成同一个源**——本机测试和 README「快速开始」都改成推荐用**同一个 PHP 内置服务器**（`php -S 127.0.0.1:<端口> -t .`，`.` 是 3as 项目根目录）同时托管 `index.html` 和 `server/` 目录，前端 `fetch('server/api/...')` 天然是同源相对路径请求，不需要处理跨源 CORS（`server/api/*.php` 目前没有内置 CORS 响应头），部署到子目录也不用改代码。放弃了「Python 静态页面 + PHP 后端两个独立进程」的方案，虽然它也能工作，但需要额外给 PHP 后端加 CORS 头，属于给自己找麻烦。
- 验证：真实样品 `chengdu-huagao-0801.glb`，起 `php -d upload_max_filesize=200M -d post_max_size=210M -S 127.0.0.1:18290 -t .`（合并服务器）。截图确认「示例模型」按钮已不存在、「打开GLB」「另存为GLB」都变成下拉菜单（`_dev/shots/todo21-00-header-empty.png`）；本地打开样品（材质数=17，回归验证「本地文件」行为不变）；走 UI 真实点击材质卡片+改 `#mdBaseColor` 颜色输入框（同 `test-mat-editor.js` 手法，不是直接调内部函数）把材质 #0 改成 `#ff2020`；「另存为GLB→保存到服务器」上传成功，拿到 36 字符 UUID 项目 ID + 64 位 hex token，弹窗正确展示两者（`_dev/shots/todo21-02-upload-result.png`）；**落盘核查**（不是只信前端）：`server/projects/<id>/` 目录、`model.glb`（glTF magic bytes `glTF`+version 2 校验通过）、`meta.json`（确认不含明文 token，只有 `ownerTokenHash`）、`versions/v1.3as.json`（注释 JSON，`materials[0].baseColorF` 精确是编辑后的红色）都真实存在；`curl` 直接打 `GET server/api/projects.php`/`?id=<id>` 两个接口交叉核对返回内容跟磁盘一致；**刷新页面**（`page.reload()`，验证不是读本地缓存）后「打开GLB→从服务器打开」，项目列表里能看到刚上传的项目（`_dev/shots/todo21-03-server-project-list.png`），选中后完整加载出编辑后的版本——材质数仍是 17（完整数据非残缺）、材质 #0 baseColor 精确是服务器上的红色（`_dev/shots/todo21-04-loaded-from-server.png`，视口截图肉眼可见红色色块+状态栏「已从服务器加载：chengdu-huagao-0801.glb（项目 ID ...）」）；控制台全程 0 报错。共 41 项断言全部 PASS。测试脚本：`_dev/test-header-menu-backend.js`（Playwright，可重跑复查；用完手动清理了测试产生的 `server/projects/<id>/` 目录，`.gitignore` 的 `server/projects/*` 规则用 `git add -n .` 交叉核对确认不会被意外提交）。

**实现记录（2026-08-06，#13，操作脚本记录 + 重放系统，本轮开发的最后一个任务）**：

对应 `Doc/TODO.md` #13，依赖 #1/#4/#6/#7/#8/#9/#10 全部已完成。**开工前先按要求检查了有没有残留实现**——搜了「操作脚本」/`script`/「重放」/`replay`/`recordScriptOp`/`anno.script` 等关键词（注意跟 `_dev/` 测试脚本文件名里的"script"一词区分开），`index.html` 里全部无匹配，确认是干净状态，没有可复用的半成品代码，从零实现。

- **接入点复用**：严格复用 #22 撤销系统（`pushUndo`）已经接入的那批写回位置，没有为这次任务再单独重新埋点一遍——材质字段编辑（`commitMatFieldGesture` + alphaMode/doubleSided 的 onchange）、材质清理菜单批量操作（`runMaterialCleanup`）、创建 Instance（`createInstance`）、节点移动/旋转（`applyTransformFromInputs`）、贴图上传/替换/移除（`uploadMatTexture`/`removeMatTexture`）、UV 变换（`commitUvFieldGesture`），六类跟 #22 报告列出的"已接入撤销"名单逐条对应。`pushUndo(label, undoFn)` 记的是"怎么撤销"，这次新增的 `recordScriptOp(op, target, params)` 记的是"这次操作是什么、参数是什么"，两者在同一批调用点紧挨着一起调，不是分两轮改代码。#22 报告里提到的"未接入撤销"的功能（材质拖拽指定 #19、包围盒创建/编辑/删除 #9、测量基点 #10、场景整体包围盒手动覆盖）这次也没有顺手补——时间有限，优先把六类核心操作做扎实（含差异梳理/重放两端都要能正确处理这六类），没有额外扩大范围。
- **记录格式**：`{ op, target, params, ts }`，`op` 是能看懂的字符串（`editMaterial`/`setUvTransform`/`setNodeTransform`/`createInstance`/`uploadTexture`/`removeTexture`/`cleanupBatch`），比 EDITOR-SPEC.md 本节开头草拟的格式（`renameFallbackMaterials`/`replaceBlackMaterial`/`applyScale` 三个独立 op）粒度更粗——材质清理菜单的三个子操作（fallback重命名/黑色改色/缩放归一化）合并记成**一条** `cleanupBatch`（`params` 就是当时勾选的 `{rename, colorMode, customHex, scaleNorm}`），跟 #22 撤销系统"三个子操作合并成一条撤销记录"的设计保持一致（避免用户一次点「应用」产生三条独立又要各自处理差异梳理的记录，复杂度不成比例）；重放时直接重新调 `runMaterialCleanup(params)` 整体重跑一遍，不是逐材质/逐节点分别重放。
- **target 设计 + 一个开发过程中发现的真实 bug**：`target: {byName, kind:'node'|'material', atIndex}`。最初设计只有 `{byName, kind}`（跟任务描述的"按名称匹配"字面一致），**用真实样品测贴图上传重放时发现一个严重问题**——`chengdu-huagao-0801.glb` 清理前全部 17 个材质的 `raw.materials[i].name` 都是同一个字符串 `"fallback Material"`（V-Ray 导出的通病，#4 材质清理菜单批量重命名要处理的就是这个），纯按名字匹配会让任何材质相关的脚本记录（不管原本编辑的是材质 #0 还是 #16）重放时统统解析成材质 #0（`Array.prototype.findIndex` 找到的第一个同名匹配）——这不是罕见边界情况，是这份真实样品里**材质相关操作的默认行为**。补加了 `atIndex`（记录那一刻目标所在的索引，只当"同名候选之间的优先提示"，不是主键）：`resolveScriptTargetIndex()` 优先检查 `atIndex` 那个位置现在是不是还是同一个名字，是就直接用；索引位置对不上（比如 3ds Max 重新导出后顺序变了）才退回"按名字找第一个"这个更弱但诚实的兜底策略。「重新指定」这个差异梳理选项选完之后也会把新目标的当前索引同步写回 `atIndex`，不然重新指定了却没有更新兜底信号，等于没修。这个 bug 是在给 `_dev/test-script-replay-texture.js` 写测试、断言"重放后材质 #16 的贴图槽位应该恢复"却发现槽位仍是空、贴图跑去了材质 #0 身上，才顺着这条线索定位到的，不是凭空想到要加这个字段。
- **差异梳理 UI 的泛化**：任务原文/EDITOR-SPEC.md 本节的措辞都是针对"节点"的（"对比新旧模型节点列表""消失的节点"），但脚本本来就会记材质相关的操作（`editMaterial`/`setUvTransform`/`uploadTexture`/`removeTexture`），如果差异梳理只覆盖节点、材质目标找不到时直接静默跳过，行为不统一、用户不知道为什么有的操作"重放了但看起来没生效"。这次把差异梳理泛化成同时覆盖两种 `target.kind`——`computeScriptMissingGroups()` 不区分节点/材质统一按 `(kind, byName)` 分组，UI 里「重新指定」下拉框按 `target.kind` 分别列当前节点或当前材质候选。这是比字面任务描述做得更完整的一处，不是曲解任务范围。
- **三选项行为**（`renderScriptReplayPanel()`/`scriptReplayRunBtn` 点击处理）：
  - **保留**（默认选中）：这次重放跳过该目标名下的全部脚本记录（`skipSet`），但**不**从 `anno.script` 删除——按任务要求的简化版本实现，"等以后同名节点出现再自动接上"这个更完整的语义（主动监听/下次重新扫描时优先尝试）没有实现，用户需要再次打开重放面板才会重新判定一次，不是自动的。
  - **放弃**：`anno.script = anno.script.filter(...)` 精确删掉该目标名下的全部记录，写 `saveAnno()` 持久化，这是永久性的（不像"保留"是"这次跳过"）。
  - **重新指定**：下拉框选一个当前模型里的同类型（节点或材质）目标，该目标名下全部脚本记录的 `target.byName`（和上面提到的 `target.atIndex`）就地更新，`saveAnno()` 持久化——这个改动是永久的，不是"这次重放用一下就还原"，往后每次重放都会用新目标。
  - 三种选择都是"按 (kind,byName) 分组"粒度操作，不是按单条脚本记录——同一个目标被好几条记录引用时，三选一对这几条记录统一生效，没有做"这一组里再拆开单独选"这种更细粒度的 UI（复杂度收益比不划算，六类操作里没有一个目标同时被超过 2-3 条记录引用的典型场景）。
- **重放执行**：`executeScriptOp(entry)` 按 `op` 分派到对应的既有写回函数——`editMaterial`→`setMatField()`、`setUvTransform`→`setUvField()`、`setNodeTransform`→`applyNodeWorldTransform()`、`createInstance`/`uploadTexture`/`removeTexture`/`cleanupBatch`→对应同名函数直接调用，**没有另外写一套"重放专用"的写回逻辑**，任务要求的"复用已有的编辑函数"逐条落实。找不到目标（`resolveScriptTargetIndex` 返回 -1）或者贴图上传数据缺失（`params.dataUri` 为空，理论上只有 `FileReader` 读取失败这种极少数场景才会发生）都返回 `false`，调用方计入"跳过"而不是当成异常；真正的执行期异常（`try/catch`）计入"失败"，两类分开统计，每一步不管成功/跳过/失败都调 `logEntry()` 记一条，不静默。`uploadTexture` 重放时贴图字节直接从脚本 `params.dataUri`（记录时就已经内嵌进脚本的完整 data URI，不是只存一个文件名占位）`fetch()` 出来重建 `Blob`/`File`，不依赖用户当时选中的那个本地 `File` 对象是否还存在——这是"脚本要能在完全不同的会话/机器上重放"这个设计目标的直接要求。
- **防重复录制**：`createInstance()`/`runMaterialCleanup()`/`uploadMatTexture()`/`removeMatTexture()` 这四个函数本身就是"操作入口"（没有额外一层 UI 手势包装函数），`recordScriptOp()` 直接写在函数体内部；重放阶段会再次调用这几个函数本身来"重新执行一遍"，如果不做防护，每次重放都会把"重放动作本身"也录进脚本，脚本越放越长。用一个模块级 `scriptReplaying` 布尔标记解决——`recordScriptOp()` 内部 `if (scriptReplaying) return`，`executeScriptOp()` 对应的 4 个 op 分支各自套一层 `scriptReplaying = true; try {...} finally { scriptReplaying = false; }`。`editMaterial`/`setUvTransform`/`setNodeTransform` 三类天然不受影响——重放直接调更底层的 `setMatField()`/`setUvField()`/`applyNodeWorldTransform()`，从不经过 `commitMatFieldGesture()`/`commitUvFieldGesture()`/`applyTransformFromInputs()` 这几个真正调用 `recordScriptOp()` 的 UI 包装函数，不需要标记保护。
- **存储**：`anno.script` 数组，`buildTables()` 里 `anno = { ..., script: old.script || [] }`，重新打开同一份文件（含"重新载入 GLB"）时保留 `old.script`（不像 `nodes`/`matNotes` 那样每次都清空重来）——脚本记的是"做过什么"，理应跨会话累积，这也是"重新载入 GLB 之后能重放脚本"这个核心用例成立的前提。导出注释 JSON（`buildExportJson()` 的 `annotations: anno`）不需要额外写导出代码，`script` 字段跟着 `anno` 整体序列化自然带出去，跟任务描述"确认一下，不用额外写导出代码"的预期一致，实测也确认了这一点。
- **这次没做的部分（如实列出，不是隐瞒着看起来完整）**：
  1. 从外部注释 JSON 文件里单独读取 `script` 字段、应用到一个刚打开的新模型——这个更完整的"导入脚本"流程没有做。当前只做了"对当前已加载模型重放当前 `anno.script`"这个最基本能力，任务原文允许这个简化（"这次先做最基本的...这个更完整的流程...你判断时间是否允许一并做，不强制"）。「重新载入 GLB」选**同一份**文件时脚本能自动接上，靠的是 `localStorage` 按 `fileKey` 持久化这个既有机制自然生效，不是专门为这次任务写的导入功能——如果要支持"打开一个全新文件 + 手动导入一份外部脚本 JSON"，还需要额外的文件选择 UI + 合并逻辑，没有做。
  2. 「保留」选项的"等以后同名节点出现再自动接上"是被动的——用户需要再次打开重放面板，`computeScriptMissingGroups()` 才会重新判定一次，没有做任何主动监听/后台扫描机制。
  3. 材质拖拽指定（#19）、包围盒创建/编辑/删除（#9）、测量基点新增/编辑/删除（#10）、场景整体包围盒手动覆盖开关这几类操作没有记进脚本——跟 #22 撤销系统的已知限制范围完全一致（这几类本来就没有接入撤销，这次也没有额外去接）。
- **测试**：`_dev/test-script-replay.js`（Playwright，44 项断言全部 PASS，可重跑复查）——用真实样品 `chengdu-huagao-0801.glb` 走完整链路：材质 #0 baseColor 改红→材质清理菜单（重命名+缩放归一化）→创建 Instance（`PArc864`）三步真实 UI 操作，逐条核对 `anno.script` 精确记录（`op`/`target`/`params` 逐字段比对，不是只看长度）；导出注释 JSON 解析确认 `script` 字段完整带出、顺序正确；「场景→重新载入GLB」选同一个文件，确认运行时状态清空（材质变回黑色、Instance 消失）但 `anno.script` 仍是 3 条（`localStorage` 持久化生效）；「场景→重放操作脚本」在无差异场景下点「开始重放」，确认材质变回红色、fallback 材质名被重新命名、节点数增加（Instance 重新创建）、缩放重新归一化，总结文案精确显示"成功 3 条，跳过 0 条，失败 0 条"；另确认重放本身不会让 `anno.script` 自我膨胀（重放前后都是 3 条，`scriptReplaying` 标记生效）；差异梳理三选项分别测试——注入一条指向不存在节点名的记录，触发差异清单（同时发现了一个真实的连带情况：第 4 步重放后材质清理把材质改名了，导致第 1 条 `editMaterial` 记录的 `target.byName`（旧的 "fallback Material"）也变成"缺失"，这是"按名字匹配"这套设计的真实连带效应，不是测试脚本的错，一并在这一步处理掉）；「放弃」确认对应记录从 `anno.script` 精确删除；「保留」确认记录原样保留、这次重放跳过（总结文案里跳过数 > 0）；「重新指定」选一个真实节点名（`L6`）确认记录的 `target.byName` 精确更新且重放成功执行；全程控制台 0 报错。另写了 `_dev/test-script-replay-texture.js`（18 项断言全部 PASS）专门补测贴图上传/替换/移除三种操作的脚本记录 + `atIndex` 同名材质兜底修复（`params.dataUri` 精确是真实贴图字节、重放后能在同名材质环境下精确命中原来那个材质而不是撞车到材质 #0）。另重跑了 `test-undo-status.js`（29 项全 PASS）、`test-scene-menu.js`（全 PASS，确认新增的「重放操作脚本」菜单项没有破坏既有场景菜单交互）、`test-bbox.js`/`test-basepoints.js`（全 PASS），以及 `test-cleanup-menu.js`/`test-instance-export.js`/`test-texture-upload.js`/`test-mat-editor.js`/`test-uv-editor.js`/`test-node-transform.js`（这几个测试脚本执行到"另存为GLB→本地下载"这一步会撞上 #21 遗留的旧按钮 ID `#exportGlbBtn` 已经改成 `#saveGlbLocalBtn` 的已知问题——`Doc/TODO.md` #22 报告已经记录过这个预置问题，不是这次改动引入的新问题，撞上之前的全部断言都正常 PASS，确认这次改动没有破坏材质清理/Instance创建/贴图上传/材质编辑/UV编辑/节点变换这些既有功能的核心逻辑）。控制台全程 0 报错。测试钩子：`window.__debugScript`（`recordScriptOp`/`resolveScriptTargetIndex`/`executeScriptOp`/`computeScriptMissingGroups`/`openScriptReplayPanel`/`runScriptReplay`，同项目其它测试钩子一样的暴露方式，供 Playwright 直接调用核心函数）。

---

## 11. 决策记录

第一版（2026-08-05 上午）留过 5 个「待确认」点，同一天下午全部确认完，记录如下，正文已经把结论并回对应小节：

| # | 问题 | 结论 |
|---|---|---|
| 1 | 第 4 节「附加信息」具体字段 | 做成开放式/可扩展信息卡片面板，不是固定字段列表，已知第一张卡片是「兼容性卡片」（见第 12 节） |
| 2 | 第 6 节 Instance 对「组」怎么处理 | 方案 ii：保留子节点粒度 |
| 3 | 第 2 节图标风格 | 图标化 + 鼠标悬浮 tooltip |
| 4 | 第 10 节操作脚本存储位置 | 合并进现有导出 JSON 的 `script` 字段 |
| 5 | 第 10 节节点重放匹配策略 | 按名称匹配 + 差异梳理 UI（新增/消失节点清单，消失节点让用户选保留/放弃/重新指定） |

现在文档里没有阻塞 todo 排期的悬而未决项。后续设计过程中如果又冒出新的待确认点，按这个格式继续往这张表里加。

---

## 12. glTF 扩展兼容性（对应「不知道线上加载器支持到什么级别」）

你提的这个问题很实际——3AS 编辑器如果写出去的 GLB 用了查看器不认的扩展，用户改完在别的地方打开会出问题。查了一下，Khronos 官方的 glTF 2.0 Sample Viewer（业界参照实现）明确支持这次规划里会用到的几个扩展，主流运行时（three.js / Babylon.js）作为最贴近参照实现的引擎，同样支持情况良好；只是没有找到一份逐引擎逐版本、按月更新的公开兼容性矩阵——这类信息本来就分散、变化快，没有人在维护一张权威总表，以下是能确认到的程度，如实说明：

| 扩展 | 3AS 会不会用到 | 支持情况 |
|---|---|---|
| `KHR_texture_transform`（贴图 UV 变换，第 7 节） | 会写 | Khronos Sample Viewer、three.js、Babylon.js 都支持；这是目前用得最广泛的 glTF 扩展之一，风险最低 |
| `EXT_mesh_gpu_instancing`（GPU 批量实例化） | **不会用**——第 6 节的 Instance 方案 ii 用的是「多节点引用同一 mesh 索引」，这是 glTF **基础规范**自带的能力，不是这个扩展；这个扩展是给「同一物体成百上千份」的场景用的批量优化，3AS 场景（几个到几十个 instance）用不上，也就不用担心它的兼容性 | — |
| `KHR_materials_variants`（材质变体，model-viewer 编辑器里那个「Create Variant」按钮用的就是它） | 暂不在这批规划里 | Khronos Sample Viewer、Babylon.js、model-viewer 都支持；以后如果做"一个模型多套材质方案切换"可以考虑 |
| `KHR_materials_specular` / `KHR_materials_emissive_strength`（3AS 现有材质表已读取） | 已在用（只读） | 广泛支持 |

**结论**：这次规划要新增的贴图 UV 编辑功能选用 `KHR_texture_transform`，是目前兼容性最有把握的选择。「附加信息」面板的兼容性卡片（第 4 节）会把这类信息展示给用户，而不是 3AS 自己在背后悄悄决定「能不能用」——你能自己看到导出的这份 GLB 依赖了什么、大概哪些地方能打开。

---

## 13. 日志系统 + 报错系统（对应第 7 点）

跟第 10 节的「操作脚本」是两回事——操作脚本记的是**用户编辑动作**（给重放用），这里要做的是**运行时诊断日志**（给复查问题用）：

- GLTFLoader 解析警告/报错（现在这些信息只会打在浏览器控制台，用户很可能根本没打开开发者工具）
- 扩展不支持提示（比如遇到 KTX2 贴图但没接 KTX2Loader）
- 「另存为 GLB」失败原因
- 贴图异步解码失败原因（现在贴图表已经会显示"无法解码"，但没记录具体是什么错误）
- 材质清理菜单（第 5 节）批量操作的执行结果（改了几个材质、跳过了几个）

做成一个可展开的日志面板（入口可以跟设置按钮/附加信息面板共用一个区域），按时间顺序列出，标注严重程度（信息/警告/错误），方便出问题时回头翻，而不是只能事后无法复现。

**实现记录**（2026-08-05，#14）：日志入口目前是头部独立按钮「日志」（带数字角标，按当前最严重级别变色），不跟任何设置面板共用——因为 #3（设置按钮+附加信息面板）还没做。等 #3 做出来之后，这个入口可能要挪进去合并，先这样独立放着。

**实现记录（续）：日志入口挪到底部状态栏 + 简单单步撤销**（2026-08-06，#22）：

**日志入口位置**：#3（设置面板）做完之后，日志入口最终没有并入设置面板——按这次任务要求改挪进了底部状态栏（`#status`）。`#status` 从原来单纯一行状态文字，改成 flex 行布局：左侧状态文字（`#statusText`，flex:1，超长省略号截断）+ 右侧两个按钮（撤销、日志）。日志面板（`#logPanel`）内容/交互完全不变，只是展开位置从贴着头部按钮下方（`top:46px`）改成贴着状态栏上方（`bottom:40px`）。头部原来的独立「日志」按钮已删除。

**简单单步撤销**（用户已确认范围：Ctrl+Z 那种单步撤销，不是本文档第 10 节后半那套操作脚本记录/重放系统）：

- 通用机制 `pushUndo(label, undoFn)` + 浅栈（`UNDO_MAX=8`）+ `performUndo()` 弹栈执行 + 状态栏按钮展示栈顶 label、栈空禁用。全局 Ctrl/Cmd+Z 快捷键（焦点在可编辑表单控件里时不触发，避免劫持浏览器原生输入撤销）。新模型加载/重新载入时清空撤销栈（旧记录闭包指向的是上一份 `raw`/`gltf`/three.js 对象，切换文件后已失效）。
- 跟已有「还原到原始值」按钮（`revertMatField`/`revertUvField`/`revertTransformField` 等）的关系：语义不同——那些还原到模型刚加载时的原始值，撤销回退的是「最近一步操作」，两者共存、不互相替代，读的快照来源也不同（撤销读「编辑前」的当前值，还原按钮读 `tables.matOriginal`/`tables.nodeOriginal` 这类加载时快照）。
- **接入范围**（按任务给的优先级顺序，全部接入完成）：
  1. 材质字段编辑（baseColor/金属度/粗糙度/自发光/自发光强度/alphaMode/doubleSided，#7/#18）——拖拽类控件（滑动条/颜色选择器）用「手势」粒度：`beginMatFieldGesture`/`commitMatFieldGesture` 在 `oninput`（连续触发）时只记手势开始前的值一次，`onchange`（松开/关闭取色器时触发一次）才真正推入撤销栈，避免栈被拖拽中间值刷满。alphaMode/doubleSided 这类只有离散 `onchange` 的控件直接单次读值即可。
  2. 材质清理菜单批量操作（#4）——`cleanupRenameFallback`/`cleanupBlackToColor`/`cleanupNormalizeScale` 三个子函数改成除了返回统计数字外，也各自收集一份 `changes` 明细（改了哪个材质/节点、改之前的值），`runMaterialCleanup` 把用户这次勾选执行的子操作产生的 `changes` 合并成**一条**撤销记录（不是拆成三条），撤销时一次性把这次批量操作动过的全部材质/全部节点都精确恢复。缩放归一化的撤销利用了一个关键点：`mesh.geometry.clone()` 从不修改原始 geometry 对象本身，只是新建一份拷贝再改，所以撤销时把 `mesh.geometry` 换回捕获的原始引用即可，不需要额外拿 `Float32Array` 存一份顶点/法线数据再逐分量写回。
  3. 创建 Instance（#6）——`cloneRecursive` 往 `raw.nodes` 尾部连续 push 新节点（同步执行、中间不会被打断），撤销时靠 `raw.nodes.length = nodeCountBefore` 截断这段连续尾段（比逐个 splice 更简单），配合从父节点 `children[]`/`scenes[].nodes` 摘除引用 + three.js 场景图 `parent.remove()` + 清理 `nodeObjects`/`gltf.parser.associations`/`tables.nodeOriginal` 对应条目。这个截断假设成立的前提是撤销栈严格后进先出弹出（本项目实现如此），不会出现「中间弹出导致后面还有更高索引节点」的情况。
  4. 节点移动/旋转编辑（#17）——`applyTransformFromInputs` 里用 `getNodeWorldTR` 读「变更前」世界空间 T/R，撤销直接调 `applyNodeWorldTransform(ni, prevT, prevRdeg)`，复用同一条写回函数。粒度是每个数字输入框单独一次 `onchange`（失焦提交）算一步。已知限制：如果这次编辑连带自动清除了包围盒（联动逻辑，见本文档 §14），撤销只恢复变换，不会把被清除的包围盒找回来。
  5. 贴图上传/替换/移除（#16）——写回前（`uploadMatTexture`/`removeMatTexture` 各自的开头）拍快照：raw 侧该槽位当前 mapDef 的深拷贝、three.js 侧每个材质实例当前各槽位属性值，撤销时原样写回。不删除已经写入 `raw.images`/`raw.textures` 的条目本身（这轮上传/替换新建的记录会留在数组里当孤儿，跟移除贴图「不做垃圾回收」的既有做法一致）。
  6. UV 变换（#8）——跟材质字段同一套「手势」粒度（`beginUvFieldGesture`/`commitUvFieldGesture`），复用 `setUvField` 写回。

- **未接入撤销的操作**（如实列出）：材质拖拽指定（#19 画廊工具栏拖拽把材质指给某个网格）、包围盒生成/编辑/删除（#9）、测量基点新增/编辑/删除/改名（#10）、场景整体包围盒手动覆盖开关、节点/材质/贴图各处的备注文字字段（`anno.matNotes`/`texNotes`/`nodes[].note` 等）。这些如果之后需要撤销支持，接入方式跟上面六类是同一套 `pushUndo(label, undoFn)` 机制，不需要另起炉灶。
- **测试**：`_dev/test-undo-status.js`（Playwright，用真实样品 `chengdu-huagao-0801.glb`，29 项断言全部 PASS），覆盖状态栏新布局、日志面板从新入口正常展开、材质 baseColor 编辑撤销（three.js 实例 + raw 两边逐值核对精确恢复）、材质清理菜单批量操作撤销（全部 17 个材质 + 全部节点 matrix/scale 逐节点深比较精确恢复，不是只恢复一个）、连续两步不同字段编辑只撤销最近一步（第二步撤销后第一步的编辑仍保留，再撤销一次才回退第一步）、控制台全程 0 报错。另外重跑了 `test-mat-editor.js`/`test-alpha-doubleside.js`/`test-uv-editor.js`/`test-texture-upload.js`/`test-node-transform.js`/`test-instance-export.js`/`test-cleanup-menu.js`/`test-highlight.js`/`test-gallery-toolbar.js` 九个既有回归测试确认核心逻辑没有被这轮改动破坏——**发现一个跟这次任务无关的预置问题**：这几个测试脚本导出 GLB 那一步全部还在用 #21（头部菜单重构）之前的旧按钮 ID `#exportGlbBtn`，#21 把它改成了下拉菜单 `#saveGlbMenuBtn`→`#saveGlbLocalBtn`，导致这些测试跑到导出步骤时 `page.waitForEvent('download')` 永远等不到、30 秒超时崩溃——这是 #21 遗留的测试脚本陈旧问题，不是这次改动引入的（在这些脚本卡住之前，前面涉及材质/UV/贴图/节点变换/Instance/清理菜单/高亮/拖拽指定材质核心逻辑的断言全部正常打印/通过），本次任务没有修这几个旧测试脚本（超出任务范围），如实记录留给以后处理。

## 14. 节点移动/旋转基础编辑（对应 `Doc/TODO.md` #17）

**背景**：模型块表的「移动 T / 旋转 R°」两列此前是只读展示（沿父链累乘世界矩阵后 `decompose()` 出来的数字，见第 6 节），这次要做的是把它们变成可编辑输入框，让技术美术能在预处理阶段直接调整节点摆位——这是 editor 自己的基线数据调整，写回目标是 `raw.nodes[i]`，跟 `Doc/EDITOR-VIEWER-CONTRACT.md` 第二节 `UserScheme.transforms`（viewer 端终端用户的「个人方案」，第二层数据）是完全不同的东西，这次不碰后者。

**入口**：选中一个节点（复用第 6.1 节的选中高亮机制）后，节点行新增第四个操作按钮「✥ 移动/旋转」，点击弹出跟测量包围盒面板（第 7 节）同一套「居中浮层」外壳的编辑面板，6 个数字输入框（位置 X/Y/Z 米，旋转 X°/Y°/Z° 度）+ 每字段一个「还原到原始值」按钮 + 面板顶部「还原此节点全部变换」整体还原按钮。这轮只做数值输入这一条路径，视口拖拽 gizmo 没有做（属于加分项，任务原文允许先不做）。

**核心难点：世界空间 → 父级局部空间转换**（这是这个功能里最容易出错、也是任务要求重点验证的一步）：

three.js 的 `Object3D.position`/`.quaternion` 是**父级局部空间**属性，用户编辑的却是世界空间数值。转换公式：

```
localMatrix = inverse(parentWorldMatrix) * targetWorldMatrix
```

`targetWorldMatrix` 由「目标世界位置 + 目标世界旋转 + 编辑前的当前世界缩放」组合而成——缩放这轮不编辑，取编辑前的当前值原样保留，这样解出的 `localMatrix` 分解出的局部缩放才会精确等于编辑前的局部缩放，不会被这次 T/R 编辑意外改动缩放字段。

**实现过程中真正踩到的坑**：3AS 内部存在两套不同的「世界空间」，混用会导致换算结果被污染，且不会报错，只是数字对不上：

1. **glTF 原生世界空间**——`nodeWorldMatrix(ni)`（第 6 节，纯粹沿 `raw.nodes` 父链累乘 local matrix），模型块表「移动 T / 旋转 R°」两列、测量包围盒 `getNodeWorldRotationDeg()` 都是这套。
2. **视口显示坐标系**——three.js 的 `obj.matrixWorld`，比 ① 多套了一层 `preprocess()` 里 `model.scale.multiplyScalar(s)` + `model.position.set(...)` 的「整体缩放+落地居中」归一化（`model` 就是 `gltf.scene` 本身，这层缩放/平移直接摞在它身上，纯粹是为了模型在视口里显示得大小合适、居中摆好，不是 glTF 节点数据本身的一部分）。测量包围盒的顶点采样（`computeOBB`）、测量基点系统都是走这套（`Doc/EDITOR-VIEWER-CONTRACT.md` 第五节明确写了基点坐标系是「`nodeObjects.matrixWorld`」）。

任务要求「编辑的是跟节点表现在显示的移动T/旋转R°列同一套数据」，也就是必须用 ①。第一版实现里从 `obj.matrixWorld.decompose()`（②）取当前世界缩放去拼 `targetWorldMatrix`（①的位置+旋转 混 ②的缩放），真实样品测试时数值对不上（面板显示的初始值本身没错，但编辑后独立重算的世界坐标跟目标值相差几十倍，正好是 `preprocess()` 那个归一化缩放系数）——改成 `curS`/`parentWorldMatrix`/验证步骤全部统一走 `nodeWorldMatrix()`（①）之后，误差降到 1e-4 米/弧度量级的浮点噪声。**这是这次实现里最重要的一条经验**：三维引擎自己认的 `matrixWorld` 不一定等于应用里定义的「世界空间」，混用前要先确认是不是同一套坐标系。

**验证方式**：写回后不信任「局部值设对了」就完事，重新调 `nodeWorldMatrix(ni)`（读刚写回的新 `raw.nodes[ni]`，跟目标 T/R 独立比对，容差 1e-4，超差只 `logEntry` 警告不阻断操作）。测试脚本层面验证得更彻底——`_dev/test-node-transform.js` 里的核对**完全不调用被测的 `nodeWorldMatrix`/`applyNodeWorldTransform`**，而是在 Playwright 页面上下文里重新手写一遍「沿 `raw.nodes` 父链累乘」的独立实现（`rawWorldTR()`），拿这份独立实现的结果去跟编辑目标值比对——这样即使被测函数本身的算法有系统性错误，测试也不会因为"复用了同一段计算逻辑"而被蒙混过去。

**raw 端写回**：原来用 `matrix` 表示的节点继续写 `matrix`（`localMatrix.toArray()`）；原来用 `translation`/`rotation`/`scale` 分开表示（或者干脆什么变换字段都没有，等同恒等）的继续分开写 `translation`/`rotation`，`scale` 字段只有原来就存在才继续维护，不凭空新增——不强行把简单节点转成矩阵表示，按任务原文要求。

**「还原到原始值」的语义取舍**：还原基准是这个节点自己的原始 local 存储（`tables.nodeOriginal[ni]`，仿照材质编辑器 `tables.matOriginal` 的模式，在 `buildTables` 打开新文件时拍快照；创建 Instance 新建的节点在创建完成那一刻单独补一份，以创建后的最终摆位当自己的「原始值」，不是它所引用的原节点的摆位，不然还原会让两份实例叠在同一个位置）套上**当前**父链世界矩阵解出的世界 T/R——不是原始父链。这是一个需要说明的设计取舍：语义是"撤销我对这个节点自己的编辑"，不是"把整个场景倒回加载时的状态"；如果用户后来又单独编辑了这个节点的父节点，父节点那份编辑不应该被这个子节点的还原按钮意外撤销掉。单字段还原（比如只还原 X 位置）的做法是只把该字段替换成原始值，其余 5 个字段维持面板当前显示的值，重新走一遍完整的写回函数——不是把 6 个字段一起弹回原始状态。

**联动：包围盒**——节点变换改变后，如果这个节点之前生成过测量包围盒（第 7 节），朝向/尺寸不再对应新姿态，直接自动清除（`delete nodeAnno(name).bbox` + 重画线框），提示用户如需要请重新生成。这是需要用户知情的设计取舍：没有做「自动跟着重新计算」，因为那样用户手动调整过的包围盒旋转角会被静默覆盖，比「清除后需要重新点一下生成」更容易让用户困惑（生成本身是一步操作，成本很低）。

**实现记录**（2026-08-06，#17）：**先检查了有没有残留实现**——搜了"移动"/"旋转编辑"/"transformEdit"/节点位置相关关键词，`index.html` 里只有第 9 节包围盒的 `rotationDeg`、第 8 节基点的 `zRotation`、节点表已有的只读 T/Rdeg 展示，确认这次是干净状态，没有可复用的半成品代码，从零实现。核心函数 `applyNodeWorldTransform`/`getNodeWorldTR`/`getNodeOriginalWorldTransform`/`openTransformPanel`/`renderTransformPanel` 挨着 `nodeAnno()` 定义（`createInstance` 之前），面板 DOM（`#transformOverlay`/`#transformPanel`）跟包围盒面板共用同一套 CSS 外壳选择器；事件绑定跟包围盒面板同一处「静态 DOM 只绑一次」的位置。`createInstance` 补了 `createdIndices` 收集 + 创建完成后统一给新节点拍 `tables.nodeOriginal` 快照。

真实样品 `chengdu-huagao-0801.glb` 验证：选了 `Prof.Jimmy Choo`（父节点 `node_1`，典型的"网格节点+无名父级壳"结构，正好测局部值/世界空间转换）——移动世界位置后，测试脚本独立重算的世界坐标误差 0.000053m；旋转世界角度后误差 0.0005°；改动过程中缩放分量精确不受影响；节点表 T/R 两列同步刷新；单字段还原（只还原 X）后 X 精确回到原始值、Y/Z 保持编辑后的值不受影响；整体还原后 T/R 精确回到原始值（误差 0m/0°）；「另存为 GLB」导出后解析文件，局部矩阵跟内存里编辑后的 `raw.nodes[ni]` 逐分量一致（位置误差 0、旋转误差 3e-8 弧度）；先生成包围盒再改变换，确认包围盒被自动清除。控制台全程 0 报错。

验证导出环节额外发现一个**跟这次功能无关、但会干扰验证方式的既有细节**：`GLTFExporter.parse(model, ...)` 传入单个 `Object3D`（`model = gltf.scene`）导出时，结果会多包一层 `"AuxScene"` 根节点，这层根节点其实就是 `model` 自己，携带了 `preprocess()` 那层「整体缩放+落地居中」视口归一化——如果测试时沿导出文件的节点树一路走到场景根去重算世界坐标，会把这层视口归一化也算进去，跟编辑时用的目标值（① 那套坐标系）对不上。最终验证方式改成直接比较导出文件里节点自己的局部矩阵（`node.matrix`，不牵扯外面套了几层）跟编辑后内存里 `raw.nodes[ni]` 的局部矩阵——两者应该逐分量一致，这样不需要关心导出文件的根节点结构细节。

测试脚本：`_dev/test-node-transform.js`（Playwright，全部断言 PASS，可重跑复查）+ `_dev/test-node-transform-visual.js`（面板关闭状态下的干净视口截图，主脚本截图会被浮层挡住看不清物体）。截图：`_dev/shots/transform-00` 至 `transform-06`、`transform-visual-00`/`01`。

## 15. 材质/贴图大图预览弹窗（对应 `Doc/TODO.md` #26，2026-08-06 完成）

**开工前先检查残留实现**：搜了「预览弹窗」/`lightbox`/「大图」/`previewOverlay`/`imgPreview`/`texPreview`/`zoomOverlay` 等关键词，`index.html` 里全部无匹配，确认是干净状态，从零实现，没有可复用的半成品代码。

**三个点击入口，一套底层展示逻辑**：① 材质画廊色块（`matCardHtml` 里的 `.swatch`）② 材质详情贴图槽缩略图（`renderUvSlot` 里的 `.tex-thumb`）③ 贴图 Tab 表格行新增的缩略图列（新写的 `texRowThumbHtml`）。三个入口各自负责「怎么找到要展示的图」，找到之后统一调 `showTexPreviewImage()`/`showTexPreviewColor()` 渲染同一个居中浮层 `#texPreviewOverlay`/`#texPreviewPanel`（跟设置面板/包围盒面板同一套「居中浮层 + 点外部关闭」外壳，静态 DOM 只绑一次事件），不重复写弹窗开合/缩放逻辑三遍。

**尺寸取舍**：贴图原始长边 ≥800px 就按 1:1 原始像素显示（`texPreviewBaseSize()`），不额外放大，避免比原图还糊、也避免占满整个屏幕看不出实际分辨率；长边 <800px 才等比放大到长边=800，给个合理的最小可视尺寸。`#texPreviewBody` 本身 `overflow:auto`，原图很大（比如 4096×4096）时不会把弹窗撑出屏幕，靠滚动查看，也可以先滚轮缩小再看全貌——没有在 JS 里再夹一层"最大不超过屏幕"的额外强制缩放，滚动 + 缩放两种手段都给用户，不替用户做选择。

**滚轮缩放**：纯 CSS `transform:scale()`（`applyTexPreviewZoom()`），不重新解码/重绘图片本身，性能好，也不会因为反复缩放累积画质损失；范围 0.1×-8×。任务需求原文明确"不需要做平移拖拽"，所以只做了缩放，没有额外做 `mousedown`+`mousemove` 平移。

**纯色材质降级**（`showTexPreviewColor`）：点击一个只有 `baseColor`（`pbrMetallicRoughness.baseColorFactor`）没有任何贴图槽的材质色块时，弹窗展示一个放大的纯色块（320×320，同样支持滚轮缩放）+ 文字提示「此材质没有贴图，仅有颜色 `#xxxxxx`」，不留空弹窗，不报错。判断顺序跟 `matCardHtml` 色块本身「有贴图显示贴图缩略图，没有显示纯色块」完全一致（`getSlotTexture(mi,'baseColor')` 找不到或解码失败才降级），保持色块本身跟预览弹窗两处视觉认知统一。

**贴图 Tab 缩略图的三级 fallback**（`openImagePreviewByIndex`，按解码成本从低到高）：
1. 有材质当前正引用这张图（`rebuildTexTable()` 新增收集的 `t.refs = [{mi, slotKey}]`，跟原本就在收集的可读字符串 `used` 并行）——直接复用该材质对应槽位已经加载好的 `THREE.Texture` 实例（`getSlotTexture`），免解码，最快，画质也最高（GLTFLoader 解出来的原图，不是转码过的）；
2. `raw.images[ti].uri` 本身就是 data URI（本工具自己上传贴图时 `uploadMatTexture` 写的就是这种，或者原始 glTF 本来就用 `uri` 形式内嵌而不是走 `bufferView`）——直接当 `<img src>` 用，只需要另开一个 `Image` 探一下原始宽高（`naturalWidth`/`naturalHeight`）；
3. 都没有（原始 GLB 内嵌贴图从来没被任何材质引用过的孤儿贴图，也没有 `uri`）——从 BIN chunk 按 `bufferView` 现场解码，跟 `rebuildTexTable()` 异步解尺寸那段同一条路径（含 `currentBinOffset` 偏移——这正是本次任务背景提到的、这一轮开工前已经修复的那个真 bug：`bufferView.byteOffset` 是相对 BIN chunk 数据起点算的，不是相对整个 GLB 文件，漏加这个偏移会导致所有内嵌贴图解码失败）。

表格里同步渲染的缩略图（`texRowThumbHtml`，跟上面异步的 `openImagePreviewByIndex` 不是同一个函数）只能同步拿到前两级——第三级「BIN chunk 现场解码」耗时不能放进同步渲染路径，这种情况先显示一个可点击的占位符（`.tex-row-thumb-ph`），点击时才走 `openImagePreviewByIndex` 的异步解码分支。

**验证用真实带贴图样品**：任务背景指出本机 `C:\Users\Lin\Desktop\Glb\` 下有真实项目 GLB 带真实贴图（客户 WIP 文件，按项目既有政策只用绝对路径引用，不拷贝进仓库），用 `画稿飞扬v2.glb`（20 材质、9 张真实贴图，含 2048×1152 等大尺寸贴图）验证：材质画廊 7 张卡片带贴图缩略图（证明 `rebuildTexTable()` BIN 偏移 bug 修复后内嵌贴图解码成功）；点色块打开预览，展示尺寸精确达到贴图原始尺寸 2048×1152（长边本来就 ≥800，不做多余放大）；滚轮向上/向下缩放，`transform: scale()` 数值确实相应变大/变小；点弹窗外部区域关闭；材质详情贴图槽缩略图、贴图 Tab 表格行缩略图（9 行全部同步生成缩略图）两个入口各自单独点击验证打开成功。再用 `chengdu-huagao-0801.glb`（17 材质、0 贴图，纯黑 `fallback Material`）验证纯色材质降级：画廊 0 张卡片带贴图缩略图（确认样品真的 0 贴图），点第一张色块弹窗展示纯色块 + 文字「此材质没有贴图，仅有颜色 `#000000`」（不是图片、不报错、不留空弹窗），点外部同样能正常关闭。控制台全程 0 报错，共 20 项断言全部 PASS。

**已知交互取舍（如实记录，不是漏掉）**：色块点击同时承担「选中材质」和「打开预览」两个职责——块视图下色块占卡片绝大部分可视面积，点色块基本等于点卡片主体；如果只是想选中材质、不想弹出预览，可以点卡片上的名字/索引文字区域（不在色块范围内，验证过点这里不会触发预览）。这带来一个可预期的连带影响：本轮之前 TODO（如 #19）写的一些回归测试脚本用 `page.click('.mat-card[...]')`（点击卡片几何中心）来模拟"选中材质"，块视图下卡片中心通常落在色块区域内，会连带弹出预览弹窗、挡住脚本后续对其它按钮的点击——这是这次新功能字面对齐任务需求（"点击色块应该弹出大图预览"）后的直接、可预期结果，不是本次实现的 bug，如果以后要重跑那些旧脚本需要相应调整点击目标（改成点名字文字区域，或者先关掉预览弹窗再继续）。

**测试脚本**：`_dev/test-preview-lightbox.js`（Playwright，20 项断言全部 PASS，可重跑复查）。截图：`_dev/shots/preview26-00` 至 `preview26-05`。

---

## 16. 详情面板 T/R/S 内联编辑 + 节点列表三行布局 + 去掉合并标签 + 拖拽重新挂靠父节点（对应 `Doc/TODO.md` #29，2026-08-06 完成）

**开工前先检查残留实现**：搜了「拖拽挂靠」/`reparent`/「节点详情重排」等关键词，`index.html` 里全部无匹配，确认是干净状态，从零实现，这几轮没有代理留下可复用的半成品代码。

### 16.1 详情面板 T/R/S 内联可编辑（任务一）

原来 §14（#17）做的移动/旋转是独立弹窗（`#transformOverlay`），这次去掉弹窗，改成内联在选中节点详情区（`renderNodeDetail()`）里：缩放 S / 移动 T / 旋转 R° 三组横向并排在同一行（`.trs-edit-row`，每组一个短标签 + 3 个数字输入框），复用 §14 已经写好的 `applyNodeWorldTransform`/`getNodeOriginalWorldTransform` 核心写回逻辑，没有重新推导矩阵数学——唯一的改动是给 `applyNodeWorldTransform` 加了第 4 个可选参数 `targetS`（不传时保持 §14 原有行为：取编辑前的当前世界缩放，等于「不编辑缩放」；传了才把它当目标缩放一起写入），因为缩放这次也从「只读展示」升级成可编辑（原来 §14 canonical 决定不编辑缩放，这次任务原文明确要求 S 也做成输入框）。

**「相对基点坐标」只读 vs 可编辑的取舍（任务原文要求判断并说明理由）**：判断维持只读展示，不做成独立可编辑输入源。理由：
- 它本质是「节点世界坐标 T 相对基点的换算显示」（`computeRelativeToBasepoint()`，见 §8），T 才是写回 `raw.nodes` 的权威数据；
- 编辑 T 之后这个值会自动跟着重算（`applyNodeTrsFromInputs` 写完会整体 `renderTab('node')`，`renderNodeDetail()` 每次渲染都重新调 `computeRelativeToBasepoint()`，天然联动，不需要额外接线）；
- 反过来如果把它做成可编辑输入源，需要处理「没有关联基点时编辑无意义」「基点以后被移动/删除时这个值的语义会失去锚点」这些额外的边界情况，比"T 是唯一权威、相对基点只是随 T 联动重算的只读派生视图"这个简单模型更容易出问题，且没有实际收益（用户要移动节点，直接编辑世界坐标 T 更直观，不需要多一条通过基点间接编辑的路径）。

**实现过程中真实踩到的两个坑（都已修复）**：

1. **精度坑**：9 个输入框显示时按固定小数位四舍五入（原计划 S/T 都是 4 位、R 3 位）。真实样品 `chengdu-huagao-0801.glb` 有节点世界缩放小到 `6e-5` 量级（`Prof.Jimmy Choo` 的 S ≈ `0.00006014941722681578`），`.toFixed(4)` 出来是 `"0.0001"`，相对误差 66%。这 9 个输入框设计成「一起提交」（改一个字段也会连带把另外 8 个当前 DOM 显示值一起送进 `applyNodeWorldTransform`），如果直接读 DOM 显示值当真值，用户只想改 T，没碰过的 S 就会被这个有损的显示值悄悄腰斩精度——这是用真实样品测试时才发现的真实 bug，不是假设的边界情况。**修复**：① 缩放 S 的显示精度从 4 位提到 8 位小数（CAD 导出的节点缩放常见到 1e-5 量级，`.trs-group input#ndSx/#ndSy/#ndSz` 单独加宽到 92px 容纳）；② `renderNodeDetail()` 每次渲染把「这一刻 9 个输入框对应的精确（未四舍五入）值」存进模块级 `lastNodeDetailTRS`，`applyNodeTrsFromInputs()` 提交时对每个字段判断「DOM 当前值是不是约等于『如果没改、这个字段应该显示成什么』」——约等于就用精确快照值（不信任有损的显示值），明显不一样才是用户真的敲了新数字，用解析出的 DOM 值。

2. **重入坑（更隐蔽，务必记录）**：「⟲ 还原此节点全部变换」按钮跟 9 个输入框放在同一个 `.trs-edit-row` 里。为了让「Tab 键在 T/R/S 九个字段之间切换」不会每切一次就提交一次半成品数据，这次没有给每个输入框各自挂 `onchange`，改成整组只挂一个 `focusout` 委托监听（`trsRow.addEventListener('focusout', ...)`，用 `e.relatedTarget` 判断新焦点还在不在组内，还在组内就不提交，真正离开整组才提交一次）。**这个设计本身是对的，但暴露了一个连锁 bug**：点击还原按钮时，按钮先获得焦点，`onclick`（`revertNodeTrsAll`）执行到 `applyNodeWorldTransform` 内部固有的 `renderTab('node')` 调用时，会把整块 `#tables` DOM（连同刚获得焦点、正在处理点击事件的按钮自己）一起替换掉——浏览器在「移除一个正获得焦点的元素」这一步会同步触发它的 `blur`/`focusout`，冒泡到（即将被替换的）`.trs-edit-row` 的 `focusout` 监听器，导致 `applyNodeTrsFromInputs()` 被**重入调用**一次；而此时 DOM 替换动作本身还没执行完（旧的 9 个输入框已经从文档移除、新的还没插入，`lastNodeDetailTRS` 也还没更新成还原后的值），重入调用读到的是「这次还原之前」的旧显示值，会把刚刚才写对的还原结果又用旧值覆盖回去。**用真实样品实测复现过这个 bug**：改 T 和 S 各一次后点还原按钮，位置/缩放数值完全没有变化（表现上像按钮没反应，实际是被重入调用悄悄覆盖回去了）。**修复**：给 `applyNodeTrsFromInputs`/`revertNodeTrsAll` 这一组「会互相触发」的入口加共享重入标记 `nodeTrsBusy`——谁先跑就把它置 `true`，重入调用一进来发现标记已经是 `true` 就直接跳过（不产生任何写回），等最外层调用结束（`finally` 块）才清掉标记。这条经验具有一般性：**任何"提交动作本身会触发 DOM 重建，且触发这个提交的元素恰好是这次重建会替换掉的 DOM 的一部分"的场景，都有同样的重入风险**，不是这次任务独有的坑，以后类似"内联编辑 + 提交时整体重渲染"的设计要留意这一条。

**验证**：真实样品 `chengdu-huagao-0801.glb`，选一个有父节点的叶子节点（`Prof.Jimmy Choo`，父级 `node_1`）——移动世界位置后，测试脚本独立重算（不调用被测的 `nodeWorldMatrix`/`applyNodeWorldTransform`，自己在页面里重新实现一遍父链累乘，见 `_dev/test-todo29.js` 的 `rawWorldTR`）确认世界坐标误差 `0.000000m`；缩放从 `~6e-5` 改到 `~9e-5`（原始值 ×1.5）后独立重算确认世界缩放误差同样 `0.000000`（且未殃及位置）；相对基点坐标验证有值且确认 DOM 里没有对应的 `<input>`（只读）；还原按钮点击后位置/缩放误差都是 `0.000000`（验证了上面那个重入 bug 已经修复，不是巧合对上）。

### 16.2 节点列表三行信息布局（任务二）

下方精简列表从原来 7 个独立 `<th>` 列改成两列：**节点**（树状缩进/折叠三角/组标签/名称，这一列也是拖拽手柄所在，见 16.4）+ **信息**（一个 `<td class="node-info-cell">` 内部用 `.node-info-row` 纵向分三行，不强求物理拆成三个 `<tr>`，视觉分组清楚即可）：
- 第一行 `.node-info-mats`：材质色块+编号，水平排列，沿用原来的画法（`n.mats.map(...)`）；
- 第二行 `.node-info-flags`：三个勾选框——显示 / 允许编辑 / **允许选中**（新增）；
- 第三行 `.node-info-meta`：别名输入框 + 关联基点下拉框 + 备注输入框（三个放一行）——原文只提到「别名+关联基点」两项放一行，这次多带上「备注」（`note` 字段）一起放进第三行，理由：`NodeAnnotation.note` 这个字段本来就存在、需要有地方编辑，硬件式地丢在别处会破坏"三行说清楚"的排布意图，就近并进语义相关的第三行（都是"给这一行起标识用的文字信息"）更合理，不是遗漏原文要求。

**「允许选中」新字段**：就是 `Doc/EDITOR-VIEWER-CONTRACT.md` 第三节「组锁定 `atomicGroup`」这个字段第一次有了真正的 UI（该文档第七节「待确认」第 1 条这次一并回填，见该文档）。语义：勾选框状态是 `atomicGroup` 的**反面**——勾上＝`atomicGroup:false`（默认态，允许单独选中/编辑）；取消勾选＝`atomicGroup:true`（这个节点被"冻结"，不能单独选中/编辑，只能作为它所属的组整体被操作）。写进跟 `visible`/`allowEdit` 同一个 `nodeAnno()` 记录对象（`nodeAnno()` 默认对象新增 `atomicGroup: false`）。`nodeAnnoHasOverride()`（§6.2 合并行挂载点判断用的"这个节点是否有非默认注释"判定）也加了 `a.atomicGroup === true` 这一条，保持跟其它字段一致的语义——一个节点被标了组锁定，也算是"用户对这个节点做过有意义的标注"，合并链挂载点重定向逻辑应该认得到。

**验证**：真实样品节点表精确剩 2 列表头，每行信息格 3 个 `.node-info-row`；「允许选中」默认勾选（`atomicGroup` 默认 `false`），取消勾选后 `nodeAnno(name).atomicGroup` 精确写成 `true`，重新勾选精确写回 `false`；显示/允许编辑两个既有勾选框回归验证仍正常工作。

### 16.3 去掉「合并」标签（任务三）

§6.2（#20）做的单子节点合并显示逻辑完全不变（`walk()` 里"纯包装组、只有一个子节点就跳过不占行"的判断、`resolveNodeRowTarget()` 的注释挂载点重定向规则，一行代码都没动）——只是不再在 UI 上告诉用户"这一行是合并过的"：列表行里的 `<span class="tag merge">⊂合并</span>` 标签、详情区的 `.node-merge-note` 说明条（"此行合并自单子节点分组链：xxx › xxx"那段文字），这两处 UI 呈现整个删掉，连带删掉对应的 CSS 规则（`.tag.merge`、`.node-merge-note`）。`resolveNodeRowTarget()` 仍然在每次渲染时被调用——`targetName` 还要用（决定"关联基点"下拉框、注释挂载到哪个节点名字上这些幕后逻辑），只是不再拿 `ancestors.length` 去渲染任何用户可见的"合并"提示。

**验证**：真实样品页面全文搜索确认不再出现"⊂合并"四个字、`.tag.merge`/`.node-merge-note` 元素数量都是 0；同时验证挂载点重定向逻辑本身仍在背后正常工作——`resolveNodeRowTarget()` 对已知的合并链节点（`L6`）仍然正确返回 `ancestors.length > 0`（链路信息还在，只是不显示）。

### 16.4 拖拽重新挂靠父节点（任务四，「非常重要」——用户原话）

**交互**：节点行的**名称格**（`<td class="node-name-cell" draggable="true">`，不是整个 `<tr>`）挂 `draggable="true"`——只挂在名称格而不是整行，是因为第二列信息格里全是 checkbox/输入框/下拉框，`draggable` 覆盖到那些控件上会跟正常的点击/输入交互打架（比如想拖动文字选区却触发了元素拖拽）；`dragover`/`drop` 挂在整个 `<tr>`，松到这一行任意位置（包括信息格）都算"拖到这一行上"。

**视觉反馈**（`bindNodeRowDragDrop()`）：`dragstart` 记录 `draggedNodeNi`（模块级变量）；`dragover` 时用 `canReparent()` 现判合法性——合法目标 `preventDefault()`（浏览器允许真正触发 drop）+ 整行淡黄背景 + 名称格底部一条黄色实线 + 追加文字「→ 将成为其子节点」；非法目标（自己或自己的子孙）**不 `preventDefault()`**（浏览器原生显示"禁止放置"光标，天然拦一道）+ 追加红字「→ 不能挂到自己/子孙节点上」。

**写回两处**：
1. `raw.nodes[]`（`moveRawNodeToParent()`）：把被拖节点的索引从原父节点的 `children[]`（或者它原本是顶层节点时的 `raw.scenes[i].nodes`）里摘除，加进新父节点的 `children[]`——跟 §6（#6，创建 Instance）「接入场景图」那段用的是**同一套寻址约定**（`parentNi` 有就挂 `raw.nodes[parentNi].children`，没有就挂 `raw.scenes[sceneIdx].nodes`），这次额外多了"摘除"这一步（创建 Instance 只有插入，不需要摘除）。
2. three.js 场景图（`moveObjToParent()`）：`newParentObj.attach(draggedObj)`。

**关键技术点：`.add()` vs `.attach()`（任务要求重点说明的一点）**——查了 `vendor/three.module.js` 里 `Object3D` 类的定义（约 7173 行起）：

- **`.add(object)`**（约 7455 行）：内部只做 `object.removeFromParent(); object.parent = this; this.children.push(object);`——**完全不碰 `object.position`/`.quaternion`/`.scale`**（局部变换）。也就是说物体被加进新父节点之后，它的局部坐标系数值原样不变，但"局部坐标系"本身已经变成了新父节点的坐标系——如果新旧父节点的世界变换不一样（几乎总是不一样），物体的世界空间位置会跟着跳到别的地方。**结论：`.add()` 保持的是 local 变换不变，会导致世界位置跳变**，这次场景不能用。
- **`.attach(object)`**（约 7551 行）：源码注释原话是「adds object as a child of this, while maintaining the object's world transform」。内部先读 `this.matrixWorld`（新父节点的世界矩阵）求逆，如果物体原来有父节点就再乘上原父节点的 `matrixWorld`，算出一个"从物体现在的局部矩阵变到新局部矩阵"的变换矩阵，`object.applyMatrix4(...)` 把这个变换应用上去再 `.decompose()` 回 `position`/`quaternion`/`scale`。等价于：`newLocalMatrix = inverse(newParent.matrixWorld) * object.matrixWorld_old`——**结论：`.attach()` 保持的是世界空间变换不变，自动把局部矩阵反算调整好**，这正是这次任务要求的行为（"保持世界空间位置不变，只改父子关系"），three.js 官方就是为这个场景设计的这个方法，**不需要自己手写矩阵反算**。这次直接用 `.attach()`，没有重新发明轮子。

**额外确认（不是想当然，真的推导过）**：3AS 内部一直很小心地区分「`nodeWorldMatrix()`（纯 `raw.nodes` 父链累乘的 glTF 原生世界空间）」和「`obj.matrixWorld`（three.js 显示坐标系，比前者多套了一层 `preprocess()` 的模型整体归一化缩放/居中，见 §14 `applyNodeWorldTransform` 头部注释）」——这两套坐标系不能混用，混用是 §14 开发时踩过的真实的坑。`.attach()` 内部用的是后者（`obj.matrixWorld`），会不会因此让算出来的局部变换跟 `raw.nodes` 原生空间对不上？推导一下：设 `model` 是 `preprocess()` 加的那层外层归一化变换 `N`（只在 `model` 自己身上，`raw.nodes` 里所有节点都是它的后代），拖拽节点和新父节点的 `obj.matrixWorld` 分别是 `N·W(dragged)`、`N·W(newParent)`（`W()` 是 `nodeWorldMatrix`，纯 raw 空间）。`.attach()` 算的局部矩阵 `= inverse(N·W(newParent)) · (N·W(dragged)) = inverse(W(newParent))·inverse(N)·N·W(dragged) = inverse(W(newParent))·W(dragged)`——`N` 精确抵消，结果跟直接在 raw 原生空间里算「`inverse(父级世界矩阵)·目标世界矩阵`」（`applyNodeWorldTransform` 用的同一个公式）**完全一致**。所以虽然 `.attach()` 走的是 three.js 的显示坐标系，算出来的局部变换写回 `raw.nodes` 仍然是正确的——不需要为了避免坐标系混用而放弃用 `.attach()`。代码里没有只信这个推导，`.attach()` 之后仍然额外用 `nodeWorldMatrix()`（raw 原生空间）独立重算一遍世界坐标校验（`reparentNode()` 里的 `posErr`/`rotErr` 校验，跟 §14 「写回后必须验证」同一个纪律），双重保险。

**`.attach()` 之后的 raw 端同步**（`syncRawLocalFromObj()`）：跟 `applyNodeWorldTransform`「raw 端写回」那段同一个约定——原来是 `matrix` 表示就重新算 `matrix`（`obj.matrix.toArray()`），原来是 `translation`/`rotation`/`scale` 分开表示就分别写（`obj.position`/`.quaternion`/`.scale`），不强行转换表示方式。

**已知限制（`.attach()` 源码自己的注释就写了，不是这次引入的新问题）**：`.attach()` 的官方注释原话还有一句「Note: This method does not support scene graphs having non-uniformly-scaled nodes(s)」——因为 `Object3D.matrix` 本质上只能装 `position`/`quaternion`/`scale` 三元组（跟 glTF 节点的 TRS 表示是同一个模型），如果目标局部矩阵因为"非均匀缩放 + 旋转叠加"产生了数学意义上的 shear（切变，不是纯旋转+缩放能表示的形状），`Matrix4.decompose()` 能保证 `position`（平移分量，矩阵最后一列，不受 3×3 部分是否正交影响）精确，但抽出来的 `quaternion`/`scale` 未必能完美复现原始朝向/形状——这是 three.js Object3D 用 TRS 三元组表示变换这个设计本身的固有限制，`applyNodeWorldTransform`（§14）自己手写的矩阵数学也是同一个数学模型、同一个限制，不是 `.attach()` 独有的新问题。**真实样品验证了这个边界情况**：`L6` 节点自身世界缩放 `S=[0.004,0.004,0.001]`明显不均匀，把另一个节点重新挂靠到 `L6` 下面时，独立重算确认**位置依然精确保持（误差 `0.000000m`）**，但旋转角度确实出现了偏差（约 8.7°，缩放也从原来均匀的量级变成了不均匀的三个不同值）——这印证了源码注释的警告是真实会触发的，不是纸面上的免责声明；同时也确认了"位置不跳变"这条任务最核心的诉求，即使在这个已知限制场景下依然成立。主验证流程特意挑选了一对"新父节点自身缩放均匀"的节点，得到干净的"T/R/S 全部精确保持"结果；这个 shear 场景是单独追加的一次验证，专门确认边界情况下核心诉求没有失守，两次验证覆盖了"正常路径"和"已知限制路径"两种情况。

**防止循环引用**：`canReparent(draggedNi, newParentNi)` 先查 `draggedNi === newParentNi`（挂到自己身上），再查 `isDescendantOf(newParentNi, draggedNi)`（新父节点是不是拖拽节点自己的后代，沿 `raw.nodes[].children` 递归查——纯数据结构判断，不依赖 three.js 场景图）。两个检查任意一个不通过就拒绝，`reparentNode()` 内部再校验一遍（不只信 UI 层的 `dragover` 判断），拒绝时 `status()`+`logEntry('warn', ...)` 给用户反馈，`raw.nodes`/three.js 场景图都不动，不会把数据结构搞坏。

**重新挂靠对"单子节点合并显示"（§6.2）的影响（如实记录，不是 bug）**：模型块树里每一行的 `draggable`/拖拽目标都是 `n.ni`——也就是这一行「实际代表」的终点节点（如果这一行是合并链，`n.ni` 是链的终点，比如 `L6`，不是链顶部的包装组 `node_3`）。所以拖拽操作只移动这一行代表的终点节点本身，不会把它头顶那条包装组链一起搬走。这带来一个可预期的连带效应：如果一个包装组只有这一个子节点（本来就是因为这样才被合并显示），把这个唯一的子节点拖走之后，这个包装组会变成 0 个子节点的空组——下次渲染时它不再满足"只有一个子节点"的合并条件，会作为一个独立的空组行重新出现在列表里（不是 bug，是"唯一子节点被移走"这个操作在任何树状结构编辑器里都会有的自然结果，参照删除单子节点的组的行为是一致的）。这次没有做"连同头顶整条包装组链一起搬走"这种更复杂的语义（任务原文没有对合并链场景给出特别要求，按最简单、行为最容易预测的解释实现：拖哪个节点就移动哪个节点）。

**撤销栈 + 操作脚本接入（任务第 4 点，判断接入成本不高，做了）**：
- **撤销**（§13/#22）：`pushUndo()` 记一条撤销闭包——不是简单调用 `reparentNode(newParentNi, oldPni)`（那样局部变换会经过两次 `.attach()` 反算，浮点误差可能累加，语义上也不精确等于"回到原状"），而是深拷贝这次操作前的原始 `raw.nodes[draggedNi]` JSON 快照（`draggedJsonBefore`），撤销时先 `moveRawNodeToParent()`+`moveObjToParent()` 挂回原父节点，再**精确恢复**这份原始快照的字段（`matrix`/`translation`/`rotation`/`scale`，只恢复原来就存在的字段，不凭空新增），用 `nodeLocalMatrix()` 重建 three.js 局部变换——比再走一次 `.attach()` 反算更贴合"撤销＝完全恢复原状"的语义。
- **操作脚本**（§10/#13）：`recordScriptOp('reparentNode', {byName, kind:'node', atIndex}, {newParentName, newParentAtIndex})`，`newParentName`/`newParentAtIndex` 不是 `ScriptTarget`（`target` 字段记的是被拖节点本身），只是这次操作的一个参数，重放时按 `target.atIndex` 同一套「hintIndex 优先命中，找不到再按名字找第一个」策略解析（`findNodeIndexByName`），跟其它 `op` 类型解析同名候选的逻辑一致。`SCRIPT_OP_LABEL`/`executeScriptOp` switch/`SPEC.md` `ScriptEntry.op` 联合类型都同步加了这一项。`reparentNode()` 本身就是"操作入口"（拖拽 drop 直接调用，中间没有另一层 UI 手势包装函数），`recordScriptOp()` 调用写在函数体末尾，靠 `scriptReplaying` 标记防止重放时自我膨胀脚本——跟 `createInstance`/`runMaterialCleanup`/`uploadMatTexture`/`removeMatTexture` 同一类模式。

**验证**：真实样品 `chengdu-huagao-0801.glb`，选两个互不相关的叶子节点（`Prof.Jimmy Choo` 拖到 `PArc864` 下——特意挑了一对"新父节点自身世界缩放均匀"的节点，得到干净结果）：
- ①视口世界空间位置没有跳变——测试脚本独立于被测代码重新沿 `raw.nodes` 父链累乘（不调用被测的 `nodeWorldMatrix`/`reparentNode` 本身），拖拽前后世界坐标误差 `0.000000m`，旋转/缩放误差同样 `0.000000`；
- ②节点树层级结构确实变了——拖拽后 `Prof.Jimmy Choo` 的新父节点索引精确等于 `PArc864`，`PArc864.children[]` 精确包含 `Prof.Jimmy Choo`，原父节点 `children[]` 里已经不再包含它（正确摘除，没有重复挂两处）；
- ③导出注释 JSON / 「另存为 GLB」都确认了新的父子关系被正确写出——GLB 解析后按（清洗过点号/空格的）节点名找到导出文件里 `PArc864` 节点，其 `children[]` 精确包含 `Prof.Jimmy Choo` 导出后的新索引；
- 「拖到自己子孙节点上」用一对有真实父子链的节点（`node_1`/`Prof.Jimmy Choo`）验证 `canReparent()` 正确返回 `false`，`reparentNode()` 真正调用也拒绝执行（不只是检查函数说不行），`raw.nodes` 长度前后不变（数据结构没有出错）；
- 拖拽悬停在合法目标行上方时截图确认视觉反馈（`node-drop-target` 类，黄色高亮 + 「→ 将成为其子节点」提示文字）；
- 已知限制专项验证：把节点拖到自身缩放不均匀的 `L6` 下面，独立重算确认位置精确保持（误差 `0.000000m`），旋转角有偏差（符合 `.attach()` 文档警告的预期）；
- 控制台全程 0 报错。

**测试用真实拖放事件而不是直接调函数**：无头 Chromium 下用原生鼠标 `down`/`move`/`up` 序列**不会可靠触发** HTML5 拖放 API 的 `dragstart`/`dragover`/`drop` 事件（这是浏览器独立的一套拖放会话机制，不是简单靠鼠标事件就能自动映射出来的，任务背景也提示了这一点）——测试脚本改成手动构造真实 `DataTransfer` 对象 + 派发 `DragEvent`（`new DragEvent('dragstart'/'dragover'/'drop', {bubbles:true, cancelable:true, dataTransfer})`），能在 `dragover` 和 `drop` 之间插入截图/断言中间状态（悬停反馈），比 `locator.dragTo()` 更可控。

**测试脚本**：`_dev/test-todo29.js`（Playwright，43 项断言全部 PASS，可重跑复查，覆盖任务一至任务四全部验收点）。截图：`_dev/shots/todo29-01`（三行布局）至 `todo29-07`（拖拽结果）。重跑既有回归测试 `test-bbox.js`/`test-basepoints.js` 全部通过；`test-instance-export.js`/`test-highlight.js`/`test-mat-editor.js`/`test-undo-status.js` 撞上两个跟这次改动无关的既有已知问题（如实记录，不是这次引入的）：① `#exportGlbBtn` 旧按钮 ID（`Doc/TODO.md` #22 已经记录过的 #21 遗留问题，这几个脚本还在用改版前的选择器）；② `#texPreviewOverlay` 拦截点击（`Doc/TODO.md` #26 已经记录过的预期连带效应，点材质色块会顺带弹出预览弹窗挡住后续点击）——两者都在这次任务开始之前就已存在，命中这两个坑之前的断言全部正常通过。测试钩子：`window.__debugReparent`（`reparentNode`/`canReparent`/`isDescendantOf`/`draggedNodeNi`）、`window.__debugTransform` 更新（去掉 `openTransformPanel`/`renderTransformPanel`/`transformPanelNi`，新增 `applyNodeTrsFromInputs`/`revertNodeTrsAll`）。

---

## 17. UI 重排 Round 3：贴图/模型块/场景面板重排 + 响应式支持（口述需求记录，2026-08-06，待评审/排期，未实现）

**背景**：Round 2（材质面板「详情上/列表下」重排 + 说明收进 ⓘ 菜单 + 滑动条黑色化 + 视口新工具条）的设计方案已经发布评审——`Doc/2026-08-06-material-panel-redesign.html`（huashu-design，含真实截图证据 + 线框对比稿），用户看完后确认了方向，并追加了这一轮更大范围的需求：贴图 Tab、模型块 Tab、场景 Tab 三个面板都要做同款「详情在上/列表在下」重排（跟 Round 2 材质面板提议是同一个方向，这次扩大到全部面板），另加若干具体 bug/缺失功能/响应式支持。

**Round 3 设计方案已发布**：`Doc/2026-08-06-panels-round3-redesign.html`（huashu-design，用真实样品 `画稿飞扬v2.glb` 逐条核对现状后产出）。核心结论：① 贴图"重复图片"排查确认不是代码 bug——`raw.images[]`/`raw.textures[]` 9:9 严格一对一、`bufferView` 字节区间互不重叠、缩略图 data URL 逐字节比对全部不同，是两张不同源文件长得像，不是渲染重复，这轮不当 bug 修；② 模型块 Tab 的"详情上/列表下"骨架其实已经在（#20 做的），问题是列表行 4 行（多了「备注」行，需要去掉恢复三行）、顶部长说明文字、材质色块数量不齐；③ 场景 Tab 需要拆成元数据卷展栏/包围盒/基准点三个独立分区，基点优先级从纯文字提示改成下拉菜单。方案里留了 3 个待确认点（贴图删除时多材质共享如何处理、基点优先级下拉是否允许用户覆盖自动值、响应式横屏规则的具体像素值），排期前需要用户过一遍确认。

### 17.1 贴图 Tab

**已实现，2026-08-07，`Doc/TODO.md` #35，实现记录见 §7 末尾**——下面 4 条需求逐条对应：第 1、2、4 点全部完成（详情/列表骨架对调、跳转联动、删除/改名/替换三个操作补齐）；第 3 点「重复图片」在 §17.1 提出前就已经在这次任务的前置排查里确认过不是代码 bug（`raw.images[]`/`raw.textures[]` 9:9 严格一对一，字节区间不重叠），本次任务描述明确排除、不需要重新排查。

1. **从材质编辑器跳转到贴图**：材质详情编辑器里，某个贴图槽已经关联了一张贴图时，需要有个入口能直接跳转到「贴图」Tab 并定位到对应那张贴图——跟现有「用于节点」chip 跳转材质、视口取色跳转材质画廊（§6.1/§6.3）是同一类「跳转+定位+高亮」模式，这次是贴图方向的对应实现，不是新发明交互。
2. **上下分栏**：贴图 Tab 也要改成「上面详情，下面列表」，跟材质面板 Round 2 提议、17.2 模型块面板是同一个骨架规则，三个 Tab 不要各自一套布局。
3. **Bug：贴图列表出现重复图片**——需要先复现定位原因（怀疑跟 `raw.images[]`/`raw.textures[]` 的引用关系有关：一张图片数据可能被多个 `raw.textures[]` 条目引用，如果贴图表是按 `textures[]` 逐条渲染而不是按去重后的 `images[]` 渲染，同一张图会在列表里出现多次；需要先读代码确认渲染依据的是哪个数组，再判断是不是这个原因，排期时一起定修复方案）。
4. **缺失编辑/删除功能**：贴图列表目前看不到删除、改备注等操作入口，需要补上（贴图表本来就有备注字段可编辑，这里的「编辑」具体指什么——比如替换贴图文件、改名——需要跟用户确认清楚范围，见下方待确认清单）。

### 17.2 模型块 Tab

**已实现，2026-08-07，`Doc/TODO.md` #36，实现记录见 §6.4**——下面 8 条需求逐条对应：第 1（上下分栏骨架）在 #20 就已经是原型，这次是打磨对齐；第 2（对齐，材质色块长短不一）、第 4（Instance 改名）、第 5（说明文字砍掉挪走）、第 7（备注列去掉重申三行结构）、第 8（子节点表示更明确）全部完成；第 3（操作入口重排，"导出"挪进"蓝框"）按 §17.4 决策记录第 1 点的确认结论（挪进视口新工具条）实现，`#modeExportBtn` 是 #34 做的，这次去掉了详情区重复的导出按钮；第 6（响应式）不在这次范围内，留给 `Doc/TODO.md` #38。

现状被用户评价为「特别混乱」，具体要求：

1. **上下分栏**：跟贴图/材质同一个方向（模型块面板 #20 已经是「详情区常驻+列表精简」这个骨架的原型，这次是继续打磨对齐到 Round 2 的规范形态，不是从头重来）。
2. **对齐**：横向、纵向都要对齐——现状详情区/列表区的列宽、同一行内多个字段的对齐存在问题，具体哪几处没对齐，实现前需要走查列出清单。
3. **操作入口重排**：「导出」按钮挪到前面，放进「蓝框」里——这里「蓝框」应指 Round 2 设计方案里提议的视口右侧新工具条（选择/移动/旋转/缩放/包裹框/中心点），需要跟用户确认「导出」是要整合进那条工具条里，还是详情区自己的操作按钮组内部往前挪顺序，两种是不同的改动范围（见下方待确认清单）。
4. **Instance 改中文名**：「⧉ Instance」按钮文案改成「样例复制」（全应用范围内出现「Instance」字样的地方都要一起改，包括撤销栈提示文字/操作脚本标签等，不只是按钮本身）。
5. **说明文字砍掉/挪走**：详情区顶部常驻的这段说明——

   > 「节点树共 112 个节点 · 56 个网格块 + 56 个分组 · 只包一个子节点的分组自动合并显示成一行（合并规则/注释挂载点见 Doc/EDITOR-SPEC.md §6.2）· 点一行查看顶点/三角/通道/属于/材质/世界变换等详情，「创建Instance/导出/包围盒」操作入口 + T/R/S 内联编辑都在上方详情区 · 拖动一行的名称到另一行上可以把它重新挂靠成那一行的子节点（保持世界空间位置不变，见 Doc/TODO.md #29） · 「关联基点」下拉选该节点相对哪个基点计算测量数值，留空＝用场景第一个基点兜底，橙色标记的基点管理见「场景」Tab（见 Doc/EDITOR-SPEC.md §8）」

   用户原话：「不要也看不懂」——不只是嫌长，是内容本身对用户不好懂。跟 Round 2 材质面板提的「常驻说明收进 ⓘ 菜单」是同一个模式，这里直接照搬复用，不用重新设计一遍交互。
6. **响应式**：支持更小的屏幕、支持横屏。这条其实是全应用范围的要求（不止模型块 Tab），放在这条底下记录是因为用户讲模型块面板时顺带提到的，实现时按全局响应式断点处理，不是模型块单独一套。当前已知的响应式行为只有 `README.md`/`SPEC.md` 提过的「< 900px 上下分栏」，这次要求覆盖更小尺寸 + 横屏方向，具体断点值排期时定。
7. **备注列去掉，重申列表行 3 行结构**：列表区去掉「备注」列；单行结构维持 #20 定的「材质水平排一行 + 显示/允许编辑/允许选中放第二行 + 别名/关联基点第三行」三行方案——这条像是重申而不是新规则，需要先核实现状实现是否已经偏离了这个三行方案（比如又多塞回了备注列），还是用户说的「一个3行」另有所指，排期前确认。
8. **子节点表示更明确**：树形列表里，判断一行是不是有子节点（以及展开/折叠状态）目前不够清楚，需要更明显的视觉指示（比如展开/折叠箭头图标、缩进引导线之类），具体视觉方案排期时定。

### 17.3 场景 Tab

1. **元数据折叠**：glTF 版本号、生成器（generator）、扩展清单（`extensionsUsed`）、动画信息这几项，改放进可折叠的卷展栏——跟 Round 2「常驻说明收进 ⓘ 菜单」是类似的「减少常驻信息」思路，只是这里用卷展栏折叠展开而不是弹出浮层，两种交互选哪个排期时定。
2. **下方分栏目**：折叠区下面，包围盒、基准点分成两个独立栏目/分区，各自都要有编辑功能入口——现状包围盒手动覆盖开关（§7 末尾）、基点新增/编辑（§8）功能本身都已经有，这条应该主要是版面上要求明确分区，不要混排在一起，具体是不是也要补充新的编辑能力排期时确认。
3. **基点优先级改下拉菜单**：测量基点系统（§8）的三级优先级（① GLB 原生 `Origin`/`_origin`/`origin` 命名节点 → ② 场景包围盒中心 → ③ 手动新增）目前是纯代码里的 fallback 逻辑，没有对应 UI 呈现，这次要求做成下拉菜单可选——具体语义是「选哪个已存在的基点当默认基点」的下拉，还是把三级优先级规则本身做成可视化展示/可调整顺序，两种理解实现范围差很多，需要排期前跟用户确认（倾向理解成前者：基点列表里选一个显式指定为「默认基点」）。

### 待确认清单（不阻塞记录，排期/设计前需要用户确认）

- 贴图「编辑」具体指哪些操作（替换文件？改名？还是别的）
- 模型块「导出」按钮挪进的「蓝框」是不是 Round 2 提议的视口新工具条，还是详情区按钮组内部重新排序
- 模型块列表「3 行结构」现状是否已经偏离 #20 定的方案，还是这次说的是别的重排要求
- 场景 Tab 元数据折叠用卷展栏还是 Round 2 那种 ⓘ 弹出菜单
- 基点优先级下拉的具体语义（指定默认基点 vs 可视化/可调整优先级顺序）
- 响应式断点的具体尺寸值（多小算「更小的屏幕」）

### 和 Round 2 的关系

Round 2 提的「详情上/列表下」+「说明收进菜单」两个模式，被这轮需求确认要**推广到贴图/模型块/场景全部面板**，不是材质面板单独的特例。视口新工具条（选择/移动/旋转/缩放/包裹框/中心点）的设计还没有被这轮需求否定或修改，维持 Round 2 提议内容，等一起排期实现。

### 17.4 决策记录（2026-08-07，Round 2 + Round 3 全部待确认点已回答）

用户对两版方案（`2026-08-06-material-panel-redesign.html` Round 2、`2026-08-06-panels-round3-redesign.html` Round 3）里留的待确认点逐条确认，结论如下，实现前不用再问：

1. **新工具条「中心点」按钮**：原话「两者都要，并且显示告诉用户基点在哪里。并且可以编辑，保存」——不是简单二选一菜单，点击后打开的面板要同时具备：① 可以关联到已有基点，② 可以以当前选中节点位置新建基点，③ 面板里显示这个基点当前的位置（不只是选择器，要有可读的坐标展示），④ 位置本身可以在这个面板里直接编辑并保存（不是只读展示）。等于把 §8 测量基点系统的"关联+新增+编辑"三件事收进这一个从工具条触发的面板里，复用 §8 已有的数据结构和写回函数，不是另起一套。
2. **动作类按钮（包裹框/中心点/导出）触发方式**：直接执行 + 对应面板已展开等待微调（Round 2 提议的推荐项，用户确认）。点击就立即生成默认结果（默认包围盒/默认基点选择/立即触发导出下载），同时对应面板自动展开，不需要用户先确认参数才执行。
3. **移动/旋转模式的视口拖拽 gizmo**：**这次要做真正的视口拖拽 gizmo**（三个方向的拖拽箭头/旋转环），不是像 Round 2 草案里倾向的"这次只连接数值面板，gizmo 留到下一轮"——用户明确要求这轮就做。这是比原方案更大的工作量，需要处理拖拽命中检测、跟 `OrbitControls` 相机操作的手势冲突（按住 gizmo 拖拽时要临时禁用相机旋转/平移）、世界空间⇄局部空间的换算（复用 §14 已有的 `nodeWorldMatrix()`/`applyNodeWorldTransform` 写回路径，gizmo 只是新增一种驱动数值变化的输入方式，不改写回逻辑本身）。数值面板（#17 已有）保留作为 gizmo 之外的精确输入手段，两者并存。
4. **贴图「删除」遇到多材质共享引用**：允许删，弹二次确认列出受影响材质（Round 3 提议的推荐项，用户确认）——删除前先反查这张 `raw.images[i]` 被哪些材质的哪些槽位引用（复用 `rebuildTexTable()` 里已经在算的 `t.refs`），弹窗列出材质名单，确认后清空全部这些槽位的引用。
5. **场景 Tab 基点优先级下拉是否允许用户覆盖**：**允许**，且用户补充了两条延伸要求（原话「按照测量需求会有多个基点。会有历史，json会包含解释，自然允许用户修改」）：
   - **允许多基点**：本来就支持（`anno.basepoints` 是数组），这条是确认不收窄成单基点。
   - **要有「历史」**：基点的新增/编辑/删除/默认基点重新指定，这几类操作要接入撤销栈（§13/#22）和操作脚本记录（§10/#13）——这是 #22 完成时明确列过的已知限制（"包围盒/测量基点新增编辑删除没有接入撤销"），这次借着基点优先级变成可交互控件的机会一并补上，不是无限制的版本历史/时间线 UI，是复用现有"撤销栈 + 操作脚本重放"这两套机制。
   - **导出 JSON 要带「解释」**：每个基点记录新增一个只读的 `resolvedReason` 类字段（导出时算出来，不是用户填的），人类可读地说明"这个基点为什么/是不是当前默认"——比如 `"GLB原生Origin节点"` / `"场景包围盒中心（兜底）"` / `"用户手动指定为默认"`，写入 `annotations.basepoints[]` 供后续查看/调试，不是新开一套解释系统。

**技术复杂度提示（如实记录，不是拒绝，是提前说明工作量）**：第 3 点（真实拖拽 gizmo）和第 5 点（基点操作接入撤销/脚本）是这次新增里工作量明显更大的两项，比原方案设想的范围更深；実现阶段建议拆成独立任务单独验证，不要跟其它小改动（图标尺寸/滑动条颜色这类）混在一个任务里一起做。

---

## 18. 多 GLB 场景合成 + 贴图批量导出（口述需求记录，2026-08-07，未评审/未排期，架构影响大）

**原话**：「另外图片拖拽进入，加载多个glb，多个glb多基点，偏移也要实装进去。另外图片也有批量导出功能。」

**这条跟前面 §17 性质不一样，先标注清楚**：§17 是同一个已加载模型内部面板怎么排布，改动范围局限在 UI 层；这条要求的是**同时加载多个 GLB 文件、在同一个视口场景里合成显示**——这会碰到当前架构一个从项目最早期就有的根本假设：`raw`/`gltf`/`model`/`matInstances`/`tables.*` 等等几乎全部模块级状态目前都是「当前只有一个模型」的单数结构（`let raw`, `let gltf`, `let model`，不是数组/Map），materials/textures/node 三张表、撤销栈、操作脚本、包围盒、测量基点全部代码路径都隐含假设"只有一份数据"。要支持多 GLB 同时加载，不是加一个循环那么简单，是要过一遍这些全局状态往「集合」方向改的影响面。**这次先只把需求记下来，不动代码，也还没有排进 §17 那批实现任务里**——跟之前几轮不一样，这条目前连设计方案草稿都没有，需要先跟用户对齐范围才能评估工作量、拆解任务。

### 拆解出的三个子需求

1. **拖拽加载多个 GLB**：现有拖拽加载入口（视口拖 `.glb` 文件触发 `preprocess()`）目前设计成单文件替换当前模型；这次要支持一次拖入多个文件（或多次拖入累加），全部加载进同一个场景，不是每次都替换掉前一个。
2. **多 GLB 各自的测量基点 + 偏移**：每个 GLB 各自可以有自己的 `anno.basepoints`（§8 已有结构，目前是"当前唯一模型"的场景级注释，这次要变成"每个已加载 GLB 各自一份"）；「偏移」大概率是指——多个模型各自的世界坐标原点可能不一致（比如两个不同项目分别导出的 GLB，各自坐标系统零点不同），需要有个手动/自动的位置偏移量，让多个模型在同一个视口里正确对齐显示，不是原点全部重叠挤在一起。具体偏移是靠"手动输入 XYZ 偏移量"还是"选各自一个基点对齐到同一世界坐标"这种更智能的方式，需要跟用户确认（很可能是后者——两个模型各自标好基点，工具自动计算让基点重合，这样比手动试参数更实用，但这只是我的猜测，不能替用户拍板）。
3. **贴图批量导出**：贴图 Tab（§17.1 已经在规划详情/列表重排）要加一个「批量导出全部贴图」的功能——大概率是把 `raw.images[]` 全部贴图打包导出（zip，或者逐个触发浏览器下载），具体是导出当前这一个模型的全部贴图、还是（如果 1/2 做完之后）导出场景里全部已加载模型的贴图汇总，取决于多 GLB 功能落地到什么程度，这条可能需要等 1/2 的范围定了才能定最终形态；但"贴图 Tab 加批量导出按钮"这个小范围（不依赖多 GLB）可以先独立做。

### 待确认（这条比 §17 的待确认更关键，直接决定要不要动架构）

- 「多个 GLB」的使用场景是什么——是"同时看/比较两个独立项目的 GLB"，还是"一个大场景本来就是拆成多个 GLB 文件分别导出，要在这个工具里拼回一个完整场景"？两种场景对"偏移"的语义完全不同（前者更像手动摆放对比，后者更像精确拼接需要对齐基准点）。
- 已有的材质清理菜单(§5)/撤销栈(§13)/操作脚本(§10)/选中高亮(§6.1) 这些功能，多 GLB 场景下是要"每个模型各自独立一套"还是"全场景共用一套，只是数据来源变多"？这直接决定architecture 改动是"轻量加一层模型集合包装"还是"大量现有函数要从单例改签名带 modelId 参数"。
- 这个功能预期使用频率——如果是偶尔用一次的场景比较需求，值不值得为它改动这么大范围的核心状态管理？如果是高频需求，可能需要专门规划一轮架构重构而不是塞进当前这批 UI 调整任务里一起做。
- 「批量导出贴图」范围——导出格式（保持原格式 zip 打包 vs 逐个触发浏览器下载）、命名规则（保留原始文件名 vs 加材质名前缀防重名）需要定。这条相对独立，风险低，不受多 GLB 问题阻塞。

### 决策记录（2026-08-07，用户已回答，创建实现任务见下）

1. **用途**：拼接成完整场景——多个 GLB 本来就是同一个场景拆成多个文件导出的，要在这个工具里拼回去。**「偏移」需要精确对齐，不是粗略摆放**，靠基点配对计算偏移量（呼应本文档第一版立项时"这段体验以后要变成 viewer 的一部分"这个方向——多文件精确拼接比"随手对比两个不相关模型"更接近这个工具的定位）。
2. **既有功能的多模型隔离范围**：材质清理菜单(§5)/撤销栈(§13)/操作脚本(§10)/选中高亮(§6.1) 每个模型各自独立一套。
3. **优先级**：高频需求，这轮（跟 #31-38 一起）就要做。

### 架构方案（高层，实现时再细化）

现有代码几乎全部模块级状态（`raw`/`gltf`/`model`/`matInstances`/`tables.*`/`currentBuf`/`currentBinOffset`/撤销栈/`anno.script`/`anno.basepoints` 等）从单例改成**按模型 ID 索引的集合**：`models = new Map(modelId -> ModelContext)`，`ModelContext` 就是把上面这些单例字段原样打包成一个对象；新增 `activeModelId`（当前"正在编辑"的模型，材质/贴图/模型块 Tab 面板显示的都是这个模型的数据，跟"场景里显示了几个模型"是两回事——面板永远只对着一个模型编辑，不做"同时编辑多个模型"这种更复杂的东西）。视口渲染时把每个模型各自的 `model`（three.js `Group`）包一层「放置容器」`Object3D`，容器上的 transform 承载模型间的对齐偏移，跟 `preprocess()` 已有的视口归一化变换分层（不要混在一起，参考本文档前面记录过的"两套世界空间"教训）。

**基点对齐流程**（初步设想，实现时跟用户再对一遍细节）：加载第二个及以后的 GLB 时，如果它也有基点（§8 已有结构），弹出「对齐到已加载模型」选择器——选一个已加载模型 + 该模型的一个基点 + 新模型自己的一个基点，工具算出让两个基点世界坐标（含朝向）重合所需的变换，写进新模型的「放置容器」transform。不做基点自动配对猜测（比如靠名字相同自动配对），一律用户手动选，避免猜错导致模型对齐到错误位置这种不容易第一时间发现的错误。

**已有功能隔离方式**：既然状态已经按 `ModelContext` 分好，材质清理菜单/撤销栈/操作脚本/选中高亮这几个功能本身的代码逻辑基本不用改——它们已经是"读写当前模型的这些字段"，只要这些字段从模块级 `let` 变成 `activeModel.xxx`，函数体内部逻辑不用重写。真正的工作量在：① 把现有几十处直接引用模块级变量的地方（`raw`/`gltf`/`model` 等）统一改成走 `activeModel.` 前缀（是体力活+要非常仔细不漏改，不是设计难题）；② 新增模型切换器 UI（Tab 栏下方或视口角落，列出已加载模型，点击切换 `activeModelId`，切换时四个面板整体刷新）；③ 视口需要同时渲染全部已加载模型（不只是 `activeModel` 那个），只是编辑操作对着 `activeModel`。

### 实现任务

拆成三个任务（见 `Doc/TODO.md`/harness TaskList #39-41）：
- **#39** 多模型核心架构：`ModelContext` 集合改造 + 拖拽多文件加载 + 模型切换器 UI——地基性质，#40 依赖它
- **#40** 基点对齐引擎 + 对齐选择器 UI——依赖 #39
- **#41** 贴图批量导出——独立，不依赖 #39/#40，可以并行先做

**风险提示**：#39 是这轮全部任务里改动面最大的一个（前面 #31-38 都是局部 UI 调整，#39 要动几乎每个函数对模块级变量的引用方式），建议单独充分测试，做完先跑一遍全部既有回归测试脚本（`_dev/test-*.js` 全部）确认单模型场景下行为没有退化，再验证多模型场景本身的新行为。

### #39 实现记录（2026-08-07/08 完成）

**架构决策：没有逐处把模块级变量改成 `activeModel.xxx`，改用"整体搬进/搬出"的 harvest/apply 模式——这是权衡过风险之后的取舍，如实记录**。

开工前先梳理了全部要处理的模块级变量：`fileKey`/`anno`/`raw`/`gltf`/`model`/`tables`/`matInstances`/`nodeObjects`/`currentBuf`/`currentBinOffset`/`undoStack`/`uvTexCache`/`matUiExpand`/`collapsedNodes`/`selectedMat`/`selectedTex`/`selectedNodeIdx`。用 `grep -c` 统计发现单是 `raw` 一个变量在 `index.html` 里就有 261 处引用，`anno` 163 处，`tables` 158 处——本节原方案设想的"把几十处直接引用模块级变量的地方统一改成 `activeModel.xxx` 前缀"，实际落地时面对的是接近 700 处分散在几十个函数里的引用点，逐处手工加前缀、逐处独立验证，在这个规模的单文件项目里既难以保证每处都测到，又非常容易漏改一处（尤其是嵌套很深的闭包，比如 `pushUndo` 存的 `undoFn`、`recordScriptOp` 的回放函数——这些闭包捕获的是变量绑定不是某一刻的值，改窜前缀的时候如果漏改其中一层，运行时不会报错，只会在特定时序下读到错误的数据，是那种"测试可能测不出来、上线后偶发"的最难查的一类 bug）。

**改用的方案**：`models = new Map()`（modelId → `ModelContext`）是真正的多模型集合，`ModelContext` 打包 `{id, name, fileKey, anno, raw, gltf, model, placementGroup, tables, matInstances, nodeObjects, currentBuf, currentBinOffset, undoStack, uvTexCache, matUiExpand, collapsedNodes, selectedMat, selectedTex, selectedNodeIdx}`。但上面列的模块级 `let` 变量**一个都没删、没有改名、也没有改成某个对象的属性**——几十个既有函数（`setMatField`/`rebuildNodeTable`/`generateBBox`/`addBasepoint`/`pushUndo`/`recordScriptOp`……）的函数体一行没动，继续读写这些裸变量。新增两个函数 `harvestModuleVarsInto(ctx)`（把当前这批模块级变量的值整体搬进某个 `ModelContext`）/`applyContextToModuleVars(ctx)`（反过来，把某个 `ModelContext` 的值整体搬回这批模块级变量），配合 `activeModelId` 和 `captureActiveModelContext()`/`switchActiveModel(id)`/`restoreActiveModelVisuals()` 几个集中的编排函数，维护一条不变量：**任何时刻，这批模块级变量都精确等于 `models.get(activeModelId)` 的内容**。业务函数不需要知道"当前是不是多模型场景"，它们看到的永远是一份"当前激活模型"的数据——这跟原方案"函数体内部逻辑不需要重写，只改变量取值来源"这条核心要求达成的效果是一样的，只是"取值来源切换"这件事被收敛进了几个集中的函数，而不是分散到几百个调用点各自处理。

**这个决策的代价，如实记录，不假装它不存在**：
- 如果某处业务逻辑在 `await` 期间跨越了一次 `switchActiveModel()`（比如贴图上传解码到一半，用户切换了激活模型），闭包后续读到的模块级变量会是切换后的新模型，不是发起操作时的旧模型。这次验证覆盖到的异步点（`GLTFLoader.parseAsync`、`createImageBitmap` 解码贴图尺寸）都是在自己的 `await` 之后不再读取跟"这次操作属于哪个模型"相关的模块级变量，没有发现这个理论风险的真实触发路径，但没有专门写测试去构造这种时序竞争场景。
- 这个模式要求"任何新增的模块级可变状态，只要它是'一份数据挂在某个模型上'的性质，就必须补进 `ModelContext` + harvest/apply 的字段清单"——如果以后有代理往模块作用域加新的 `let` 又忘了补这两个函数，会悄悄变成"全部模型共享一份"，这类 bug 初期不容易被发现（不报错，只是"新加的这个字段莫名其妙跨模型串了"）。已经在 `ModelContext` 声明处和两个 harvest/apply 函数头部写了大段注释提醒这一点。

**开工前的踩坑复盘（§14 两套世界空间教训在这次的应用）**：`model`（three.js `Group`，即 `gltf.scene`）自身只承载 `preprocess()` 里"落地居中缩放"这层归一化变换；新增的「放置容器」`placementGroup`（`THREE.Group`）包在 `model` 外面，`scene.add(placementGroup)`，`placementGroup.add(model)`，承载"模型间对齐偏移"（`Doc/TODO.md` #40 的范围，这次恒为单位矩阵）。两层变换全程没有混在一起写——`preprocess()` 里凡是碰 `model.scale`/`model.position` 的代码一行没动，新增的 `placementGroup` 相关代码只做 `new THREE.Group()`/`add()`/`remove()`，不设置任何 transform。已用测试脚本验证：`model.parent` 精确是 `__placementGroup:` 前缀命名的容器，容器本身是 `scene` 的直接子对象。

**「重新载入 GLB」的容器复用**：`preprocess(buf, fname, opts)` 新增 `existingPlacementGroup` 参数——`mode:'reload'` 时传入当前激活模型已有的 `placementGroup` 实例，函数内部先清空它的子对象（旧 `model`）再塞入新解析出的 `model`，容器实例本身不重新 `new`（`#40` 真正写入偏移量之后，reload 不应该把用户已经对齐好的偏移清掉——这次虽然偏移恒为单位矩阵，但提前把"reload 复用容器"这个骨架搭对了）。`mode:'add'`（新增模型）则 `new THREE.Group()` 新建一个容器。

**多文件拖拽**：视口 `drop` 事件处理器改成遍历 `e.dataTransfer.files` 里全部 `.glb` 文件，**顺序 `await` 逐个调用 `openBuf(..., {mode:'add'})`**，不是 `Promise.all` 并发——因为 `preprocess()`/`buildTables()` 内部借用同一批模块级变量当"解析这一个文件"的草稿纸，并发跑会互相踩踏（后一个文件的 `raw = ...` 覆盖前一个文件还没来得及被 harvest 走的 `raw`）。「打开 GLB → 本地文件」菜单沿用原来的单选 `<input type="file">`（没有加 `multiple` 属性）——多文件加载的主入口是拖拽，菜单入口保持单选是刻意的风险控制：`#fileInput` 同时被「打开 GLB」（`mode:'add'`）和「重新载入 GLB」（`mode:'reload'`）两个不同入口共用，靠一次性标记 `pendingOpenMode` 区分，如果菜单入口也支持多选，`mode:'reload'` 场景下用户不小心多选了文件会有歧义（选第一个还是报错），干脆维持单选，多文件场景交给语义更清晰的拖拽入口。

**发现并修复的一个真实 bug（不是理论风险，是测试真的复现出来的）**：`buildTables()` 是单模型时代遗留的写法——`tables.mat = ...`/`tables.scene = ...` 这类"改 `tables` 现有对象的属性"，不是`tables = {...}` 整体重新赋值（那时候全应用生命周期只有一份 `tables`，这么写没问题）。第一版实现里，后台追加加载第二个模型时没有在调用 `preprocess()` 前把模块级 `tables` 重置成一个全新对象，导致 `buildTables()` 直接在上一步 `captureActiveModelContext()` 刚存进 `ctx_A.tables` 的**同一个对象引用**上做修改——`ctx_A.tables` 和新建的 `ctx_B.tables` 变成同一个对象，编辑模型 A 的材质后，切到模型 B 材质详情面板 `#mdBaseColor` 颜色输入框会错误地显示模型 A 编辑后的颜色（`raw` 数据本身没串——`raw` 每次都是 `GLTFLoader` 重新解析出的全新对象——但 `tables` 这层纯展示派生数据串了）。修复：`openBuf()` 的 `mode:'add'` 分支里，调用 `preprocess()` 之前显式 `tables = { mat: [], tex: [], node: [], scene: {} }`（跟已经在做的 `anno = null` 重置同一个理由）。这个 bug 是 `_dev/test-todo39-multimodel.js` 阶段2b 第一次跑就复现出来的，不是事后猜出来补的测试。

**发现并修复的第二个真实 bug**：`switchActiveModel(id)` 最初漏了刷新 header 顶部的 `#fileInfo` 文件信息条——切换激活模型后材质/贴图/模型块/场景四个面板都正确刷新了，但顶部"文件名 · 大小 · 材质数 · 贴图数 · 网格块数"这一行文字停留在切换前的模型，容易让用户误判"到底在编辑哪个文件"。抽出共用函数 `updateFileInfoText(fname, byteLength)`（原来是三处各自写一份格式完全一样的模板字符串，这次新增第三个调用点时顺手抽出来），`switchActiveModel()` 里补上这一处调用。这个 bug 是截图走查时肉眼发现的（`_dev/shots-39/39-06-switcher-after-switch.png`），随后补了对应断言到 `_dev/test-todo39-switcher-ui.js` 防止再退化。

**模型切换器 UI**：header 里 `#fileInfo`（当前文件名那段文字）右侧新增 `#modelMenuWrap`——跟已有的「场景 ▾」「打开 GLB ▾」「另存为 GLB ▾」同一套 `.menu-wrap`/`.menu-btn`/`.menu-dropdown` 下拉外壳、同一个 `registerMenu()` 基础设施，没有发明新组件。按钮文案「模型 (N) ▾」，没有任何模型时整体 `hidden`（跟 `#viewTools`/`#modeTools` 同一个"模型加载后才显示"惯例）。下拉内容是 `renderModelSwitcher()` 纯动态渲染（每次模型集合变化都整体重渲染，模型数量级只有个位数不需要增量 diff），当前激活模型那一项 `● ` 前缀 + `.active-model` 类加粗高亮。点某一项：关闭下拉 + `switchActiveModel(id)`。

**视口同时渲染全部模型**：不需要改渲染循环本身——每个模型的 `placementGroup` 只要 `scene.add()` 过就会一直留在场景图里（除非用户以后要"卸载模型"，这次任务没有这个需求），既有的 `renderer.render(scene, camera)` 天然会画出全部已加载模型，只是编辑操作（材质面板/节点树/gizmo）永远只对着 `activeModelId` 对应的那一份数据。用两个真实的不同 GLB 样品（`chengdu-huagao-0801.glb` 17 材质 + `画稿飞扬v2.glb` 20 材质）交叉验证过——用 `placementGroup.visible = false` 分别隐藏其中一个，确认两个模型各自有独立、真实不同的几何体在同时渲染（不是同一份内容重复），只是这次两个样品恰好都是同一个客户「Prof.Jimmy Choo」的展台设计变体，视觉上相似度较高，仅靠肉眼看合成截图容易误判成"只有一个模型在渲染"，加了这道"分别隐藏"的交叉验证排除这个误判。

**既有功能改造范围**：材质清理菜单（§5）/撤销栈（§13）/操作脚本（§10）/选中高亮（§6.1）/包围盒（§9）/测量基点（§8）/gizmo（§19）——这几类点名要求验证的功能，因为采用的是 harvest/apply 整体搬迁方案，函数体全部**一行没改**，正确性完全来自"模块级变量在正确的时刻指向正确的 `ModelContext`"这条不变量是否被维护对。逐类验证方式：
- **撤销栈**：模型 A 做一次材质编辑（`undoLen` 变 1），切到模型 B 确认 `undoStack` 是空的（`undoLen===0`），切回 A 确认 `undoLen` 精确保留。
- **包围盒**：模型 A 用 `generateBBox(ni)` 生成一个包围盒，切到模型 B 确认视口青色线框数量为 0（`syncBBoxHelpers()` 会在 `restoreActiveModelVisuals()` 里针对新激活模型重新跑一遍，自动清掉上一个模型的残留线框——这两个函数一行没改，"读当前 `anno`"这个既有行为本身就保证了隔离，不需要额外写清理逻辑），切回 A 确认线框重新画出来。
- **测量基点**：模型 A 用 `addBasepoint()` 新增一个基点（长度变 2），切到模型 B 确认基点列表仍是初始的 1 个（默认基点），互不污染。
- **操作脚本**：模型 A 的材质编辑 + 新增基点两步操作后 `anno.script` 长度精确为 2，切到模型 B 确认 `anno.script` 是空数组。
- **选中高亮 / gizmo**：`nodeSelectHelper`/`matHighlightGroup`/`transformControls` 都是场景级单例（不放进 `ModelContext`，因为它们是"视觉呈现"不是"持久数据"）——`restoreActiveModelVisuals()` 在每次切换/后台追加加载完成后，按新激活模型持久化的 `selectedNodeIdx`/`selectedMat` 重新调用既有的 `setNodeSelection`/`clearNodeSelection`/`applyMatHighlight`（这三个函数内部本来就会先清后建），天然保证视觉状态跟着切换正确重建，不会有 A 的高亮框残留在 B 的编辑视图里。gizmo 本身没有单独测试新增代码——它读的 `nodeObjects`/`selectedNodeIdx` 本来就是模块级变量，自动跟着 harvest/apply 走，`_dev/test-todo33-gizmo.js`（单模型场景）重跑全部 54 项断言 PASS 确认没有退化。

**验证**：
- **第一阶段（单模型场景零退化）**：重跑了任务要求的全部既有回归测试——`test-mat-editor.js`/`test-uv-editor.js`/`test-texture-upload.js`/`test-todo32-matpanel-reorder.js`/`test-todo35-textab.js`/`test-todo36-nodetab.js`/`test-bbox.js`/`test-basepoints.js`/`test-todo37-scenetab.js`/`test-undo-status.js`/`test-todo29.js`/`test-todo33-gizmo.js`/`test-todo34-actions.js`/`test-todo38-responsive.js` 全部 PASS；`test-cleanup-menu.js`/`test-instance-export.js`/`test-script-replay.js` 三个在核心断言全部 PASS 之后，撞上跟这次改造无关的既有已知问题（`#exportGlbBtn` 是 #21 头部菜单重构后遗留的旧选择器；`#texPreviewOverlay` 拦截点击是 #26 已经记录在案的问题）——用 `git diff` 确认这次改动完全没有碰过这两处代码路径，如实记录为既有问题不是新回归。`test-todo36-nodetab.js`/`test-basepoints.js`/`test-todo37-scenetab.js` 三个测试脚本里原本"不 reload 页面、直接对 `#fileInput` 第二次 `setInputFiles` 期待替换语义"的用法，因为 `#fileInput` 现在默认是"追加"语义，需要相应改成走「场景 ▾ → 重新载入 GLB」的真实入口（`page.waitForEvent('filechooser')` + 点击 `#reloadGlbBtn`）或者 `page.reload()`——这是测试脚本要适配这次故意做出的语义变化，不是产品代码的 bug，已同步修好并重新跑通。
- **第二阶段（多模型场景）**：新增 `_dev/test-todo39-multimodel.js`（39 项断言）+ `_dev/test-todo39-multidrop.js`（单次 `drop` 事件携带 2 个不同真实 GLB 文件的 `DataTransfer`）+ `_dev/test-todo39-openmenu-add.js`（「打开 GLB → 本地文件」菜单入口累加验证）+ `_dev/test-todo39-switcher-ui.js`（11 项断言，真实点击模型切换器下拉菜单，不走 `__debug` 钩子）。覆盖：`placementGroup` 分层结构、单次/多次/菜单三种入口的累加加载、"第一个模型自动激活，后续不自动切换"规则、模型切换器 UI（列表渲染/当前项高亮/真实点击切换/`header` 文件信息条同步）、材质编辑/撤销栈/包围盒/测量基点/操作脚本五类功能的隔离性（用真实 UI 交互编辑模型 A，独立读模型 B 的 `ModelContext` 确认零污染，再切回 A 确认编辑保留）、视口同时渲染验证（`placementGroup.visible` 分别切换排除"看起来像只有一个模型"的视觉误判）。
- 控制台全程 0 报错（贯穿全部新增测试脚本 + 全部重跑的既有回归测试）。

**已知未覆盖 / 有意留白的部分（如实列出）**：
- 没有为"异步操作跨越 `switchActiveModel()`"这种理论时序竞争风险写专门的压力测试（见上面"这个决策的代价"一节），现有代码路径没有发现真实触发场景，但没有形式化证明它不存在。
- 贴图 Tab（`selectedTex`）的隔离性没有像材质/包围盒/基点那样写专门断言——`selectedTex` 走的是跟 `selectedMat`/`selectedNodeIdx` 完全同一套 harvest/apply 机制，原理上没有理由表现不同，但没有额外用真实 UI 交互单独测一遍这一项。
- 场景整体包围盒手动覆盖（`anno.sceneBbox`）、`defaultBasepointName` 手动指定默认基点（`Doc/TODO.md` #37）这两项没有单独测隔离性——同样是走 `anno` 整体 harvest/apply，原理上应该正确隔离，但没有专门验证。
- 没有测试"加载 3 个及以上模型"的场景，只验证了 2 个模型；`models` 是普通 `Map`，理论上不应该有数量上限问题，但没有实测超过 2 个的场景。

调试钩子：`window.__debugModels = { models, activeModelId, switchActiveModel, openBuf, makeModelId, currentModuleState }`，跟既有 `window.__debugRaw`/`__debugScene` 等同一套暴露方式，供测试脚本直接读多模型集合状态、触发加载/切换，不用只靠拖拽 DOM 事件才能测多模型场景。

### #40 实现记录（2026-08-08 完成，依赖 #39）

**开工前检查**：先跑了 `_dev/test-todo39-multimodel.js`（39 项 PASS）、`_dev/test-basepoints.js`（全 PASS）、`_dev/test-bbox.js`（全 PASS）确认 #39/#8/#9 基线是绿的。搜了一遍 `index.html`（`对齐`/`align`/`拼接` 等关键词），除了 #39 留下的 `placementGroup` 骨架注释（"恒为单位矩阵，#40 范围"）外没有任何相关残留实现，从零开始。

**触发流程**：`openBuf()` 的 `mode:'add'` 后台追加分支（`!willBecomeActive`）末尾新增一段检查——`ctx.anno.basepoints`（新模型自己的基点列表，用 `ctx`，不是模块级 `anno`，因为此刻模块级变量已经被 `applyContextToModuleVars(activeCtx)` 换回原激活模型的数据）非空，且 `candidateAlignModels(ctx.id)`（场景里其它带基点的已加载模型）非空，两个条件都满足才 `await openAlignPicker(ctx)`。第一个模型加载（`willBecomeActive===true`）时场景里必然没有"别的模型"，`candidateAlignModels` 永远返回空数组，这条分支天然不会触发，不需要用 `willBecomeActive` 再判一次。

**选择器 UI**：`#alignOverlay`/`#alignPanel` 照抄包围盒/中心点面板（`#bboxOverlay`/`#centerPointOverlay`）同一套「居中浮层，静态 DOM 不进 `renderTab` innerHTML」外壳，不发明新样式。三个下拉框——① 已加载模型 A（`candidateAlignModels()` 结果，排除新加载的这个）；② A 的一个基点（联动 A 的选择变化）；③ 新模型自己的一个基点——**不做任何自动按名字配对猜测**（§18 决策记录第 1 条逐字落实：多模型场景下几乎每个模型都会自动种一个名叫「默认基点」的基点，如果按名字自动配对会把互不相关的两个"默认基点"错误地配成一对，这正是决策记录警告的"猜错导致对齐到错误位置"），下拉框默认选中各自列表第一项只是原生 `<select>` 行为，不是本工具替用户做的判断。「跳过（不对齐）」放在标题栏（视觉上等价"关闭"），但**没有绑定点击背景关闭**这个交互——这是这次任务里对既有面板惯例的一处刻意偏离：对齐是"加载流程的一部分"，不是包围盒/中心点那种"随时能关的辅助面板"，如果沿用点击背景关闭，用户误触背景会在没有意识到的情况下跳过对齐，必须显式点「对齐」或「跳过」两个按钮之一。

**对齐算法**（标准的"给定一对锚点重合，反解相似变换"公式，无缩放项）：

新增 `basepointWorldTR(bp, placementGroup)`——把基点存储的局部值（`bp.position`/`bp.zRotation`，这套数值所在的坐标系是"它所属模型的 `placementGroup` 局部坐标系"，见下面"跟 #39 架构的磨合"一节）变换成真实世界 T + 四元数：

```
localPos  = Vector3(bp.position)
localQuat = Quaternion.fromEulerY(bp.zRotation)          // 只绕 Y 轴，见 §8 zRotation 约定
worldPos  = placementGroup.matrixWorld · localPos          // 恒等 placementGroup 时 = localPos
worldQuat = decompose(placementGroup.matrixWorld).quaternion · localQuat
```

`placementGroup` 传 `null`（或未加载模型的占位调用）时退化成 `{position: localPos, quaternion: localQuat}`，即恒等变换，不引入额外分支。

新增 `computeAlignmentTransform(bpA, placementGroupA, bpB)`：

```
worldA = basepointWorldTR(bpA, placementGroupA)   // A 基点的真实世界 T/R
localB = basepointWorldTR(bpB, null)              // B 基点在 B 自己模型坐标系下的 T/R
                                                    // （B 是刚加载的新模型，此刻 placementGroup_B 恒为单位矩阵）
Q = worldA.quaternion · localB.quaternion⁻¹        // 先解旋转
P = worldA.position − Q·localB.position            // 旋转确定后，位置直接反推
```

推导：要求解的 `placementGroup_B` 变换 `T(x) = Q·x + P` 需要满足 `T(localB.position) = worldA.position` 且 `Q·localB.quaternion = worldA.quaternion`，代入即得上式。`localB.quaternion`/`worldA.quaternion` 都是绕 Y 轴的纯旋转（`zRotation` 语义，§8"只绕竖直轴的方位角"），Y 轴旋转在乘法/求逆下封闭（阿贝尔子群），`Q` 的结果数学上保证仍然是纯 Y 轴旋转，不会因为浮点运算引入额外轴分量的漂移。

`applyModelAlignment(ctxB, ctxA, bpA, bpB)` 把算出的 `{position, quaternion}` 写入 `ctxB.placementGroup.position`/`.quaternion`（`scale` 显式钉在 `(1,1,1)`，防御性写法），显式调一次 `updateMatrixWorld(true)`——不是因为 three.js 场景图不会自动重算（会），而是让"对齐完成后立刻同步读 `matrixWorld` 做验证"（比如测试脚本紧接着这一步就读数值）不用等下一帧渲染循环。

**跟 #39 架构的磨合点（如实记录，任务原文点名要求汇报）**：`placementGroup` 引入非单位矩阵变换后，`anno.basepoints` 存储的数值第一次出现"局部值 vs 真实世界值"的分裂——#39 完成时 `placementGroup` 恒为单位矩阵，两者数值上永远相等，所以 §8/§37 写基点相关代码时全部隐含假设了"`bp.position` 就是世界坐标"，这个假设在 #40 之前一直成立、从未被检验过。排查发现两处既有函数直接用了这个假设，如果不修，一旦某个模型真的被对齐（`placementGroup` 不再是单位矩阵），会产生真实的功能错误：

1. **`computeRelativeToBasepoint()`**（§8，模型块 Tab「相对基点坐标」计算）：原实现拿节点的真实世界坐标（`obj.matrixWorld`，因为 `nodeObjects` 是 `model` 的后代、`model` 是 `placementGroup` 的后代，天然含 `placementGroup` 变换）直接减 `bp.position`（不含 `placementGroup`）——如果这个节点所属的模型自己被对齐过，这是两个不同坐标系的数值相减，结果错误。**已修**：改成先用 `basepointWorldTR(bp, 当前激活模型的placementGroup)` 把 `bp` 换算成真实世界值，再跟节点世界坐标相减。`placementGroup` 恒为单位矩阵时这个改动是恒等操作，不影响任何 #39 之前的既有断言。

2. **`syncBasepointHelpers()`/`clearBasepointHelpers()`**（§8，基点橙色标记可视化）：原实现硬编码 `scene.add(helper)`/`scene.remove(h)`——如果当前激活模型自己被对齐过，标记会用未经 `placementGroup` 变换的 `bp.position` 摆在"对齐前的旧位置"，跟已经通过 `placementGroup` 移动过的模型本体脱节，是真实会发生的视觉 bug（标记飘在半空，不跟着模型走），不是假设性的边界情况。**已修**：标记改成挂进"当前激活模型的 `placementGroup`"而不是直接挂 `scene`（`clearBasepointHelpers` 相应改成 `h.parent.remove(h)` 通用摘除，不再假设父级一定是 `scene`）——靠三维引擎场景图父子关系自动带上这次变换，不用像 `computeRelativeToBasepoint()` 那样手动算矩阵。只有激活模型的基点会被可视化，这条跟 §18 既有设计（"视觉状态是单例、不属于任何 `ModelContext`"）一致，切换模型时 `restoreActiveModelVisuals()` 重新调用这个函数，天然会换到新激活模型的 `placementGroup` 下重画。

**没有修的一处平行问题（如实记录，不假装没看到）**：包围盒（§9，`nodeAnno().bbox` 存的 `rotationDeg`/`size`/`center`）是同一类"缓存的世界空间派生值"，理论上有跟基点完全一样的陈旧风险——如果某个节点所属的模型被对齐后，它的包围盒线框（`buildBBoxHelper()`，仍然硬编码 `scene.add()`）也会跟模型本体脱节。这次任务范围明确是"基点对齐引擎"，包围盒不在范围内，为了不越界改动没有顺手修，如实记录这个平行的潜在问题留给以后需要时处理（修法完全类似——把 bbox 线框也挂进 `placementGroup` 而不是 `scene`）。

**精度验证**（`_dev/test-todo40-align.js`，30 项断言全 PASS，两层独立校验方法论，参考 §14/§16/§19 已有的"独立重算校验"）：

- **合成样品**：`_dev/gen-align-test-gltfs.js` 生成模型 A（`Origin` 命名节点，局部 T=(0,0,0)、旋转恒等——这个特例选择是为了让 A 基点的世界坐标精确等于 `preprocess()` 归一化平移量本身，排除缩放干扰，方便交叉验证）+ 模型 B（`_origin` 命名节点，局部 T=(5,1,-3)、绕 Y 轴 40°——平移和旋转都不是零/恒等，两个分量都会被真正验证到，不会因为凑巧是恒等变换蒙混过关）；两个 GLB 各自还挂了一个三角形网格节点撑起包围盒。两个文件都靠 §8 已验证的「GLB 原生约定优先级 1」（`Origin`/`_origin`/`origin` 大小写不敏感自动探测）天然带一个基点，不用在 UI 里手动新建。
- **流程**：加载 A（不弹窗，没有可对齐目标）→ 加载 B（弹窗，下拉框断言：模型 A 下拉恰好 1 项且排除 B 自己、A 的基点下拉恰好 1 项、B 自己的基点下拉恰好 1 项）→ 点「对齐」（默认选中项就是唯一选项）。
- **校验层 1（算法精度）**：测试脚本在 Playwright 侧用**纯手写的四元数代数**（`qMul`/`qConj`/`qFromEulerY`/`qRotateVec` 全部手写实现，不调用 `THREE.Quaternion` 的 `multiply`/`invert`，也不调用被测的 `computeAlignmentTransform`/`applyModelAlignment` 本身）独立重算一遍期望的 `placementGroup_B`，用应用实际写入的值逐分量比对，四元数符号歧义（`q` 和 `-q` 表示同一个旋转）先用点积判符号再比。**实测误差 7.1e-15**（IEEE 754 双精度浮点噪声量级）。
- **校验层 2（物理层，端到端）**：完全不读 `anno` 数据，直接从两个模型 Origin 节点各自的 three.js `matrixWorld.elements`（列主序 4×4 矩阵的原始数组）里手工取平移分量（`elements[12,13,14]`）+ 手工做矩阵×向量乘法把局部 +X/+Z 方向轴变换到世界空间，比较对齐后两个模型 Origin 节点的世界坐标/朝向是否重合——这是最贴近"用户在视口里真正会看到什么"的检验方式，不信任 `anno` 数据层，只信任渲染实际用的场景图矩阵。**实测误差同样是 7.1e-15**，比预先设定的 2e-3 容差好得多。**如实报告一个精度上限的说明**：`bp.position`/`bp.zRotation` 在 `computeDefaultBasepoint()` 里创建时经过 `toFixed(4)`（米，4位小数）/`toFixed(2)`（度，2位小数）量化——这是 #8 就有的既有设计，不是这次引入的，这次合成测试选用的具体坐标（`preprocess()` 算出来的归一化平移量）凑巧落在这次的量化不损失精度的数值上，所以物理层也达到了机器精度；但这不是必然的——如果基点的真实坐标不是这种"整除"数值，`toFixed()` 量化本身会引入米级 1e-4 / 度级 1e-2 量级的截断误差，复合传播到物理层比对上，物理层精度届时会降到那个量级，**这是 #8 基点存储格式的固有分辨率上限，不是这次对齐算法本身的误差来源**——算法本身（校验层1）在任何输入下都应该保持在浮点噪声量级，因为它只是一次性的四元数代数，没有迭代/近似步骤。
- **真实样品**：`C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb` 加载两次（第二次加载时改文件名避免 `fileKey` 撞车、误复用第一次的历史注释），走真实 UI 点击流程（不是调试钩子直接调函数）——两份文件基点相同，对齐后完全重合（`placementGroup_B` 写入有限数值，不是 `NaN`/崩溃），验证的是"流程本身通畅"这条任务允许的合理简化场景，不强求两份不同文件。
- **新模型没有基点时跳过弹窗**：因为首次加载任何模型都会自动种一颗基点（`old.basepoints || [computeDefaultBasepoint()]`），"不带 `Origin` 节点"不足以制造"新模型 0 基点"的场景——测试用了真实存在的代码路径：先加载一次某文件、用 `window.__debugBasepoints.deleteBasepoint(0)` 删光唯一基点（`saveAnno()` 把空数组 `[]` 持久化到 `localStorage`）、刷新页面（`page.reload()`，`localStorage` 保留）、加载一个带基点的模型 A、再加载**同一份**"已删光基点"的文件（`fileKey` 相同，`buildTables()` 里 `old.basepoints || [seed]` 因为 `old.basepoints` 是 `[]`（truthy，不会走 `||` 右边）恢复出 0 个基点，不是重新种子）——确认对齐选择器全程没有弹出过、`placementGroup` 保持单位矩阵。
- **用户取消（跳过）**：点「跳过」后 `placementGroup` 保持单位矩阵（位置/旋转都验证），状态栏文案精确含"已跳过对齐"字样，且验证了页面后续仍能正常响应 `page.evaluate`（没有卡死/抛错中断）。
- 全程控制台 0 报错。

**UI 反馈**：`applyModelAlignment()` 只是设置 `placementGroup.position`/`.quaternion`，three.js 场景图下一帧渲染循环自动用新值重算 `matrixWorld`，视口位置立即跟着更新——不需要手动触发重渲染。已用真实截图确认生效（`_dev/shots-40/40-02-after-align.png`：两个合成三角形对齐后在视口里精确重叠成一个，只看得到一个三角形轮廓）。

**回归测试**：`test-todo39-multimodel.js`/`test-todo39-multidrop.js`/`test-todo39-openmenu-add.js`/`test-todo39-switcher-ui.js` 四个 #39 测试脚本因为"加载第二个模型触发对齐选择器"变成了新常态（两份真实样品都会自动种基点），各自补上"等待弹窗出现→点跳过"这一步后全部恢复 PASS；`test-basepoints.js`/`test-bbox.js` 全 PASS（`computeRelativeToBasepoint`/`syncBasepointHelpers` 的改动在 `placementGroup` 恒为单位矩阵时是恒等操作，不影响任何既有断言）；`test-uv-editor.js` 原有"不 reload、直接对 `#fileInput` 二次 `setInputFiles` 期待替换语义"的既有写法（#39 完成时只修了三个测试脚本，这次撞见第四个）因为触发了新弹窗而超时崩溃，补上"等待弹窗→点跳过→手动切到刚追加的模型"三步后恢复正常；`test-mat-editor.js`/`test-texture-upload.js`/`test-todo37-scenetab.js`/`test-undo-status.js`/`test-todo34-actions.js`/`test-todo36-nodetab.js` 全部重跑 PASS，控制台全程 0 报错。

**发现但未修的既有问题（如实记录，不是这次引入）**：`test-todo30-basepoint-source.js` 用 `git stash` 切回 #40 开工前的代码状态交叉验证，确认它在纯 #39 完成态下就已经会在另一个步骤报 `TypeError: Cannot read properties of null`——是 #39 遗留、跟这次改动完全无关的既有测试脚本技术债，不在这次任务范围内，如实记录不顺手修（避免超出任务边界）。

**测试脚本**：`_dev/test-todo40-align.js`（Playwright，30 项断言全 PASS，可重跑复查）+ `_dev/gen-align-test-gltfs.js`（合成测试 GLB 生成器，产出 `_dev/test-align-a.glb`/`_dev/test-align-b.glb`）。截图：`_dev/shots-40/40-01-picker-open.png`（选择器面板）、`40-02-after-align.png`（对齐后视口重叠效果+状态栏文案）、`40-03-real-sample-picker.png`/`40-04-real-sample-aligned.png`（真实样品流程）。调试钩子：`window.__debugAlign = { basepointWorldTR, computeAlignmentTransform, applyModelAlignment, candidateAlignModels, openAlignPicker, closeAlignPicker, alignPickerOpen }`，同既有 `__debugModels`/`__debugBasepoints` 一套暴露方式。

### #41 实现记录（2026-08-08 完成，独立，不依赖 #39/#40）

**开工前先搜了一遍 index.html**：搜「批量导出」「导出全部贴图」「exportAllTex」「JSZip」全部无匹配，`vendor/` 目录下也没有任何通用打包库（只有 three.js 相关的 `controls`/`exporters`/`loaders`），确认是干净状态、从零实现。

**方案选择：方案B（逐个 `<a download>` 触发浏览器原生下载），没有选方案A（JSZip 打包）**——理由如实记录：

- 项目从立项起就坚持「build-free、纯本地 vendor、不依赖 CDN」，`vendor/` 目前只服务 three.js 生态（加载器/导出器/控件），引入 JSZip 会是第一个跟 three.js 无关的通用工具库依赖，且 JSZip 打包需要把全部贴图字节先读进内存拼一个 zip 结构（哪怕是纯本地 vendor，也是额外的运行时内存峰值和一次性 CPU 压缩耗时），换来的收益只是把「用户看到 N 次浏览器下载确认」变成「用户看到 1 次」。
- 真实样品/典型场景的贴图数量是个位数到十几张（本次验证样品 9 张），方案 B「逐个下载之间插入 180ms 间隔」这个已知的浏览器连续下载拦截风险，在这个数量级下影响可控（验证时 Playwright 环境 9 张全部正常触发，没有被拦截；真实 Chrome/Edge 用户首次触发会看到一次「该网站要下载多个文件」确认弹窗，允许一次后同一站点不会再问，已经在 `#texHelpOverlay` 说明文字里如实告知这个已知的用户体验代价，不是把限制藏起来）。
- 方案 B 零新依赖，实现和验证都更简单可靠——复用了项目里已有的 `saveGlbLocalBtn`/`exportBtn` 同一套 `URL.createObjectURL(blob) + <a download> + click()` 模式（见 `exportGlbBlob()`/`$('exportBtn').onclick` 附近代码），不用学一个新库的 API、不用担心 zip 格式实现细节（压缩算法/CRC/目录结构）引入新的 bug 来源。

**两种贴图数据来源，`getTexExportBlob(i)` 统一处理，复用 #26/#16 已有的解码路径，没有重新发明**：

1. **GLB 内嵌贴图（`bufferView`）**：跟 `rebuildTexTable()`/`openImagePreviewByIndex()` 完全同一条切片公式——`currentBinOffset + bufferView.byteOffset` 定位到 `currentBuf` 里的字节区间，`new Blob([slice], {type: im.mimeType})` 直接就是原始文件字节。比 `openImagePreviewByIndex()` 那边（那边为了当 `<img src>` 用，会多转一次 canvas → PNG）更保真——这里是下载原始文件用于导出，不需要也不应该经过 canvas 重新编码（会丢失原始格式，JPEG 会被转成 PNG，还会有一次有损转码）。
2. **本工具自己上传/替换过的贴图**：去代码里确认后发现存储方式是 `raw.images[i].uri`——`uploadMatTexture()`（#16）用 `FileReader.readAsDataURL(file)` 把用户上传的原始文件整个编码成 data URI 存进这个字段（**不经过 canvas**，见该函数注释「保留原始文件字节」），所以导出时直接 `fetch(uri).then(r => r.blob())` 就能拿回逐字节相同的原始文件——验证时用 `Buffer.equals()` 逐字节比对下载文件和本地源文件，完全相同。

**命名去重**：`dedupeExportFileName(name, usedCount)`，`usedCount` 是一次批量导出生命周期内的 `Map`（不是模块级状态，每次调用 `exportAllTextures()` 重新开一个），第 N 次出现同名时在扩展名前插入 `(N)`——`texture.png` → `texture(1).png` → `texture(2).png`，没有扩展名的名字直接在末尾加 `(N)`。

**UI**：贴图 Tab 列表区顶部，跟「共 N 张贴图」计数同一行、右对齐（`.tex-toolbar` 新增，CSS 规则复用材质 Tab `.mat-toolbar` 的 flex 布局，选择器合并写成 `.mat-toolbar, .tex-toolbar`，不重复定义一遍），按钮文案「⇓ 批量导出全部贴图」。没有贴图时（`raw.images.length === 0`）按钮渲染出来但 `disabled`，`title` 提示「此模型没有贴图，无法导出」——照抄 #35 已有的「按钮永远渲染、用 disabled+title 表达不可用」惯例（替换/删除按钮都是这个模式），没有整个按钮隐藏消失。`exportAllTextures()` 本身在 `raw.images.length === 0` 时也会防御性早退（`status('没有可导出的贴图')`），不依赖调用方一定检查过按钮状态才调用。`#texHelpOverlay` 说明浮层追加一段文字介绍这个按钮 + 如实告知浏览器连续下载确认提示的已知代价。

**进度反馈**：导出过程中状态栏文案 `批量导出贴图中…（N/总数）` 实时更新（每完成一张更新一次），全部完成后 `批量导出完成：成功 N 张，失败 M 张`，同时写一条 `logEntry`（有失败张数时用 `warn` 级别，全部成功用 `info`）——不是点一下按钮就没有任何反馈干等浏览器弹下载框。

**默认针对 `activeModel`，没有做"导出全场景多模型贴图汇总"**：`exportAllTextures()`/`getTexExportBlob()` 读的都是模块级 `raw`/`tables`/`currentBuf`/`currentBinOffset`，在 #39 的 harvest/apply 架构下这些变量精确等于 `activeModel` 的数据，天然只导出当前激活模型——任务描述里这条本来就不是硬性要求，这次没有顺手做多模型汇总（会引入"要不要按模型分子文件夹命名""模型间贴图重名怎么防覆盖"这类新问题，超出这次任务范围）。

**验证**（`_dev/test-todo41-texexport.js`，Playwright，28 项断言全 PASS，真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`，9 张内嵌贴图）：

- **下载数量精确性**：`page.on('download', ...)` 监听 + 轮询等待，触发一次批量导出后捕获到的下载事件数量精确等于 `raw.images.length`（9 个），不多不少。
- **字节完整性，两层独立校验**（同 §14/§40 记录的方法论，不信任被测代码或浏览器自己的判断）：每个下载文件用 Node 手写的 PNG/JPEG 文件头解析器（`pngDims()`/`jpegDims()`，直接读 `IHDR` 字段 / 扫描 `SOFn` marker，不调用任何图像解码库、不调用被测的 `createImageBitmap` 路径）独立解出宽高，逐一比对跟 UI 显示的尺寸（`rebuildTexTable()` 用 `createImageBitmap` 异步解出的那份）精确相等——证明下载下来的字节真的是完整、未损坏的图片，不是巧合凑出来的文件大小。另外对 bufferView 来源的全部 9 张，下载文件字节长度精确等于 `bufferView.byteLength`（证明切片没有多切/少切一个字节）。
- **命名去重**：人为把 `raw.images[1].name` 改成跟 `raw.images[0].name` 相同（等价于用户通过改名输入框把两张贴图改成同名），触发导出后确认第一张保留原名、第二张精确变成 `原名(1).扩展名`，且总下载数量仍然精确等于 9（没有因为重名互相覆盖导致文件"丢失"，这里的"丢失"指的是文件名层面的覆盖风险，不是浏览器下载数量本身会减少——两次导出各自独立触发浏览器下载，这条断言主要验证的是命名逻辑没有把两个不同的 blob 生成同一个文件名）。
- **上传替换贴图来源**：走真实 UI 流程点击「⇅ 替换贴图」上传本地 PNG（`test-tex-1-red.png`，32×32），确认 `raw.images` 新增一条 `uri` 来源（非 `bufferView`）记录后，批量导出数量精确变成 10（含新贴图），新贴图对应的下载文件用 `Buffer.equals()` 跟本地源文件逐字节比对完全相同，独立解码出的尺寸 32×32 也跟本地源文件一致。
- **无贴图禁用态**：复用已有的 `_dev/gen-empty-slot-gltf.js` 合成样品（`test-empty-slot.glb`，`materials` 存在但没有任何贴图槽，`raw.images` 是 `undefined`）——用干净的 `page.reload()` 重新单独加载（不是在已有模型基础上追加，因为 #39 之后 `#fileInput` 默认是"追加新模型"语义，直接追加不会让空贴图模型变成激活模型，这个坑在写测试时先撞到过一次，改成 reload 后独立加载解决），确认按钮渲染出来但 `disabled`、`title` 含"没有贴图"提示，且直接调用 `window.__debugTexExport.exportAllTextures()` 不抛异常、给出状态栏提示。
- 全程控制台 0 报错。
- **既有回归**：重跑 `_dev/test-todo35-textab.js`（贴图 Tab #35 全部既有功能），43 项断言全部 PASS，没有破坏详情/列表布局、跳转联动、改名/替换/删除。

调试钩子：`window.__debugTexExport = { exportAllTextures, getTexExportBlob, dedupeExportFileName }`，同既有 `__debugAlign`/`__debugModels` 一套暴露方式，供测试脚本既能走真实 UI 点击（配合 `page.on('download')`），也能直接调底层函数独立验证解码/命名逻辑，不用每次都触发一整轮下载。

**测试脚本/截图**：`_dev/test-todo41-texexport.js`（可重跑复查）；截图 `_dev/shots-41/41-00-tex-tab-with-export-btn.png`（按钮在贴图列表顶部的位置）、`41-01-empty-state.png`（无贴图禁用态）。

---

## 19. 视口新工具条：模式组（选择/移动/旋转/缩放）+ 真实拖拽 gizmo（对应 `Doc/TODO.md` #33，§17.4 决策记录第2、3点，2026-08-07 完成）

**背景**：§14（节点移动/旋转基础编辑）当时只做了数值输入这一条路径（面板里的 T/R°输入框），视口拖拽 gizmo 明确留到了以后。§17.4 决策记录第3点用户后来明确要求这轮就要做真正的拖拽 gizmo，不是继续往后拖——这一节就是那部分的实现记录。**跟 §14 是并存关系，不是替换**：数值面板（现在是 #29 之后的详情区内联 `ndTx`/`ndTy`/`ndTz`/`ndRx`/`ndRy`/`ndRz`/`ndSx`/`ndSy`/`ndSz` 九个输入框）原封不动，这次只是新增了第二种驱动同一份底层数据的输入方式。

### 19.1 vendor：TransformControls.js（r166，版本对齐确认）

`vendor/controls/TransformControls.js` 是新增文件，直接从 `https://raw.githubusercontent.com/mrdoob/three.js/r166/examples/jsm/controls/TransformControls.js` 原样取的，一行没改。版本号确认：`vendor/three.module.js` 里 `const REVISION = '166'`，跟现有 `vendor/loaders/GLTFLoader.js`/`vendor/controls/OrbitControls.js`/`vendor/exporters/GLTFExporter.js` 是同一个 r166，任务要求的"不要引入不匹配版本"这条满足。r166 的 `TransformControls` 是老式 API——它本身就是 `Object3D`（内部把可视 gizmo 当子对象直接挂在自己身上），`scene.add(transformControls)` 就能用，不是更高版本 three.js 才有的 `.getHelper()` 拆分写法（那种新写法在 r166 上会直接报错，确认过没有混用）。

### 19.2 新工具条骨架

视口右侧新增竖排 dock `#modeTools`，位置对称于左侧已有的视角预设工具条 `#viewTools`（`position:absolute; top:12px; right:12px`），跟 `#viewTools` 同一套「竖排 + gap:1px 露分隔线」的紧凑小工具条外壳，直接照搬其 CSS 结构和配色公式（`.on`/`.active` 都是「文字变 `--accent`、背景变 `--panel2`」，不是另发明一种高亮方式）。模型加载后才显示（`buildTables()` 末尾跟 `#viewTools` 同一行一起 `hidden = false`）。

- **上半组（互斥，四选一）**：▢ 选择（默认）/ ✥ 移动 / ↻ 旋转 / ⤢ 缩放，`data-mode` 属性驱动，点击调 `setEditMode(mode)`。
- **中间分隔条**：`.mode-tools-sep`，不设自己的背景色，露出容器本身的 `--line` 背景，视觉上跟按钮间 1px 缝隙同一个原理，只是故意留得更高做出分组感。
- **下半组（快捷动作，占位）**：⬚ 包裹框 / ⊕ 中心点，`data-action="bbox"`/`data-action="center"`，**这次只搭视觉骨架，没有绑定任何 `onclick`**——具体行为是 `Doc/TODO.md` #34 的范围，任务原文明确要求不要抢先实现避免跟 #34 代理重复/冲突，按钮点击目前没有任何反应（不是遗漏，是刻意留白，代码里有对应注释）。

### 19.3 「选择」模式：视口点击选中节点

新增 `raycastNodeAt(clientX, clientY)`：复用既有 `raycastMeshAt()`（#19 视口取色已经在用的同一条命中路径）拿到点击命中的 `THREE.Mesh`，再沿 `.parent` 链向上走，用 `gltf.parser.associations` 查 `info.nodes !== undefined`（`isNodeLevelObject()` 同一个判定思路，处理多 primitive 网格节点内部的 `Group` 包装层，跟 `createInstance` 附近现有注释的做法一致），找到最近的「节点级」对象即为命中的节点索引。

视口新增一对 `pointerdown`/`pointerup` 监听器，跟 #19 视口取色（`pickModeActive`）同一套「按下-抬起距离阈值（5px）判定点击 vs 拖拽转视角」手法，互斥于：① 材质取色模式激活时（取色优先）；② 这次 pointerdown 命中了 gizmo 本身（`transformControls.dragging` 在 TransformControls 自己的 pointerdown 处理里同步置 true，早于事件冒泡到 `viewport` 的监听器，见下面 19.4）。命中节点后直接复用 `jumpToNode(name)`（§6.1 已有的「选中+切到模型块 Tab+滚动定位+闪烁高亮」全链路，不新写一套选中反馈）。

点击行为在四个模式下都生效（不只是「选择」模式）——跟 `Doc/2026-08-06-material-panel-redesign.html` §04 的设计说明一致：「选中某个模式后，点视口里的物体即按该模式操作」，移动/旋转/缩放模式下点别的物体一样会切换选中目标。「选择」模式的特殊之处只是不出 gizmo（`editMode==='select'` 时 `syncGizmoToSelection()` 直接 `detach()`）。

### 19.4 「移动」「旋转」「缩放」：真实拖拽 gizmo

`const transformControls = new TransformControls(camera, renderer.domElement); scene.add(transformControls);`——只 `scene.add()`，不进 `model` 子树，天然被 `GLTFExporter` 导出路径排除（跟 §6.1 选中高亮/包围盒线框同一个既有约定），已用 `scene.children.includes()`/`model.children.includes()` 两条断言验证。

- **模式切换**：`setEditMode(mode)` 只在 mode 是 `translate`/`rotate`/`scale` 时调 `transformControls.setMode(mode)`；`syncGizmoToSelection()` 统一收敛「gizmo 该不该显示、挂在哪个节点上」的判断（`editMode==='select'` 或没有选中节点就 `detach()`，否则 `attach(nodeObjects.get(selectedNodeIdx))`），在 `setNodeSelection()`/`clearNodeSelection()`/`setEditMode()` 三处调用点都收敛到这一个函数，不在多处各自判断一遍。
- **缩放模式没有做简化版**：TransformControls 官方自带 `scale` 模式（跟 `translate`/`rotate` 是同一个类的三种模式，`setMode('scale')` 即可），接入成本跟另外两种模式完全一样，**这次做的是真实拖拽缩放手柄，不是"点物体展开数值面板缩放输入框"那种简化版**——任务允许在精度/复杂度问题较大时降级，但实测下来 TransformControls 本身已经把三种模式都做好了，没有必要降级，如实报告没有做简化。
- **跟 OrbitControls 的手势冲突**：照抄 three.js 官方推荐模式，监听 `transformControls.addEventListener('dragging-changed', ev => { controls.enabled = !ev.value; ... })`——`dragging` 是 TransformControls 内部用 `Object.defineProperty` 包装的属性，值变化时自动 `dispatchEvent({type:'dragging-changed', value})`，不是这次自己发明的机制。已用真实鼠标 down/move-序列/up 验证：按下命中轴手柄那一刻 `controls.enabled` 立即变 `false`，拖拽过程中相机 `position`/`target` 全程保持不变（≠ 相机跟着转/平移），松手后 `controls.enabled` 恢复 `true`。
- **数值同步（gizmo → raw.nodes）**：`gizmoTargetWorldTR(ni, parentOf)`——TransformControls 拖拽期间直接改的是 `obj.position`/`.quaternion`/`.scale`（three.js 场景图对象的父级局部空间属性，语义上跟 `raw.nodes[ni]` 的 `translation`/`rotation`/`scale` 是同一件事），**不能直接把这仨字段原样抄进 `raw.nodes[ni]`**（虽然数值上是对的，但会绕开 §14/#29 统一走的"世界空间换算+校验"这条路径）——正确做法是：用 `obj` 当前的局部变换现算一个局部矩阵，乘父节点的 `nodeWorldMatrix(pni, parentOf)`（父节点没被这次拖拽动过，`raw.nodes[pni]` 仍权威）得到目标世界矩阵，`decompose()` 出目标世界 T/Rdeg/S，喂给 §14 已有的 `applyNodeWorldTransform(ni, targetT, targetRdeg, targetS)`——**跟 #17/#29 数值面板输入走的是完全同一个写回函数**，gizmo 只是新增了第三种（前两种是数值输入框、#29 之前的独立弹窗）算出 `targetT/targetRdeg/targetS` 的方式。这里特别要注意**不能**直接用 `nodeWorldMatrix(ni,...)` 读被拖拽节点自己的世界矩阵——那个函数读的是 `raw.nodes[ni]`，这一刻还是拖拽前的旧值（TransformControls 只改了 three.js 对象，没有改 `raw`），必须现读 `obj` 的局部变换。
- **拖拽结束才算一次完整操作**：`dragging-changed` 从 `true→false`（松手）那一刻，① 判断有没有真的变化（跟数值面板同一个"没动过不产生撤销记录"标准）；② 有变化才调 `applyNodeWorldTransform` 写回；③ `pushUndo`/`recordScriptOp`（`op: 'setNodeTransform'`，**复用 #29 数值面板已经在用的同一个 op 名字**，重放/差异梳理不需要为 gizmo 单独识别一种新操作类型）；④ `renderTab('node')` 刷新数值面板。拖拽开始（`dragging-changed` 变 `true`）那一刻拍一次"变更前"世界 T/R/S 快照（`getNodeWorldTR`，跟数值面板 `applyNodeTrsFromInputs` 的 before 快照同一条路径），供松手时算 diff/撤销用。
- **双向同步**：① gizmo → 数值面板——拖拽结束提交后 `renderTab('node')` 重渲染详情区，数值输入框读的是当时最新的 `getNodeWorldTR()`，天然显示拖拽后的值；② 数值面板 → gizmo——数值面板提交（`applyNodeTrsFromInputs`）本来就调 `applyNodeWorldTransform`，会把新的局部 T/Q/S 写回 `obj.position`/`.quaternion`/`.scale`；`TransformControls` 每帧在自己的 `updateMatrixWorld()` 里读 `this.object.matrixWorld` 重算 gizmo 应该画在哪（r166 源码原有行为，不是这次新加的），**不需要为这个方向另外写任何代码**，gizmo 会在下一帧自动跟到新位置。两个方向都用真实事件驱动验证过（gizmo 方向：真实鼠标拖拽后读数值框 DOM 值；数值面板方向：`page.fill()`+触发 `.trs-edit-row` 的 `focusout` 提交后读 `transformControls.worldPosition`）。
- **拖拽中的选中框**：`nodeSelectHelper`（§6.1 的 `Box3Helper`）不会自动跟着被拖拽对象走——`Box3Helper` 每帧自己读 `this.box` 重算位置/尺寸，但 `box` 的边界值要有人主动喂新值才会变。新增 `updateNodeSelectHelperBox()`，挂在 `transformControls.addEventListener('objectChange', ...)`（拖拽过程中每次改动都会触发）上，拖拽时选中框会跟着实时收紧/移动，不会跟被拖拽物体脱节。

### 19.5 已知限制：非均匀父级世界缩放 + 偏离缩放主轴的旋转

**这不是这次新写的 gizmo 代码引入的 bug，是 §14 定下、§16.1（#29）已经记录过一次的"世界空间 T/R/S"模型本身的固有数学局限，这次验证过程中用真实拖拽复现了一次，如实记录**：

如果一个节点的父节点自身世界缩放不均匀（比如 `画稿飞扬v2.glb` 里 `node_5`，世界缩放 `[4.4366, 4.4366, 1.0000]`——X/Y 一致但 Z 明显不同），对它的子节点做一次不沿缩放主轴的旋转（比如绕 X/Y 轴转较大角度），父级非均匀缩放 + 子级旋转复合出来的世界变换矩阵会带**剪切（shear）分量**，`Matrix4.decompose()` 无法精确还原出一个"纯旋转"分量，只能给出近似值——用 gizmo 拖拽 Y 轴旋转环转了约 41° 后，独立交叉验证（四元数夹角）显示约 34° 的偏差。

**验证过程中额外做的诊断，证明这不是 gizmo 专属问题**：换回同一个节点（`L6`），完全不碰 gizmo、直接调用跟数值面板输入同一个函数 `applyNodeWorldTransform(4, T, [35,0,0], S)`（模拟用户在 #17/#29 数值面板里手动输入 X 轴旋转 35°），一样复现出约 33° 的偏差——证明这是共享写回函数本身的特性，gizmo 只是新增了一种更容易踩中它的输入方式（用户很少会在数值面板里手动敲一个偏离缩放主轴的角度组合，但拖拽旋转环时很自然会转到任意角度）。换一个父节点世界缩放均匀的节点（`PArc864`，父节点缩放 `[2.3647,2.3647,2.3647]`）重复同样的 gizmo 拖拽流程，四元数交叉验证误差精确为 `0.00°`——证实"父级缩放均匀"是问题的边界条件，不是 gizmo 拖拽输入方式本身不精确。

**没有在这次任务里修**：修正这个问题需要重新设计"世界空间旋转"在非均匀缩放父级下的定义方式（比如改成记录/编辑"局部旋转"而不是"世界旋转"，或者引入显式剪切分量），是明显更大的一块工作，超出这次任务范围（任务要求"复用§14已有的换算逻辑，不要另起一套"），如实记录、留给以后有真实需求时再评估。真实样品里大多数节点父级缩放是均匀的（`画稿飞扬v2.glb` 26 个节点里只有 `node_5`/`node_25` 两个父级缩放不均匀），这个限制的实际触发概率不高。

### 19.6 验证

测试脚本：`_dev/test-todo33-gizmo.js`（Playwright，54 项断言全部 PASS，可重跑复查）。真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`（20 材质、9 贴图、26 节点的密集展台场景）。**全程用真实鼠标 `page.mouse.move`/`.down()`/`.up()` 事件序列驱动 gizmo 拖拽，没有走捷径直接调内部函数**——轴手柄的屏幕坐标不是手算的（gizmo 尺寸随相机距离自动缩放，手算容易算错），是在页面上下文里现场用 `THREE.Raycaster` 从投影中心螺旋展开搜索，直到真的命中该轴的 picker mesh 为止，拿到这个真实屏幕坐标后才交给 Playwright 的 `page.mouse` API。

覆盖：① 新工具条骨架（四个互斥模式按钮+分隔线+两个占位动作按钮，默认选择模式）；② 视口点击选中节点（真实 down/up 序列，命中节点跟独立预测一致，自动切到模型块 Tab）；③ 移动模式 gizmo 正确挂到选中节点；④ 真实拖拽 X 轴箭头——`OrbitControls.enabled` 在按下瞬间变 `false`、拖拽全程相机位置/target 不变、松手恢复 `true`；写回后用**两条完全独立的路径**交叉验证世界坐标（纯 JSON 手写父链累乘 `rawWorldTR()`，等价于测试脚本自己重新实现一遍 `nodeWorldMatrix()` 但不调用被测代码一行；vs. 从 three.js 真实 `obj.matrixWorld` 出发除掉 `model.matrixWorld`（`preprocess()` 整体归一化）反推"raw glTF 空间"世界矩阵，这条路径完全经过 three.js 引擎自己的矩阵传播，不调用 `nodeWorldMatrix`/`rawWorldTR` 任何一行）——两条路径误差 `1e-14` 米量级；⑤ 数值面板→显示拖拽后的世界坐标（gizmo→面板同步）；⑥ 撤销一步精确回到拖拽前（`raw.nodes[4]` 逐值核对）；⑦ 数值面板输入新坐标后 `obj.position`/gizmo `worldPosition` 精确跟着变（面板→gizmo 反向同步）；⑧ 旋转模式真实拖拽旋转环（父级缩放均匀节点，四元数夹角交叉验证误差 `0°`）+ 撤销；⑨ 已知限制复现（见 19.5）；⑩ 缩放模式真实拖拽缩放手柄（真实 gizmo，非简化版），交叉验证误差 `1e-19` 量级；⑪ `transformControls` 是 `scene` 直接子对象、不在 `model` 子树里（导出天然排除）；⑫ 控制台全程 0 报错。

另重跑既有回归测试确认没有破坏已有功能：`test-viewport-tools.js`（21 项 PASS）、`test-undo-status.js`（29 项 PASS）、`test-todo29.js`（43 项 PASS，含它自己那条"新父节点缩放不均匀"已知限制的独立验证，跟 19.5 是同一类现象在不同功能上的表现）、`test-bbox.js`（全 PASS）——`test-highlight.js`/`test-scene-menu.js` 撞上 #26 已经记录在案、与这次改动无关的既有问题（`#texPreviewOverlay` 拦截点击），如实记录不是这次引入的回归。

截图：`_dev/shots-33/00` 至 `06`（新工具条骨架、gizmo 挂载、拖拽中/后的移动/旋转/缩放三种手柄、数值面板反向同步）。调试钩子：`window.__debugGizmo = { transformControls, editMode, setEditMode, raycastNodeAt, gizmoTargetWorldTR, syncGizmoToSelection, model }`。

### 19.7 视口新工具条：动作组（包裹框/中心点/导出）接上行为（对应 `Doc/TODO.md` #34，§17.4 决策记录第1、2点，2026-08-07 完成）

**背景**：§19.2 搭好的下半组「⬚包裹框」「⊕中心点」两个按钮当时只是视觉骨架，刻意没有绑 `onclick`（留给这次任务，避免代理间冲突）。这次接上行为，并按决策记录第1点要求新增了第三个「导出」按钮。三个都直接在 `#modeBboxBtn`/`#modeCenterBtn` 这两个既有按钮上接 `onclick`，没有重新建一套新按钮；`#modeExportBtn` 是新增的第三个，跟前两个共享同一套 CSS/DOM 结构。

**三个都不是模式按钮**——跟上半组「选择/移动/旋转/缩放」四选一的持久互斥态不同，这三个是点一下就执行一次的动作，不维护「选中/激活」状态。视觉反馈交给原生 CSS `:active`（按下瞬间文字变 `--accent`、背景变 `--panel2`，松手恢复），没有额外的 JS 状态维护；没有选中节点时通过 `button.disabled` 禁用（`syncModeActionButtons()`，挂在 `setNodeSelection()`/`clearNodeSelection()` 两处状态变化点，跟 `syncGizmoToSelection()` 同一个收敛模式），三个按钮的 `onclick` 内部也各自保留一次 `selectedNodeIdx === null` 的防御性判断+`status()`提示，应对「disabled 被绕过」的边界情况（比如测试脚本直接调 `.onclick()`），不会报错。

**「导出」按钮**：`exportSelectedNode(ni)`——§6 已有的函数，一行没改，直接复用。点击立即触发浏览器下载（没有面板，导出选中节点这个动作在 §6 里从来就没有中间态面板，符合决策记录第2点"直接执行"的精神——只是这个动作恰好没有"结果面板"可展开）。

**「包裹框」按钮**：`openBBoxPanel(ni)`——§9 已有的函数，一行没改。没有 bbox 时内部先调 `generateBBox(ni)`（按节点世界空间旋转角生成默认定向包围盒）再打开编辑面板；已有 bbox 时直接打开编辑，不重新生成（不会冲掉用户手动改过的角度）。跟模型块 Tab 详情区（§6.2）原有的「⬚ 包围盒」按钮是**同一个函数、两个入口**，行为逐字节一致，不是照着抄一份。

**「中心点」按钮——决策记录第1点原话「两者都要，并且显示告诉用户基点在哪里。并且可以编辑，保存」**：

新增居中浮层 `#centerPointOverlay`/`#centerPointPanel`，外壳（Overlay/Panel/PanelHead/Body 四层结构、尺寸/间距/配色）直接照抄 `#bboxOverlay` 那一套，没有发明新样式。内容三块，同时呈现在同一个面板里，不是切换式的两个模式：

1. **关联到已有基点**：一个 `<select id="cpBasepointSel">` 下拉框，选项是「（不关联，用默认）」+ 全部 `anno.basepoints`。选中后直接写 `nodeAnno(targetName).basepointRef`——`targetName` 用 `resolveNodeRowTarget(ni)` 解析（§6.2 合并行挂载点规则），跟节点表「关联基点」列（`data-bpref`）走的是完全同一套数据结构和写回逻辑，只是这次画在浮层里而不是表格行内。
2. **以当前节点位置新建基点**：新函数 `addBasepointAtNode(ni)`——position 取该节点当前的世界坐标（`nodeObjects.get(ni).matrixWorld`，跟 §8 全篇「基点位置用 `nodeObjects` 这套归一化后坐标系」的既有取舍一致），zRotation 固定给 0°（决策记录原文只要求"以当前选中节点的世界坐标位置新建"，没提朝向要不要对齐节点旋转，延续 `addBasepoint()` 手动新增时 `zRotation` 默认 0 的既有约定）。**新建完自动把当前节点关联到这个新基点**——这是本次任务里做的一处产品判断：面板打开的初衷就是"给这个节点配一个基点"，新建了却不关联，用户还要再手动去下拉框选一次，多一步没有必要，如实记录在这里（不是决策记录逐字要求的，是延伸出的合理默认行为）。
3. **常驻显示当前生效基点的可读位置 + 直接编辑保存**：`effectiveBasepointForNode(targetName)`（§8 已有的 fallback 规则：显式关联就用那个，没关联就退到场景第一个基点）算出"当前生效基点"，面板下半部分常驻显示它的 X/Y/Z/朝向° 四个 `<input type="number">`，不是只有一个选择器看不到数值。这四个输入框的 `onchange` 直接调 `updateBasepointPos`/`updateBasepointRot`——跟「场景」Tab 基点列表里对应输入框调用的是**完全相同的函数**，写回 `anno.basepoints[idx]`，视口橙色标记通过这两个函数内部已有的 `syncBasepointHelpers()` 自动跟着重画，没有为中心点面板另外接一遍视口同步逻辑。没有任何基点时（`anno.basepoints` 是空数组），位置区域隐藏、改显示提示文案引导去新建。

**一处必要的既有函数微调（`refreshBasepointUI()`）**：`updateBasepointPos`/`updateBasepointRot`/`addBasepoint`/`addAutoBasepoint`/`deleteBasepoint`/`updateBasepointName` 这六个 §8 已有的基点写回函数，原本结尾统一硬编码 `renderTab('scene')`——这在 #34 之前是对的（基点的增删改此前只能从「场景」Tab 自己触发，那一刻 `currentTab` 本来就是 `'scene'`）。这次新增的中心点面板是第二个入口，可以在「模型块」Tab 打开（选中节点、点视口工具条「中心点」按钮），如果还硬编码 `renderTab('scene')`，会在用户毫无预期的情况下把右侧面板切到「场景」Tab。改成 `refreshBasepointUI()`——按 `currentTab==='scene'` 判断要不要重渲染场景 Tab，另外只要 `#centerPointOverlay` 没隐藏就重渲染中心点面板（`renderCenterPointPanel()`）。这是对这六个函数收尾行为的最小必要调整，写回逻辑本身（`bp.position[axis] = ...; saveAnno(); syncBasepointHelpers();`）一行没动，符合任务要求的"复用§8已有的写回函数，不要另起一套"。

**基点创建逻辑顺带抽了一层公共函数** `createBasepoint(position, zRotation, namePrefix, source)`——`addBasepoint()`（场景 Tab「＋新增基点」）、`addAutoBasepoint()`（场景 Tab「⟲生成默认基点」）、`addBasepointAtNode()`（中心点面板「＋新建基点」）三处共用同一段 `push+saveAnno+syncBasepointHelpers` 逻辑，不各自重写一遍；三者的差异只在传给它的 position/zRotation/namePrefix/source 参数不同。

**CSS 类名踩坑记录**：中心点面板里常驻显示位置的那一行，最初直接复用了「场景」Tab 基点列表用的 `.bp-row` 类（视觉上是同一套横排+wrap+输入框样式），实测跑 `test-basepoints.js` 时发现它断言 `document.querySelectorAll('.bp-row').length === 2` 之类的精确行数——中心点面板一旦打开，这行常驻元素也会被计入，导致既有断言错误地多算 1 行，`page.waitForSelector('.bp-row')` 也会因为优先解析到这个（可能是 `hidden` 的）浮层内元素而超时。改成新类名 `.cp-row`，跟 `.bp-row` 的共享样式声明（`input[type="number"]`/`input[type="text"]` 的尺寸/聚焦描边）合并成 `.bp-row, .cp-row { ... }` 选择器同时命中两者——视觉规则仍然只声明一份、没有复制一份数值，但 DOM 查询不会再互相污染。

**验证**：`_dev/test-todo34-actions.js`（Playwright，50 项断言全部 PASS，可重跑复查）。真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`。覆盖：① 未选中节点时三个按钮 `disabled`，绕过 `disabled` 强行调 `onclick` 也只弹状态栏提示、不报错、不开面板；② 选中节点后三个按钮启用；③ 「导出」触发下载，导出 GLB 的 `meshes`/`materials` 数量精确等于该节点子树依赖收集的预期值（多 primitive 网格节点会被 `GLTFExporter` 拆成多份独立 mesh 条目，跟 §6 已经记录过的既有行为一致，测试脚本按 primitives 数而不是 raw mesh 索引数计算预期值）；④ 「包裹框」没有 bbox 时点击直接生成默认值+自动展开编辑面板；手动改旋转角后再次点击「包裹框」按钮确认是直接打开编辑、没有重新生成把手动改过的角度冲掉；⑤ 「中心点」面板打开，关联下拉框选项数=基点数+1，面板显示的 X/Y/Z/朝向°精确等于该基点当前值；⑥ 「以当前节点位置新建基点」——新建的基点世界坐标用**跟被测代码完全独立的纯 JSON 父链累乘**（`window.rawWorldTNormalized`，自己重新实现一遍矩阵乘法，不调用 `nodeObjects`/`addBasepointAtNode` 内部任何一行，另外还要再乘一次 `model.matrixWorld` 才能对齐 §8 的归一化坐标系，测试脚本自己独立算出这个换算关系）重新算一遍核对，容差 1e-3 米内精确匹配；新建后自动关联当前节点到新基点；⑦ 编辑面板里的 X/朝向° 输入框改值后，`anno.basepoints[idx]` 精确更新，视口橙色基点标记的位置和朝向四元数（跟 `zRotation` 对应的世界 Y 轴旋转角独立算出的四元数交叉核对）同步刷新；⑧ 「场景」Tab 的基点列表能看到中心点面板新建/编辑过的同一条记录（同一份 `anno.basepoints` 数据，两处 UI 一致）；⑨ 面板关闭（点「关闭」按钮 / 点外部背景）两种方式都测到；⑩ 控制台全程 0 报错。另重跑 `test-todo33-gizmo.js`（54 项 PASS，同步更新了其中一条断言——下半组按钮数量从 2 个占位按钮变成 3 个真正可用按钮，`['bbox','center']` 改成 `['bbox','center','export']`）、`test-basepoints.js`（全 PASS）、`test-bbox.js`（全 PASS）、`test-todo30-basepoint-source.js`（全 PASS）确认没有破坏 #33/#8/#9 的既有功能；`test-instance-export.js` 跑到「另存为GLB」那一步撞上 #21 遗留的旧按钮 ID `#exportGlbBtn`（`Doc/TODO.md` #22/#13 报告已经反复记录过的既有问题，跟这次改动无关），在撞上之前 Instance 创建/mesh 索引校验等核心逻辑全部正常通过。测试脚本：`_dev/test-todo34-actions.js`。截图：`_dev/shots-34/00` 至 `04`。调试钩子：`window.__debugModeTools = { syncModeActionButtons, openCenterPointPanel, renderCenterPointPanel, addBasepointAtNode, createBasepoint, refreshBasepointUI, centerPointNi }`。

---

## 20. 响应式：三档断点 + 横屏规则（对应 `Doc/TODO.md` #38，§17 Round 2/3 §04，本轮 #31-38 批次最后一项，2026-08-07 完成）

**背景**：README/SPEC 原来只记了一条响应式规则——「< 900px 时上下分栏」，对应 `index.html` 里唯一的 `@media (max-width: 900px)` 块（`main{flex-direction:column}` + `#panel{width:100%}` + `#viewport{height:40vh}`）。这次任务要求在这条基础上加两档：`<600px` 手机竖屏进一步收紧、独立的横屏规则（`max-height:500px`，强制左右分栏）。**开工前先搜了 index.html 有没有残留实现**——只有这一条 900px 规则，没有任何 600px/横屏相关的媒体查询残留，是干净状态，从这条既有规则上继续加，不是推倒重来。

### 20.1 走查方法：先截图找真实问题，不是「不分青红皂白全局缩小」

用 Playwright + 真实样品 `画稿飞扬v2.glb`，在写任何新 CSS 之前先在 6 组视口尺寸（1280×800/768×1024/375×667/320×568/812×375/1024×400）跑一遍现状截图 + DOM 几何检测（水平溢出量、header 子元素越界量、`#modeTools`/`#panel` 是否重叠），照走查结果定位真实存在的问题，而不是猜。走查发现两个真实 bug（不是这次改动引入的，是既有代码的问题，这次任务顺手一起修了）：

1. **`#panel` 缺 `min-height:0`，纵向内容在窄屏下超出视口却无法滚动到**：`#panel` 在 `main{flex-direction:column}` 生效后变成纵轴（高度方向）flex 子项，没有 `min-height:0` 时它的"自动最小尺寸"由内容撑开决定——实测 700×900 视口下 `#panel` 实际高度被撑到 794px、底部落在 y=1259，远超视口 900px 高度，而 `html`/`body` 又是 `overflow:hidden`，超出的部分既看不到也没法滚动到。这意味着**「< 900px 上下分栏」这条现有规则，从它存在的第一天起就没有真正跑对过**——下方节点/材质列表内容一旦超过一屏，剩下的部分对用户来说是彻底不可达的，不是"需要滚动才能看到"而是"永远看不到"。补一行 `#panel { min-height: 0; }` 后，滚动交给内部 `#tables`（本来就是 `flex:1` + `min-height:0` + `overflow-y:auto`）正确处理，两层 nested flex 现在都遵守同一条收缩规则。**这条修复不算是新增响应式行为，是让"现状规则"名副其实地生效**，任务里"600-900px 确认还生效、不用重写"这条要求因此不是空话。
2. **`header` 8+ 个按钮/菜单一字排开，窄屏下会被压缩到内部文字被迫二次换行，把 `header` 高度顶起来几倍**：实测 375px 视口下 `header` 高度被顶到 165px（正常 ~44px），把下面视口/面板都往下挤；进一步走查发现这不只是 <600px 的问题——812px 宽度（横屏尺寸）下 header 总内容宽度跟容器只差几像素，同样会在某个按钮内部触发文字二次换行，把 header 顶到 80px 高（正常情况的近两倍），侵占横屏本来就紧张的可视高度。**修复本体（`header{flex-wrap:wrap}` + `header>*{white-space:nowrap}`）没有放在某个特定宽度断点里，而是提到了 `header` 的基础规则**——这条规则只在内容真放不下时才生效（内容够宽时保持单行，不会平白无故多出一行），实测桌面 1280px 宽度下没有副作用，所以不需要用媒体查询限定它的生效范围。效果是"放不下的整个按钮挪到下一行"而不是"按钮内部文字断行"，行为更可控，也正好是任务要求的「操作按钮从横排改自动换行」在 header 这个具体位置的体现。

### 20.2 三档断点实现

- **≥900px**：现状桌面布局，视口+右侧面板左右分栏——没有对应的媒体查询，默认状态就是，不用改。
- **600-900px**：`@media (max-width: 900px)` 这条既有规则原样保留（`main{flex-direction:column}` + `#panel{width:100%;flex:1}` + `#viewport{height:40vh}`），只补了上面 20.1 第 1 点的 `min-height:0` 修复。
- **<600px（手机竖屏）**：新增 `@media (max-width: 600px)` 块，在 600-900px 规则基础上进一步收紧，走查后确定收紧了三类真实存在的问题（不是全局无差别缩小）：
  1. **header**：基础规则已经解决了"内容顶高"这个结构性问题（见 20.1），这里只是在 <600px 进一步压缩 `padding`/`gap`，另外把 `.spacer`（原本用来把右侧按钮推到最右边）在 <600px 隐藏——因为一旦 header 换行成多行，spacer 的"撑开推右"语义在窄屏下没有意义，独占一行反而浪费纵向空间；`header .file`（文件名）超长时 `max-width:96px` + 省略号截断，避免单独把 header 撑得更宽。
  2. **居中浮层固定宽度面板**（材质说明/清理菜单/设置/重放/服务器项目/上传结果/包围盒/中心点/贴图删除/各种 ⓘ 说明弹窗，共 12 个 `id`）：全部原本是固定 px 宽度（340-480px），在 320-375px 视口下比视口本身还宽——这些浮层的外壳都是 `position:fixed;inset:0;display:flex;align-items:center;justify-content:center` 居中容器，不会裁切子元素，超宽的面板会左右两边一起探出视口，物理上不可见也点不到（用 `document.elementFromPoint` 验证过顶层命中元素确实会是别的东西）。统一加 `width:92vw;max-width:92vw`，跟原本各自的 `width:XXXpx` 是同一个 ID 选择器特异度、后声明覆盖前声明，不用 `!important`。
  3. **数值面板/表格**：`.node-detail`/`.mdh`/`.mf-row`/`.trs-edit-row` 的内边距和间距收紧一档；`.trs-group input[type="number"]` 宽度从 56px/92px（S 缩放框）收窄到 46px/74px，减少 T/R/S 九个输入框在窄面板里挤出太多换行；表格 `td`/`th` 内边距、字号跟着降一档。
  4. **`#modeTools`/`#viewTools` 新工具条**：图标按钮 `padding`/`font-size` 也收紧一档（详见 20.3，属于"顺手做"，不是发现了遮挡才补的）。

  **`.node-detail-actions`/`.tex-detail-actions` 这类操作按钮组本来就已经是 `flex-wrap:wrap`**（`Doc/TODO.md` #17/#26 就这么写的），这次任务要求的「操作按钮从横排改自动换行」这条在这两处不需要新增代码，是现状已经符合；这次只是在 <600px 下把它们的按钮 `padding`/`font-size` 也收紧了一点，减少不必要的挤压换行。

### 20.3 横屏规则：`@media (max-height: 500px)`，独立于宽度断点

**核心问题**：横屏时如果沿用「<900px 上下分栏」，`#viewport` 会被 `height:40vh` 砍掉一半还多——手机横屏可视高度本来就只有 350-400px 左右，40vh 只剩 140-160px，3D 视口小到没法用。横屏应该反过来优先保证视口可视高度，面板收窄成固定宽度侧栏挪到旁边，这跟窄屏竖屏规则的诉求（"横向空间紧张，纵向随便"）正好相反。**这条规则只判断 `max-height`，完全不看宽度**，所以是跟 <600px 规则正交的一条独立规则，不是同一个断点体系里的分支。

```css
@media (max-height: 500px) {
  main { flex-direction: row; }
  #panel { width: 300px; max-width: 42vw; min-width: 200px; flex: 0 0 auto; height: auto;
    border-left: 1px solid var(--line); border-top: none; }
  #viewport { flex: 1; height: auto; }
  header { padding: 6px 12px; }
  header h1 { font-size: 11px; }
}
```

`#panel` 宽度用 `width:300px` + `max-width:42vw` + `min-width:200px` 三者组合（不是单一固定值）：常规横屏宽度（812/1024px）下生效的是 `300px`，极窄横屏（比如老式手机横屏 480px 宽）时 `max-width:42vw` 接管防止面板吃掉太多本来就不多的横向空间，`min-width:200px` 兜底面板不会窄到按钮点不了。

**写在 `<style>` 块最后一条媒体查询是故意的，不是随手放的位置**：横屏矮屏幕如果宽度恰好也 <600px（比如竖着拿的老式横屏手机），会同时命中 `max-width:900px`/`max-width:600px`/`max-height:500px` 三条规则，三条对 `main`/`#panel`/`#viewport` 都覆盖了同一批属性（`flex-direction`/`width`/`height`/`flex`）。CSS 里选择器特异度相同时（这里三条规则里对应选择器都是同样的 `main`/`#panel`/`#viewport` 元素/ID 选择器，特异度一致）后声明的规则赢——横屏规则排最后，就能保证"横屏优先于宽度断点"生效，不需要 `!important` 这种更粗暴的手段，任务原文明确要求了这个优先级关系，这是实现它的具体方式。

### 20.4 `#modeTools`/`#viewTools` 新工具条在窄屏/横屏下的位置验证

**结论：不需要额外的收起/展开交互，工具条本来就贴视口边缘，天然不会被面板遮挡**——验证下来是"测了确认没问题"，不是"猜没问题就跳过验证"：

- `#modeTools`（右）/`#viewTools`（左）都是 `position:absolute` 挂在 `#viewport` 内部（不是挂在 `main`/`body` 上），`top:12px`，左右分别 `left:12px`/`right:12px`。只要 `#viewport` 和 `#panel` 是两个不重叠的兄弟元素（横屏左右分栏、竖屏上下分栏都满足这一条），两个工具条就永远只会出现在 `#viewport` 自己的矩形范围内，物理上不可能跟 `#panel` 重叠——这是布局结构决定的，不依赖某个具体断点的像素调优。
- **横屏是这次唯一有风险的场景**（宽度分栏时 `#viewport` 变窄，理论上工具条可能被挤到跟 `#panel` 太近甚至重叠）：实测走查过程中确实在**加横屏规则之前**的过渡状态下抓到过一次 `#modeTools` 跟 `#panel` 重叠（812×375 视口下，套用旧的 900px 断点导致 `#panel` 定位异常），但那是"横屏误用了竖屏规则"这个问题本身的症状，不是工具条设计有缺陷——20.3 的横屏规则实现后，`#viewport`/`#panel` 变回两个正常并排的矩形，重叠随之消失，用 Playwright 矩形相交检测复核过 6 组视口全部 `false`（不重叠）。
- 命中测试：`document.elementFromPoint()` 验证 `#modeTools` 每个按钮中心点，顶层元素就是按钮自己，6 组视口下全部通过——不只是"没有几何重叠"，是"确认真的点得到"。
- <600px 下按 20.2 第 4 点把工具条按钮 `padding`/`font-size` 顺手收紧了一档（`#viewTools button` padding 6px 11px→5px 8px，字号不变；`#modeTools button` padding 7px 9px→5px 7px，字号 14px→12px），这是"顺手让极窄视口下的点击热区更协调"，**不是因为发现了遮挡问题才做的补救**，如实记录区分开这两种性质不同的改动。

### 20.5 验证

测试脚本：`_dev/test-todo38-responsive.js`（Playwright，58 项断言全部 PASS）+ `_dev/test-todo38-overlay-panels.js`（12 项断言全部 PASS，专门测居中浮层面板的 `max-width:92vw` 兜底）。真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`。覆盖 6 组视口（1280×800 桌面 / 768×1024 中间档 / 375×667 与 320×568 手机竖屏 / 812×375 与 1024×400 横屏矮屏），每组视口验证：① `main` 的 `flex-direction` 精确匹配该档位预期（横屏 `row`、竖屏中间/手机档 `column`、桌面 `row`）；② 横屏面板宽度 <60% 视口宽、视口高度保住 >55% 可视高度（对照修复前"上下分栏"规则被误用时视口只剩 40vh 的旧行为，是明显改善，55% 这个阈值不是硬性产品指标，是"证明比旧行为好得多"的检验线）；③ `document.documentElement.scrollWidth <= window.innerWidth`（无水平溢出）；④ header 全部直接子元素完整落在视口宽度内（换行到第二行允许，任何单个子元素超界不允许）；⑤ `#modeTools`/`#viewTools` 跟 `#panel` 矩形不相交 + 全部按钮 `elementFromPoint` 命中测试；⑥ 切换材质/贴图/模型块/场景四个 Tab，模型块 Tab 选中节点、材质 Tab 选中材质卡片，详情区展开后都不引发新的水平溢出；⑦ 居中浮层面板（设置面板）完整落在视口内（`left>=-1 && right<=winW+1`）；⑧ 控制台全程 0 报错。截图：`_dev/shots-38/`，每组视口存了整体全览（`-00-overview`）、模型块详情区（`-01-node`）、材质详情区（`-02-mat`）三张，另外 `-settings-overlay.png` 单独证明浮层面板 max-width 兜底生效。

**过程中一个需要说明的测试细节，如实记录**：验证脚本切换 Tab 并点击材质卡片后再打开设置面板这个动作序列里，`#settingsBtn` 的点击一度被拦截——诊断确认拦截它的是 `#texPreviewOverlay`（材质大图预览浮层），这是 §19.6 已经记录在案、与本次改动无关的既有问题（点击材质卡片会意外让贴图大图预览浮层保持可交互状态，挡住后续点击）。为了不让这个已知问题干扰响应式验证的结论，`test-todo38-overlay-panels.js` 单独跑一遍干净流程（只加载模型直接点设置按钮，不经过材质卡片点击），12 项全部 PASS，证明浮层面板本身的响应式行为是正确的。

**回归测试**：重跑 `_dev/test-todo33-gizmo.js`（54 项 PASS）、`_dev/test-viewport-tools.js`（全 PASS）、`_dev/test-todo36-nodetab.js`（35 项 PASS）、`_dev/test-todo37-scenetab.js`（82 项 PASS）、`_dev/test-undo-status.js`（29 项 PASS），确认这次的 CSS 改动（尤其是 `header`/`#panel` 两条基础规则的调整）没有破坏桌面尺寸下的既有功能。

---

## 21. GLB 解包导出（glTF 分离格式）+ 相机视角卷展栏（口述需求记录，2026-08-08，已确认，待排期）

**原话**：「另外我理解glb是个包。能否导出素材和glb里其他信息呢？在菜单里增加。另外在左上角视图区域增加相机卷展览可以新建和存储/删除重命名视角。当然折叠在菜单内部。」

用户对两条需求里的关键歧义点已经逐条确认（见下），不需要再澄清，可以直接排期。

### 21.1 GLB 解包导出（glTF 分离格式）

**确认结论**：不是简单扩展 #41 的贴图批量导出，而是把当前 GLB **解包成标准 glTF 分离格式**——`.gltf`（JSON 文档）+ `.bin`（几何/动画等二进制缓冲区）+ 全部贴图文件，一个文件夹或 zip 打包整体下载。这正好是 `CHANGELOG.md` v0.1.0 起一直标注的已知限制「不支持 glTF 分离格式（.gltf + .bin + 贴图文件夹）」的**反方向**——原来那条限制说的是"不能读入分离格式"，这次要做的是"能导出成分离格式"，是两件事，不冲突，但导出这条做成了之后，理论上也顺带验证了分离格式本身这套三件套数据结构是不是正确、可以为以后"读入分离格式"打基础。

**技术要点（待实现时细化，这里先记落地方向）**：
- 数据来源：`raw`（= `gltf.parser.json`，已加载模型的完整 glTF JSON）里的 `buffers`/`bufferViews`/`images` 等，加上视口当前实际编辑状态（跟「另存为 GLB」§3 一样，要反映用户已经做的全部编辑，不是原始加载时的快照）——最简单可靠的路径是复用 §3 已有的 `GLTFExporter.parse()` 得到最新的 GLB 二进制，再把这份 GLB 按 §17.1 已经验证过的 GLB chunk 结构（12字节头+JSON chunk+BIN chunk）**手动拆包**：JSON chunk 直接存成 `.gltf`（改写内部 `images[].bufferView`/`uri`/`buffers[].uri` 等引用指向拆出来的外部文件，不能保留原来指向内嵌 BIN chunk 的写法）、BIN chunk 存成 `.bin`（`buffers[0].uri` 指向这个文件名）、每张贴图各自单独存成文件（`images[]` 从 `bufferView` 引用改成 `uri` 指向对应文件名，去掉多余的 `bufferView`/`mimeType` 就着这层改动一起处理）。
- 打包成 zip 还是文件夹：参考 #41 的选型讨论（本项目至今没有非 three.js 的 vendor 依赖），如果要 zip 需要引入打包库；如果嫌加依赖麻烦，也可以用「逐个触发浏览器下载」这个 #41 已经用过的零依赖方案，让用户自己建文件夹放在一起——实现时再判断，不是这次要求的强制项。
- 入口位置：用户原话是「在菜单里增加」——放进现有「另存为 GLB ▾」下拉菜单（跟「本地文件」「保存到服务器」并列一个新选项，比如「解包为 glTF（.gltf+.bin+贴图）」），不需要新开一个菜单入口。
- 多模型场景（#39）下，默认针对 `activeModel`（当前正在编辑的模型），不强制要求支持"打包全部已加载模型"。

### 21.2 相机视角卷展栏

**确认结论**（三点待确认，已逐条问清）：
1. **视角字段**：不只是位置+看向目标点（跟现有 4 个预设视角一样的两个向量），还要记录**视野/缩放**等参数——具体对应 three.js `PerspectiveCamera` 的 `fov`（视野角度）和 `zoom`（如果用到），加上 `OrbitControls` 当前状态需要的话一并记（比如 `target`），做到"应用这条保存的视角后，画面精确复现保存时的样子"，不是只恢复个大概位置。
2. **归属范围**：**每个模型各自独立一份**，跟 #39 多模型架构、§8 测量基点、§9 包围盒等标注数据同一个存储层级——存进当前激活模型的 `anno`（比如 `anno.cameraViews`，数组，每项 `{name, position, target, fov, zoom, ...}`），切换模型（#39 模型切换器）时这份列表要跟着换成新激活模型的。
3. **UI 位置与形态**：视口左上角现有的视角工具条（正视/侧视/顶视/默认 4 个按钮）**旁边或下方**增加入口，具体交互原话明确是「折叠在菜单内部」——默认收起，不常驻占用视口空间，点开才展开列表（可以是一个小箭头/图标触发的下拉，或者卷展栏 `<details>`，具体选哪种排期时定，参考 §17.3 场景 Tab 元数据卷展栏已经用过的模式，不用发明新组件）。展开后的列表要支持：**新建**（保存当前相机位置成一条新记录）、**应用**（点某条记录切回那个视角）、**重命名**、**删除**——四个操作原话都点到了，不能只做新建+应用。

### 待实现范围内的小判断（不阻塞排期，实现时按此执行，不必再问）

- 「解包导出」优先保证正确性（导出后的 `.gltf`+`.bin`+贴图三件套要能被本工具自己重新打开验证往返正确，或者至少能过 glTF 官方校验工具的语义检查），打包成 zip 还是逐个下载看实现时的权衡，不是这次决策的重点。
- 「相机视角」的默认命名（新建时如果用户没输入名字）可以参考基点系统 §8 已有的"默认基点"/"默认基点2"防重名逻辑，不用另外发明一套。
- 两条需求互相独立，可以并行排期，没有依赖关系。

### #42 实现记录（GLB 解包导出，2026-08-08 完成，§21.2 相机视角卷展栏未在这次范围内）

**开工前先搜了一遍 `index.html`**：「解包」「分离格式」「unpack」全部无匹配，没有任何残留实现，从零开始。

**数据来源，跟 §3「另存为 GLB」同一条路径**：新增函数 `unpackGlbToGltf()` 第一步就是 `await exportGlbBlob()`——这是 §3 已有的 `GLTFExporter.parse(model, ..., {binary:true})` 包一层 Promise 的既有函数，跟「本地文件」「保存到服务器」两个既有菜单项共用同一份实现，一行没改。拿到的是反映当前编辑状态（材质改色/贴图替换/节点变换/Instance）的最新 GLB，不读 `raw`/`currentBuf`（原始加载快照）。

**手动拆包**：新增 `splitGlbChunks(buf)`，跟既有 `findGlbBinChunkOffset()` 同一套 12 字节头 + chunk 循环的结构认知，区别是这次要把 JSON chunk 和 BIN chunk**整个**取出来（不是只要 BIN chunk 的起始偏移）——JSON chunk 用 `TextDecoder` 解成字符串再 `JSON.parse()`（这一步天然完成了"深拷贝"，改这份对象不会影响原始 `raw`），BIN chunk 保持 `ArrayBuffer` 不动。

**`images[]`/`buffers[]`/`bufferViews[]` 改写逻辑（`convertGltfJsonToSeparate()`，本任务技术核心）**：

- **文件名来源，一处返工**：任务描述原话是「文件名用 `images[i].name`」，但读 `vendor/exporters/GLTFExporter.js` 的 `processImage()` 源码确认这条路径导出的 `images[]` 条目永远只有 `{ mimeType }`，从来不带 `.name` 字段——这层信息实际落在引用这张图的 `textures[].name` 上（`textureDef.name = map.name`，而 `map.name` 又是 `GLTFLoader` 加载时用 `texture.name = textureDef.name || sourceDef.name || 原始uri` 这条既有规则设置的，见 `vendor/loaders/GLTFLoader.js` ~3223 行）。改成反查第一个引用这张 image 的 `textures[]` 条目取 `.name`，取不到才退回 `image_N`。真实样品验证下来 9 张贴图全部有名字（这份 GLB 原始导出工具自己写的 `texture_source1_sampler1` 这类名字），一次都没退回过 `image_N`，但代码保留了这条 fallback，不假设"一定有名字"。扩展名来自 `mimeType`（`image/png`→`png`、`image/jpeg`→`jpg`，`image/webp` 防御性映射到 `webp` 但实测走不到——`GLTFExporter` 内部已经把 webp 材质强制转码成 png 了）。
- **重名防覆盖**：直接复用 #41 的 `dedupeExportFileName(name, usedCount)`，一行没改，`usedCount` 是这次解包生命周期内新开的 `Map`。
- **`buffers[0].uri`**：`GLTFExporter` 二进制模式下这个字段原本是省略的（隐式指向 GLB 自己唯一的 BIN chunk），分离格式没有这层隐式关系，显式补上 `base + '.bin'`。`byteLength` 字段本来就等于 BIN chunk 整体大小，不用改。
- **`bufferView` 清理策略（如实记录判断过程，这是任务描述点名要求"仔细处理"的部分）**：
  1. 每处理一张 image，把它原来指向的 `bufferView` 下标记进一个候选删除集合 `imageBufferViewIdx`。
  2. **不假设"这个 bufferView 只被这一个 image 用"**——显式反查全部 `accessors[].bufferView` + `accessors[].sparse.{indices,values}.bufferView`（还有防御性地反查一遍剩下的 `images[].bufferView`，应对理论上没转换成功的条目），建一个 `stillReferenced` 集合。只有在候选删除集合里、又不在 `stillReferenced` 里的下标才真正删除——真实样品验证：89 个 `bufferViews` 里精确有 9 个进了候选集合，这 9 个全部不在 `stillReferenced` 里（`GLTFExporter` 给每张贴图单独生成专属 `bufferView`，读 `processBufferViewImage()` 源码确认过不会跟其它数据共享），最终清理后剩 80 个，跟"89 − 9 = 80"精确对上。
  3. 删除数组条目后，同步重新编号剩余 `accessors[].bufferView`/`sparse.*.bufferView` 的下标（`remap` 一个 `Map<旧下标,新下标>`）——这一步是必须的，不重新编号会让所有排在被删条目后面的 `accessor` 全部指错。
  4. **`.bin` 文件本身不重新拼装缩小**——BIN chunk 整体原样落盘。删掉的 9 个 `bufferView` 曾经指向的那段字节仍然存在于 `.bin` 里，只是 JSON 层面已经没有任何 `bufferView`/`accessor`/`image` 指向那段范围，成了"没人读但字节还在"的死重量。**这是任务描述里明确允许的简化，不是偷懒没想到**：判断过重新拼一份最小化 buffer 需要处理"多个 bufferView 是否有重叠/共享同一段字节"这类额外的正确性风险（这次真实样品验证下来没有这种情况，但不能假设所有 glTF 都没有），权衡下来选择保留死重量、只清理 JSON 层引用——`.bin` 文件体积等于原 GLB 的 BIN chunk 整体大小，不会因为贴图被抽出去而变小，如实记录这条不是"完美最小化"方案。

**打包方式：没有选 #41 讨论过的两个方案里的任何一个，是这次任务单独做的判断**——手写了一个只支持 STORE（不压缩）的最小 ZIP 打包器（`crc32()` 标准查表法 + `buildZipBlob()`，本地文件头/中央目录/EOCD 三段定长二进制结构，UTF-8 文件名标记位处理中文文件名）。理由：

1. 这次导出的产物是「一整套包」——`.gltf` 里 `buffers[0].uri`/`images[].uri` 写死指向 `model.bin`/`images/xxx.png` 这些相对路径，必须精确对应磁盘上的文件位置，这套引用关系才成立。
2. #41 的「逐个 `<a download>`」方案对这次不够可靠：多次导出之间，Chromium 自己的"文件已存在→自动加 (1)"重命名逻辑不受 `dedupeExportFileName()` 控制，会让实际落地文件名跟 `.gltf` 里已经写死的 `uri` 对不上；`<a download="images/x.png">` 虽然 Chromium 系浏览器支持在下载目录下建子文件夹，但落地位置固定是浏览器"下载"目录，不是用户这次想要的目标目录。
3. 引入 JSZip 这类 vendor 库（#41 讨论过的另一个方案）能解决上面的问题，但会是项目第一个非 three.js 的通用工具库依赖；这次贴图数据本身已经是 PNG/JPEG 压缩格式，套一层 DEFLATE 收益很小，STORE-only 的 ZIP 格式规范本身很薄（没有压缩算法要处理，只有几个定长二进制结构体 + CRC32），手写风险可控，选了这条路——零新增 vendor 依赖，跟项目一贯的 build-free 立场同样成立，同时换来"uri 引用关系解压后 100% 精确成立"这条硬保证。

**入口**：「另存为 GLB ▾」下拉菜单新增 `#saveGlbUnpackBtn`「解包为 glTF（.gltf+.bin+贴图）」，跟「本地文件」「保存到服务器…」并列，同一套 `saveGlbMenu` 交互。下载文件名 `<原文件名>.gltf-unpacked.zip`。默认只针对 `activeModel`（跟 #41 一样读模块级 `raw`/`model`，在 #39 harvest/apply 架构下天然只处理当前激活模型）。

**验证（`_dev/test-todo42-unpack.js`，Playwright + Node 双侧校验，41 项断言全 PASS）**：真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb`（20 材质，9 贴图，含 2048×1152 大尺寸贴图）+ 边界样品 `_dev/test-empty-slot.glb`（纯几何、无贴图）。

- **结构合法性**：`asset.version==='2.0'`；`buffers[0].uri`/全部 `images[].uri` 指向解压后磁盘上真实存在的文件；`buffers[0].byteLength` 精确等于 `.bin` 实际字节数；全部 `images[]` 条目已去掉 `bufferView`/`mimeType`；全部 `accessors[].bufferView` 索引在清理后的范围内，没有产生悬空引用；`bufferViews` 数量精确从 89 降到 80（验证了上面 bufferView 清理策略的判断没有多删/少删）。
- **ZIP 格式本身的正确性**：Node 侧独立重新实现一遍 ZIP 读取（不 `require` 被测代码一行）+ 独立重新实现一遍 `crc32`，解压出的每个条目字节数据重算 CRC32 跟 ZIP 头里记录的 CRC 字段逐一比对，全部一致——证明打包器本身的字节拼装（偏移量、长度字段、CRC 字段）没写错，不是"因为浏览器还是能弹出保存对话框就假设它对了"。
- **材质编辑传播**：先走真实 UI（点材质卡片改 `#mdBaseColor` 输入框，跟 `test-mat-editor.js` 同一手法）把材质 0 改成红色，再触发解包导出，确认导出结果里同名材质的 `baseColorFactor` 是编辑后的值（`[1, 0.014, 0.014, 1]` 附近），不是原始值。
- **几何往返（两层独立校验）**：① 总量——Node 手写 `decodeAccessor()`（按 glTF 2.0 规范手算 `componentType`/`type`/`byteStride` 独立解码，不调用 three.js 也不调用被测代码）从解压出的 `.bin` 算出总顶点数/总三角形数，跟"编辑前从 three.js 实时场景读到的 ground truth"精确一致（25018 顶点、31812 三角形，两边分毫不差）；② 具体值——抽 3 个节点（`MainBooth_1/2/3`）的首个顶点坐标，Node 独立解码出来的值跟编辑前 three.js 里的值逐分量精确相等（容差 1e-4，实测完全相等，因为几何数据没有被这次编辑触碰、也没有经过任何有损转换）。
  - **踩了一个坑，如实记录**：第一版验证脚本直接 `window.__debugScene.traverse()`（`scene` 本身）统计"编辑前"的 mesh/顶点数，得到 87 个 mesh、28599 顶点，比实际模型多出一大截，抽样节点名字也变成了神秘的 `"X"`/`"Y"`/`"Z"`——排查发现 `scene` 里还混着 `TransformControls`（§19 gizmo）的内部辅助网格（轴柄用 `"X"`/`"Y"`/`"Z"` 命名），这些常驻 `scene.add()` 过、不属于模型本体的东西被一起数进去了。改成读 `window.__debugModels.model`（= `gltf.scene`，`exportGlbBlob()` 传给 `GLTFExporter.parse()` 的同一个对象）后数字精确对上——顺手在 `index.html` 的 `__debugModels` 调试钩子里补了 `model` getter（原来只有 `models`/`activeModelId`，没有直接暴露当前激活模型的 three.js 对象本身），并在 getter 旁边写了这条踩坑记录，方便以后的验证脚本别重蹈覆辙。
- **贴图往返，两层独立校验**：① **尺寸多重集合比对**——9 张贴图各自用手写 PNG/JPEG 头解析器（同 #41 方法论）独立解出尺寸，跟原始 GLB 里 9 张贴图独立解出的尺寸做多重集合比对（`{width}x{height}` 计数），完全一致（含验证能找到样品文档提到的 2048×1152 那张）。**没有按数组下标位置比对**——如实记录一个真实发现：`GLTFExporter` 内部按材质遍历顺序重新生成 `images[]` 数组，导出结果的图片顺序跟原始文件的 `images[]` 顺序**不是**同一个（实测 9 张里第 0/1 位精确互换），下标对下标比较会比错，所以改成顺序无关的多重集合比对。② **像素级比对（非通用机制，如实标注）**——这份真实样品的 `textures[].name` 恰好带 `texture_source<N>_sampler<M>` 这种编码了原始 `textures[N].source` 的命名（原始文件自己的导出工具起的名字，不是本工具生成的），借这个巧合精确配对全部 9 对「导出图片 ↔ 原始图片」，用浏览器 `createImageBitmap`+`OffscreenCanvas` 解码成像素数组比较：4 张 PNG（无损格式）平均每通道像素差异精确为 0（canvas 编解码零噪声）；5 张 JPEG（有损格式）平均每通道差异在 0～1.07 之间（有损重新编码的正常代价，不是 bug）——**这里也如实记录一个技术限制**：`GLTFExporter` 导出时贴图统一经过「解码成 canvas 再重新编码」这一步（读 `processImage()` 源码确认，`ctx.drawImage()` + `canvas.toBlob(mimeType)`），不是像 #41 那样直接切原始文件字节，所以导出的贴图文件**字节层面不会跟原始文件逐字节相同**（跟 #41「逐字节相同」的验收标准不一样）——格式保留（PNG 还是 PNG、JPEG 还是 JPEG，靠 `texture.userData.mimeType` 全程带着），像素内容对 PNG 是近似无损、对 JPEG 是可接受的有损重新压缩，这是"必须用 `exportGlbBlob()`/`GLTFExporter.parse()` 拿到反映当前编辑状态的数据"这条任务要求（第 1 条）带来的必然代价，不是这次实现挑的一个更差的路径。
- **GLTFLoader 独立往返加载**：新增独立 harness 页面 `_dev/harness-gltfload.html`（不进 `index.html`，纯 `three.js`+`GLTFLoader` 的最小页面，因为 `CHANGELOG.md` 记录过本工具本体不支持读入分离格式，这次任务范围也没有要求做"读入"）——通过本机 PHP 开发服务器把解压出的文件夹整体挂到 `http://127.0.0.1:18244/_dev/downloads-42/extracted-real/`（含中文文件名 `画稿飞扬v2.gltf`，验证过 Chromium+PHP 内置服务器的 UTF-8 URL 编解码全程正常，不是这次特意回避的风险点），`GLTFLoader.load(url,...)` 无报错完整解析完成，`mesh`/顶点/三角形统计**三方**互相一致（three.js 编辑前实时场景 ground truth / Node 手写 `decodeAccessor` / `GLTFLoader` 重新加载）。
- **边界情况：纯几何无贴图**——`_dev/test-empty-slot.glb`，确认解包结果 zip 只有 `.gltf`+`.bin` 两个条目、没有 `images/*`，导出的 `.gltf` 的 `images` 字段为空/不存在，`GLTFLoader` 同样无报错加载成功。
- 控制台全程 0 报错（主页面 + 两个独立 harness 页面）。

**调试钩子**：`window.__debugUnpackGltf = { unpackGlbToGltf, splitGlbChunks, convertGltfJsonToSeparate, buildZipBlob, crc32, exportGlbBlob }`，同既有 `__debugTexExport`/`__debugAlign` 一套暴露方式。另在 `window.__debugModels` 补了 `model` getter（见上面"踩了一个坑"部分）。

**测试脚本/文件**：`_dev/test-todo42-unpack.js`（Playwright，41 项断言，可重跑复查）、`_dev/harness-gltfload.html`（独立 GLTFLoader 往返验证 harness）；下载产物 `_dev/downloads-42/`（含解压出的 `.gltf`+`.bin`+贴图，供人工复查）；截图 `_dev/shots-42/42-00-menu-with-unpack-entry.png`（菜单新增项）。

### #43 实现记录（相机视角卷展栏，2026-08-08 完成）

**开工前先搜了一遍 `index.html`**：「相机视角」「cameraView」「视角卷展栏」全部无匹配，没有任何残留实现，从零开始。

**视角字段 `{name, position, target, fov, zoom, up}`——比口述需求原文多了一个 `up`，不是凭空发明**：去代码核对既有 4 个预设视角 `frameViewportPreset()` 是怎么设相机的，发现「顶视」预设会把 `camera.up` 从默认 `(0,1,0)` 改成 `(0,0,-1)`（跟视线方向平行会退化的既有处理，见该函数注释），`OrbitControls` 拖拽过程中不会自动纠正这个值——如果用户点了「顶视」以后又手动微调机位、这时新建一个视角，不存 `up` 的话，「应用」这条视角时相机会沿用当时环境里的 `up`（不受应用动作影响），不一定等于保存时的 `up`，不满足"精确复现"这条硬要求。`position`/`target` 对应 `camera.position`/`controls.target`（跟 `frameViewportPreset()` 一致）；`fov` 对应 `camera.fov`；`zoom` 对应 `camera.zoom`——核对过 `vendor/controls/OrbitControls.js`，本项目 `OrbitControls` 没有开 `zoomToCursor`，鼠标滚轮缩放走 `dollyIn`/`dollyOut`（改 `camera.position` 到目标的距离，不改 `.zoom`），所以 `zoom` 实测总是 `1`，但字段仍按 `camera.zoom` 完整存取，不假设用不上。存储不做 `toFixed()` 量化（§8 基点系统的 `position.toFixed(4)`/`zRotation.toFixed(2)` 是那边自己的既有设计，这次没有照抄——相机视角要求的复现精度比基点更高，量化到 4 位小数的米制误差量级正好跟验收要求的 1e-4 容差贴脸，没有必要冒这个险，直接存 `Vector3.toArray()`/`camera.fov`/`camera.zoom` 的原始浮点值）。

**每模型独立存储**：`anno.cameraViews`（数组），初始化时 `old.cameraViews || []`（没有基点系统那种"首次加载自动种一条"的规则，纯空数组起步，等用户自己点「＋新建视角」）。因为 `anno` 整体已经在 #39 的 `harvestModuleVarsInto()`/`applyContextToModuleVars()` 里按 `ModelContext` 搬运，`cameraViews` 从写下第一行代码起就自动获得多模型隔离，不需要另外接一遍隔离逻辑——这是这次任务里少数"跟着现有架构白拿正确性"的地方，验证部分详见下面。

**UI：折叠形态选了「点图标弹出小面板」，不是 `<details>` 卷展栏**——两种候选参考了任务里点名的两个既有先例：§17.3 场景 Tab 元数据卷展栏（`<details>`，嵌在右侧面板的正常文档流里，展开会把下面的兄弟内容顺流推挤）、header 三个下拉菜单（`场景 ▾`/`打开GLB ▾`/`另存为GLB ▾`，`.menu-wrap`/`.menu-btn`/`.menu-dropdown` + `registerMenu()`，展开的面板是 `position:absolute` 浮层，不影响周围元素排版）。这次入口要浮在视口左上角（`position:absolute` 的 `#viewTools` 正下方），`<details>` 展开会把它自己的高度实打实地推挤给排在它下面的兄弟元素——但这里根本没有"下面的兄弟元素"需要考虑排版联动，反而是"不能让面板展开时把视口内容渲染逻辑复杂化"，`.menu-dropdown` 那种不影响文档流的浮层完全贴合这个场景，所以选了后者，直接复用 `registerMenu()` 这套点击外部/ESC 自动关闭的基础设施，没有另外写一套开关逻辑——这是"折叠在菜单内部"字面意义上最贴近的既有组件（本来就是"菜单"）。

**跟 4 个预设视角按钮的空间关系**：新增外层包装 `#viewToolsGroup`（`position:absolute;top:12px;left:12px;display:flex;flex-direction:column;gap:8px`），把原来直接扛 `position:absolute` 的 `#viewTools` 挪进去当普通 flex 子项，新增的 `#camViewsWrap` 是它下面的第二个 flex 子项——用 flex `gap` 让两个 dock 竖排贴在一起，没有手算 `#viewTools` 4 个按钮的实际渲染高度再拿这个像素数字去定第二个 dock 的 `top` 偏移（`#viewTools` 按钮数量/尺寸以后一变，硬编码的偏移量就得跟着改，脆弱）。触发按钮 `#camViewsBtn` 视觉上没有用 header 菜单的 `.menu-btn` 样式（那是有边框的顶栏按钮语言，跟视口角落的紧凑工具条不搭），照抄 `#viewTools button` 同一套配色公式（背景 `var(--panel)`、文字 `var(--ink-dim)`，hover/`.open` 变 `var(--accent)`），只是单独一个按钮不需要 `gap:1px` 露分隔线那套，给它自己一圈 1px 边框当独立小方块。按钮文案 `📷 视角 (N) ▾` 常驻显示当前视角数量。

**四个操作**：

- **新建**（`addCameraView()`）：把 `camera.position`/`controls.target`/`camera.up`/`camera.fov`/`camera.zoom` 实时状态整份存成一条新记录。默认命名照抄 §8 `uniqueBasepointName()` 的防重名写法（`uniqueCameraViewName(base, excludeIdx=-1)`：不撞名直接用本名，撞了从 2 开始找第一个没占用的后缀），"视角"/"视角2"……
- **应用**（`applyCameraView(idx)`）：直接跳变，没有过渡动画——去代码核对过 4 个预设视角 `frameViewportPreset()` 本身就是 `camera.position.copy()`/`controls.target.copy()` 瞬间切换，没有任何 tween/动画中间态，所以这里跟着用同一套体验，没有额外发明过渡效果。应用后 `camera.updateProjectionMatrix()`（`fov`/`zoom` 改了必须调，不调的话画面不会用新视野角重新投影）+ `controls.update()`。
- **重命名**（`renameCameraView(idx, newName)`）：改 `name` 字段，同样走 `uniqueCameraViewName(trimmed, idx)`（排除自己）防重名；空字符串或跟原名相同视为无操作，只重渲染把输入框内容恢复，不产生一次空写入。
- **删除**（`deleteCameraView(idx)`）：从数组里 `splice`。

**"当前生效视角"概念——引入了，跟 `activeViewportPreset` 平行但不合并**：任务原文给了"可以不需要"的自由裁量，最终判断是引入更好——用户很可能会先点一个预设视角、手动微调、再存成书签，这种"哪个视角当前生效"的直觉在 4 个预设按钮上已经有了（`activeViewportPreset` + `.active` 高亮 + 手动拖拽后清空），相机视角列表如果完全没有对应反馈，用户点了"应用"却看不出任何列表反馈，体验上是倒退。新增 `activeCameraViewName`（模块级 `let`，不放进 `ModelContext`——纯粹是"哪一行该高亮"的展示状态，不是持久数据，跟 `activeViewportPreset` 本身也不在 `ModelContext` 里同一个归类）：

- 应用某条视角后 `activeCameraViewName` 设成那条的 `name`，同时清空 `activeViewportPreset`（应用的保存视角不属于 4 个预设中任何一个，跟点了预设按钮要清空基点/视角另一边高亮同一个"互斥"理由，双向都遵守）；点 4 个预设按钮时同理反过来清空 `activeCameraViewName`（`frameViewportPreset()` 内部已经有 `activeViewportPreset=name` 这行，这次任务没有改这个函数本身，而是在 `controls.addEventListener('start', ...)` 手动拖拽清空逻辑里顺带清了 `activeCameraViewName`——两套高亮各自在各自的"生效"函数里正向设置，在同一个"手动拖拽=脱离预设状态"事件里统一清空，没有互相調用对方的清空逻辑，保持代码路径独立不缠绕）。
- 手动拖拽视口（`controls` 的 `'start'` 事件）：跟 `activeViewportPreset` 同一个既有理由——用户动过手之后画面不再精确是应用那一刻的样子，两个高亮态一起清空。
- 删除/重命名：删除当前生效的那条，`activeCameraViewName` 清空（不留死引用）；重命名当前生效的那条，`activeCameraViewName` 跟着改名同步过去（不会因为名字对不上而无缘无故掉高亮）。
- 切换模型（`switchActiveModel()`）/ 新模型成为激活模型（`preprocess()` 的 `becomesActive` 分支）：都清空 `activeCameraViewName`——即使新模型碰巧也有同名视角，那也是没有被真正应用过的，不该被误标成生效中。

**实现过程中发现并修复的一个真实交互 bug（不是理论风险，Playwright 真实点击测出来的）**：应用/删除按钮的 `onclick` 内部都会同步调用 `renderCamViewsList()`（整段替换 `#camViewsList.innerHTML`），这会把刚被点击的那个按钮从 DOM 树上摘掉；`registerMenu()` 的"点外部关闭"判断依据是 `document` 级 `click` 监听器里的 `wrap.contains(e.target)`，这段判断在事件冒泡阶段执行——此时 `e.target` 虽然还是那个按钮的 JS 引用，但它已经被上面的 `innerHTML` 替换摘出了 DOM 树，`wrap.contains()` 对一个已经不在树里的节点返回 `false`，整次点击被误判成"点了下拉外部"，导致应用/删除一次面板就自己意外收起——真实复现方式：连续删除两条视角时，删完第一条面板自动关掉，第二条的删除按钮点不到（Playwright 报 `element is not visible` 超时）。修复：`renderCamViewsList()` 里给应用/删除两个按钮的 `onclick` 都加一行 `e.stopPropagation()`（放在处理函数最前面，`applyCameraView`/`deleteCameraView` 调用之前），让这次点击事件根本不冒泡到 `document`，从源头避免误判；这跟"应用/删除后要不要关闭面板"这个产品决定无关（这次的产品决定是"应用/删除后面板保持展开，方便连续操作/对比多条视角"，模型切换器 `renderModelSwitcher()` 那边选的是相反的产品决定——点了就调用 `modelMenu.close()` 主动关闭，两者都是"显式决定"，不是被这个 DOM detach 时序坑意外决定的）。

**多模型隔离验证**：`_dev/test-todo43-cameraviews.js`（Playwright，60 项断言全 PASS），真实样品 `C:\Users\Lin\Desktop\Glb\画稿飞扬v2.glb` 加载两次（第二次改文件名 `huagao-model-b.glb` 模拟第二个不同 GLB，参考 #39/#40 方法论）：模型 A 新建 1 条视角（"视角"）→ 追加加载模型 B（真实样品都会自动种一个默认基点，触发 #40 「对齐到已加载模型」选择器，点跳过）→ 此刻仍在模型 A，列表长度不受影响（仍是 1）→ 切到模型 B，`cameraViews` 精确为空数组（不显示 A 的记录）→ 模型 B 自己新建一条，默认命名精确是"视角"（B 自己独立计数，不受 A 已有"视角"这个名字影响，证明防重名集合是按模型各自的 `anno.cameraViews` 算的，不是全局共享）→ 切回模型 A，记录精确保留（长度仍为 1，名字未变）。

**新建/应用精度验证（容差 1e-4，实测达到浮点噪声量级）**：点「正视」预设制造一个确定的相机状态，Playwright 侧独立读取 `window.__debugViewport.camera.position/controls.target/camera.up/camera.fov/camera.zoom`（不经过被测的新建/应用函数）记录下来；点「＋新建视角」后，读 `anno.cameraViews[0]` 逐分量比对，实测差值精确为 `0`（`position`/`target`/`up`/`fov`/`zoom` 全部）；再点「顶视」预设漂移到明显不同的状态（用距离 >0.01 的检查确认真的漂移了，不是巧合没变），点「应用」后再次独立读取相机实际状态，逐分量比对回保存前记录的值，差值同样精确为 `0`（远优于 1e-4 的验收容差）。重命名（含撞名防重复防重名）、删除（含"删的正好是当前生效的"级联清空 `activeCameraViewName`）的数据正确性也在同一个脚本里逐项验证。

**折叠/展开交互验证**：默认收起（`#camViewsDropdown[hidden]`）、点击触发按钮展开（`hidden` 移除 + `.open` 高亮类）、点击外部关闭、空列表显示"暂无保存的视角"提示 + 仍能点「＋新建视角」。

**回归测试**：`_dev/test-viewport-tools.js`（既有 4 个预设视角工具条，7 组断言全 PASS，确认 `#viewToolsGroup` 包装层重构没有破坏 `#viewTools` 本身的行为/按钮数量/文案）、`_dev/test-todo39-multimodel.js`（39 项 PASS）、`_dev/test-todo38-responsive.js`（58 项 PASS，确认 `#viewToolsGroup` 新增布局层没有引入横向溢出或跟 `#panel`/`#modeTools` 的几何冲突）、`_dev/test-basepoints.js`（全 PASS，确认 `anno` 初始化对象新增 `cameraViews` 字段没有影响基点相关逻辑）、`_dev/test-todo33-gizmo.js`（54 项 PASS，确认视口右侧 `#modeTools`/gizmo 不受视口左侧新增 dock 影响）。控制台全程 0 报错（贯穿新增测试脚本 + 全部重跑的既有回归测试）。

**调试钩子**：`window.__debugCameraViews = { addCameraView, applyCameraView, renameCameraView, deleteCameraView, uniqueCameraViewName, renderCamViewsList, activeCameraViewName（getter）, cameraViews（getter，读当前 anno.cameraViews，因为 anno 会随模型切换/加载指向新对象，写成 getter 避免测试脚本读到陈旧快照——跟 __debugViewport.activeViewportPreset 同一个理由） }`，`window.__debugViewport` 沿用既有暴露方式未新增字段（`camera`/`controls` 已经在里面）。

**测试脚本/截图**：`_dev/test-todo43-cameraviews.js`（Playwright，60 项断言全 PASS，可重跑复查）；截图 `_dev/shots-43/43-00-dropdown-open-empty.png`（默认折叠展开后的空状态）、`43-01-two-views.png`（两条视角记录，UI 布局全貌）、`43-02-applied-active-row.png`（应用后行高亮 + 视口画面切换）、`43-03-model-b-isolated.png`（模型 B 独立列表）。
