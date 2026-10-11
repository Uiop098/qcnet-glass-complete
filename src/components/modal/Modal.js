/**
 * Modal Component
 */

import { fadeIn, fadeOut } from '../../animations/fade.js';
import { scaleIn, scaleOut } from '../../animations/scale.js';

export class Modal {
  constructor(options = {}) {
    const {
      title = '',
      content = '',
      size = 'md',
      closeOnOverlay = true,
      closeOnEscape = true,
      animation = 'scale',
      onOpen = null,
      onClose = null
    } = options;

    this.options = options;
    this.isOpen = false;
    this.element = null;
    this.previousFocus = null;
    
    this.create();
    this.attachEvents();
  }

  create() {
    // Create overlay
    this.overlay = document.createElement('div');
    this.overlay.className = 'ui-modal-overlay';
    this.overlay.style.display = 'none';

    // Create modal
    this.element = document.createElement('div');
    this.element.className = `ui-modal ui-modal-${this.options.size}`;
    this.element.setAttribute('role', 'dialog');
    this.element.setAttribute('aria-modal', 'true');

    // Header
    if (this.options.title) {
      const header = document.createElement('div');
      header.className = 'ui-modal-header';
      
      const title = document.createElement('h2');
      title.className = 'ui-modal-title';
      title.textContent = this.options.title;
      
      const closeBtn = document.createElement('button');
      closeBtn.className = 'ui-modal-close';
      closeBtn.innerHTML = '&times;';
      closeBtn.setAttribute('aria-label', 'Close');
      closeBtn.addEventListener('click', () => this.close());
      
      header.appendChild(title);
      header.appendChild(closeBtn);
      this.element.appendChild(header);
    }

    // Content
    const content = document.createElement('div');
    content.className = 'ui-modal-content';
    content.innerHTML = this.options.content;
    this.element.appendChild(content);

    // Footer
    if (this.options.footer) {
      const footer = document.createElement('div');
      footer.className = 'ui-modal-footer';
      footer.innerHTML = this.options.footer;
      this.element.appendChild(footer);
    }

    this.overlay.appendChild(this.element);
  }

  attachEvents() {
    // Close on overlay click
    if (this.options.closeOnOverlay) {
      this.overlay.addEventListener('click', (e) => {
        if (e.target === this.overlay) {
          this.close();
        }
      });
    }

    // Close on escape
    if (this.options.closeOnEscape) {
      this.handleEscape = (e) => {
        if (e.key === 'Escape' && this.isOpen) {
          this.close();
        }
      };
      document.addEventListener('keydown', this.handleEscape);
    }
  }

  open() {
    if (this.isOpen) return;

    this.isOpen = true;
    this.previousFocus = document.activeElement;

    // Append to body
    document.body.appendChild(this.overlay);
    document.body.style.overflow = 'hidden';

    // Show with animation
    this.overlay.style.display = 'flex';
    
    if (this.options.animation === 'scale') {
      fadeIn(this.overlay, { duration: 200 });
      scaleIn(this.element, { duration: 300, from: 0.95 });
    } else {
      fadeIn(this.overlay, { duration: 300 });
      fadeIn(this.element, { duration: 300 });
    }

    // Focus trap
    this.trapFocus();

    if (this.options.onOpen) {
      this.options.onOpen();
    }
  }

  close() {
    if (!this.isOpen) return;

    this.isOpen = false;

    // Hide with animation
    if (this.options.animation === 'scale') {
      fadeOut(this.overlay, { 
        duration: 200,
        onComplete: () => {
          this.overlay.remove();
          document.body.style.overflow = '';
        }
      });
      scaleOut(this.element, { duration: 200, to: 0.95 });
    } else {
      fadeOut(this.overlay, { 
        duration: 300,
        onComplete: () => {
          this.overlay.remove();
          document.body.style.overflow = '';
        }
      });
    }

    // Restore focus
    if (this.previousFocus) {
      this.previousFocus.focus();
    }

    if (this.options.onClose) {
      this.options.onClose();
    }
  }

  trapFocus() {
    const focusableElements = this.element.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    
    const firstElement = focusableElements[0];
    const lastElement = focusableElements[focusableElements.length - 1];

    this.element.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;

      if (e.shiftKey) {
        if (document.activeElement === firstElement) {
          lastElement.focus();
          e.preventDefault();
        }
      } else {
        if (document.activeElement === lastElement) {
          firstElement.focus();
          e.preventDefault();
        }
      }
    });

    firstElement?.focus();
  }

  destroy() {
    this.close();
    document.removeEventListener('keydown', this.handleEscape);
  }
}

export default Modal;
