/**
 * Hamburger Menu Component
 */

export class Hamburger {
  constructor(options = {}) {
    const {
      isOpen = false,
      onClick = null
    } = options;

    this.isOpen = isOpen;
    this.options = options;
    this.element = this.create();
    
    this.init();
  }

  create() {
    const button = document.createElement('button');
    button.className = 'hamburger';
    button.setAttribute('aria-label', 'Toggle menu');
    button.setAttribute('aria-expanded', this.isOpen ? 'true' : 'false');

    for (let i = 0; i < 3; i++) {
      const line = document.createElement('span');
      line.className = 'hamburger-line';
      button.appendChild(line);
    }

    if (this.isOpen) {
      button.classList.add('is-open');
    }

    return button;
  }

  init() {
    this.element.addEventListener('click', () => {
      this.toggle();
      
      if (this.options.onClick) {
        this.options.onClick(this.isOpen);
      }
    });
  }

  toggle() {
    this.isOpen = !this.isOpen;
    
    if (this.isOpen) {
      this.element.classList.add('is-open');
      this.element.setAttribute('aria-expanded', 'true');
    } else {
      this.element.classList.remove('is-open');
      this.element.setAttribute('aria-expanded', 'false');
    }
  }

  open() {
    if (!this.isOpen) {
      this.toggle();
    }
  }

  close() {
    if (this.isOpen) {
      this.toggle();
    }
  }

  getElement() {
    return this.element;
  }
}

export default Hamburger;
