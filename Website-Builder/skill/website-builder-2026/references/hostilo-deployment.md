# Hostilo.app Deployment Mode

## 🚀 §13. HOSTILO.APP DEPLOYMENT MODE (v2.1)

Every generated site must be **deployable on hostilo.app in 30 seconds**. Hostilo serves `https://<name>.hostilo.app` (or a custom domain) and can be driven two ways: directly through the Hostilo MCP tools, or by hand (paste/upload in the dashboard). Build the same code for both.

**1. Output modes**
- **Single-file** (default): one `index.html` (max 5 MB), all CSS in `<style>`, all JS in an inline `<script>` before `</body>`. Fonts via Strategy B (§1). Images via absolute `https://` URLs.
- **Multi-file** (when the user supplies assets): `index.html` + `css/` + `js/` + `fonts/` + `img/`.

**2. How Hostilo stores multi-file sites (critical)**
- `index.html` becomes the page at the site root.
- **Every other file goes to `assets/`, folders kept**: `css/style.css` is served at `/assets/css/style.css`.
- Hostilo rewrites links in `index.html` **only in a publish call that also contains the other files**. Tested 2026-10-17: with `index.html` + assets in one call, `href`/`src` on `<link>`, `<script>`, `<img>`, `<source srcset>` (single candidate), `url()` inside inline `<style>`, `<a href="other.html">` and even root-absolute `/x` were all prefixed with `assets/`. Write normal **relative paths with no leading `/`** (`css/style.css`, `js/app.js`, `fonts/x.woff2`, `img/hero.avif`).
- **Not rewritten:** `imagesrcset` on `<link rel=preload>` (write it with an explicit `assets/` prefix: `assets/img/hero-sm.avif 640w, …`). Not tested: multi-candidate `srcset`/`<source srcset>`, inline `style="…url()"`, `og:image` and other `<meta content>` — write those with an explicit `assets/` prefix or an absolute URL.
- **An `index.html`-only publish (`update_site`, or `publish_files` with just `index.html`) rewrites NOTHING.** `img/h.png` stays `img/h.png` and misses `assets/img/h.png`. So when editing a multi-file site, re-send the full file set in one `publish_files` call, or write the `assets/` prefix yourself. Already-prefixed paths are left alone in index-only publishes; mixed behaviour in a full publish is untested, so prefer one convention per file.
- Inside external CSS (stored as-is, not rewritten) use paths relative to the CSS file (`url(../fonts/x.woff2)`).
- Absolute URLs (canonical, `og:image`, JSON-LD) need the final address: `https://<name>.hostilo.app/…` or the custom domain. An uploaded `img/og-image.jpg` is served at `https://<site>/assets/img/og-image.jpg`.
- Extra `.html` pages also land in `assets/`, so real multi-page navigation is not a root-level structure. Default to one `index.html` with section anchors; only build subpages if the user accepts `assets/` URLs.
- `robots.txt`, `sitemap.xml`, `llms.txt` cannot be placed at the root (they would end in `assets/`). Do not generate them; say so at handoff.

**3. Platform limits** (check before publishing)
- Single `html`: ≤ 5 MB.
- Multi-file: ≤ 300 files, ≤ 10 MB per file, ≤ 20 MB total, plus the account storage limit.
- Allowed types: html, css, js, json, images (jpg, png, gif, svg, webp, avif, ico), fonts (woff, woff2, ttf, otf), txt, xml, pdf.
- **No video files** (mp4/webm…): embed YouTube/Vimeo instead.
- Subdomain: 3–30 chars, lowercase letters, digits and hyphens.

**4. Static forms** — no backend on static hosting:

```html
<form action="https://hostilo.app/api/forms/FORM_ID" method="POST">
```

- `FORM_ID` comes from the user's Hostilo dashboard; if they don't have one yet, generate the form with `action="#"` plus a clearly marked `TODO` comment and a `mailto:` fallback link.
- Always include the honeypot field (§6) and the AJAX enhancement (§11) — but the form must also work as a plain POST.
- NOTE: this endpoint format is not exposed by the Hostilo MCP tools and is unconfirmed for the new platform. Verify against Hostilo's current docs before promising it; the `mailto:` fallback is always safe.

**5. Optional metadata** — `<meta name="generator" content="Hostilo.app AI Builder">` is a harmless provenance tag; publishing does not require it.

**6. Headers vs `<meta>`** — security headers (HSTS, `X-Frame-Options`/`frame-ancestors`, `Permissions-Policy`) are HTTP-only and platform-level. The in-HTML CSP (§1) is defense-in-depth, not a replacement. Never emit header-only directives in `<meta>` — browsers ignore them. (That Hostilo sets these headers is assumed, not confirmed.)

**7. No server-side anything** — no PHP, no databases, no SSR. Dynamic behavior = client-side JS or third-party APIs (Stripe, Calendly, EasyCart, Tidio…), all permitted by the §1 CSP.

**8. Analytics** — Hostilo has built-in, cookie-less, IP-less statistics (page views, visitors; countries, sources, devices and top pages on PRO; bots excluded). Prefer them: no consent banner needed. Add GA4/GTM only if the user asks, with consent handling.

**9. Templates** — for small local businesses (café, plumber, electrician, hair salon, accountant…) Hostilo has ready-made designs (`list_templates`; `create_site` with `template`, `template_variant`, `template_fields`, `lang` incl. `pl`). Offer a template when speed matters more than a bespoke design; otherwise build from scratch.

**10. Where it runs — the `hostilo.app` connector**

Hostilo is listed in Claude's connector directory as the `hostilo.app` connector (an MCP server). With it enabled, the whole job — brief, build, publish, verify, rollback, traffic — happens inside one conversation:

- **Claude chat** (claude.ai, desktop, mobile): enable the connector in Settings → Connectors, then ask for the site.
- **Claude Code**: add the same MCP server; tools show up as `mcp__hostilo_app__*`. You can also build the files in the project folder and publish them from there.
- **ChatGPT / any client without Hostilo tools**: use path B (paste ritual) below.

Tool map: `list_sites`, `list_templates`, `create_site`, `get_site`, `update_site`, `publish_files`, `import_zip`, `list_versions`, `restore_version`, `get_site_stats`, `get_ai_traffic`.

**11. Deploy — pick the path**

*A. Hostilo tools available (preferred) — full cycle from chat:*
1. Detect the tools first (`list_sites` works = connected). If they are missing, fall to path B and say why.
2. `list_sites` — find the site to update or confirm the name is free. Small local business? Offer `list_templates` + `create_site` with `template` before building from scratch.
3. Build the files per this skill. The public address is `https://<name>.hostilo.app` — use it for canonical, `og:*` and JSON-LD.
4. `dry_run: true` (`publish_files` / `import_zip`) and read the report.
5. Publish only after the user said to ("Build!", "publish"). New site: confirm the name first (it counts toward the plan's site limit), then `create_site` with `html` (single-file) or `files` (multi-file). Existing single-file site: `get_site` (note `version`), then `update_site` with `expected_version`. Existing multi-file site: `publish_files` with `index.html` AND all other files in one call (an index-only publish skips link rewriting, see §2). Many or large binaries: build a ZIP and use `import_zip` with `zip_url` (e.g. a GitHub archive URL) or `zip_base64` instead of inlining base64 in the conversation. Never publish a page `get_site` flagged as TRUNCATED.
6. Read the `url` the tool returns. If it differs from the address you used in canonical/`og:*`/JSON-LD (tool descriptions also mention other Hostilo domains), republish the full set with the correct URLs.
7. Verify: `get_site` shows links rewritten to `assets/`; open the live URL if the environment can reach it.
8. Hand over: live URL, rollback (`list_versions` / `restore_version`, last 10 versions), and where to look at traffic.
9. Later, close the loop: `get_site_stats` (views, visitors; sources/devices/top pages on PRO) and `get_ai_traffic` (which AI crawlers read the site — ChatGPT, Claude, Perplexity and others — and in which format: HTML, Markdown, llms.txt, robots.txt; per-bot detail on PRO). Use it to judge the GEO/AEO work and iterate.

*B. No Hostilo tools (ChatGPT, plain chat) — the 30-second ritual:*
1. Copy the final HTML (or gather the file set / ZIP).
2. Paste/upload at **hostilo.app**.
3. Publish — live in ~30 seconds, no build, no config.
4. Smoke-test the live URL on a real phone + run Lighthouse on the live URL (not localhost).

After either path: open the live URL and check that fonts, CSS, JS and images loaded (a 404 on `assets/…` means a wrong path — see §2).

---
