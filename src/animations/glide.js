/**
 * Glide Animation
 * Smooth position interpolation
 */

export function enableGlide(element, options = {}) {
  const {
    smoothing = 0.15,
    threshold = 0.1
  } = options;

  let targetX = 0;
  let targetY = 0;
  let currentX = 0;
  let currentY = 0;
  let rafId = null;

  const animate = () => {
    currentX += (targetX - currentX) * smoothing;
    currentY += (targetY - currentY) * smoothing;

    element.style.transform = `translate(${currentX}px, ${currentY}px)`;

    if (Math.abs(targetX - currentX) > threshold || 
        Math.abs(targetY - currentY) > threshold) {
      rafId = requestAnimationFrame(animate);
    } else {
      rafId = null;
    }
  };

  const moveTo = (x, y) => {
    targetX = x;
    targetY = y;

    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  };

  const stop = () => {
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
  };

  return { moveTo, stop };
}

export default { enableGlide };
