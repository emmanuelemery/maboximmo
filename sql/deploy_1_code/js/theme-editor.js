/**
 * theme-editor.js
 * Rôle     : Éditeur de thème par société — logique complète
 * Dépend   : components.js
 * Date     : 2026-04-03
 */

'use strict';

/* ══════════════════════════════════════════
   GÉNÉRATION DE PALETTE depuis une couleur base
══════════════════════════════════════════ */
const ColorUtils = {
  /**
   * Convertit hex → HSL
   */
  hexToHsl(hex) {
    let r = parseInt(hex.slice(1,3),16)/255;
    let g = parseInt(hex.slice(3,5),16)/255;
    let b = parseInt(hex.slice(5,7),16)/255;
    const max=Math.max(r,g,b), min=Math.min(r,g,b);
    let h,s,l=(max+min)/2;
    if(max===min){ h=s=0; }
    else{
      const d=max-min;
      s=l>0.5?d/(2-max-min):d/(max+min);
      switch(max){
        case r: h=((g-b)/d+(g<b?6:0))/6; break;
        case g: h=((b-r)/d+2)/6; break;
        case b: h=((r-g)/d+4)/6; break;
      }
    }
    return [h*360, s*100, l*100];
  },

  /**
   * HSL → hex
   */
  hslToHex(h,s,l) {
    h/=360; s/=100; l/=100;
    let r,g,b;
    if(s===0){ r=g=b=l; }
    else{
      const hue2rgb=(p,q,t)=>{
        if(t<0)t+=1; if(t>1)t-=1;
        if(t<1/6)return p+(q-p)*6*t;
        if(t<1/2)return q;
        if(t<2/3)return p+(q-p)*(2/3-t)*6;
        return p;
      };
      const q=l<0.5?l*(1+s):l+s-l*s;
      const p=2*l-q;
      r=hue2rgb(p,q,h+1/3);
      g=hue2rgb(p,q,h);
      b=hue2rgb(p,q,h-1/3);
    }
    const toHex=x=>Math.round(x*255).toString(16).padStart(2,'0');
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
  },

  /**
   * Génère 9 niveaux de nuances à partir d'une couleur base
   * Niveau 500 = couleur originale
   */
  generatePalette(baseHex) {
    const [h, s, l] = this.hexToHsl(baseHex);
    // Lightness targets pour 50→900
    const targets = {
      50:  95, 100: 90, 200: 80, 300: 68,
      400: 57, 500: l,  600: Math.max(l-8,20),
      700: Math.max(l-18,15), 800: Math.max(l-28,10),
      900: Math.max(l-38,5)
    };
    const palette = {};
    Object.entries(targets).forEach(([level, lightness]) => {
      // Saturation légèrement plus faible sur les extrêmes
      const satAdj = (level <= 100 || level >= 800) ? s * 0.7 : s;
      palette[level] = this.hslToHex(h, Math.min(satAdj, 100), lightness);
    });
    return palette;
  }
};

/* ══════════════════════════════════════════
   THÈMES PRÉRÉGLÉS
══════════════════════════════════════════ */
const PRESETS = {
  classique: {
    name: 'Classique',
    primary: '#2563EB',
    secondary: '#64748B',
    sidebar: '#1E3A5F',
    topbar: '#ffffff',
    font: 'Inter',
    radius: 8,
    sidebarWidth: 260,
    density: 'normal'
  },
  moderne: {
    name: 'Moderne',
    primary: '#6366F1',
    secondary: '#8B5CF6',
    sidebar: '#1E1B4B',
    topbar: '#ffffff',
    font: 'Poppins',
    radius: 12,
    sidebarWidth: 280,
    density: 'comfortable'
  },
  naturel: {
    name: 'Naturel',
    primary: '#10B981',
    secondary: '#059669',
    sidebar: '#064E3B',
    topbar: '#F0FDF4',
    font: 'Nunito',
    radius: 10,
    sidebarWidth: 260,
    density: 'normal'
  },
  chaud: {
    name: 'Chaud',
    primary: '#F97316',
    secondary: '#EAB308',
    sidebar: '#431407',
    topbar: '#FFF7ED',
    font: 'Lato',
    radius: 8,
    sidebarWidth: 260,
    density: 'normal'
  },
  sombre: {
    name: 'Sombre Pro',
    primary: '#818CF8',
    secondary: '#A78BFA',
    sidebar: '#0F1117',
    topbar: '#161B27',
    font: 'DM Sans',
    radius: 8,
    sidebarWidth: 260,
    density: 'compact',
    darkByDefault: true
  }
};

/* ══════════════════════════════════════════
   ÉDITEUR DE THÈME
══════════════════════════════════════════ */
const ThemeEditor = {
  preview: null,  // Element de prévisualisation
  state: {
    name: 'Ma Société',
    primary: '#6366F1',
    secondary: '#8B5CF6',
    sidebar: '#312E81',
    topbar: '#ffffff',
    links: '#6366F1',
    font: 'Inter',
    fontSize: 14,
    radius: 8,
    sidebarWidth: 260,
    density: 'normal'
  },

  init() {
    this.preview = document.getElementById('theme-preview');
    this._bindControls();
    this._bindPresets();
    this._bindExport();
    this._render();
  },

  _bindControls() {
    // Nom de société
    this._bind('input[name="company-name"]', 'input', e => {
      this.state.name = e.target.value;
      this._render();
    });

    // Couleur primaire
    this._bind('#color-primary', 'input', e => {
      this.state.primary = e.target.value;
      document.getElementById('color-primary-hex').value = e.target.value;
      this._render();
    });
    this._bind('#color-primary-hex', 'input', e => {
      const val = e.target.value;
      if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        this.state.primary = val;
        document.getElementById('color-primary').value = val;
        this._render();
      }
    });

    // Couleur secondaire
    this._bind('#color-secondary', 'input', e => {
      this.state.secondary = e.target.value;
      document.getElementById('color-secondary-hex').value = e.target.value;
      this._render();
    });
    this._bind('#color-secondary-hex', 'input', e => {
      const val = e.target.value;
      if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        this.state.secondary = val;
        document.getElementById('color-secondary').value = val;
        this._render();
      }
    });

    // Sidebar color
    this._bind('#color-sidebar', 'input', e => {
      this.state.sidebar = e.target.value;
      this._render();
    });

    // Topbar color
    this._bind('#color-topbar', 'input', e => {
      this.state.topbar = e.target.value;
      this._render();
    });

    // Font
    this._bind('#font-select', 'change', e => {
      this.state.font = e.target.value;
      this._loadFont(e.target.value);
      this._render();
    });

    // Font size
    this._bind('#font-size', 'input', e => {
      this.state.fontSize = parseInt(e.target.value);
      document.getElementById('font-size-val').textContent = e.target.value + 'px';
      this._render();
    });

    // Border radius
    this._bind('#border-radius', 'input', e => {
      this.state.radius = parseInt(e.target.value);
      document.getElementById('radius-val').textContent = e.target.value + 'px';
      this._render();
    });

    // Sidebar width
    this._bind('#sidebar-width', 'input', e => {
      this.state.sidebarWidth = parseInt(e.target.value);
      document.getElementById('sidebar-width-val').textContent = e.target.value + 'px';
      this._render();
    });

    // Density
    document.querySelectorAll('[data-density]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-density]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.state.density = btn.dataset.density;
        this._render();
      });
    });
  },

  _bind(selector, event, handler) {
    document.querySelector(selector)?.addEventListener(event, handler);
  },

  _bindPresets() {
    document.querySelectorAll('[data-preset]').forEach(btn => {
      btn.addEventListener('click', () => {
        const preset = PRESETS[btn.dataset.preset];
        if (!preset) return;
        this._applyPreset(preset);
      });
    });
  },

  _applyPreset(preset) {
    this.state.primary      = preset.primary;
    this.state.secondary    = preset.secondary;
    this.state.sidebar      = preset.sidebar;
    this.state.topbar       = preset.topbar;
    this.state.font         = preset.font;
    this.state.radius       = preset.radius;
    this.state.sidebarWidth = preset.sidebarWidth;
    this.state.density      = preset.density;

    // Sync UI
    const setVal = (sel, val) => { const el = document.querySelector(sel); if (el) el.value = val; };
    setVal('#color-primary',       preset.primary);
    setVal('#color-primary-hex',   preset.primary);
    setVal('#color-secondary',     preset.secondary);
    setVal('#color-secondary-hex', preset.secondary);
    setVal('#color-sidebar',       preset.sidebar);
    setVal('#color-topbar',        preset.topbar);
    setVal('#font-select',         preset.font);
    setVal('#font-size',           14);
    setVal('#border-radius',       preset.radius);
    setVal('#sidebar-width',       preset.sidebarWidth);

    const radiusEl = document.getElementById('radius-val');
    if (radiusEl) radiusEl.textContent = preset.radius + 'px';
    const sidEl = document.getElementById('sidebar-width-val');
    if (sidEl) sidEl.textContent = preset.sidebarWidth + 'px';

    document.querySelectorAll('[data-density]').forEach(b => {
      b.classList.toggle('active', b.dataset.density === preset.density);
    });

    if (preset.darkByDefault) window.V2?.DarkMode?.enable(false);
    else window.V2?.DarkMode?.disable(false);

    this._loadFont(preset.font);
    this._render();
  },

  _loadFont(name) {
    const fontMap = {
      'Inter':    'Inter:wght@400;500;600;700',
      'Roboto':   'Roboto:wght@400;500;700',
      'Poppins':  'Poppins:wght@400;500;600;700',
      'Lato':     'Lato:wght@400;700',
      'Open Sans':'Open+Sans:wght@400;600;700',
      'Nunito':   'Nunito:wght@400;600;700',
      'DM Sans':  'DM+Sans:wght@400;500;700'
    };
    const slug = fontMap[name];
    if (!slug) return;
    const id = `gfont-${name.replace(/\s+/g,'-')}`;
    if (!document.getElementById(id)) {
      const link = document.createElement('link');
      link.id   = id;
      link.rel  = 'stylesheet';
      link.href = `https://fonts.googleapis.com/css2?family=${slug}&display=swap`;
      document.head.appendChild(link);
    }
  },

  _render() {
    if (!this.preview) return;
    const palette = ColorUtils.generatePalette(this.state.primary);
    const palSec  = ColorUtils.generatePalette(this.state.secondary);

    // Density spacing multipliers
    const densityMap = { comfortable: 1.25, normal: 1, compact: 0.8 };
    const mult = densityMap[this.state.density] || 1;

    const css = `
      --brand-50:  ${palette[50]};
      --brand-100: ${palette[100]};
      --brand-200: ${palette[200]};
      --brand-300: ${palette[300]};
      --brand-400: ${palette[400]};
      --brand-500: ${palette[500]};
      --brand-600: ${palette[600]};
      --brand-700: ${palette[700]};
      --brand-800: ${palette[800]};
      --brand-900: ${palette[900]};
      --brand-primary:        ${palette[500]};
      --brand-primary-hover:  ${palette[600]};
      --brand-primary-active: ${palette[700]};
      --brand-primary-light:  ${palette[50]};
      --brand-sidebar-bg:   ${this.state.sidebar};
      --brand-topbar-bg:    ${this.state.topbar};
      --brand-font-sans:    '${this.state.font}', -apple-system, sans-serif;
      --radius-base:  ${this.state.radius}px;
      --radius-sm:    ${Math.max(this.state.radius - 4, 2)}px;
      --radius-md:    ${this.state.radius}px;
      --radius-lg:    ${this.state.radius + 4}px;
      --radius-xl:    ${this.state.radius + 8}px;
      --text-base: ${this.state.fontSize}px;
      --sidebar-width: ${this.state.sidebarWidth}px;
      --space-1: ${Math.round(4*mult)}px;
      --space-2: ${Math.round(8*mult)}px;
      --space-3: ${Math.round(12*mult)}px;
      --space-4: ${Math.round(16*mult)}px;
      --space-5: ${Math.round(20*mult)}px;
      --space-6: ${Math.round(24*mult)}px;
    `;

    this.preview.style.cssText = css;

    // Update preview company name
    const nameEl = this.preview.querySelector('[data-preview-name]');
    if (nameEl) nameEl.textContent = this.state.name;

    // Update palette swatches in preview
    this._updateSwatches(palette);
  },

  _updateSwatches(palette) {
    const container = document.getElementById('palette-preview');
    if (!container) return;
    container.innerHTML = Object.entries(palette).map(([level, hex]) => `
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
        <div style="width:32px;height:32px;border-radius:6px;background:${hex};border:1px solid rgba(0,0,0,0.1);flex-shrink:0;"></div>
        <span style="font-size:12px;color:var(--text-secondary);">${level}</span>
        <span style="font-size:11px;color:var(--text-tertiary);font-family:monospace;">${hex}</span>
      </div>
    `).join('');
  },

  _bindExport() {
    document.getElementById('btn-generate-css')?.addEventListener('click', () => {
      const css = this._generateCSS();
      const modal = document.getElementById('export-modal');
      const area = document.getElementById('export-css-output');
      if (area) area.value = css;
      window.V2?.Modal?.open('export-modal');
    });

    document.getElementById('btn-copy-css')?.addEventListener('click', () => {
      const area = document.getElementById('export-css-output');
      navigator.clipboard.writeText(area?.value || '').then(() => {
        window.V2?.Toast?.success('CSS copié dans le presse-papier');
      });
    });

    document.getElementById('btn-download-css')?.addEventListener('click', () => {
      const css  = this._generateCSS();
      const blob = new Blob([css], { type: 'text/css' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href     = url;
      a.download = `theme-${this.state.name.toLowerCase().replace(/\s+/g,'-')}.css`;
      a.click();
      URL.revokeObjectURL(url);
    });
  },

  _generateCSS() {
    const palette = ColorUtils.generatePalette(this.state.primary);
    const date    = new Date().toLocaleDateString('fr-FR');

    return `/* Thème ${this.state.name} — généré le ${date} */
:root {
  /* Couleurs primaires */
  --brand-50:  ${palette[50]};
  --brand-100: ${palette[100]};
  --brand-200: ${palette[200]};
  --brand-300: ${palette[300]};
  --brand-400: ${palette[400]};
  --brand-500: ${palette[500]};
  --brand-600: ${palette[600]};
  --brand-700: ${palette[700]};
  --brand-800: ${palette[800]};
  --brand-900: ${palette[900]};

  --brand-primary:        ${palette[500]};
  --brand-primary-hover:  ${palette[600]};
  --brand-primary-active: ${palette[700]};
  --brand-primary-light:  ${palette[50]};

  /* Interface */
  --brand-sidebar-bg:   ${this.state.sidebar};
  --brand-topbar-bg:    ${this.state.topbar};

  /* Typographie */
  --brand-font-sans: '${this.state.font}', -apple-system, BlinkMacSystemFont, sans-serif;
  --text-base: ${this.state.fontSize}px;

  /* Géométrie */
  --brand-radius-base: ${this.state.radius}px;
  --sidebar-width: ${this.state.sidebarWidth}px;
}
`;
  }
};

/* ══════════════════════════════════════════
   INIT
══════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('theme-preview')) {
    ThemeEditor.init();
  }
});

window.ThemeEditor = ThemeEditor;
window.ColorUtils  = ColorUtils;
