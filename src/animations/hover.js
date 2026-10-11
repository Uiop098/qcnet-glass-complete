/**
 * Hover Effects
 * Reusable hover animation utilities
 */

export function enableHoverLift(element, options = {}) {
  const {
    distance = 6,
    duration = 240,
    shadow = true
  } = options;

  element.style.transition = `transform ${duration}ms ease, box-shadow ${duration}ms ease`;

  const handleMouseEnter = () => {
    element.style.transform = `translateY(-${distance}px)`;
    
    if (shadow) {
      element.style.boxShadow = '0 12px 24px rgba(0, 0, 0, 0.15)';
    }
  };

  const handleMouseLeave = () => {
    element.style.transform = '';
    element.style.boxShadow = '';
  };

  element.addEventListener('mouseenter', handleMouseEnter);
  element.addEventListener('mouseleave', handleMouseLeave);

  return () => {
    element.removeEventListener('mouseenter', handleMouseEnter);
    element.removeEventListener('mouseleave', handleMouseLeave);
  };
}

export function enableHoverScale(element, options = {}) {
  const {
    scale = 1.05,
    duration = 240
  } = options;

  element.style.transition = `transform ${duration}ms ease`;

  const handleMouseEnter = () => {
    element.style.transform = `scale(${scale})`;
  };

  const handleMouseLeave = () => {
    element.style.transform = 'scale(1)';
  };

  element.addEventListener('mouseenter', handleMouseEnter);
  element.addEventListener('mouseleave', handleMouseLeave);

  return () => {
    element.removeEventListener('mouseenter', handleMouseEnter);
    element.removeEventListener('mouseleave', handleMouseLeave);
  };
}

export function enableHoverGlow(element, options = {}) {
  const {
    color = 'rgba(99, 102, 241, 0.5)',
    blur = 30,
    duration = 300
  } = options;

  element.style.transition = `box-shadow ${duration}ms ease`;

  const handleMouseEnter = () => {
    element.style.boxShadow = `0 0 ${blur}px ${color}`;
  };

  const handleMouseLeave = () => {
    element.style.boxShadow = '';
  };

  element.addEventListener('mouseenter', handleMouseEnter);
  element.addEventListener('mouseleave', handleMouseLeave);

  return () => {
    element.removeEventListener('mouseenter', handleMouseEnter);
    element.removeEventListener('mouseleave', handleMouseLeave);
  };
}

export default { enableHoverLift, enableHoverScale, enableHoverGlow };
