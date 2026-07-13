# Website Builder 2026

> AI system prompt & skill that generates **production-ready static websites** — WCAG 2.2 AA, Core Web Vitals green, SEO + AI-Search optimized, zero "AI-slop", and deployable on [hostilo.app](https://hostilo.app) in 30 seconds.

Available in two interchangeable forms — a **Claude Skill** and a **ChatGPT Custom GPT** — both driven by the same v2.0 engineering spec. Ask for a website in plain language and get a single self-contained `index.html` you can paste straight into a static host.

---

## What it does

Turns a short brief (or a guided 10-question interview) into a complete, self-contained website that is:

- **Accessible** — WCAG 2.2 AA: keyboard nav, visible focus, ARIA-correct mobile menu, 44×44px targets, contrast-checked per theme, `prefers-reduced-motion` respected.
- **Fast** — Core Web Vitals green: LCP < 2.5s, CLS < 0.1, INP < 200ms. AVIF→WebP→JPG pipeline, hero preload + `fetchpriority`, deferred JS, self-hosted variable fonts.
- **Findable** — classic SEO **and** AI Search (GEO/AEO): canonical, robots directives, full Open Graph/Twitter, and a JSON-LD schema pack (`Organization` + `WebSite` + `WebPage`, conditional `FAQPage` / `BreadcrumbList` / `BlogPosting` / `LocalBusiness`).
- **Distinctive** — an anti-AI-slop ruleset plus a mandatory **industry → aesthetic mapping**, so a fintech site never looks like a bakery, and one "signature element" carries each design.
- **Modern** — CSS `@layer` architecture, design tokens, container queries, View Transitions API, `color-scheme`-aware light/dark, `text-wrap: balance`.
- **Deployable** — one `index.html`, no build step, static-safe forms (Hostilo Forms endpoint + honeypot + AJAX with native-POST fallback), EU AI Act Art. 50 disclosure.

---

## Repository structure

```
Website-Builder/
├── master-website-builder-prompt-2026-v2.md   # The full spec (§1–§17) — source of truth
├── website-builder-2026.skill                 # Packaged Claude Skill (installable)
├── skill/                                      # Unpacked skill source
│   └── website-builder-2026/
│       ├── SKILL.md                            # Orchestrator + hard invariants
│       └── references/
│           ├── workflow.md                     # Interactive 10-step brief
│           ├── design-directions.md            # Anti-slop rules + industry map
│           ├── foundation-html-seo.md          # <head>, CSP, meta pack, JSON-LD
│           ├── css-system.md                   # Tokens, @layer, components, CQ, motion, images
│           ├── javascript.md                   # Theme, nav, scroll, forms
│           ├── hostilo-deployment.md           # Static-host packaging & forms
│           └── qa-checklist.md                 # 60+ point pre-launch checklist
├── chatgpt/
│   └── custom-gpt-website-builder.md           # ChatGPT Custom GPT config + Instructions block
└── README.md
```

---

## Install

### Claude

**Claude.ai** — open the `website-builder-2026.skill` card and click **Save skill** (if your plan/organization allows skill creation).

**Claude Code** — the `.skill` file is a plain ZIP; unpack it into your skills directory:

```bash
unzip website-builder-2026.skill -d ~/.claude/skills/      # global
# or into .claude/skills/ inside a project repo
```

Once installed it auto-triggers on any website request ("make me a landing page…", "zrób mi stronę…", or anything mentioning hostilo.app).

### ChatGPT

Follow [`chatgpt/custom-gpt-website-builder.md`](chatgpt/custom-gpt-website-builder.md):

1. **Create a GPT → Configure**, paste the Instructions block (≈7.6k chars, fits the 8k limit).
2. Upload `master-website-builder-prompt-2026-v2.md` as **Knowledge**.
3. Enable **Canvas**; save.

The same block also works as **ChatGPT Project** instructions.

---

## Usage

**Guided:**
```
Make me a landing page for my SaaS — walk me through it step by step
```
The assistant runs a 10-question brief (type, industry, audience, goal, CTA, sections, aesthetic, colors, fonts, content), auto-selects a design direction from the industry map, then builds after you confirm.

**Fast track:**
```
Landing page for a hosting startup · goal: trial signups · CTA: "Start Free"
· dark mode · single-file for hostilo.app
```

**Audit an existing page:**
```
Audit this HTML against the 2026 checklist and fix what fails
```

---

## Highlights of the v2.0 spec

The `-v2` spec implements a full technical audit over v1.0:

- Removed the unsupported `aspect-ratio: attr()` CLS hack → explicit `width`/`height` attributes.
- Replaced the over-restrictive `default-src 'self'` CSP with a practical allow-list that works with GA4/GTM/Stripe/YouTube/Calendly out of the box.
- Added the AI-Search meta pack, the full JSON-LD schema pack (incl. `FAQPage`), AVIF, container queries, View Transitions, CSS `@layer`, `color-scheme`, self-hosted fonts, form `autocomplete`, canonical/OG/theme-color, and Hostilo-specific static forms & deploy metadata.
- Added the **mandatory industry → aesthetic mapping** so generated sites stop looking alike.

Full changelog: §17 of the spec.

---

## License

MIT — see [`LICENSE`](LICENSE). Built for the [Hostilo](https://hostilo.app) ecosystem by [IMJinteractive](https://imjinteractive.pl).
