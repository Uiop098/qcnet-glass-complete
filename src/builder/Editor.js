/**
 * Visual Builder Editor
 * Main editor interface
 */

import { Canvas } from './Canvas.js';
import { Toolbar } from './Toolbar.js';
import { LeftPanel } from './LeftPanel.js';
import { RightPanel } from './RightPanel.js';

export class Editor {
  constructor(options = {}) {
    this.options = options;
    this.selectedElement = null;
    this.project = {
      id: null,
      name: 'Untitled Project',
      pages: [],
      components: [],
      themes: [],
      settings: {}
    };
    
    this.element = this.create();
    this.init();
  }

  create() {
    const editor = document.createElement('div');
    editor.className = 'builder-editor';

    // Create toolbar
    this.toolbar = new Toolbar({
      onSave: () => this.save(),
      onUndo: () => this.undo(),
      onRedo: () => this.redo(),
      onPreview: () => this.togglePreview(),
      onBuild: () => this.build()
    });

    // Create left panel
    this.leftPanel = new LeftPanel({
      onComponentSelect: (component) => this.addComponent(component),
      onLayerSelect: (layer) => this.selectElement(layer)
    });

    // Create canvas
    this.canvas = new Canvas({
      onElementSelect: (element) => this.selectElement(element),
      onElementUpdate: (element, props) => this.updateElement(element, props)
    });

    // Create right panel
    this.rightPanel = new RightPanel({
      onPropertyChange: (property, value) => this.updateSelectedElement(property, value)
    });

    // Assemble editor
    editor.appendChild(this.toolbar.getElement());

    const mainContent = document.createElement('div');
    mainContent.className = 'builder-main';
    
    mainContent.appendChild(this.leftPanel.getElement());
    mainContent.appendChild(this.canvas.getElement());
    mainContent.appendChild(this.rightPanel.getElement());
    
    editor.appendChild(mainContent);

    return editor;
  }

  init() {
    // Initialize command history
    this.history = {
      past: [],
      present: null,
      future: []
    };

    // Keyboard shortcuts
    this.setupShortcuts();
    
    // Auto-save
    this.setupAutoSave();
  }

  setupShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Ctrl/Cmd + S - Save
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        this.save();
      }

      // Ctrl/Cmd + Z - Undo
      if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        this.undo();
      }

      // Ctrl/Cmd + Shift + Z - Redo
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'z') {
        e.preventDefault();
        this.redo();
      }

      // Delete - Remove element
      if (e.key === 'Delete' && this.selectedElement) {
        e.preventDefault();
        this.deleteElement(this.selectedElement);
      }

      // Escape - Deselect
      if (e.key === 'Escape') {
        this.deselectElement();
      }
    });
  }

  setupAutoSave() {
    setInterval(() => {
      this.autoSave();
    }, 30000); // Auto-save every 30 seconds
  }

  selectElement(element) {
    // Deselect previous
    if (this.selectedElement) {
      this.selectedElement.classList.remove('is-selected');
    }

    this.selectedElement = element;
    element.classList.add('is-selected');

    // Update right panel
    this.rightPanel.showProperties(element);
  }

  deselectElement() {
    if (this.selectedElement) {
      this.selectedElement.classList.remove('is-selected');
      this.selectedElement = null;
      this.rightPanel.clear();
    }
  }

  addComponent(component) {
    const element = this.canvas.addElement(component);
    this.selectElement(element);
    this.recordHistory();
  }

  updateSelectedElement(property, value) {
    if (!this.selectedElement) return;

    this.updateElement(this.selectedElement, { [property]: value });
  }

  updateElement(element, props) {
    Object.entries(props).forEach(([key, value]) => {
      if (key in element.style) {
        element.style[key] = value;
      } else {
        element.setAttribute(key, value);
      }
    });

    this.recordHistory();
  }

  deleteElement(element) {
    element.remove();
    this.deselectElement();
    this.recordHistory();
  }

  recordHistory() {
    const state = this.canvas.getState();
    
    this.history.past.push(this.history.present);
    this.history.present = state;
    this.history.future = [];
  }

  undo() {
    if (this.history.past.length === 0) return;

    const previous = this.history.past.pop();
    this.history.future.unshift(this.history.present);
    this.history.present = previous;
    
    this.canvas.setState(previous);
  }

  redo() {
    if (this.history.future.length === 0) return;

    const next = this.history.future.shift();
    this.history.past.push(this.history.present);
    this.history.present = next;
    
    this.canvas.setState(next);
  }

  async save() {
    try {
      const data = {
        project: this.project,
        pages: this.canvas.getState()
      };

      // Send to backend
      const response = await fetch('/api/projects', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });

      if (response.ok) {
        this.toolbar.setSaveStatus('saved');
      } else {
        this.toolbar.setSaveStatus('error');
      }
    } catch (error) {
      console.error('Save failed:', error);
      this.toolbar.setSaveStatus('error');
    }
  }

  autoSave() {
    // Save to localStorage
    const state = {
      project: this.project,
      pages: this.canvas.getState(),
      timestamp: Date.now()
    };

    localStorage.setItem('builder_autosave', JSON.stringify(state));
  }

  togglePreview() {
    this.leftPanel.toggle();
    this.rightPanel.toggle();
    this.canvas.togglePreview();
  }

  async build() {
    this.toolbar.setBuildStatus('building');

    try {
      const response = await fetch('/api/builds', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          project: this.project,
          pages: this.canvas.getState()
        })
      });

      if (response.ok) {
        this.toolbar.setBuildStatus('success');
      } else {
        this.toolbar.setBuildStatus('error');
      }
    } catch (error) {
      console.error('Build failed:', error);
      this.toolbar.setBuildStatus('error');
    }
  }

  getElement() {
    return this.element;
  }

  destroy() {
    this.element.remove();
  }
}

export default Editor;
