/**
 * DOM Utilities
 */

export function createElement(tag, options = {}) {
  const element = document.createElement(tag);
  
  if (options.className) {
    element.className = options.className;
  }
  
  if (options.id) {
    element.id = options.id;
  }
  
  if (options.attributes) {
    Object.entries(options.attributes).forEach(([key, value]) => {
      element.setAttribute(key, value);
    });
  }
  
  if (options.styles) {
    Object.assign(element.style, options.styles);
  }
  
  if (options.children) {
    if (Array.isArray(options.children)) {
      options.children.forEach(child => {
        if (typeof child === 'string') {
          element.appendChild(document.createTextNode(child));
        } else {
          element.appendChild(child);
        }
      });
    } else {
      element.innerHTML = options.children;
    }
  }
  
  if (options.dataset) {
    Object.entries(options.dataset).forEach(([key, value]) => {
      element.dataset[key] = value;
    });
  }
  
  return element;
}

export function query(selector, context = document) {
  return context.querySelector(selector);
}

export function queryAll(selector, context = document) {
  return Array.from(context.querySelectorAll(selector));
}

export function addClass(element, ...classes) {
  element.classList.add(...classes);
}

export function removeClass(element, ...classes) {
  element.classList.remove(...classes);
}

export function toggleClass(element, className) {
  element.classList.toggle(className);
}

export function hasClass(element, className) {
  return element.classList.contains(className);
}

export function setAttributes(element, attributes) {
  Object.entries(attributes).forEach(([key, value]) => {
    element.setAttribute(key, value);
  });
}

export function getOffset(element) {
  const rect = element.getBoundingClientRect();
  return {
    top: rect.top + window.scrollY,
    left: rect.left + window.scrollX,
    width: rect.width,
    height: rect.height
  };
}

export function isVisible(element) {
  return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
}

export function hide(element) {
  element.style.display = 'none';
}

export function show(element, display = 'block') {
  element.style.display = display;
}

export default {
  createElement,
  query,
  queryAll,
  addClass,
  removeClass,
  toggleClass,
  hasClass,
  setAttributes,
  getOffset,
  isVisible,
  hide,
  show
};
