/**
 * Ripple Click Animation
 * Material Design style ripple effect
 */

export function createRipple(element, event) {
  // Skip if reduced motion
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  const ripple = document.createElement('span');
  ripple.className = 'ui-ripple';
  
  const rect = element.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height);
  
  ripple.style.width = `${size}px`;
  ripple.style.height = `${size}px`;
  
  const x = event.clientX - rect.left - size / 2;
  const y = event.clientY - rect.top - size / 2;
  
  ripple.style.left = `${x}px`;
  ripple.style.top = `${y}px`;
  
  element.appendChild(ripple);
  
  // Remove after animation
  setTimeout(() => {
    ripple.remove();
  }, 600);
}

/**
 * Enable ripple on element
 */
export function enableRipple(element, options = {}) {
  const {
    color = 'currentColor',
    opacity = 0.2,
    duration = 600
  } = options;

  // Ensure element has position context
  const position = getComputedStyle(element).position;
  if (position === 'static') {
    element.style.position = 'relative';
  }
  
  // Ensure overflow hidden
  element.style.overflow = 'hidden';

  // Add ripple styles
  const style = document.createElement('style');
  style.textContent = `
    .ui-ripple {
      position: absolute;
      border-radius: 50%;
      pointer-events: none;
      transform: scale(0);
      animation: ripple ${duration}ms ease-out;
      background: ${color};
      opacity: ${opacity};
      z-index: 0;
    }
    
    @keyframes ripple {
      to {
        transform: scale(2);
        opacity: 0;
      }
    }
  `;
  
  if (!document.getElementById('ripple-styles')) {
    style.id = 'ripple-styles';
    document.head.appendChild(style);
  }

  // Add click listener
  const handleClick = (e) => {
    createRipple(element, e);
  };

  element.addEventListener('pointerdown', handleClick);
  
  // Return cleanup function
  return () => {
    element.removeEventListener('pointerdown', handleClick);
  };
}

export default { createRipple, enableRipple };
