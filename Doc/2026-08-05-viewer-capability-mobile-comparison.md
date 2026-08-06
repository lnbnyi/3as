# Babylon.js / Google model-viewer / Sketchfab：能力与手机端开销细化对比

这份笔记是 `EDITOR-SPEC.md` 第 12 节的延伸，但角度不同：第 12 节回答的是「3AS 写出去的 GLB 用了某个 glTF 扩展，各家查看器认不认」；这份回答的是「如果 3AS 以后要嵌入移动端查看/编辑模式，这三个方案本身的能力边界和手机开销分别是什么样」。三者定位差异（Babylon.js = 完整 3D 引擎、model-viewer = Google 的网页组件、Sketchfab = 模型托管分享平台）已经口头对齐过，这里不重复背景，直接进细节。

调研时间：2026-08-05。所有体积数字是当天从官方 CDN/npm 实测下载得到的，会随版本更新变化，仅供当前决策参考。

---

## 一、能力对比

| 维度 | Babylon.js | Google `<model-viewer>` | Sketchfab |
|---|---|---|---|
| 定位 | 完整开源 3D 引擎（Microsoft 赞助） | Web Component，专注"展示一个模型" | 模型托管 + 分享平台，浏览器内嵌 iframe 查看器 |
| 开源/License | 是，Apache-2.0（[GitHub](https://github.com/BabylonJS/Babylon.js/blob/master/license.md)） | 是，Apache-2.0（[GitHub](https://github.com/google/model-viewer/blob/master/LICENSE)） | 否，闭源 SaaS；服务条款里明确"软件本身归 Sketchfab 所有"（[Terms of Use](https://sketchfab.com/developers/terms)） |
| PBR 工作流 | 完整：metal-rough + specular-gloss，支持大部分 `KHR_materials_*` 扩展族 | 完整（内部依赖 three.js 的渲染管线），支持主流 PBR 扩展 | 完整，且有自己的材质编辑器（上传后可在网页上二次调整材质，不是纯只读展示） |
| 动画/骨骼蒙皮 | 完整：glTF 动画 + 完整骨骼系统 + 可编程动画混合、状态机 | 支持 glTF 动画播放（`play()`/`pause()`/`animationName` 等 API），但不暴露骨骼级别的编程控制 | 支持动画播放，编程可控性取决于其 Viewer API（用于嵌入网站的可控查看器），细粒度不如引擎级 API |
| 光照/环境贴图 | 完整：IBL、HDR 环境贴图、多光源、阴影系统 | 支持环境贴图（`environment-image`/`skybox-image`），内建几套自动曝光/色调映射 | 支持自定义 HDRI 环境，上传界面里有专门的灯光/环境编辑面板 |
| 后处理效果 | 完整管线：Bloom、SSAO、DoF、色调映射、自定义 shader pass 等 | 有限：核心库本身不带通用后处理管线，Google 另有一个附加包 `@google/model-viewer-effects` 提供少量效果（Bloom 等），不是内建的 | 后处理选项由平台控制（渲染设置在上传/编辑界面里配置），不开放给嵌入方做自定义 shader |
| AR 支持方式 | 无内建 AR 封装，需要自己接 WebXR API | 内建三选一自动降级：`webxr`（浏览器内 WebXR）→ `scene-viewer`（Android Scene Viewer app）→ `quick-look`（iOS，需要 USDZ）。iOS 分支现在支持"自动在浏览器内把 GLB 转成 USDZ"，但社区反馈这个自动转换不够可靠，尤其是带动画的模型，官方建议仍手动提供预转换好的 `ios-src`（[GitHub Discussion #2975](https://github.com/google/model-viewer/discussions/2975)、[Issue #1108](https://github.com/google/model-viewer/issues/1108)） | 支持 AR Quick Look/Scene Viewer 跳转，机制上类似 model-viewer，但由平台托管转换 |
| 集成难度 | 需要写 JS：创建 engine/scene/camera/light，再手动加载模型；灵活但代码量明显更多 | 一行 HTML 标签：`<model-viewer src="model.glb">`，零 JS 也能跑基础展示 | 一行 `<iframe>` embed 代码（从 Sketchfab 后台复制），同样零 JS；但模型必须先上传到 Sketchfab 服务器，不能直接指向自己域名下的 GLB |
| 费用/授权 | 免费、无使用限额（自建） | 免费、无使用限额（自建） | 有分层限额：免费版每月 10 次上传、单模型最大 100MB；付费版 Pro $15/月起（50 次上传/月、单模型 200MB），更高档位价格更贵（[Sketchfab Plans](https://sketchfab.com/plans)）——这是本质区别：前两者是"你自己托管、没有平台方限制"，Sketchfab 是"内容托管在对方那，受对方额度和条款约束" |

---

## 二、手机端开销：机制先厘清，再看体积

### 2.1 三者的渲染机制不是一回事

这一点必须先说清楚，否则体积对比会误导人：

- **Babylon.js**：库代码在访客手机的浏览器里跑，用手机自己的 GPU 做 WebGL/WebGPU 渲染。开销 = 你自己的 JS 库体积 + 手机本地渲染负担，两者都由你控制、也都由你担责。
- **model-viewer**：机制和 Babylon.js 本质相同——同样是把渲染代码下发到访客浏览器，用手机本地 WebGL 渲染。它的渲染层直接依赖 three.js（`package.json` 里 `three` 是一个正常的、锁定大版本号的依赖，官方文档明确建议"跟着 model-viewer 锁定的版本走"，不是维护一份独立分支）。不存在"服务器帮你渲染"这回事。
- **Sketchfab**：查证结果是 **client-side WebGL 渲染，不是服务器端流送画面**（[Sketchfab Blog: What is WebGL](https://sketchfab.com/blogs/enterprise/what-is-webgl)）——iframe 里跑的还是访客手机自己的 WebGL。区别在于：①这份查看器代码是从 `sketchfab.com` 自己的 CDN 加载的，不计入你网站自己的 bundle 体积，但访客手机总的下载量和渲染负担并不会因此减少；②模型上传后会经过 Sketchfab 自己的处理管线（格式转换、压缩），你嵌入页面时看到的不一定是原始 GLB 本身；③Sketchfab 另外有一个叫 **MASSIVE** 的高级功能，针对超大模型（当前公开资料里提到的是 OBJ/PLY 数据集，数千万面级别）做基于视角距离的渐进式几何+纹理流送、外加一个可调"Quality slider"（[Stream massive 3D models](https://sketchfab.com/blogs/community/stream-massive-models-now-with-texture-support/)）。普通规模模型的默认 embed 是否触发这套渐进流送机制，官方资料没有讲清楚阈值，不确定。

一句话总结：**三者手机端实际渲染负担的量级是同类的**（都是本地 WebGL/WebGPU），差别不在"谁帮你省了渲染"，而在"代码体积你控不控制得了"和"平台有没有额外的自动优化/流送机制"。

### 2.2 库体积实测（2026-08-05，从官方 CDN/npm 直接下载 + 本地 gzip）

| 文件 | 原始大小 | gzip 后 | 说明 |
|---|---|---|---|
| Babylon.js 完整 UMD 引擎（`cdn.babylonjs.com/babylon.js`） | 8.19 MB | **1.77 MB** | 官方 CDN 默认 `<script>` 直接引入的版本，打包了几乎全部内建功能（相机、GUI、物理占位、粒子系统等），是"最大情况" |
| Babylon.js loaders 插件（`babylonjs.loaders.min.js`，加载 GLB 必需） | 523 KB | **123 KB** | 需要和上面的引擎一起加载才能读 glTF/GLB |
| **Babylon 最简起步组合（CDN 方式）** | — | **约 1.9 MB gzip** | 引擎 + loaders 之和 |
| `@google/model-viewer`（`dist/model-viewer.min.js`） | 1.04 MB | **283 KB** | 单文件 Web Component，本身已经打包了渲染器、加载器、AR 逻辑 |
| Sketchfab 查看器 JS | 无法直接测 | — | 不是可自行下载的 npm/CDN 包，代码托管在对方服务器、版本随时可能变，且不打算自建时也没有"下载下来测体积"的必要——但它加载到访客浏览器这件事本身是确定的 |

补充说明两点，避免误读：

1. Babylon.js 上面的 1.9MB gzip 是"整包 CDN 引入"的最坏情况。社区反馈用 ES Module 按需 import + tree-shaking，实际项目能做到 **约 350-400 KB gzip**（[Babylon 论坛帖](https://forum.babylonjs.com/t/babylon-js-bundle-size/23477)），但这需要自己写模块化的导入代码，不是"引一个 script 标签"那么简单，集成成本会跟着上升。
2. model-viewer 作为单个 Web Component 文件，**没有 tree-shaking 空间**——283 KB gzip 基本就是它的下限，除非用官方在做的"实验性模块化拆分"（未在本次调研中找到已经稳定可用的公开方案）。

对比结论：**如果 3AS 走"贴一行 HTML 标签"的最省事集成路径，model-viewer（约 283KB gzip）明显比 Babylon.js CDN 直引（约 1.9MB gzip）轻；但如果愿意为 Babylon.js 投入模块化打包的工程成本，两者体积能拉近到同一量级（约 350-400KB vs 约 283KB）。**

### 2.3 官方有没有专门的移动端优化文档/选项

- **Babylon.js**：有非常具体的资料。官方论坛置顶帖列了约 38 条低端设备优化手段（[Best Practices for Optimizing Babylon.js Scenes](https://forum.babylonjs.com/t/best-practices-for-optimizing-babylon-js-scenes-not-just-on-lower-end-devices/58688)），包括：Scene Optimizer 自动降级管线（按帧率动态关阴影/后处理/降贴图分辨率）、`freezeWorldMatrix()`/冻结静态材质、KTX2/Basis/ASTC 压缩贴图、LOD 系统、thin instances 等。WebGPU 支持宣称"完整"，但社区反馈移动端 WebGPU 还在追赶阶段，有人报告过移动端 WebGPU 相对 WebGL 反而出现性能倒退（[WebGPU performance regression on mobile 帖](https://forum.babylonjs.com/t/webgpu-performance-regression-and-worker-thread-lag-on-mobile-since-v9-5-0/63456)），说明这条路线在移动端还没有完全成熟，目前更稳妥的默认仍是 WebGL。
- **model-viewer**：没有找到一份专门的"移动端渲染性能优化指南"（不代表不存在，只是没检索到）。它在移动端投入的重点明显是 **AR 体验**而不是渲染性能调优——WebXR/Scene Viewer/Quick Look 三选一自动降级机制做得很细，这是它区别于 Babylon.js 的地方（Babylon.js 没有这套封装，AR 要自己接）。
- **Sketchfab**：面向普通用户的资料里能看到的是"Quality slider"和 MASSIVE 的渐进式流送（见 2.1），偏"面向内容体积"而非"面向渲染管线调优"，没有找到类似 Babylon.js 那样细粒度的移动端渲染优化选项列表——这符合它"托管平台"的定位：优化策略是平台方在背后做的，不暴露给嵌入方调。

### 2.4 严格 benchmark：没有，如实说明

三者之间**没有找到一份公开的、严格的手机端跑分对比**（比如同一模型在同一台手机上分别用三种方案跑帧率/内存/首屏耗时的横向测试）。这类测试本身工作量大、又要兼顾三种截然不同的集成方式，检索范围内没有人系统做过。以上 2.2/2.3 的体积数字和机制描述是能查到的最接近的**代理指标**，不是性能实测——如果 3AS 后续真的要做移动端模式的技术选型，跑一次自己的最小对比 demo（同一个 GLB，三种方案各测一次真机加载时间和帧率）比继续查文献更可靠。

---

## 三、初步倾向（不是定论）

3AS 目前是**编辑器/工具定位**，不是电商展示页也不是模型托管社区，如果以后要做移动端查看/编辑模式，初步倾向是 **Babylon.js**：

- model-viewer 集成最省事、体积也更小，但它是"展示组件"而不是引擎——3AS 需要的场景图操作、材质编辑、测量标注这类"编辑"能力，model-viewer 没有对应的编程接口，勉强做也是在组件外面另起一套逻辑，架构上别扭。
- Sketchfab 直接排除——它是托管平台，模型要先传到对方服务器才能看，这和 3AS「本地/自己服务器上处理 GLB」的工作方式冲突，而且收费分层会成为额外的产品约束。
- Babylon.js 体积代价是真实存在的（CDN 直引约 1.9MB gzip），但可以通过模块化打包压到 350-400KB 量级，且它是三者里唯一一个"引擎级可编程"的选项，跟 3AS 现有的桌面端编辑逻辑（如果也用了类似渲染层）更容易共享代码。

这只是这次调研后的初步方向，不是决定——真到了要做移动端模式的时候，建议先按 2.4 说的做一次自己的最小对比 demo 再拍板。

---

## 四、追加：把 three.js 也摆进来对比

原调研只比了 Babylon.js/model-viewer/Sketchfab 三家，没纳入 3AS 自己现在就在用的 three.js——但既然是"选型参考"，漏了自己已经在用的这个反而说不通，追加如下（2026-08-05 同一天补的）：

| 维度 | three.js |
|---|---|
| 定位 | 底层渲染库，比 Babylon.js 更"轻引擎"——场景图/相机/加载器都有，但编辑器 UI、物理、状态机这类上层工具要么自己拼、要么装社区 addon |
| 开源/License | MIT（比 Babylon.js 的 Apache-2.0 更宽松） |
| 库体积 | `three.module.js` 单文件（未 tree-shake）gzip 约 **127 KB**（[unpkg 实测](https://unpkg.com/three/build/three.module.js)）——比 model-viewer（283KB）和 Babylon.js CDN 直引（1.9MB）都小，跟 Babylon.js 模块化打包后的体量（350-400KB）比也更轻 |
| WebGPU | r171 起 `three/webgpu` 生产可用，自动回退 WebGL2 |
| PBR/动画 | 完整支持，骨骼动画/环境贴图/主流 `KHR_materials_*` 扩展都有 |

**关键发现（顺带订正了本文档 2.1 节最初版本里一个不准确的说法）**：查了 `@google/model-viewer` 的 `package.json`，`three` 在里面是一个正常的、锁定大版本号的依赖（`^0.183.0`），**不是内部维护的定制分支**——2.1 节现在的措辞已经改过来了。这也是本节的核心论点：**model-viewer 本质是 three.js 的一层高级封装**，所以真正对位、值得放一起比的是 **Babylon.js vs three.js** 这两个"引擎级"选项，model-viewer 是第三条轴上"更省事但天花板更低"的选项，不是跟前两者同一层面的竞争关系。

**3AS 场景下的现实意义**：3AS 现在的 `vendor/three.module.js` 就是 three.js 0.166.1，如果以后真要做移动端查看/编辑模式，其实不用在"选型"和"已经在用"之间二选一——升级/复用现有 three.js 版本可能比引入 Babylon.js 或 model-viewer 的迁移成本更低，这点原调研的"初步倾向 Babylon.js"结论应该结合这条一起看，不是单独成立的。

**可视化对比表**（含官方 logo，Babylon.js / three.js / model-viewer / Sketchfab 四家横向对比）：已发布成 Artifact，见 <https://claude.ai/code/artifact/4b56817e-f2d7-4375-ab5d-a37629cfbda1>
