/**
 * Magnetic Interaction
 * Element follows pointer with smooth interpolation
 */

export function enableMagnetic(element, options = {}) {
  const {
    strength = 0.3,
    maxDistance = 100,
    smoothing = 0.15,
    returnSpeed = 0.2
  } = options;

  let targetX = 0;
  let targetY = 0;
  let currentX = 0;
  let currentY = 0;
  let rafId = null;
  let isHovering = false;

  const animate = () => {
    // Smooth interpolation
    currentX += (targetX - currentX) * (isHovering ? smoothing : returnSpeed);
    currentY += (targetY - currentY) * (isHovering ? smoothing : returnSpeed);

    // Apply transform
    element.style.transform = `translate(${currentX}px, ${currentY}px)`;

    // Continue if not at rest
    if (Math.abs(targetX - currentX) > 0.1 || Math.abs(targetY - currentY) > 0.1) {
      rafId = requestAnimationFrame(animate);
    }
  };

  const handlePointerMove = (e) => {
    const rect = element.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;

    const deltaX = e.clientX - centerX;
    const deltaY = e.clientY - centerY;
    const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY);

    if (distance < maxDistance) {
      isHovering = true;
      targetX = deltaX * strength;
      targetY = deltaY * strength;
    } else {
      isHovering = false;
      targetX = 0;
      targetY = 0;
    }

    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  };

  const handlePointerLeave = () => {
    isHovering = false;
    targetX = 0;
    targetY = 0;

    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  };

  element.addEventListener('pointermove', handlePointerMove);
  element.addEventListener('pointerleave', handlePointerLeave);

  // Return cleanup
  return () => {
    element.removeEventListener('pointermove', handlePointerMove);
    element.removeEventListener('pointerleave', handlePointerLeave);
    if (rafId) {
      cancelAnimationFrame(rafId);
    }
    element.style.transform = '';
  };
}

export default { enableMagnetic };
