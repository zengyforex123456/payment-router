# Converge Landing Page 端到端管道 需求书 v2.0

> 修订 v2.0: 渲染层用 Latte 原子设计, 不用 Builder Block JSON

## 0. 用户画像

| 角色 | 场景 | 痛点 | 触点 |
|------|------|------|------|
| 联盟营销者 | 为 campaign 创建转化着陆页 | 文案不会写, 设计不会做, 不知道哪版好 | Landing Builder → Campaign → ABTest |
| 广告优化师 | 管理 50+ campaigns, 每个需 LP | 模板太少, 文案手动改, 无 A/B 数据 | Funnel Builder → CopyPipeline |
| SaaS 运营 | 维护产品官网 landing page | 改文案要动 PHP 数组, 无 A/B, 无转化追踪 | Landing Builder → CopyEvaluator |

## 0.5 核心使用场景

### 场景1: 快乐路径 — 从 idea 到 A/B 测试上线 (营销者, 5 分钟)

1. 营销者输入产品描述: "USDT 支付自动验证, 多链支持, 即时到账" → IntentEngine 识别意图 (R1)
2. CopyPipeline 生成 3 版文案 → 每版输出结构化 Latte 数据数组 (hero/features/cta 等) (R2)
3. Landing Builder 页面渲染 3 个变体预览 — 用同一套 `templates/landing/_*.latte` 原子模板, 不同数据 (R3)
4. 用户微调数据 → 预览即时刷新 (Latte 热编译, 无 Builder 依赖)
5. 一键部署: Latte → HTML → LpDeployer static → CampaignBridge 关联 campaign (R4)
6. ABTest 自动创建 3 路实验 → 流量分配 → Tracking 追踪转化 (R5)
7. 7 天后 Bayesian 判定胜者 → 自动全量切换 → 通知用户 (R6)

### 场景2: 异常路径 — LLM 不可用, 用户手动模式

1. CopyPipeline 超时 → 降级: 用预置数据模板 (PAS/AIDA/BAB 三套默认数据) 填充用户关键词 (R8)
2. 用户直接编辑 Latte 数据表单 (key-value 编辑器, 无需懂 HTML)
3. 预览仍通过 Latte 模板渲染
4. 手动保存 → 部署 → 追踪正常

## 1. 问题陈述

**当前状态**: Converge 有完整的 Latte 原子设计模板系统 (`templates/landing/_*.latte` 14 个文件) 和成熟的 CopyPipeline (S1-S5 LLM 管道), 但两套系统互不通信。

**核心问题**: 用户创建 landing page 需要手动写 PHP 数据数组 (如 `landing.php` 的 `$hero`, `$feat`, `$cmp`), 不懂代码的人无法操作。CopyPipeline 产出的 Markdown 文案无法自动填入 Latte 模板变量。

**影响范围**: `landing.php` 已证明 Latte 原子设计的威力——同一套 `_*.latte` 模板, 换数据就换页面。但数据仍需开发者手写。竞品 (Unbounce, ClickFunnels) 已提供 AI 生成+可视化编辑一体化。

## 2. 方案对比

| 维度 | 方案A: CopyPipeline→Latte 桥接 (推荐) | 方案B: CopyPipeline→Builder Block JSON | 方案C: 只修 Funnel Builder |
|------|------|------|------|
| 渲染层 | Latte 原子模板 (已有 14 个) | Builder Block 类 (33 个 PHP 类) | TemplateEngine `{{var}}` 替换 |
| 原子设计 | ✅ `_hero.latte` `_feature-grid.latte` 等天然原子 | ⚠️ Block 类是组件, 但不是 Latte | ❌ raw HTML, 无原子概念 |
| 数据格式 | PHP 关联数组 (与 `landing.php` 一致) | Block JSON `[{type, config}]` | SQL 模板 + `{{var}}` 占位 |
| CopyPipeline 输出→渲染 | 直接映射到数组字段 | 需 LLM 映射 JSON schema | 需 `{{var}}` 字符串替换 |
| 复用现有 | CopyPipeline + LatteEngine + LpDeployer | CopyPipeline + Builder + PageRenderer | TemplateEngine + LpDeployer |
| 改动量 | 新建 2 模块 + 1 页面 | 新建 3 模块 + 1 页面 | 改现有 funnel-builder-v2.php |
| 设计令牌 | ✅ `var(--color-*)` 已内置于 `_*.latte` | ✅ Block 类使用令牌 | ❌ 15 个 SQL 模板硬编码颜色 |
| 风险 | 低, 不改旧模块 | 中, 两套渲染系统并存 | 高, 破坏现有 Funnel Builder |

**推荐**: 方案A — CopyPipeline→Latte 桥接。理由:
1. `landing.php` 已证明 Latte 原子设计可行 — 数据驱动, 模板复用
2. 不改任何旧模块, 新功能=新文件挂 EventBus
3. 避免两套渲染系统 (Builder Blocks vs Latte partials) 并存
4. CopyPipeline 输出直接映射到 Latte 数据数组, 不需要中间 JSON 格式

## 3. 架构设计

### Latte 原子设计体系

```
L5 页面 (Pages)
  templates/pages/landing.latte          ← 编排 $blockOrder + $visibleBlocks
  templates/pages/lp-variant.latte       ← 新: 通用 LP 变体页面模板
    │
    │ {include}
    ▼
L4 有机体 (Organisms) — templates/landing/_*.latte
  _hero.latte      _trust-bar.latte    _how-it-works.latte
  _feature-grid.latte  _comparison.latte   _social-proof.latte
  _pricing.latte   _faq.lattice       _final-cta.latte
  _nav.latte       _footer.latte       _register-modal.latte
    │
    │ 内嵌
    ▼
L3 分子 (Molecules) — app/UI/Molecules/*.php
  StatCard::render()  DataTable::render()  EmptyState::render()
    │
    │ 组合
    ▼
L2 原子 (Atoms) — app/UI/*.php
  Badge::render()  Button::render()  Input::render()  Spinner::render()
    │
    │ 引用
    ▼
L1 令牌 (Tokens) — design-tokens.json → tokens.css
  var(--color-primary)  var(--space-md)  var(--radius-lg)
```

**关键**: 每个 `_*.latte` 文件是一个有机体 (Organism), 接收 PHP 数据数组, 输出 HTML 片段。换数据 = 换页面, 不改模板。

### 数据流

```
用户输入产品描述 (自然语言)
  │
  ▼
CopyPipeline::convert($description)
  │ S1→S5 LLM 管道
  ▼
结构化文案数据 (PHP 数组, 与 landing.php 格式一致)
  {
    hero:  {badge, title, subtitle, cta_text, cta_url, cta_sub},
    trust:  {items: [{value, label}, ...]},
    how:    {badge, title, steps: [{title, desc}, ...]},
    feat:   {badge, title, subtitle, features: [{icon, title, desc}, ...]},
    cmp:    {badge, title, competitors, rows: [...]},
    pricing:{...},
    faq:    {items: [{q, a}, ...]},
    cta:    {title, subtitle, button, url},
    blockOrder: ['hero','trust','how','features','comparison','pricing','faq','cta']
  }
  │
  ▼
LatteEngine::display('pages/lp-variant', $data)
  │ 同一套 _*.latte 原子模板, 不同数据
  ▼
HTML → LpDeployer::deploy($lpId, $html, $slug)
  │
  ▼
/public/lp/{slug}/index.html  ← Nginx 直接服务, 零 PHP
```

### 新建模块 (2)

| 模块 | 文件 | 职责 | 一句话 |
|------|------|------|------|
| **CopyToLanding** | `modules/CopyToLanding/` 六边形模块 | CopyPipeline Markdown → Latte 数据数组映射 | CopyPipeline 输出转译为 Latte 模板变量 |
| **LandingAB** | `modules/LandingAB/` 六边形模块 | LP A/B 实验创建 + 流量分配 + Bayesian 自动选优 | 部署 LP 时自动创建 N 路实验 |

### 新建页面 (1)

| 文件 | 职责 |
|------|------|
| `public/landing-builder.php` | 统一入口: 输入描述→AI生成文案→预览→编辑→部署→A/B |

### 新建 Latte 模板 (1)

| 文件 | 职责 |
|------|------|
| `templates/pages/lp-variant.latte` | 通用 LP 变体页面模板 — 接收数据数组, 通过 `$blockOrder` 驱动 section 渲染 |

### Landing Builder 页面 — 组件化 UI 设计

Builder 页面本身遵循 Latte 原子设计。每层只用下层组件, 不跨层。

```
┌── L4 Page: landing-builder.php ────────────────────────┐
│  PHP 控制器: 编排 AI生成·预览·编辑·部署 四个流程          │
│  通过 LatteEngine::display('pages/landing-builder') 渲染 │
└────────────────────────────────────────────────────────┘
         │ props ↓              ↑ Stimulus events
┌── L3 Organisms: templates/_builder/*.latte ────────────┐
│  _ai-panel.latte       AI 输入面板 + 生成按钮 + 结果列表  │
│  _variant-tabs.latte   变体 A/B/C 切换标签               │
│  _section-list.latte   可拖拽排序的 section 列表          │
│  _section-editor.latte 单个 section 的数据字段编辑表单     │
│  _live-preview.latte   实时预览 iframe                    │
│  _deploy-panel.latte   部署面板( slug / 部署 / 状态 )     │
└────────────────────────────────────────────────────────┘
         │ 内嵌 PHP 组件  ↓
┌── L2 Molecules: app/UI/Molecules/ ─────────────────────┐
│  SectionCard::render()    可拖拽 section 缩略卡片         │
│  DataField::render()      动态 key-value 字段编辑器       │
│  VariantTab::render()     变体标签(含状态 Badge)          │
│  PipelineProgress::render()  CopyPipeline 步骤进度条      │
└────────────────────────────────────────────────────────┘
         │ 组合 ↓
┌── L1 Atoms: app/UI/ (已有, 不改) ──────────────────────┐
│  Badge::render()  Button::render()  Input::render()      │
│  Spinner::render()  Grid::render()  Card::render()       │
│  Heading::render()  Alert::render()  EmptyState::render()│
└────────────────────────────────────────────────────────┘
```

#### L3 有机体详细设计

**`_ai-panel.latte`** — AI 文案生成面板
```latte
{* 输入区 + 生成按钮 + 3 版结果卡片 *}
<section class="col-span-full">
  {=Heading::render('AI 文案生成', ['level' => 2])|noescape}

  <div class="ai-input-row">
    {=Input::render('productDesc', 'textarea', ['placeholder' => '描述你的产品...', 'rows' => 3])|noescape}
    {=Button::render('✨ 生成 3 版文案', ['variant' => 'primary', 'id' => 'btn-generate'])|noescape}
  </div>

  {=PipelineProgress::render($pipelineState)|noescape}

  <div class="variant-results grid cols-3 gap-md">
    {foreach $variants as $v}
      {=SectionCard::render($v['label'], $v['sections'], $v['active'])|noescape}
    {/foreach}
  </div>
</section>
```

**`_section-list.latte`** — 可拖拽 Section 排序
```latte
{* blockOrder 可视化编辑器 — 拖拽调整 section 顺序, 勾选显隐 *}
<section>
  {=Heading::render('页面结构', ['level' => 3])|noescape}
  <div x-data="sectionSorter({blockOrder: {$blockOrder|json_encode}, visible: {$visibleBlocks|json_encode}})"
       class="section-order-list">
    <template x-for="(section, i) in sections" :key="section.key">
      <div class="section-order-item"
           :class="{opacity50: !section.visible}"
           x-sortable-item
           draggable="true">
        <span class="drag-handle">⠿</span>
        <span x-text="section.label"></span>
        <input type="checkbox" x-model="section.visible" @change="updatePreview()">
      </div>
    </template>
  </div>
</section>
```

**`_section-editor.latte`** — 单 Section 数据编辑器
```latte
{* 选中 section → 显示其数据字段 → 编辑 → 即时刷新预览 *}
<section>
  {=Heading::render('编辑: ' . $activeSection['label'], ['level' => 3])|noescape}
  <form x-data="sectionEditor({$sectionData|json_encode})"
        @input.debounce.500ms="updatePreview()">
    {foreach $activeSection['fields'] as $field}
      {=DataField::render($field['key'], $field['value'], $field['type'] ?? 'text')|noescape}
    {/foreach}
  </form>
</section>
```

**`_live-preview.latte`** — 实时预览 iframe
```latte
{* 右侧: LP 变体实时预览 + 响应式断点切换 *}
<section>
  <div class="preview-toolbar">
    {=Button::render('📱', ['variant' => 'ghost', 'size' => 'sm'])|noescape}
    {=Button::render('💻', ['variant' => 'ghost', 'size' => 'sm'])|noescape}
  </div>
  <iframe srcdoc="{$previewHtml|escape}" class="lp-preview-iframe"></iframe>
</section>
```

**`_deploy-panel.latte`** — 部署面板
```latte
{* 底部: slug 输入 + 部署按钮 + 状态 + A/B 实验配置 *}
<section>
  {=Input::render('slug', 'text', ['placeholder' => 'my-landing-page'])|noescape}
  {=Button::render('🚀 部署上线', ['variant' => 'primary', 'id' => 'btn-deploy'])|noescape}
  {if $deployStatus}
    {=Badge::render($deployStatus, ['variant' => $deployOk ? 'success' : 'danger'])|noescape}
  {/if}
  {if $abEnabled}
    {=Badge::render('A/B 实验已创建', ['variant' => 'info'])|noescape}
  {/if}
</section>
```

#### L2 分子 (新建 PHP 组件)

| 组件 | 文件 | 签名 | 职责 |
|------|------|------|------|
| `SectionCard` | `app/UI/Molecules/SectionCard.php` | `SectionCard::render(string $label, array $sections, bool $active): string` | 变体缩略卡片 — 显示 hero/features/cta 数量 |
| `DataField` | `app/UI/Molecules/DataField.php` | `DataField::render(string $key, mixed $value, string $type): string` | 动态字段编辑器 — text/textarea/richtext 三种类型 |
| `VariantTab` | `app/UI/Molecules/VariantTab.php` | `VariantTab::render(string $label, bool $active, ?string $badge): string` | 变体标签 — 含状态 Badge (生成中/A/B 测试中) |
| `PipelineProgress` | `app/UI/Molecules/PipelineProgress.php` | `PipelineProgress::render(array $state): string` | CopyPipeline 进度 — S1→S5 步骤条 + 当前步骤高亮 |

#### 页面布局 (6 列网格)

```
┌──────────────────────────────────────────────────┐
│  L3: _ai-panel.latte (col-span-6)                │
│  [输入框] [生成按钮] [S1→S2→S3→S4→S5 进度条]      │
├──────────────────────────────────────────────────┤
│  L3: _variant-tabs.latte (col-span-6)             │
│  [变体A ✅] [变体B] [变体C]                        │
├───────────────────────┬──────────────────────────┤
│ 左列 (col-span-2)     │ 右列 (col-span-4)         │
│                       │                          │
│ L3: _section-list     │ L3: _live-preview         │
│  ☑ Hero               │ ┌────────────────────┐   │
│  ☑ Trust Bar          │ │  iframe 实时预览    │   │
│  ☑ How It Works       │ │                    │   │
│  ☐ Features           │ │  同一套 _*.latte   │   │
│  ☑ Comparison         │ │  不同数据          │   │
│  ☑ Pricing            │ │                    │   │
│  ☐ FAQ                │ └────────────────────┘   │
│  ☑ Final CTA          │                          │
│                       │ 响应式切换: 📱 💻 🖥      │
├───────────────────────┴──────────────────────────┤
│ L3: _section-editor.latte (col-span-2)           │
│  编辑: Hero                                      │
│  badge: [Every Sale Feels Like a Win]            │
│  title: [Until They Refund...]                   │
│  subtitle: [The platform doesn't know...]        │
│  cta_text: [Plug the Leak in 3 Minutes]          │
│  cta_url: [register.php]                         │
├──────────────────────────────────────────────────┤
│ L3: _deploy-panel.latte (col-span-6)             │
│  slug: [my-product-lp] [🚀 部署上线] [✅ 已发布]    │
│  ☑ 创建 A/B 实验 (3 变体均分流量)                  │
└──────────────────────────────────────────────────┘
```

#### Stimulus Controller (3 个，已从 Alpine 迁移至 Stimulus/TDA)

> 注: 本 PRD 原始版本使用 Alpine.js。当前架构已迁移至 Stimulus + TDA 三层模型。
> 详见 `.claude/reference/tda-architecture.md` 和 `.claude/reference/page-pipeline.md`

```js
// public/build/js/controllers/lp_builder_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["descInput", "variantList", "preview"];
    static values = { loading: Boolean, error: String, variants: Array, activeVariant: Number };

    connect() { this.activeVariantValue = 0; }

    async generate() {
        this.loadingValue = true; this.errorValue = null;
        try {
            const r = await fetch('/api/copy/generate-landing', {
                method: 'POST', body: JSON.stringify({desc: this.descInputTarget.value})
            });
            const data = await r.json();
            this.variantsValue = data.variants;
            this.selectVariant(0);
        } catch (e) { this.errorValue = e.message; }
        finally { this.loadingValue = false; }
    }

    selectVariant({ params: { index } }) {
        this.activeVariantValue = index;
        this.updatePreview();
    }

    async updatePreview() {
        const v = this.variantsValue[this.activeVariantValue];
        const r = await fetch('/api/lp/preview', {
            method: 'POST', body: JSON.stringify({data: v.data, blockOrder: v.blockOrder})
        });
        this.previewTarget.innerHTML = await r.text();
    }

    async deploy() {
        const r = await fetch('/api/lp/deploy', {
            method: 'POST', body: JSON.stringify({
                slug: this.$refs.slugInput.value,
                variants: this.variants.map(v => ({data: v.data, blockOrder: v.blockOrder}))
            })
        });
        const res = await r.json();
        this.deployStatus = res.ok ? '已发布' : '部署失败';
    },

    async retry() { this.loading = true; this.error = null; await this.generate(); }
}));
```

### 通信接口 (EventBus)

```
EventBus::subscribe(LandingPageDeployed::class, → LandingAB::autoCreateExperiment())
EventBus::subscribe(ABTestWinnerDeclared::class,  → Campaign::autoSwitchLP())
EventBus::subscribe(CopyGenerated::class,         → CopyToLanding::mapToLatteData())
```

### 关键决策 (4 条)

1. **渲染用 Latte, 不用 Builder** — `landing.php` + 14 个 `_*.latte` 原子模板已经证明可行, 避免引入第二套渲染系统
2. **CopyPipeline 输出结构化数据, 不是 Markdown** — S5 之后加一步 S6: Markdown→Latte 数据数组映射, 用 LLM 一步完成
3. **不改旧模块** — CopyPipeline、LatteEngine、LpDeployer、ABTest 全部零修改, 新功能=新文件挂 EventBus
4. **降级优先** — CopyPipeline 失败→预置数据模板; Latte 编译失败→纯文本回退

## 4. 演进路线图

| Phase | 交付物 | 状态 | 验收日期 |
|------|------|:---:|------|
| P1: CopyToLanding | S6 映射步骤 + 3 套预置数据模板 (PAS/AIDA/BAB) | ✅ 完成 | 2026-07-19 |
| P2: Landing Builder | `landing-builder.php` + `lp-variant.latte` | ✅ 完成 | 2026-07-19 |
| P3: LandingAB | LP A/B 实验 + Bayesian 自动选优 + 全量切换 | ✅ 完成 | 2026-07-20 |
| P4: 闭环优化 | AB 结果回写 CopyEvaluator 校准 Pearson r | ⬜ 待实施 | — |

## 5. 需求清单

| ID | 功能 | 描述≤30字 | 优先级 | 验收 | 实现文件 |
|------|------|------|:---:|------|------|
| R1 | AI 文案生成 | 用户输入产品描述 → CopyPipeline 生成结构化数据 | P0 | ✅ | `CopyToLanding/Application/GenerateLandingDataUseCase.php` |
| R2 | S6 文案→Latte 数据映射 | CopyPipeline Markdown 输出转为 Latte 模板变量数组 | P0 | ✅ | `CopyToLanding/Application/MapCopyToLatteDataUseCase.php` |
| R3 | 预置数据模板 | PAS/AIDA/BAB 三套默认数据, LLM 不可用时降级 | P0 | ✅ | `CopyToLanding/Infrastructure/PresetDataTemplates.php` |
| R4 | Landing Builder 页面 | 输入→生成→预览→编辑→部署 一站式 | P0 | ✅ | `public/landing-builder.php` |
| R5 | 通用 LP Latte 模板 | `lp-variant.latte` — 数据驱动的 section 编排 | P0 | ✅ | `templates/pages/lp-variant.latte` |
| R6 | 一键部署为 LP | Latte→HTML→LpDeployer static→CampaignBridge | P0 | ✅ | `CopyToLanding/Application/DeployLandingUseCase.php` |
| R7 | LP A/B 实验 | 部署时自动创建 N 路 A/B 实验 | P1 | ✅ | `LandingAB/Application/CreateLPExperimentUseCase.php` |
| R8 | 自动选优胜者 | Bayesian 显著→自动全量切换→通知 | P1 | ✅ | `LandingAB/Application/AutoDeclareWinnerUseCase.php` |
| R9 | 降级容错 | LLM 失败→预置模板; 每步有 fallback | P0 | ✅ | 各 UseCase catch 块 |
| R10 | 数据编辑器 | 非技术人员可编辑 Latte 数据数组的 key-value 表单 | P1 | ✅ | `public/assets/js/components/lp-builder.js` |

## 6. 审计修正

| 版本 | 修正项 | 原方案 | 修正后 | 审计人 | 日期 |
|------|------|------|------|------|------|
| v1.0 | 初版 | Builder Block JSON 桥接 | — | Claude | 2026-07-19 |
| v2.0 | 渲染层改为 Latte 原子设计 | Builder Block 类渲染 | Latte `_*.latte` 原子模板 + 数据数组 | Claude | 2026-07-19 |
| v2.1 | 全量审计: R1-R10 全部实现验收通过 | 需求vs实际代码逐项比对 | 10/10 完成, P1-P3 已交付 | Claude | 2026-07-20 |

## 7. 增强缺口

| 缺口 | 优先级 | 阻塞原因 | 计划版本 |
|------|:---:|------|------|
| 可视化 Latte 数据编辑器 | P2 | 需动态表单生成 + 实时 Latte 编译 | v2.2 |
| LP 模板市场 | P2 | 需用户体系+付费 | v2.3 |
| 自定义域名 LP | P2 | 需 DNS/SSL 基础设施 | v2.3 |
| 多语言 LP 自动翻译 | P3 | 需 I18n 管道扩展 | v2.4 |
| LP 热图/录屏 | P3 | 需前端 SDK | v3.0 |
