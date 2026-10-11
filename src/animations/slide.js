/**
 * Slide Animations
 */

export function slideIn(element, options = {}) {
  const {
    direction = 'up',
    distance = 30,
    duration = 300,
    easing = 'ease',
    delay = 0,
    onComplete
  } = options;

  const transforms = {
    up: `translateY(${distance}px)`,
    down: `translateY(-${distance}px)`,
    left: `translateX(${distance}px)`,
    right: `translateX(-${distance}px)`
  };

  element.style.opacity = '0';
  element.style.transform = transforms[direction];
  element.style.transition = `opacity ${duration}ms ${easing} ${delay}ms, transform ${duration}ms ${easing} ${delay}ms`;
  
  requestAnimationFrame(() => {
    element.style.opacity = '1';
    element.style.transform = 'translate(0, 0)';
  });

  if (onComplete) {
    setTimeout(onComplete, duration + delay);
  }
}

export function slideOut(element, options = {}) {
  const {
    direction = 'down',
    distance = 30,
    duration = 300,
    easing = 'ease',
    delay = 0,
    onComplete
  } = options;

  const transforms = {
    up: `translateY(-${distance}px)`,
    down: `translateY(${distance}px)`,
    left: `translateX(-${distance}px)`,
    right: `translateX(${distance}px)`
  };

  element.style.transition = `opacity ${duration}ms ${easing} ${delay}ms, transform ${duration}ms ${easing} ${delay}ms`;
  element.style.opacity = '0';
  element.style.transform = transforms[direction];

  if (onComplete) {
    setTimeout(onComplete, duration + delay);
  }
}

export default { slideIn, slideOut };
