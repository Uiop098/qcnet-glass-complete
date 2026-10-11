/**
 * Right Panel Component
 * Property inspector
 */

export class RightPanel {
  constructor(options = {}) {
    this.options = options;
    this.element = this.create();
    this.visible = true;
    this.selectedElement = null;
  }

  create() {
    const panel = document.createElement('div');
    panel.className = 'builder-right-panel';

    const header = document.createElement('div');
    header.className = 'builder-panel-header';
    header.textContent = 'Properties';

    this.content = document.createElement('div');
    this.content.className = 'builder-panel-content';
    this.content.innerHTML = '<div class="builder-panel-empty">Select an element to edit</div>';

    panel.appendChild(header);
    panel.appendChild(this.content);

    return panel;
  }

  showProperties(element) {
    this.selectedElement = element;
    this.content.innerHTML = '';

    // Create property groups
    this.addSection('Layout', [
      { label: 'Display', type: 'select', property: 'display', options: ['block', 'flex', 'grid', 'inline-block'] },
      { label: 'Width', type: 'text', property: 'width' },
      { label: 'Height', type: 'text', property: 'height' },
      { label: 'Position', type: 'select', property: 'position', options: ['static', 'relative', 'absolute', 'fixed'] }
    ]);

    this.addSection('Spacing', [
      { label: 'Padding', type: 'text', property: 'padding' },
      { label: 'Margin', type: 'text', property: 'margin' }
    ]);

    this.addSection('Typography', [
      { label: 'Font Size', type: 'text', property: 'fontSize' },
      { label: 'Font Weight', type: 'select', property: 'fontWeight', options: ['400', '500', '600', '700'] },
      { label: 'Color', type: 'color', property: 'color' },
      { label: 'Text Align', type: 'select', property: 'textAlign', options: ['left', 'center', 'right'] }
    ]);

    this.addSection('Appearance', [
      { label: 'Background', type: 'color', property: 'backgroundColor' },
      { label: 'Border', type: 'text', property: 'border' },
      { label: 'Border Radius', type: 'text', property: 'borderRadius' },
      { label: 'Opacity', type: 'range', property: 'opacity', min: 0, max: 1, step: 0.1 }
    ]);
  }

  addSection(title, properties) {
    const section = document.createElement('div');
    section.className = 'builder-property-section';

    const header = document.createElement('div');
    header.className = 'builder-property-section-header';
    header.textContent = title;

    const content = document.createElement('div');
    content.className = 'builder-property-section-content';

    properties.forEach(prop => {
      const field = this.createPropertyField(prop);
      content.appendChild(field);
    });

    section.appendChild(header);
    section.appendChild(content);
    this.content.appendChild(section);
  }

  createPropertyField(prop) {
    const field = document.createElement('div');
    field.className = 'builder-property-field';

    const label = document.createElement('label');
    label.className = 'builder-property-label';
    label.textContent = prop.label;

    let input;

    switch (prop.type) {
      case 'text':
        input = document.createElement('input');
        input.type = 'text';
        input.value = this.selectedElement?.style[prop.property] || '';
        break;

      case 'color':
        input = document.createElement('input');
        input.type = 'color';
        input.value = this.selectedElement?.style[prop.property] || '#000000';
        break;

      case 'select':
        input = document.createElement('select');
        prop.options.forEach(option => {
          const opt = document.createElement('option');
          opt.value = option;
          opt.textContent = option;
          input.appendChild(opt);
        });
        input.value = this.selectedElement?.style[prop.property] || prop.options[0];
        break;

      case 'range':
        input = document.createElement('input');
        input.type = 'range';
        input.min = prop.min;
        input.max = prop.max;
        input.step = prop.step;
        input.value = this.selectedElement?.style[prop.property] || prop.min;
        break;

      default:
        input = document.createElement('input');
        input.type = 'text';
    }

    input.className = 'builder-property-input';
    
    input.addEventListener('change', (e) => {
      if (this.options.onPropertyChange) {
        this.options.onPropertyChange(prop.property, e.target.value);
      }
    });

    field.appendChild(label);
    field.appendChild(input);

    return field;
  }

  clear() {
    this.selectedElement = null;
    this.content.innerHTML = '<div class="builder-panel-empty">Select an element to edit</div>';
  }

  toggle() {
    this.visible = !this.visible;
    this.element.style.display = this.visible ? 'flex' : 'none';
  }

  getElement() {
    return this.element;
  }
}

export default RightPanel;
