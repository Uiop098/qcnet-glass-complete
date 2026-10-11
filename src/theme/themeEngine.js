/**
 * Theme Engine
 * Manages theme switching, persistence, and CSS variable application
 */

import { themes } from './themes/index.js';
import { tokens } from './tokens/index.js';

class ThemeEngine {
  constructor() {
    this.currentTheme = 'light';
    this.customThemes = new Map();
    this.listeners = new Set();
    
    this.init();
  }

  /**
   * Initialize theme engine
   */
  init() {
    // Load saved theme or detect system preference
    const saved = localStorage.getItem('theme');
    const system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    
    this.currentTheme = saved || system;
    
    // Listen for system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
      if (!localStorage.getItem('theme')) {
        this.setTheme(e.matches ? 'dark' : 'light');
      }
    });
    
    // Apply initial theme
    this.applyTheme(this.currentTheme);
  }

  /**
   * Set theme
   */
  setTheme(themeName) {
    if (!themes[themeName] && !this.customThemes.has(themeName)) {
      console.warn(`Theme "${themeName}" not found`);
      return;
    }

    this.currentTheme = themeName;
    localStorage.setItem('theme', themeName);
    
    this.applyTheme(themeName);
    this.notifyListeners(themeName);
    
    // Dispatch custom event
    window.dispatchEvent(new CustomEvent('themechange', {
      detail: { theme: themeName }
    }));
  }

  /**
   * Get current theme
   */
  getTheme() {
    return this.currentTheme;
  }

  /**
   * Toggle between light and dark
   */
  toggle() {
    const current = this.currentTheme;
    const next = current === 'dark' ? 'light' : 'dark';
    this.setTheme(next);
  }

  /**
   * Apply theme by setting CSS variables
   */
  applyTheme(themeName) {
    const theme = themes[themeName] || this.customThemes.get(themeName);
    if (!theme) return;

    const root = document.documentElement;
    
    // Set theme name attribute
    root.setAttribute('data-theme', themeName);
    
    // Apply color variables
    Object.entries(theme.colors).forEach(([key, value]) => {
      root.style.setProperty(`--color-${this.kebabCase(key)}`, value);
    });
    
    // Apply design tokens
    this.applyTokens();
  }

  /**
   * Apply design tokens as CSS variables
   */
  applyTokens() {
    const root = document.documentElement;
    
    // Spacing
    Object.entries(tokens.spacing).forEach(([key, value]) => {
      root.style.setProperty(`--space-${key}`, value);
    });
    
    // Radius
    Object.entries(tokens.radius).forEach(([key, value]) => {
      root.style.setProperty(`--radius-${key}`, value);
    });
    
    // Shadows
    Object.entries(tokens.shadows).forEach(([key, value]) => {
      root.style.setProperty(`--shadow-${key}`, value);
    });
    
    // Motion
    Object.entries(tokens.motion.duration).forEach(([key, value]) => {
      root.style.setProperty(`--duration-${key}`, value);
    });
    
    Object.entries(tokens.motion.easing).forEach(([key, value]) => {
      root.style.setProperty(`--ease-${key}`, value);
    });
    
    // Typography
    Object.entries(tokens.typography.fontSize).forEach(([key, value]) => {
      root.style.setProperty(`--text-${key}`, value);
    });
    
    Object.entries(tokens.typography.fontWeight).forEach(([key, value]) => {
      root.style.setProperty(`--font-${key}`, value);
    });
    
    Object.entries(tokens.typography.lineHeight).forEach(([key, value]) => {
      root.style.setProperty(`--leading-${key}`, value);
    });
  }

  /**
   * Register custom theme
   */
  registerTheme(name, themeConfig) {
    this.customThemes.set(name, themeConfig);
  }

  /**
   * Get all available themes
   */
  getAvailableThemes() {
    return [
      ...Object.keys(themes),
      ...Array.from(this.customThemes.keys())
    ];
  }

  /**
   * Subscribe to theme changes
   */
  subscribe(callback) {
    this.listeners.add(callback);
    return () => this.listeners.delete(callback);
  }

  /**
   * Notify listeners
   */
  notifyListeners(theme) {
    this.listeners.forEach(callback => callback(theme));
  }

  /**
   * Utility: Convert camelCase to kebab-case
   */
  kebabCase(str) {
    return str.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
  }

  /**
   * Get CSS variable value
   */
  getCSSVariable(name) {
    return getComputedStyle(document.documentElement)
      .getPropertyValue(`--${name}`)
      .trim();
  }

  /**
   * Set CSS variable
   */
  setCSSVariable(name, value) {
    document.documentElement.style.setProperty(`--${name}`, value);
  }
}

// Create singleton instance
export const themeEngine = new ThemeEngine();

export default themeEngine;
