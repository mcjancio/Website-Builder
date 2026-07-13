# Hostilo.app Deployment Mode

## 🚀 §13. HOSTILO.APP DEPLOYMENT MODE (v2.0)

Every generated site must be **deployable on hostilo.app in 30 seconds**: paste (or upload) → publish → live. That imposes hard constraints — treat them as features:

**1. Output modes**
- **Single-file** (default): one `index.html`, all CSS in `<style>`, all JS in an inline `<script>` before `</body>`. Fonts via Strategy B (§1). Images via absolute `https://` URLs.
- **Multi-file** (when the user uploads assets): `index.html` + `/fonts/*.woff2` + `/img/*.{avif,webp,jpg}` + optional `styles.css` / `app.js`, relative paths. Fonts via Strategy A — preferred for performance and GDPR.

**2. Static forms** — no backend on static hosting:

```html
<form action="https://hostilo.app/api/forms/FORM_ID" method="POST">
```

- `FORM_ID` comes from the user's Hostilo dashboard; if they don't have one yet, generate the form with `action="#"` plus a clearly marked `TODO` comment and a `mailto:` fallback link.
- Always include the honeypot field (§6) and the AJAX enhancement (§11) — but the form must also work as a plain POST.

**3. Deploy metadata** (in `<head>`):

```html
<meta name="generator" content="Hostilo.app AI Builder">
<meta name="hostilo-version" content="1.0">
```

**4. Headers vs `<meta>`** — Hostilo sets HTTP-only security headers (HSTS, `X-Frame-Options`/`frame-ancestors`, `Permissions-Policy`) at platform level. The in-HTML CSP (§1) is defense-in-depth, not a replacement. Never emit header-only directives in `<meta>` — browsers ignore them.

**5. No server-side anything** — no PHP, no databases, no SSR. Dynamic behavior = client-side JS or third-party APIs (Stripe, Calendly, EasyCart, Tidio…), all of which the §1 CSP already permits.

**6. 30-second deploy ritual** (mention it to the user at handoff):
1. Copy the final HTML (or gather the file set)
2. Paste/upload at **hostilo.app**
3. Publish — live in ~30 seconds, no build, no config
4. Smoke-test the live URL on a real phone + run Lighthouse on the live URL (not localhost)

---

