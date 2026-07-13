# JavaScript Utilities: Theme, Accessible Nav, Observer, Smooth Scroll, Form Validator + Hostilo Submit

### §11. JAVASCRIPT UTILITIES (v2.0)

```javascript
// ============================================
// THEME MANAGER (v2.0: color-scheme sync + view transition)
// ============================================
class ThemeManager {
  constructor() {
    this.theme = localStorage.getItem('theme') ||
      (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    this.apply();
    // Follow OS changes until the user makes an explicit choice
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
      if (!localStorage.getItem('theme')) {
        this.theme = e.matches ? 'dark' : 'light';
        this.apply();
      }
    });
  }

  apply() {
    // [data-theme] also flips `color-scheme` (§3), so native form
    // controls, scrollbars and UA widgets follow automatically.
    document.documentElement.setAttribute('data-theme', this.theme);
  }

  toggle() {
    const swap = () => {
      this.theme = this.theme === 'light' ? 'dark' : 'light';
      this.apply();
      localStorage.setItem('theme', this.theme);
    };
    document.startViewTransition ? document.startViewTransition(swap) : swap();
  }
}
const themeManager = new ThemeManager();

// ============================================
// ACCESSIBLE MOBILE MENU (v2.0)
// aria-expanded sync · Escape closes · closes on link click
// ============================================
class MobileMenu {
  constructor(toggleSel = '.nav-toggle', menuSel = '#primary-menu') {
    this.toggle = document.querySelector(toggleSel);
    this.menu = document.querySelector(menuSel);
    if (!this.toggle || !this.menu) return;

    this.toggle.addEventListener('click', () => this.set(!this.isOpen()));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isOpen()) {
        this.set(false);
        this.toggle.focus(); // return focus to the trigger (WCAG)
      }
    });
    this.menu.addEventListener('click', (e) => {
      if (e.target.closest('a')) this.set(false);
    });
  }
  isOpen() { return this.toggle.getAttribute('aria-expanded') === 'true'; }
  set(open) {
    this.toggle.setAttribute('aria-expanded', String(open));
    this.menu.classList.toggle('is-open', open);
    document.body.classList.toggle('menu-open', open);
  }
}
const mobileMenu = new MobileMenu();

// ============================================
// INTERSECTION OBSERVER (lazy scroll animations)
// ============================================
const observeElements = (selector, callback, options = {}) => {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        callback(entry.target);
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -100px 0px', ...options });

  document.querySelectorAll(selector).forEach(el => observer.observe(el));
};
observeElements('.animate-on-scroll', (el) => el.classList.add('is-visible'));

// ============================================
// SMOOTH SCROLL (v2.0: with focus management)
// v1.0 scrolled visually but left keyboard/AT focus behind — fixed.
// ============================================
document.querySelectorAll('a[href^="#"]:not([href="#"])').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    const target = document.querySelector(this.getAttribute('href'));
    if (!target) return;
    e.preventDefault();
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
    history.pushState(null, '', this.getAttribute('href'));
  });
});

// ============================================
// FORM VALIDATOR + HOSTILO AJAX SUBMIT (v2.0)
// Progressive enhancement: falls back to native POST without JS.
// ============================================
class FormValidator {
  constructor(formSelector) {
    this.form = document.querySelector(formSelector);
    if (!this.form) return;
    this.status = this.form.querySelector('.form-status');
    this.init();
  }

  init() {
    this.form.setAttribute('novalidate', ''); // we render nicer errors ourselves
    this.form.addEventListener('submit', (e) => this.handleSubmit(e));
    this.form.querySelectorAll('input, textarea, select').forEach(field => {
      field.addEventListener('blur', () => this.validateField(field));
      field.addEventListener('input', () => this.clearError(field));
    });
  }

  validateField(field) {
    if (field.closest('.hp-field')) return true; // skip honeypot
    const error = this.getError(field);
    if (error) { this.showError(field, error); return false; }
    this.clearError(field);
    return true;
  }

  getError(field) {
    if (field.hasAttribute('required') && !field.value.trim()) {
      return 'This field is required';
    }
    if (field.type === 'email' && field.value) {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(field.value)) return 'Please enter a valid email address';
    }
    if (field.hasAttribute('minlength')) {
      const min = parseInt(field.getAttribute('minlength'), 10);
      if (field.value.length < min) return `Minimum ${min} characters`;
    }
    return null;
  }

  showError(field, message) {
    const errorEl = field.parentElement.querySelector('.form-error') ||
                    this.createErrorElement(field);
    errorEl.textContent = message;
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', errorEl.id);
  }

  clearError(field) {
    const errorEl = field.parentElement.querySelector('.form-error');
    if (errorEl) errorEl.textContent = '';
    field.removeAttribute('aria-invalid');
  }

  createErrorElement(field) {
    const error = document.createElement('span');
    error.className = 'form-error';
    error.id = `error-${field.id || Math.random().toString(36).slice(2, 11)}`;
    error.setAttribute('role', 'alert');
    field.parentElement.appendChild(error);
    return error;
  }

  setStatus(message) {
    if (this.status) this.status.textContent = message; // role="status" announces it
  }

  async handleSubmit(e) {
    e.preventDefault();

    let isValid = true;
    this.form.querySelectorAll('input, textarea, select').forEach(field => {
      if (!this.validateField(field)) isValid = false;
    });
    if (!isValid) {
      this.form.querySelector('[aria-invalid="true"]')?.focus();
      return;
    }

    // Honeypot filled → bot → silently pretend success
    const hp = this.form.querySelector('.hp-field input');
    if (hp && hp.value) { this.setStatus('✅ Message sent.'); this.form.reset(); return; }

    const btn = this.form.querySelector('[type="submit"]');
    btn.disabled = true;
    this.setStatus('Sending…');

    try {
      const res = await fetch(this.form.action, {
        method: 'POST',
        body: new FormData(this.form),
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      this.setStatus('✅ Message sent. We will get back to you shortly.');
      this.form.reset();
    } catch (err) {
      console.error('Form submit failed:', err);
      // Fallback: native submission still delivers the data
      this.form.submit();
    } finally {
      btn.disabled = false;
    }
  }
}
const validator = new FormValidator('#contact-form');
```

---

