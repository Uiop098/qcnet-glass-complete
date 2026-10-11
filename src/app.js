/**
 * Main Application Entry Point
 */

import { themeEngine } from './theme/themeEngine.js';
import { Editor } from './builder/Editor.js';

class App {
  constructor() {
    this.init();
  }

  async init() {
    console.log('🚀 Initializing Visual Web Builder...');

    // Initialize theme engine
    themeEngine.init();
    console.log('✓ Theme engine initialized');

    // Create editor
    this.editor = new Editor({
      container: '#app'
    });

    // Mount editor
    const appContainer = document.getElementById('app');
    appContainer.appendChild(this.editor.getElement());
    console.log('✓ Editor mounted');

    // Try to restore autosaved state
    this.restoreAutoSave();

    console.log('✓ App ready');
  }

  restoreAutoSave() {
    try {
      const saved = localStorage.getItem('builder_autosave');
      if (saved) {
        const data = JSON.parse(saved);
        const timestamp = new Date(data.timestamp);
        
        console.log(`Found autosave from ${timestamp.toLocaleString()}`);
        
        // Optionally restore state
        // this.editor.canvas.setState(data.pages);
      }
    } catch (error) {
      console.error('Failed to restore autosave:', error);
    }
  }
}

// Initialize app when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => new App());
} else {
  new App();
}

// Export for debugging
window.app = App;
