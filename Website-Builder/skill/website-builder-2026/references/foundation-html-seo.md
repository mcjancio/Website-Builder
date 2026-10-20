# Foundation: HTML Head, Meta Pack, CSP & JSON-LD Schema Pack

### §1. HTML FOUNDATION (v2.0)

```html
<!DOCTYPE html>
<html lang="en" dir="ltr"> <!-- lang MUST match page language: "pl" for Polish -->
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  <!-- Native UI hints: form controls, scrollbars & browser chrome follow the theme -->
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#0B0F19" media="(prefers-color-scheme: dark)">

  <!-- ============ SECURITY: PRACTICAL CSP (v2.0) ============
       Works out-of-the-box with GA4, GTM, Cloudflare Insights, YouTube,
       Vimeo, Stripe, Calendly, HubSpot, Tidio. Tighten `https:` to an
       explicit domain allow-list once the project's third parties are known.
       NOTE: X-Frame-Options, frame-ancestors and HSTS work ONLY as HTTP
       headers (browsers IGNORE them in <meta>) — Hostilo is documented to set
       them at the platform level (not re-verified). Never put them in HTML. -->
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' data: https:; connect-src 'self' https:; frame-src https:; object-src 'none'; base-uri 'self'; form-action 'self' https:; upgrade-insecure-requests">
  <meta http-equiv="X-Content-Type-Options" content="nosniff">
  <meta name="referrer" content="strict-origin-when-cross-origin">

  <!-- ============ SEO CORE ============ -->
  <title>[50–60 chars, primary keyword first]</title>
  <meta name="description" content="[150–160 chars, benefit + CTA]">
  <link rel="canonical" href="{{SITE_URL}}/">
  <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

  <!-- ============ AI SEARCH / E-E-A-T SIGNALS (GEO/AEO) ============ -->
  <meta name="author" content="[Author / Company Name]">
  <meta name="creator" content="[Company Name]">
  <meta name="publisher" content="[Company Name]">
  <!-- Content pages (blog / article) ONLY: -->
  <meta property="article:published_time" content="2026-01-15T08:00:00+01:00">
  <meta property="article:modified_time"  content="2026-02-01T10:30:00+01:00">
  <meta property="article:author" content="[Author Name]">

  <!-- ============ OPEN GRAPH ============ -->
  <meta property="og:site_name" content="[Site Name]">
  <meta property="og:locale" content="en_US"> <!-- pl_PL for Polish sites -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="{{SITE_URL}}/">
  <meta property="og:title" content="[Title for social media]">
  <meta property="og:description" content="[Description for social media]">
  <meta property="og:image" content="{{SITE_URL}}/og-image.jpg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="[What the image shows]">

  <!-- ============ TWITTER / X ============ -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="[Title]">
  <meta name="twitter:description" content="[Description]">
  <meta name="twitter:image" content="{{SITE_URL}}/og-image.jpg">

  <!-- ============ HOSTILO DEPLOY METADATA (OPTIONAL) ============
       {{SITE_URL}} = the live URL returned by Hostilo (currently
       https://<name>.hostilo.page) or the custom domain. Replace after the
       first publish and republish. Generator tags are harmless, not required. -->
  <meta name="generator" content="Hostilo.app AI Builder">
  <meta name="hostilo-version" content="1.0">

  <!-- ============ FAVICON ============ -->
  <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
  <link rel="apple-touch-icon" href="img/apple-touch-icon.png">

  <!-- ============ FONTS — STRATEGY A (PREFERRED): SELF-HOSTED ============
       Use when deploying multi-file to Hostilo (fonts/*.woff2 alongside
       index.html; Hostilo stores them under assets/ and rewrites RELATIVE
       links in index.html — never use a leading slash. @font-face lives in
       the external css/site.css with url(../fonts/…)). Fastest FCP, no third-party dependency,
       GDPR-clean (no Google Fonts IP transfer). -->
  <link rel="preload" href="fonts/display-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="fonts/body-variable.woff2" as="font" type="font/woff2" crossorigin>
  <!-- + matching @font-face rules with font-display: swap in critical CSS (§10) -->

  <!-- ============ FONTS — STRATEGY B: GOOGLE FONTS ============
       Use ONLY in single-file mode (no asset uploads). -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=[UNIQUE_FONT]:wght@400;600;700&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=[UNIQUE_FONT]:wght@400;600;700&display=swap" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=[UNIQUE_FONT]:wght@400;600;700&display=swap"></noscript>

  <!-- ============ LCP IMAGE PRELOAD (hero only, if hero has an image) ============ -->
  <link rel="preload" as="image" href="img/hero-lg.avif"
        imagesrcset="img/hero-sm.avif 640w, img/hero-md.avif 1024w, img/hero-lg.avif 1920w"
        imagesizes="100vw" fetchpriority="high">

  <!-- ============ CRITICAL CSS (inline: @layer declaration + tokens + above-the-fold) ============ -->
  <style>
    /* See §3–§10. In single-file mode ALL CSS lives here. */
  </style>
</head>
<body>
  <!-- Skip link (WCAG 2.4.1) -->
  <a href="#main-content" class="skip-link">Skip to content</a>

  <header class="site-header">
    <nav class="nav" aria-label="Main navigation">
      <a href="/" class="nav-logo" aria-label="[Company] — home">[LOGO]</a>

      <!-- Accessible mobile toggle (v2.0): aria-expanded + aria-controls -->
      <button class="nav-toggle" type="button"
              aria-expanded="false" aria-controls="primary-menu">
        <span class="sr-only">Toggle menu</span>
        <span class="nav-toggle-bar" aria-hidden="true"></span>
      </button>

      <ul id="primary-menu" class="nav-menu">
        <li><a href="#features">Features</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="#faq">FAQ</a></li>
        <li><a href="#contact" class="btn btn-primary">Contact</a></li>
      </ul>
    </nav>
  </header>

  <main id="main-content">
    <!-- hero → features → about / social proof → faq → cta → contact -->
  </main>

  <footer>
    <!-- links, contact, copyright -->

    <!-- EU AI Act, Article 50 disclosure -->
    <div class="ai-disclosure">
      ⚠️ Parts of this content may be AI-generated. Verify before use. Compliant with EU AI Act Art. 50.
    </div>
  </footer>

  <!-- JSON-LD Schema Pack — see §2 -->
  <script type="application/ld+json">{ "...": "see §2" }</script>

  <!-- Deferred JS: external file in multi-file mode… -->
  <script defer src="js/app.js"></script>
  <!-- …or a plain inline <script> right here in single-file mode (already after the DOM). -->
</body>
</html>
```

**v2.0 notes on this foundation:**
- `dir="ltr"` added; switch to `rtl` for Arabic/Hebrew builds.
- `role="banner"` / `role="main"` / `role="contentinfo"` removed — redundant on top-level `<header>/<main>/<footer>` (implicit landmarks).
- The old `default-src 'self'`-only CSP was replaced: it instantly broke GA4, GTM, YouTube, Stripe, Calendly and Cloudflare Insights on real deployments.
- Invalid `<meta http-equiv="X-Frame-Options">` and `Permissions-Policy` metas removed — header-only directives, silently ignored in `<meta>`; the hosting platform sets them.
- Optional for multi-page sites: publish `/llms.txt` (plain-text site map for AI crawlers) next to `robots.txt` and `sitemap.xml`.

### §2. JSON-LD SCHEMA PACK (v2.0)

**Selection matrix — generate schemas automatically based on page content:**

| Page situation | Required schemas |
|---|---|
| Every page (always) | `Organization` + `WebSite` + `WebPage` (in one `@graph`) |
| Page contains an FAQ section | + `FAQPage` |
| Multi-page site / subpage | + `BreadcrumbList` |
| Blog post / article | + `BlogPosting` (author, datePublished, dateModified, image) |
| Local business (restaurant, clinic, law firm…) | `Organization` → `LocalBusiness` (+ address, geo, openingHoursSpecification) |
| E-commerce product page | + `Product` (offers, price, priceCurrency, availability) |
| Service page | + `Service` |

**Base pack (always present):**

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Organization",
      "@id": "{{SITE_URL}}/#organization",
      "name": "[Company Name]",
      "url": "{{SITE_URL}}/",
      "logo": { "@type": "ImageObject", "url": "{{SITE_URL}}/logo.png" },
      "sameAs": [
        "https://www.linkedin.com/company/example",
        "https://x.com/example"
      ],
      "contactPoint": {
        "@type": "ContactPoint",
        "telephone": "[+48 ...]",
        "contactType": "customer service",
        "availableLanguage": ["en", "pl"]
      }
    },
    {
      "@type": "WebSite",
      "@id": "{{SITE_URL}}/#website",
      "url": "{{SITE_URL}}/",
      "name": "[Site Name]",
      "publisher": { "@id": "{{SITE_URL}}/#organization" },
      "inLanguage": "en"
    },
    {
      "@type": "WebPage",
      "@id": "{{SITE_URL}}/#webpage",
      "url": "{{SITE_URL}}/",
      "name": "[Page Title]",
      "isPartOf": { "@id": "{{SITE_URL}}/#website" },
      "datePublished": "2026-01-15",
      "dateModified": "2026-02-01"
    }
  ]
}
</script>
```

**FAQPage (generate automatically whenever an FAQ section exists):**

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "[Question 1]?",
      "acceptedAnswer": { "@type": "Answer", "text": "[Answer 1]" }
    },
    {
      "@type": "Question",
      "name": "[Question 2]?",
      "acceptedAnswer": { "@type": "Answer", "text": "[Answer 2]" }
    }
  ]
}
</script>
```

> ⚠️ **Rule:** FAQ text in the schema MUST match the visible on-page FAQ verbatim (Google penalizes mismatches). FAQ sections are the single highest-leverage AEO element — AI answer engines quote them directly.

**BreadcrumbList (multi-page sites only):**

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "{{SITE_URL}}/" },
    { "@type": "ListItem", "position": 2, "name": "Blog", "item": "{{SITE_URL}}/blog/" },
    { "@type": "ListItem", "position": 3, "name": "[Current page]" }
  ]
}
</script>
```

---

