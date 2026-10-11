/**
 * Button Component
 * Supports variants, sizes, states, and interactions
 */

import { createRipple } from '../../animations/ripple.js';
import { enableMagnetic } from '../../animations/magnetic.js';

export class Button {
  constructor(options = {}) {
    const {
      variant = 'primary',
      size = 'md',
      disabled = false,
      loading = false,
      icon = null,
      iconPosition = 'left',
      ripple = true,
      magnetic = false,
      fullWidth = false,
      onClick = null
    } = options;

    this.options = options;
    this.element = this.create();
    this.cleanupFunctions = [];
    
    this.init();
  }

  /**
   * Create button element
   */
  create() {
    const button = document.createElement('button');
    button.className = this.getClasses();
    button.type = 'button';
    
    if (this.options.disabled) {
      button.disabled = true;
    }

    // Create inner structure
    const content = document.createElement('span');
    content.className = 'ui-button-content';

    // Add icon if provided
    if (this.options.icon && !this.options.loading) {
      const icon = document.createElement('span');
      icon.className = 'ui-button-icon';
      icon.innerHTML = this.options.icon;
      
      if (this.options.iconPosition === 'left') {
        content.appendChild(icon);
      }
    }

    // Add loading spinner
    if (this.options.loading) {
      const spinner = document.createElement('span');
      spinner.className = 'ui-button-spinner';
      spinner.innerHTML = this.getSpinnerSVG();
      content.appendChild(spinner);
    }

    // Add label if provided
    if (this.options.label || this.options.children) {
      const label = document.createElement('span');
      label.className = 'ui-button-label';
      label.textContent = this.options.label || this.options.children;
      content.appendChild(label);
    }

    // Add icon on right
    if (this.options.icon && this.options.iconPosition === 'right' && !this.options.loading) {
      const icon = document.createElement('span');
      icon.className = 'ui-button-icon';
      icon.innerHTML = this.options.icon;
      content.appendChild(icon);
    }

    button.appendChild(content);
    
    return button;
  }

  /**
   * Initialize button
   */
  init() {
    // Ripple effect
    if (this.options.ripple && !this.options.disabled) {
      this.element.addEventListener('pointerdown', (e) => {
        createRipple(this.element, e);
      });
    }

    // Magnetic effect
    if (this.options.magnetic && !this.options.disabled) {
      const cleanup = enableMagnetic(this.element);
      this.cleanupFunctions.push(cleanup);
    }

    // Click handler
    if (this.options.onClick) {
      this.element.addEventListener('click', this.options.onClick);
    }
  }

  /**
   * Get button classes
   */
  getClasses() {
    const classes = ['ui-button'];
    
    classes.push(`ui-button-${this.options.variant}`);
    classes.push(`ui-button-${this.options.size}`);
    
    if (this.options.fullWidth) {
      classes.push('ui-button-full');
    }
    
    if (this.options.loading) {
      classes.push('ui-button-loading');
    }
    
    if (this.options.disabled) {
      classes.push('ui-button-disabled');
    }

    return classes.join(' ');
  }

  /**
   * Get spinner SVG
   */
  getSpinnerSVG() {
    return `
      <svg class="ui-spinner" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.25"/>
        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      </svg>
    `;
  }

  /**
   * Set loading state
   */
  setLoading(loading) {
    this.options.loading = loading;
    
    if (loading) {
      this.element.classList.add('ui-button-loading');
      this.element.disabled = true;
    } else {
      this.element.classList.remove('ui-button-loading');
      this.element.disabled = this.options.disabled;
    }
  }

  /**
   * Set disabled state
   */
  setDisabled(disabled) {
    this.options.disabled = disabled;
    this.element.disabled = disabled;
    
    if (disabled) {
      this.element.classList.add('ui-button-disabled');
    } else {
      this.element.classList.remove('ui-button-disabled');
    }
  }

  /**
   * Destroy button
   */
  destroy() {
    this.cleanupFunctions.forEach(fn => fn());
    this.element.remove();
  }

  /**
   * Get DOM element
   */
  getElement() {
    return this.element;
  }
}

export default Button;
