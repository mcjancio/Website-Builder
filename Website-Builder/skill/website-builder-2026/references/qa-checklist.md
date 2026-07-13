# QA Checklist & Testing Tools

## ✅ §15. QA & PRE-LAUNCH CHECKLIST 2026 (v2.0)

**CRITICAL (must be ✓):**
- [ ] **Lighthouse ≥ 95** on the LIVE URL (Performance / Accessibility / SEO / Best Practices)
- [ ] **LCP < 2.5 s** — hero preloaded, `fetchpriority="high"`, AVIF, NOT lazy-loaded
- [ ] **CLS < 0.1** — `width`/`height` attributes on every `<img>` (never the `attr()` hack), `aspect-ratio` on embeds, `scrollbar-gutter: stable`
- [ ] **INP < 200 ms** — JS deferred, no long tasks, explicit transition properties (no `transition: all` on hot paths)
- [ ] **CSP present and tested** — page works with its real third parties (GA4/GTM/Stripe/YouTube…); no header-only directives in `<meta>`
- [ ] **Fonts**: max 2 families, preloaded, `font-display: swap`, subset (latin-ext for Polish); self-hosted in multi-file mode

**SEO & AI SEARCH:**
- [ ] `<link rel="canonical">` present
- [ ] `robots` meta with `max-image-preview:large`
- [ ] `author` / `creator` / `publisher` meta; `article:published_time` + `modified_time` on content pages
- [ ] Full OG set incl. `og:site_name`, `og:locale`, `og:image:alt` + Twitter card
- [ ] **Schema pack validated** in Google Rich Results Test: Organization + WebSite + WebPage, FAQPage if FAQ exists, BreadcrumbList if multi-page
- [ ] FAQ schema text matches visible FAQ verbatim
- [ ] Headings phrased as user questions where natural (AEO)
- [ ] `sitemap.xml` + `robots.txt` (+ optional `llms.txt`) for multi-page sites
- [ ] **EU AI Act Art. 50 disclosure** in footer · GDPR consent if analytics/cookies

**ACCESSIBILITY (WCAG 2.2 AA):**
- [ ] Keyboard: Tab through the whole page; **Escape closes** the mobile menu and overlays
- [ ] `aria-expanded` + `aria-controls` on every toggle, state synced by JS
- [ ] Focus visible (≥ 2px ring); focus moves with smooth-scroll anchors; focus returns to trigger on close
- [ ] Contrast ≥ 4.5:1 (normal text), ≥ 3:1 (large text/UI) — verified per theme
- [ ] Touch targets ≥ 44×44 px · all images have `alt` · all fields have `<label>`
- [ ] `prefers-reduced-motion` respected — including view transitions
- [ ] Screen reader pass (NVDA/VoiceOver); form status announced via `role="status"`
- [ ] `lang` and `dir` correct for the page language

**MODERN CSS/UX (v2.0):**
- [ ] `@layer` order declared once at the top; no specificity wars
- [ ] `color-scheme` set in meta AND per theme — native inputs match dark mode
- [ ] `theme-color` meta for both schemes
- [ ] Container queries on reusable components; media queries only for scaffolding
- [ ] View transitions degrade gracefully (feature-detected)
- [ ] `text-wrap: balance` on headings; `:target { scroll-margin-top }` under sticky header

**FORMS:**
- [ ] `autocomplete` on every relevant field (+ `inputmode`)
- [ ] Honeypot present; AJAX submit with native-POST fallback; works without JS
- [ ] Hostilo Forms `FORM_ID` configured (or TODO + `mailto:` fallback clearly marked)

**MOBILE & CROSS-BROWSER:**
- [ ] Real devices: iOS Safari + Android Chrome (not just DevTools)
- [ ] Desktop: Chrome, Firefox, Safari, Edge
- [ ] AVIF fallback chain verified (AVIF → WebP → JPG)

**HOSTILO.APP:**
- [ ] Single-file: zero external CSS/JS files, fonts via Strategy B
- [ ] Multi-file: relative paths correct, fonts via Strategy A
- [ ] `generator` + `hostilo-version` meta present
- [ ] Post-publish smoke test of the live URL on a real phone
- [ ] No `console.log`, no secrets/API keys in client code

---

## 🛠️ §16. TESTING TOOLS (RECOMMENDED STACK)

**Performance & CWV:** Lighthouse (DevTools) · PageSpeed Insights · WebPageTest · CrUX report
**Accessibility:** axe DevTools · WAVE · NVDA / VoiceOver · Color Contrast Analyzer (CCA)
**SEO & Schema:** Google Search Console · Rich Results Test · Schema.org Validator · Screaming Frog · Ahrefs Webmaster Tools (free)
**Images & Fonts:** Squoosh (AVIF/WebP) · `avifenc` · glyphhanger / fonttools (subsetting) · google-webfonts-helper (self-hosting)
**Cross-browser:** BrowserStack · LambdaTest · DevTools Device Mode
**Code quality:** W3C HTML/CSS validators · ESLint · Prettier
**Security:** Mozilla Observatory · securityheaders.com · SSL Labs

**Official references:** WCAG 2.2 quickref (w3.org/WAI/WCAG22/quickref) · WCAG 3.0 draft · EU AI Act service desk · web.dev/vitals · schema.org · MDN
**Design inspiration (anti-slop):** Awwwards · SiteInspire · Godly · Minimal Gallery · Behance

---

