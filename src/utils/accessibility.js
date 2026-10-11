/**
 * Accessibility Utilities
 */

export function trapFocus(element) {
  const focusableElements = element.querySelectorAll(
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
  );
  
  const firstElement = focusableElements[0];
  const lastElement = focusableElements[focusableElements.length - 1];

  const handleKeyDown = (e) => {
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
  };

  element.addEventListener('keydown', handleKeyDown);
  firstElement?.focus();

  return () => {
    element.removeEventListener('keydown', handleKeyDown);
  };
}

export function setAriaExpanded(element, expanded) {
  element.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

export function setAriaHidden(element, hidden) {
  element.setAttribute('aria-hidden', hidden ? 'true' : 'false');
}

export function setAriaLabel(element, label) {
  element.setAttribute('aria-label', label);
}

export function announceToScreenReader(message, priority = 'polite') {
  const announcement = document.createElement('div');
  announcement.setAttribute('role', 'status');
  announcement.setAttribute('aria-live', priority);
  announcement.className = 'sr-only';
  announcement.textContent = message;
  
  document.body.appendChild(announcement);
  
  setTimeout(() => {
    announcement.remove();
  }, 1000);
}

export default {
  trapFocus,
  setAriaExpanded,
  setAriaHidden,
  setAriaLabel,
  announceToScreenReader
};
