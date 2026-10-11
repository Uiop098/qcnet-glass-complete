/**
 * Scale Animations
 */

export function scaleIn(element, options = {}) {
  const {
    from = 0.95,
    duration = 300,
    easing = 'ease',
    delay = 0,
    onComplete
  } = options;

  element.style.opacity = '0';
  element.style.transform = `scale(${from})`;
  element.style.transition = `opacity ${duration}ms ${easing} ${delay}ms, transform ${duration}ms ${easing} ${delay}ms`;
  
  requestAnimationFrame(() => {
    element.style.opacity = '1';
    element.style.transform = 'scale(1)';
  });

  if (onComplete) {
    setTimeout(onComplete, duration + delay);
  }
}

export function scaleOut(element, options = {}) {
  const {
    to = 0.95,
    duration = 300,
    easing = 'ease',
    delay = 0,
    onComplete
  } = options;

  element.style.transition = `opacity ${duration}ms ${easing} ${delay}ms, transform ${duration}ms ${easing} ${delay}ms`;
  element.style.opacity = '0';
  element.style.transform = `scale(${to})`;

  if (onComplete) {
    setTimeout(onComplete, duration + delay);
  }
}

export function pulse(element, options = {}) {
  const {
    scale = 1.05,
    duration = 600,
    iterations = 1
  } = options;

  element.style.animation = `pulse ${duration}ms ease-in-out ${iterations}`;
  
  // Add keyframes if not exists
  if (!document.getElementById('pulse-keyframes')) {
    const style = document.createElement('style');
    style.id = 'pulse-keyframes';
    style.textContent = `
      @keyframes pulse {
        0%, 100% {
          transform: scale(1);
        }
        50% {
          transform: scale(${scale});
        }
      }
    `;
    document.head.appendChild(style);
  }
}

export default { scaleIn, scaleOut, pulse };
