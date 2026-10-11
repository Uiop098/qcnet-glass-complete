/**
 * Canvas Component
 * Visual editing canvas
 */

export class Canvas {
  constructor(options = {}) {
    this.options = options;
    this.element = this.create();
    this.mode = 'desktop'; // desktop, tablet, mobile
    this.previewMode = false;
    this.elements = [];
    
    this.init();
  }

  create() {
    const wrapper = document.createElement('div');
    wrapper.className = 'builder-canvas-wrapper';

    const canvas = document.createElement('div');
    canvas.className = 'builder-canvas';
    canvas.setAttribute('data-mode', this.mode);

    // Create viewport
    this.viewport = document.createElement('div');
    this.viewport.className = 'builder-viewport';
    
    canvas.appendChild(this.viewport);
    wrapper.appendChild(canvas);

    return wrapper;
  }

  init() {
    // Enable drag and drop
    this.enableDragDrop();
    
    // Enable element selection
    this.enableSelection();
  }

  enableDragDrop() {
    this.viewport.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
    });

    this.viewport.addEventListener('drop', (e) => {
      e.preventDefault();
      
      const componentType = e.dataTransfer.getData('component-type');
      if (componentType) {
        const element = this.createElementFromType(componentType);
        
        // Position at drop location
        const rect = this.viewport.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        element.style.position = 'absolute';
        element.style.left = `${x}px`;
        element.style.top = `${y}px`;
        
        this.viewport.appendChild(element);
        this.elements.push(element);
        
        if (this.options.onElementSelect) {
          this.options.onElementSelect(element);
        }
      }
    });
  }

  enableSelection() {
    this.viewport.addEventListener('click', (e) => {
      if (this.previewMode) return;
      
      e.stopPropagation();
      
      const element = e.target.closest('[data-builder-element]');
      if (element && this.options.onElementSelect) {
        this.options.onElementSelect(element);
      }
    });
  }

  createElementFromType(type) {
    const element = document.createElement('div');
    element.setAttribute('data-builder-element', type);
    element.setAttribute('data-element-id', `element_${Date.now()}`);
    element.className = `builder-element builder-element-${type}`;
    
    // Set default content based on type
    switch (type) {
      case 'button':
        element.innerHTML = '<button class="ui-button ui-button-primary ui-button-md">Button</button>';
        break;
      
      case 'text':
        element.innerHTML = '<p>Text content</p>';
        break;
      
      case 'heading':
        element.innerHTML = '<h2>Heading</h2>';
        break;
      
      case 'card':
        element.innerHTML = '<div class="ui-card ui-card-default ui-card-padding-md"><div class="ui-card-content">Card content</div></div>';
        break;
      
      case 'container':
        element.style.padding = '20px';
        element.style.border = '2px dashed #cbd5e1';
        element.textContent = 'Container';
        break;
      
      default:
        element.textContent = type;
    }
    
    return element;
  }

  addElement(component) {
    const element = this.createElementFromType(component.type);
    this.viewport.appendChild(element);
    this.elements.push(element);
    return element;
  }

  setMode(mode) {
    this.mode = mode;
    this.element.querySelector('.builder-canvas').setAttribute('data-mode', mode);
    
    // Adjust viewport size
    const sizes = {
      mobile: '375px',
      tablet: '768px',
      desktop: '100%'
    };
    
    this.viewport.style.maxWidth = sizes[mode];
  }

  togglePreview() {
    this.previewMode = !this.previewMode;
    
    if (this.previewMode) {
      this.element.classList.add('preview-mode');
    } else {
      this.element.classList.remove('preview-mode');
    }
  }

  getState() {
    return {
      elements: this.elements.map(el => ({
        id: el.getAttribute('data-element-id'),
        type: el.getAttribute('data-builder-element'),
        html: el.innerHTML,
        styles: el.style.cssText,
        position: {
          left: el.style.left,
          top: el.style.top
        }
      }))
    };
  }

  setState(state) {
    // Clear current elements
    this.viewport.innerHTML = '';
    this.elements = [];
    
    // Restore elements
    state.elements.forEach(elementData => {
      const element = this.createElementFromType(elementData.type);
      element.setAttribute('data-element-id', elementData.id);
      element.innerHTML = elementData.html;
      element.style.cssText = elementData.styles;
      
      this.viewport.appendChild(element);
      this.elements.push(element);
    });
  }

  getElement() {
    return this.element;
  }
}

export default Canvas;
