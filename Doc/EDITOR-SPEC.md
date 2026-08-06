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
