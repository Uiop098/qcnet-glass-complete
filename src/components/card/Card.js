/**
 * Card Component
 */

import { enableHoverLift } from '../../animations/hover.js';
import { enableMouseTracking } from '../../animations/mouseTracking.js';
import { enableTilt } from '../../animations/tilt.js';

export class Card {
  constructor(options = {}) {
    const {
      variant = 'default',
      interactive = false,
      tilt = false,
      mouseTracking = false,
      padding = 'md',
      onClick = null
    } = options;

    this.options = options;
    this.element = this.create();
    this.cleanupFunctions = [];
    
    this.init();
  }

  create() {
    const card = document.createElement('div');
    card.className = this.getClasses();

    if (this.options.header) {
      const header = document.createElement('div');
      header.className = 'ui-card-header';
      header.innerHTML = this.options.header;
      card.appendChild(header);
    }

    if (this.options.content || this.options.children) {
      const content = document.createElement('div');
      content.className = 'ui-card-content';
      content.innerHTML = this.options.content || this.options.children || '';
      card.appendChild(content);
    }

    if (this.options.footer) {
      const footer = document.createElement('div');
      footer.className = 'ui-card-footer';
      footer.innerHTML = this.options.footer;
      card.appendChild(footer);
    }

    return card;
  }

  init() {
    if (this.options.interactive) {
      const cleanup = enableHoverLift(this.element, { shadow: true });
      this.cleanupFunctions.push(cleanup);
    }

    if (this.options.tilt) {
      const cleanup = enableTilt(this.element, { glare: true });
      this.cleanupFunctions.push(cleanup);
    }

    if (this.options.mouseTracking) {
      const cleanup = enableMouseTracking(this.element, { glowEffect: true });
      this.cleanupFunctions.push(cleanup);
    }

    if (this.options.onClick) {
      this.element.addEventListener('click', this.options.onClick);
      this.element.style.cursor = 'pointer';
    }
  }

  getClasses() {
    const classes = ['ui-card'];
    
    classes.push(`ui-card-${this.options.variant}`);
    classes.push(`ui-card-padding-${this.options.padding}`);
    
    if (this.options.interactive) {
      classes.push('ui-card-interactive');
    }

    return classes.join(' ');
  }

  destroy() {
    this.cleanupFunctions.forEach(fn => fn());
    this.element.remove();
  }

  getElement() {
    return this.element;
  }
}

export default Card;
