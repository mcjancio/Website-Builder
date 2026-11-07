# CSS System: Tokens, @layer, Components, Container Queries, Motion, Performance

**Contents:** §3 tokens + `@layer` · §4 reset/base/a11y/CLS · §5 typography · §6 components (buttons, cards, nav, forms + autocomplete) · §7 layout + container queries · §8 responsive · §9 View Transitions · §10 performance (AVIF, fonts, preload)

### §3. CSS ARCHITECTURE — `@layer` + DESIGN TOKENS (v2.0)

**Cascade layers are the v2.0 backbone.** Declare the order ONCE at the very top of the stylesheet — later layers always win, regardless of selector specificity. This kills the specificity wars that plague generated CSS (`.section` vs `.cta` cancelling each other's paddings).

```css
/* FIRST LINE OF CSS — layer order = priority order (later wins) */
@layer reset, tokens, base, components, utilities;
```

```css
/* ============================================
   @layer tokens — DESIGN TOKEN SYSTEM
   ============================================ */
@layer tokens {
  :root {
    /* Native form controls, scrollbars & UA widgets follow the theme */
    color-scheme: light dark;

    /* ── SPACING ── */
    --space-xs: 0.25rem;   /* 4px */
    --space-sm: 0.5rem;    /* 8px */
    --space-md: 1rem;      /* 16px */
    --space-lg: 1.5rem;    /* 24px */
    --space-xl: 2rem;      /* 32px */
    --space-2xl: 3rem;     /* 48px */
    --space-3xl: 4rem;     /* 64px */
    --space-4xl: 6rem;     /* 96px */

    /* ── TYPOGRAPHY ── */
    --font-display: [UNIQUE_DISPLAY_FONT], serif;
    --font-body: [UNIQUE_BODY_FONT], sans-serif;
    --font-mono: [UNIQUE_MONO_FONT], 'Courier New', monospace;

    --text-xs: 0.75rem;
    --text-sm: 0.875rem;
    --text-base: 1rem;
    --text-lg: 1.125rem;
    --text-xl: 1.25rem;
    --text-2xl: 1.5rem;
    --text-3xl: 1.875rem;
    --text-4xl: 2.25rem;
    --text-5xl: 3rem;
    --text-6xl: 3.75rem;

    --line-height-tight: 1.2;
    --line-height-base: 1.6;
    --line-height-relaxed: 1.8;

    --letter-spacing-tight: -0.025em;
    --letter-spacing-normal: 0;
    --letter-spacing-wide: 0.025em;

    /* ── RADIUS & BORDERS ── */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 1rem;
    --radius-xl: 1.5rem;
    --radius-2xl: 2rem;
    --radius-full: 9999px;

    --border-width: 1px;
    --border-width-md: 2px;
    --border-width-lg: 4px;

    /* ── SHADOWS ── */
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);

    /* ── MOTION ── */
    --duration-fast: 150ms;
    --duration-base: 200ms;
    --duration-slow: 300ms;
    --duration-slower: 500ms;

    --ease-in: cubic-bezier(0.4, 0, 1, 1);
    --ease-out: cubic-bezier(0, 0, 0.2, 1);
    --ease-in-out: cubic-bezier(0.4, 0, 0.2, 1);
    --ease-bounce: cubic-bezier(0.68, -0.55, 0.265, 1.55);

    --transition-fast: var(--duration-fast) var(--ease-in-out);
    --transition-base: var(--duration-base) var(--ease-in-out);
    --transition-slow: var(--duration-slow) var(--ease-in-out);

    /* ── Z-INDEX SCALE ── */
    --z-base: 1;
    --z-dropdown: 100;
    --z-sticky: 200;
    --z-fixed: 300;
    --z-modal: 400;
    --z-toast: 500;
    --z-tooltip: 600;

    /* ── LAYOUT ── */
    --container-sm: 640px;
    --container-md: 768px;
    --container-lg: 1024px;
    --container-xl: 1280px;
    --container-2xl: 1536px;
    --header-height: 72px;

    /* ── BREAKPOINTS (JS-readable only!) ──
       CSS media queries CANNOT consume custom properties.
       These exist so JS can read them via getComputedStyle(). */
    --breakpoint-sm: 640px;
    --breakpoint-md: 768px;
    --breakpoint-lg: 1024px;
    --breakpoint-xl: 1280px;

    /* ── FOCUS ── */
    --focus-ring-width: 2px;
    --focus-ring-offset: 2px;
  }

  /* ── LIGHT THEME (default) ── */
  [data-theme="light"] {
    color-scheme: light; /* v2.0: native inputs render light */

    --color-primary: [UNIQUE_PRIMARY];
    --color-primary-hover: [DARKER];
    --color-primary-light: [LIGHTER];

    --color-bg-base: #FFFFFF;
    --color-bg-secondary: #F9FAFB;
    --color-bg-tertiary: #F3F4F6;
    --color-bg-accent: [PRIMARY_TINT];

    --color-text-primary: #111827;
    --color-text-secondary: #4B5563;
    --color-text-tertiary: #6B7280;
    --color-text-muted: #9CA3AF;
    --color-text-inverse: #FFFFFF;

    --color-border-light: #F3F4F6;
    --color-border-base: #E5E7EB;
    --color-border-strong: #D1D5DB;

    --color-success: #10B981;
    --color-warning: #F59E0B;
    --color-error: #DC2626;   /* darker than #EF4444 → passes 4.5:1 on white */
    --color-info: #2563EB;

    --color-focus: var(--color-primary);
    --color-focus-ring: color-mix(in srgb, var(--color-primary) 25%, transparent);
  }

  /* ── DARK THEME ── */
  [data-theme="dark"] {
    color-scheme: dark; /* v2.0: native inputs render dark */

    --color-primary: [ADJUSTED_FOR_DARK];
    --color-primary-hover: [LIGHTER_IN_DARK];
    --color-primary-light: [DARKER_IN_DARK];

    --color-bg-base: #0B0F19;
    --color-bg-secondary: #111827;
    --color-bg-tertiary: #1F2937;
    --color-bg-accent: [PRIMARY_SHADE];

    --color-text-primary: #F9FAFB;
    --color-text-secondary: #D1D5DB;
    --color-text-tertiary: #9CA3AF;
    --color-text-muted: #6B7280;
    --color-text-inverse: #111827;

    --color-border-light: #1F2937;
    --color-border-base: #374151;
    --color-border-strong: #4B5563;

    --color-success: #34D399;
    --color-warning: #FBBF24;
    --color-error: #F87171;
    --color-info: #60A5FA;

    --color-focus: var(--color-primary);
    --color-focus-ring: color-mix(in srgb, var(--color-primary) 35%, transparent);

    /* Deeper shadows in dark mode */
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
    --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
  }
}
```

### §4. RESET, BASE & ACCESSIBILITY UTILITIES (v2.0)

```css
/* ============================================
   @layer reset
   ============================================ */
@layer reset {
  *, *::before, *::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  img, picture, video, iframe, canvas, svg {
    display: block;
    max-width: 100%;
    height: auto;
  }

  input, button, textarea, select {
    font: inherit;
  }
}

/* ============================================
   @layer base
   ============================================ */
@layer base {
  html {
    scroll-behavior: smooth;
    scrollbar-gutter: stable;            /* v2.0: no layout jump when scrollbar appears */
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  body {
    font-family: var(--font-body);
    font-size: var(--text-base);
    line-height: var(--line-height-base);
    color: var(--color-text-primary);
    background-color: var(--color-bg-base);
    transition: background-color var(--transition-base), color var(--transition-base);
  }

  /* v2.0: anchor targets never hide under the sticky header */
  :target {
    scroll-margin-top: calc(var(--header-height) + var(--space-lg));
  }

  /* ── ACCESSIBILITY UTILITIES ── */
  .skip-link {
    position: absolute;
    top: -999px;
    left: -999px;
    z-index: var(--z-tooltip);
  }
  .skip-link:focus {
    top: var(--space-md);
    left: var(--space-md);
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-base);
    color: var(--color-text-primary);
    border: var(--border-width-md) solid var(--color-primary);
    border-radius: var(--radius-md);
    text-decoration: none;
    font-weight: 600;
  }

  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }

  /* Focus ring for keyboard users only */
  :focus-visible {
    outline: var(--focus-ring-width) solid var(--color-focus);
    outline-offset: var(--focus-ring-offset);
  }
  :focus:not(:focus-visible) {
    outline: none;
  }
}
```

**CLS PREVENTION — corrected in v2.0:**

```css
@layer base {
  /* RULE 1: ALWAYS write width="" and height="" ATTRIBUTES on every <img>.
     The browser derives the aspect-ratio automatically — zero CSS needed.

     RULE 2 (v2.0 fix): NEVER use `aspect-ratio: attr(width) / attr(height)`.
     attr() inside aspect-ratio is NOT supported cross-browser — the v1.0
     rule silently did nothing. Removed.

     RULE 3: Reserve space for embeds & dynamic slots with explicit ratios: */
  .embed-16x9 { aspect-ratio: 16 / 9; width: 100%; }
  .embed-4x3  { aspect-ratio: 4 / 3;  width: 100%; }
  .embed-1x1  { aspect-ratio: 1;      width: 100%; }

  /* Skeleton for late-arriving content */
  .skeleton-loader {
    min-height: 200px;
    border-radius: var(--radius-md);
    background: linear-gradient(90deg,
      var(--color-bg-secondary) 0%,
      var(--color-bg-tertiary) 50%,
      var(--color-bg-secondary) 100%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s ease-in-out infinite;
  }
  @keyframes skeleton-loading {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
  }
}
```

**REDUCED MOTION (global kill-switch):**

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

---

### §5. TYPOGRAPHY SYSTEM (v2.0)

```css
@layer base {
  h1, h2, h3, h4, h5, h6 {
    font-family: var(--font-display);
    font-weight: 700;
    line-height: var(--line-height-tight);
    letter-spacing: var(--letter-spacing-tight);
    color: var(--color-text-primary);
    margin-bottom: var(--space-lg);
    text-wrap: balance;                  /* v2.0: no orphan words in headings */
  }

  h1 {
    font-size: clamp(var(--text-4xl), 5vw + 1rem, var(--text-6xl));
    margin-bottom: var(--space-xl);
  }

  h2 {
    font-size: clamp(var(--text-3xl), 4vw + 1rem, var(--text-5xl));
    margin-top: var(--space-3xl);
  }
  /* Editorial flavor only — skip for Minimal/Brutalist directions: */
  .style-editorial h2 {
    padding-bottom: var(--space-lg);
    border-bottom: var(--border-width-md) solid var(--color-border-base);
  }

  h3 {
    font-size: clamp(var(--text-2xl), 3vw + 1rem, var(--text-4xl));
    margin-top: var(--space-2xl);
  }

  h4 { font-size: var(--text-xl); margin-top: var(--space-xl); }
  h5 { font-size: var(--text-lg); margin-top: var(--space-lg); }
  h6 {
    font-size: var(--text-base);
    margin-top: var(--space-lg);
    text-transform: uppercase;
    letter-spacing: var(--letter-spacing-wide);
  }

  p {
    margin-bottom: var(--space-lg);
    max-width: 70ch;                     /* optimal reading measure */
    text-wrap: pretty;                   /* v2.0: better rag, no orphans */
  }

  .lead {
    font-size: var(--text-lg);
    line-height: var(--line-height-relaxed);
    color: var(--color-text-secondary);
  }

  .small { font-size: var(--text-sm); }
  .text-muted { color: var(--color-text-muted); }

  a {
    color: var(--color-primary);
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 2px;
    transition: color var(--transition-base), text-decoration-thickness var(--transition-base);
  }
  a:hover {
    color: var(--color-primary-hover);
    text-decoration-thickness: 2px;
  }
  a:focus-visible {
    outline: var(--focus-ring-width) solid var(--color-focus);
    outline-offset: var(--focus-ring-offset);
    border-radius: var(--radius-sm);
  }
}
```

> 🎨 **AEO tip:** phrase `h2`/`h3` headings as the questions users actually ask ("How much does X cost?", "Is X safe?"). AI answer engines lift question-shaped headings + the paragraph below them verbatim into answers.

---

### §6. COMPONENT PATTERNS (v2.0)

```css
/* ============================================
   @layer components — BUTTONS
   ============================================ */
@layer components {
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-xl);
    font-family: var(--font-body);
    font-size: var(--text-base);
    font-weight: 600;
    line-height: 1;
    text-decoration: none;
    border: var(--border-width) solid transparent;
    border-radius: var(--radius-md);
    cursor: pointer;
    white-space: nowrap;
    user-select: none;
    /* v2.0: explicit property list — never `transition: all` on hot paths */
    transition: background-color var(--transition-base),
                border-color var(--transition-base),
                color var(--transition-base),
                transform var(--transition-base),
                box-shadow var(--transition-base);
    /* WCAG 2.2 minimum touch target */
    min-width: 44px;
    min-height: 44px;
  }

  .btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
  }

  .btn-primary {
    background: var(--color-primary);
    color: var(--color-text-inverse);
    border-color: var(--color-primary);
  }
  .btn-primary:hover {
    background: var(--color-primary-hover);
    border-color: var(--color-primary-hover);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
  }

  .btn-secondary {
    background: transparent;
    color: var(--color-primary);
    border-color: var(--color-primary);
  }
  .btn-secondary:hover { background: var(--color-primary-light); }

  .btn-ghost {
    background: transparent;
    color: var(--color-text-primary);
  }
  .btn-ghost:hover { background: var(--color-bg-secondary); }

  /* Size modifiers (DRY) */
  .btn--large { padding: var(--space-lg) var(--space-2xl); font-size: var(--text-lg); }
  .btn--small { padding: var(--space-sm) var(--space-md); font-size: var(--text-sm); }
  .btn--wide  { width: 100%; }
  .btn--icon  { padding: var(--space-md); aspect-ratio: 1; }
}

/* ============================================
   @layer components — CARDS (container-query ready, see §7)
   ============================================ */
@layer components {
  .card {
    background: var(--color-bg-base);
    border: var(--border-width) solid var(--color-border-base);
    border-radius: var(--radius-lg);
    padding: var(--space-2xl);
    box-shadow: var(--shadow-sm);
    transition: box-shadow var(--transition-base),
                transform var(--transition-base),
                border-color var(--transition-base);
  }
  .card:hover {
    box-shadow: var(--shadow-lg);
    transform: translateY(-4px);
    border-color: var(--color-primary);
  }

  .card-title { font-size: var(--text-xl); font-weight: 700; margin-bottom: var(--space-sm); }
  .card-description { color: var(--color-text-secondary); font-size: var(--text-sm); }
  .card-content { margin-bottom: var(--space-lg); }
  .card-footer {
    padding-top: var(--space-lg);
    border-top: var(--border-width) solid var(--color-border-light);
  }
}

/* ============================================
   @layer components — STICKY HEADER + ACCESSIBLE MOBILE NAV (v2.0)
   ============================================ */
@layer components {
  .site-header {
    position: sticky;
    top: 0;
    z-index: var(--z-sticky);
    background: color-mix(in srgb, var(--color-bg-base) 85%, transparent);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-bottom: var(--border-width) solid var(--color-border-light);
  }

  .nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-lg);
    min-height: var(--header-height);
    max-width: var(--container-xl);
    margin-inline: auto;
    padding-inline: var(--space-lg);
  }

  .nav-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    min-height: 44px;
    background: none;
    border: 0;
    cursor: pointer;
  }

  .nav-menu {
    display: none;
    list-style: none;
  }
  .nav-menu.is-open {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
    position: absolute;
    inset: var(--header-height) 0 auto 0;
    background: var(--color-bg-base);
    padding: var(--space-lg);
    box-shadow: var(--shadow-lg);
    border-bottom: var(--border-width) solid var(--color-border-base);
  }

  body.menu-open { overflow: hidden; } /* mobile scroll lock */

  @media (min-width: 768px) {
    .nav-toggle { display: none; }
    .nav-menu {
      display: flex;
      align-items: center;
      gap: var(--space-lg);
      position: static;
    }
  }
}

/* ============================================
   @layer components — FORMS
   ============================================ */
@layer components {
  .form-group { margin-bottom: var(--space-xl); }

  .form-label {
    display: block;
    margin-bottom: var(--space-sm);
    font-weight: 600;
    font-size: var(--text-sm);
    color: var(--color-text-primary);
  }
  .form-label .required { color: var(--color-error); margin-left: var(--space-xs); }

  .form-input, .form-textarea, .form-select {
    display: block;
    width: 100%;
    padding: var(--space-md);
    font: inherit;
    color: var(--color-text-primary);
    background: var(--color-bg-base);
    border: var(--border-width) solid var(--color-border-base);
    border-radius: var(--radius-md);
    transition: border-color var(--transition-base), box-shadow var(--transition-base);
  }
  .form-input:focus, .form-textarea:focus, .form-select:focus {
    outline: none;
    border-color: var(--color-focus);
    box-shadow: 0 0 0 3px var(--color-focus-ring);
  }
  /* Show invalid state only after the user has interacted */
  .form-input:user-invalid, .form-textarea:user-invalid {
    border-color: var(--color-error);
  }

  .form-helper { display: block; margin-top: var(--space-xs); font-size: var(--text-sm); color: var(--color-text-tertiary); }
  .form-error  { display: block; margin-top: var(--space-xs); font-size: var(--text-sm); color: var(--color-error); }
  .form-status { margin-top: var(--space-md); font-weight: 600; }

  /* Honeypot — visually removed, invisible to humans, filled by bots */
  .hp-field {
    position: absolute;
    left: -9999px;
    width: 1px;
    height: 1px;
    overflow: hidden;
  }
}
```

**Form HTML — with `autocomplete` map (v2.0). `action` per `hostilo-deployment.md` §5 (template form / Hostilo `FORM_ID` / `action="#"` + TODO + `mailto:` fallback; endpoint pattern not re-verified 2026-10-19):**

```html
<form id="contact-form" class="form"
      action="https://hostilo.app/api/forms/FORM_ID" method="POST">

  <div class="form-group">
    <label class="form-label" for="name">Full name<span class="required" aria-hidden="true">*</span></label>
    <input class="form-input" type="text" id="name" name="name"
           required autocomplete="name">
  </div>

  <div class="form-group">
    <label class="form-label" for="email">Email<span class="required" aria-hidden="true">*</span></label>
    <input class="form-input" type="email" id="email" name="email"
           required autocomplete="email" inputmode="email">
    <span class="form-helper">We reply within 24 h.</span>
  </div>

  <div class="form-group">
    <label class="form-label" for="company">Company</label>
    <input class="form-input" type="text" id="company" name="company"
           autocomplete="organization"> <!-- essential for B2B conversion -->
  </div>

  <div class="form-group">
    <label class="form-label" for="phone">Phone</label>
    <input class="form-input" type="tel" id="phone" name="phone"
           autocomplete="tel" inputmode="tel">
  </div>

  <div class="form-group">
    <label class="form-label" for="message">Message<span class="required" aria-hidden="true">*</span></label>
    <textarea class="form-textarea" id="message" name="message" rows="5" required></textarea>
  </div>

  <!-- Honeypot (spam protection on static forms) -->
  <div class="hp-field" aria-hidden="true">
    <label for="website">Website</label>
    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
  </div>

  <button class="btn btn-primary" type="submit">Send message</button>
  <p class="form-status" role="status" aria-live="polite"></p>
</form>
```

**`autocomplete` cheat sheet (use on EVERY relevant field — big mobile-conversion win):**

| Field | `autocomplete` value | Extra |
|---|---|---|
| Full name | `name` | or `given-name` + `family-name` |
| Email | `email` | `inputmode="email"` |
| Phone | `tel` | `inputmode="tel"` |
| Company (B2B) | `organization` | |
| Job title (B2B) | `organization-title` | |
| Street | `street-address` | |
| Postal code | `postal-code` | `inputmode="numeric"` for PL |
| Country | `country-name` | |
| Website | `url` | `inputmode="url"` |
| New password | `new-password` | |
| Login password | `current-password` | |
| One-time code | `one-time-code` | `inputmode="numeric"` |

> ⚠️ Never build credit-card fields on a static site — always embed Stripe/PayU/Przelewy24 hosted elements instead.

---

### §7. LAYOUT: CONTAINERS, GRID & CONTAINER QUERIES (v2.0)

```css
/* ============================================
   @layer components — PAGE SCAFFOLDING
   ============================================ */
@layer components {
  .container {
    width: 100%;
    max-width: var(--container-xl);
    margin-inline: auto;
    padding-inline: var(--space-lg);
  }
  .container-sm  { max-width: var(--container-sm); }
  .container-md  { max-width: var(--container-md); }
  .container-lg  { max-width: var(--container-lg); }
  .container-2xl { max-width: var(--container-2xl); }

  .section    { padding-block: var(--space-4xl); }
  .section-sm { padding-block: var(--space-2xl); }
  .section-lg { padding-block: clamp(var(--space-4xl), 10vw, 8rem); }

  .grid { display: grid; gap: var(--space-lg); }
  .grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
  .grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
  .grid-cols-4 { grid-template-columns: repeat(4, 1fr); }

  /* Auto-fit responsive grid — zero media queries needed */
  .grid-auto-fit {
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 350px), 1fr));
  }
}

/* ============================================
   @layer utilities — FLEX / SPACING HELPERS
   ============================================ */
@layer utilities {
  .flex { display: flex; }
  .flex-col { flex-direction: column; }
  .flex-wrap { flex-wrap: wrap; }
  .items-center { align-items: center; }
  .items-start { align-items: flex-start; }
  .items-end { align-items: flex-end; }
  .justify-center { justify-content: center; }
  .justify-between { justify-content: space-between; }
  .justify-end { justify-content: flex-end; }
  .gap-sm { gap: var(--space-sm); }
  .gap-md { gap: var(--space-md); }
  .gap-lg { gap: var(--space-lg); }
  .gap-xl { gap: var(--space-xl); }
}
```

**CONTAINER QUERIES — the 2026 component default (v2.0):**

Media queries answer *"how wide is the VIEWPORT?"* — container queries answer *"how wide is MY SLOT?"*. A card in a 3-column grid and the same card in a sidebar get the right layout automatically.

```css
@layer components {
  /* 1. Make every reusable-component wrapper a container */
  .card-wrapper {
    container-type: inline-size;
    container-name: card;
  }

  /* 2. The component responds to ITS OWN width, not the viewport */
  @container card (min-width: 500px) {
    .card {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: var(--space-xl);
      align-items: center;
    }
  }

  /* 3. Container-query units: cqi = 1% of container inline size */
  .card-title {
    font-size: clamp(var(--text-lg), 5cqi, var(--text-2xl));
  }
}
```

> **Rule of thumb:** container queries for reusable components (cards, testimonials, pricing tiles, CTA blocks) · media queries only for page scaffolding (nav breakpoint, grid column count, section padding).

---

### §8. RESPONSIVE DESIGN — MOBILE FIRST

```css
/* BASE = mobile (< 640px). Everything above is enhancement. */

@media (min-width: 640px) {  /* SM — small tablets */
  .container { padding-inline: var(--space-xl); }
  .grid-cols-sm-2 { grid-template-columns: repeat(2, 1fr); }
  .grid-cols-sm-3 { grid-template-columns: repeat(3, 1fr); }
}

@media (min-width: 768px) {  /* MD — tablets */
  :root { --header-height: 80px; }
  .grid-cols-md-2 { grid-template-columns: repeat(2, 1fr); }
  .grid-cols-md-3 { grid-template-columns: repeat(3, 1fr); }
  .grid-cols-md-4 { grid-template-columns: repeat(4, 1fr); }
}

@media (min-width: 1024px) { /* LG — desktops */
  .container { padding-inline: var(--space-2xl); }
  .grid-cols-lg-3 { grid-template-columns: repeat(3, 1fr); }
  .grid-cols-lg-4 { grid-template-columns: repeat(4, 1fr); }
}

@media (min-width: 1280px) { /* XL — large desktops */
  .grid-cols-xl-4 { grid-template-columns: repeat(4, 1fr); }
  .grid-cols-xl-5 { grid-template-columns: repeat(5, 1fr); }
}
```

---

### §9. MOTION: VIEW TRANSITIONS API (v2.0)

**Cross-document (multi-page sites) — pure CSS, zero JS:**

```css
/* Opt in: same-origin navigations get a smooth crossfade */
@view-transition {
  navigation: auto;
}

::view-transition-old(root),
::view-transition-new(root) {
  animation-duration: var(--duration-slow);
  animation-timing-function: var(--ease-in-out);
}

/* Morph a specific element between pages/states */
.hero-title { view-transition-name: hero-title; }

/* Accessibility: kill VT animations for reduced-motion users */
@media (prefers-reduced-motion: reduce) {
  ::view-transition-group(*),
  ::view-transition-old(*),
  ::view-transition-new(*) {
    animation: none !important;
  }
}
```

**Same-document (theme switch, tab change, gallery filtering) — feature-detected JS:**

```javascript
function updateWithTransition(updateFn) {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!document.startViewTransition || reduced) {
    updateFn();
    return;
  }
  document.startViewTransition(updateFn);
}

// Usage: updateWithTransition(() => themeManager.toggle());
```

> Progressive enhancement by design: browsers without View Transitions simply swap instantly. Never gate functionality on it.

---

### §10. PERFORMANCE OPTIMIZATIONS (v2.0)

**Image pipeline — AVIF first (v2.0):**

```html
<picture>
  <!-- AVIF: ~50% smaller than JPEG, ~20-30% smaller than WebP -->
  <source type="image/avif"
          srcset="img-sm.avif 640w, img-md.avif 1024w, img-lg.avif 1920w"
          sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw">
  <source type="image/webp"
          srcset="img-sm.webp 640w, img-md.webp 1024w, img-lg.webp 1920w"
          sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw">
  <img src="img-md.jpg"
       alt="[Descriptive alt text]"
       width="1024" height="768"
       loading="lazy" decoding="async">
</picture>
```

**LCP / hero image — the opposite rules apply:**

```html
<img src="hero-lg.avif" alt="[Hero alt]"
     width="1920" height="1080"
     fetchpriority="high" decoding="async">
<!-- NO loading="lazy" on the hero. EVER. + <link rel="preload"> in <head> (§1). -->
```

**Budgets:** content images < 100 KB · hero < 150 KB · total images per page < 700 KB. Tools: Squoosh, `avifenc`, `cwebp`.

**Self-hosted fonts (Strategy A) — `@font-face`:**

```css
/* In the EXTERNAL css/site.css (Hostilo stores it as assets/css/site.css): sibling folders via ../ —
   correct whether or not the platform rewrites links. Inline <style> must not rely on url() rewriting. */
@font-face {
  font-family: 'DisplayFont';
  src: url('../fonts/display-variable.woff2') format('woff2-variations');
  font-weight: 100 900;
  font-display: swap;
  unicode-range: U+0000-00FF, U+0100-017F; /* latin + latin-ext (Polish ą ć ę ł ń ó ś ź ż) */
}
```

Rules: max **2 font families** · variable fonts over static weights · subset to the scripts you need · always `font-display: swap` · preload in `<head>` (§1).

**Script & CSS loading:**

```html
<!-- Critical CSS: inline in <head>. Non-critical CSS (multi-file mode): -->
<!-- Relative paths, NO leading slash (Hostilo rewrites relative links in index.html to assets/) -->
<link rel="preload" href="css/site.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="css/site.css"></noscript>

<!-- JS: always deferred, never parser-blocking -->
<script defer src="js/app.js"></script>
```

---

