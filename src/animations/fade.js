/**
 * Fade Animations
 */

export function fadeIn(element, options = {}) {
  const {
    duration = 300,
    easing = 'ease',
    delay = 0,
    onComplete
  } = options;

  element.style.opacity = '0';
  element.style.transition = `opacity ${duration}ms ${easing} ${delay}ms`;
  
  requestAnimationFrame(() => {
    element.style.opacity = '1';
  });

  if (onComplete) {
    setTimeout(onComplete, duration + delay);
  }
}

export function fadeOut(element, options = {}) {
  const {
    duration = 300,
    easing = 'ease',
    delay = 0,
    onComplete
  } = options;

  element.style.transition = `opacity ${duration}ms ${easing} ${delay}ms`;
  element.style.opacity = '0';

  if (onComplete) {
    setTimeout(onComplete, duration + delay);
  }
}

export function fadeToggle(element, options = {}) {
  const isVisible = element.style.opacity !== '0';
  
  if (isVisible) {
    fadeOut(element, options);
  } else {
    fadeIn(element, options);
  }
}

export default { fadeIn, fadeOut, fadeToggle };
