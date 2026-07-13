# Design Directions & Industry Mapping (Anti-AI-Slop)

## 🎨 §12. DESIGN GUIDELINES — ANTI-AI-SLOP (v2.0)

### CRITICAL RULES

❌ **NEVER USE:**
- Inter, Roboto, Arial, system-font stacks as the design voice
- Purple gradients on white backgrounds
- Stock Tailwind colors (#3B82F6, #8B5CF6, #EC4899) as brand colors
- Centered-everything layouts; uniform border-radius on every element
- Predictable card grids with no point of view
- **The 2026 AI-default looks** (they scream "generated" regardless of subject):
  1. Warm cream background (~#F4F1EA) + high-contrast serif + terracotta accent
  2. Near-black background + one acid-green or vermilion accent
  3. Broadsheet layout: hairline rules, zero radius, dense newspaper columns
- The same aesthetic direction for two different industries (see mapping below)

✅ **ALWAYS USE:**
- **Characterful fonts**: DM Serif Display, Playfair Display, Fraunces, Space Grotesk, Space Mono, JetBrains Mono, Crimson Pro, Libre Baskerville, Syne, Clash Display, Bricolage Grotesque…
- **A palette derived from the subject's world** — the industry's materials, instruments and vernacular, not a generic "pretty" scheme
- **One signature element** — the single memorable thing (a hero device, an interaction, a typographic move). Spend your boldness there; keep everything around it disciplined.
- **Asymmetric layouts**: diagonal flows, overlapping elements, intentional density OR generous whitespace (never the timid middle)
- **Structure that encodes meaning**: numbered markers only for real sequences; eyebrows/dividers only when they say something true about the content
- **One orchestrated motion moment** (page-load sequence or scroll reveal) over scattered micro-effects

### AESTHETIC DIRECTIONS (choose exactly 1 per project)

1. **Brutalist/Raw** — stark type, exposed grid, high contrast B/W
2. **Neo-Brutalism** — thick borders, hard shadows, bright colors, playful
3. **Glassmorphism** — blur, transparency, depth, frosted layers
4. **Neumorphism** — soft shadows, subtle depth, monochromatic
5. **Swiss/Modernist** — grid precision, grotesque type, extreme whitespace
6. **Editorial/Magazine** — huge typography, photo-forward, dynamic columns
7. **Retro-Futuristic** — synthwave hues, geometry, neon accents
8. **Organic/Natural** — earthy tones, flowing shapes, texture
9. **Minimal Luxury** — refined, serif-led, premium restraint
10. **Maximalist** — layered, colorful, experimental, decorative

### 🗺️ INDUSTRY → AESTHETIC MAPPING (v2.0 — MANDATORY)

When the user picks *"match to industry"* (or gives no style preference), use this map. **Never reuse the same direction for two different industries.** Randomize *within* the mapped pair and vary palette temperature, radius scale and layout asymmetry so two sites from the same industry still differ.

| Industry / niche | Primary direction | Alternative | Typography flavor |
|---|---|---|---|
| Finance / fintech / insurance | Swiss/Modernist | Editorial | Neo-grotesque + tabular numerals |
| Law firm / notary | Editorial + Minimal Luxury | Swiss | High-contrast serif |
| Hosting / dev tools / infra SaaS | Neo-Brutalism | Brutalist/Raw + dark | Grotesque + mono accents |
| AI startup | Retro-Futuristic | Glassmorphism (dark) | Wide grotesque + mono |
| Creative agency / studio | Maximalist | Minimal Luxury | Statement display face |
| Fashion e-commerce | Editorial/Magazine | Minimal Luxury | Didone serif |
| Food / restaurant / café | Organic/Natural | Editorial | Warm serif + script accent |
| Health / wellness / medical | Organic + Minimal | Glassmorphism (light) | Humanist sans |
| Education / online courses | Swiss | Neo-Brutalism (playful) | Friendly geometric sans |
| Real estate / architecture | Minimal Luxury | Swiss | Elegant serif + light sans |
| Non-profit / NGO | Organic | Editorial | Humanist |
| Music / events / nightlife | Maximalist | Retro-Futuristic | Experimental display |
| B2B SaaS (general) | Swiss + one bold accent | Neo-Brutalism | Grotesque |
| Industrial / manufacturing | Brutalist/Raw | Swiss | Condensed grotesque |
| Beauty / cosmetics | Minimal Luxury | Organic | Refined serif |

**Document the decision** at the top of every generated file:

```html
<!-- DESIGN DIRECTION: Neo-Brutalism
     Palette: ink #101014 / signal #D7FF3E / paper #F2F0E9 / alert #FF4D2E
     Type: Space Grotesk (display) + JetBrains Mono (accents)
     Signature: draggable terminal-style deploy card in the hero -->
```

---

