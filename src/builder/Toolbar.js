/**
 * Toolbar Component
 */

export class Toolbar {
  constructor(options = {}) {
    this.options = options;
    this.element = this.create();
  }

  create() {
    const toolbar = document.createElement('div');
    toolbar.className = 'builder-toolbar';

    // Left section
    const left = document.createElement('div');
    left.className = 'builder-toolbar-section';
    
    const logo = document.createElement('div');
    logo.className = 'builder-toolbar-logo';
    logo.textContent = 'Visual Builder';
    
    const projectName = document.createElement('div');
    projectName.className = 'builder-toolbar-project';
    projectName.textContent = 'Untitled Project';
    
    left.appendChild(logo);
    left.appendChild(projectName);

    // Center section
    const center = document.createElement('div');
    center.className = 'builder-toolbar-section';
    
    const undoBtn = this.createButton('Undo', 'undo', () => this.options.onUndo?.());
    const redoBtn = this.createButton('Redo', 'redo', () => this.options.onRedo?.());
    
    center.appendChild(undoBtn);
    center.appendChild(redoBtn);

    // Right section
    const right = document.createElement('div');
    right.className = 'builder-toolbar-section';
    
    this.saveBtn = this.createButton('Save', 'save', () => this.options.onSave?.());
    const previewBtn = this.createButton('Preview', 'preview', () => this.options.onPreview?.());
    const buildBtn = this.createButton('Build', 'build', () => this.options.onBuild?.());
    
    right.appendChild(this.saveBtn);
    right.appendChild(previewBtn);
    right.appendChild(buildBtn);

    toolbar.appendChild(left);
    toolbar.appendChild(center);
    toolbar.appendChild(right);

    return toolbar;
  }

  createButton(label, type, onClick) {
    const button = document.createElement('button');
    button.className = `builder-toolbar-btn builder-toolbar-btn-${type}`;
    button.textContent = label;
    button.addEventListener('click', onClick);
    return button;
  }

  setSaveStatus(status) {
    this.saveBtn.setAttribute('data-status', status);
    
    const text = {
      saving: 'Saving...',
      saved: 'Saved',
      error: 'Error'
    }[status] || 'Save';
    
    this.saveBtn.textContent = text;
    
    if (status === 'saved' || status === 'error') {
      setTimeout(() => {
        this.saveBtn.textContent = 'Save';
        this.saveBtn.removeAttribute('data-status');
      }, 2000);
    }
  }

  setBuildStatus(status) {
    // Similar to setSaveStatus
    const buildBtn = this.element.querySelector('.builder-toolbar-btn-build');
    buildBtn.setAttribute('data-status', status);
    
    const text = {
      building: 'Building...',
      success: 'Built',
      error: 'Failed'
    }[status] || 'Build';
    
    buildBtn.textContent = text;
    
    if (status === 'success' || status === 'error') {
      setTimeout(() => {
        buildBtn.textContent = 'Build';
        buildBtn.removeAttribute('data-status');
      }, 2000);
    }
  }

  getElement() {
    return this.element;
  }
}

export default Toolbar;
