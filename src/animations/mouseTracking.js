/**
 * Mouse Tracking Effect
 * 3D tilt following mouse movement
 */

export function enableMouseTracking(element, options = {}) {
  const {
    maxRotation = 10,
    smoothing = 0.1,
    glowEffect = false,
    glowColor = 'rgba(99, 102, 241, 0.3)'
  } = options;

  let targetRotateX = 0;
  let targetRotateY = 0;
  let currentRotateX = 0;
  let currentRotateY = 0;
  let rafId = null;

  // Set perspective
  element.style.transformStyle = 'preserve-3d';
  element.style.transition = 'box-shadow 300ms ease';

  const animate = () => {
    currentRotateX += (targetRotateX - currentRotateX) * smoothing;
    currentRotateY += (targetRotateY - currentRotateY) * smoothing;

    element.style.transform = `perspective(1000px) rotateX(${currentRotateX}deg) rotateY(${currentRotateY}deg)`;

    if (Math.abs(targetRotateX - currentRotateX) > 0.1 || 
        Math.abs(targetRotateY - currentRotateY) > 0.1) {
      rafId = requestAnimationFrame(animate);
    }
  };

  const handlePointerMove = (e) => {
    const rect = element.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;

    targetRotateX = (0.5 - y) * maxRotation;
    targetRotateY = (x - 0.5) * maxRotation;

    if (glowEffect) {
      const glowX = x * 100;
      const glowY = y * 100;
      element.style.boxShadow = `${(x - 0.5) * 20}px ${(y - 0.5) * 20}px 40px ${glowColor}`;
    }

    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  };

  const handlePointerLeave = () => {
    targetRotateX = 0;
    targetRotateY = 0;
    
    if (glowEffect) {
      element.style.boxShadow = '';
    }

    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  };

  element.addEventListener('pointermove', handlePointerMove);
  element.addEventListener('pointerleave', handlePointerLeave);

  return () => {
    element.removeEventListener('pointermove', handlePointerMove);
    element.removeEventListener('pointerleave', handlePointerLeave);
    if (rafId) {
      cancelAnimationFrame(rafId);
    }
    element.style.transform = '';
    element.style.boxShadow = '';
  };
}

export default { enableMouseTracking };
