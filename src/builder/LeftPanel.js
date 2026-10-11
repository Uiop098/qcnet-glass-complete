/**
 * Left Panel Component
 * Component library and layer tree
 */

export class LeftPanel {
  constructor(options = {}) {
    this.options = options;
    this.element = this.create();
    this.visible = true;
  }

  create() {
    const panel = document.createElement('div');
    panel.className = 'builder-left-panel';

    // Tabs
    const tabs = document.createElement('div');
    tabs.className = 'builder-panel-tabs';
    
    const componentsTab = this.createTab('Components', true);
    const layersTab = this.createTab('Layers', false);
    
    tabs.appendChild(componentsTab);
    tabs.appendChild(layersTab);

    // Content
    const content = document.createElement('div');
    content.className = 'builder-panel-content';
    
    this.componentsView = this.createComponentsView();
    this.layersView = this.createLayersView();
    
    content.appendChild(this.componentsView);
    content.appendChild(this.layersView);

    panel.appendChild(tabs);
    panel.appendChild(content);

    return panel;
  }

  createTab(label, active) {
    const tab = document.createElement('button');
    tab.className = 'builder-panel-tab' + (active ? ' active' : '');
    tab.textContent = label;
    
    tab.addEventListener('click', () => {
      this.switchTab(label);
    });
    
    return tab;
  }

  createComponentsView() {
    const view = document.createElement('div');
    view.className = 'builder-components-view';
    
    const categories = [
      {
        name: 'Layout',
        components: ['Container', 'Grid', 'Flex', 'Stack']
      },
      {
        name: 'Content',
        components: ['Heading', 'Text', 'Image', 'Video']
      },
      {
        name: 'Components',
        components: ['Button', 'Card', 'Modal', 'Navbar']
      },
      {
        name: 'Forms',
        components: ['Input', 'Textarea', 'Select', 'Checkbox']
      }
    ];

    categories.forEach(category => {
      const categoryEl = document.createElement('div');
      categoryEl.className = 'builder-component-category';
      
      const header = document.createElement('div');
      header.className = 'builder-component-category-header';
      header.textContent = category.name;
      
      const items = document.createElement('div');
      items.className = 'builder-component-items';
      
      category.components.forEach(comp => {
        const item = this.createComponentItem(comp);
        items.appendChild(item);
      });
      
      categoryEl.appendChild(header);
      categoryEl.appendChild(items);
      view.appendChild(categoryEl);
    });

    return view;
  }

  createComponentItem(name) {
    const item = document.createElement('div');
    item.className = 'builder-component-item';
    item.textContent = name;
    item.draggable = true;
    
    item.addEventListener('dragstart', (e) => {
      e.dataTransfer.setData('component-type', name.toLowerCase());
      e.dataTransfer.effectAllowed = 'copy';
    });
    
    item.addEventListener('click', () => {
      if (this.options.onComponentSelect) {
        this.options.onComponentSelect({ type: name.toLowerCase(), name });
      }
    });
    
    return item;
  }

  createLayersView() {
    const view = document.createElement('div');
    view.className = 'builder-layers-view';
    view.style.display = 'none';
    
    const tree = document.createElement('div');
    tree.className = 'builder-layer-tree';
    tree.innerHTML = '<div class="builder-layer-empty">No elements yet</div>';
    
    view.appendChild(tree);
    
    return view;
  }

  switchTab(tabName) {
    // Update tab active state
    const tabs = this.element.querySelectorAll('.builder-panel-tab');
    tabs.forEach(tab => {
      if (tab.textContent === tabName) {
        tab.classList.add('active');
      } else {
        tab.classList.remove('active');
      }
    });

    // Show corresponding view
    if (tabName === 'Components') {
      this.componentsView.style.display = 'block';
      this.layersView.style.display = 'none';
    } else {
      this.componentsView.style.display = 'none';
      this.layersView.style.display = 'block';
    }
  }

  toggle() {
    this.visible = !this.visible;
    this.element.style.display = this.visible ? 'flex' : 'none';
  }

  getElement() {
    return this.element;
  }
}

export default LeftPanel;
