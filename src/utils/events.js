/**
 * Event Utilities
 */

export function on(element, event, handler, options) {
  element.addEventListener(event, handler, options);
  return () => element.removeEventListener(event, handler, options);
}

export function off(element, event, handler, options) {
  element.removeEventListener(event, handler, options);
}

export function once(element, event, handler, options) {
  const wrappedHandler = (e) => {
    handler(e);
    off(element, event, wrappedHandler, options);
  };
  on(element, event, wrappedHandler, options);
}

export function delegate(parent, selector, event, handler) {
  const wrappedHandler = (e) => {
    let target = e.target;
    while (target && target !== parent) {
      if (target.matches(selector)) {
        handler.call(target, e);
        return;
      }
      target = target.parentElement;
    }
  };
  
  parent.addEventListener(event, wrappedHandler);
  return () => parent.removeEventListener(event, wrappedHandler);
}

export function trigger(element, eventName, detail = {}) {
  const event = new CustomEvent(eventName, {
    bubbles: true,
    cancelable: true,
    detail
  });
  element.dispatchEvent(event);
}

export function debounce(func, wait = 300) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

export function throttle(func, limit = 300) {
  let inThrottle;
  return function executedFunction(...args) {
    if (!inThrottle) {
      func(...args);
      inThrottle = true;
      setTimeout(() => inThrottle = false, limit);
    }
  };
}

export default {
  on,
  off,
  once,
  delegate,
  trigger,
  debounce,
  throttle
};
