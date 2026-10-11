/**
 * Custom Cursor Effect
 * Optional custom cursor with following dot/ring
 */

export function enableCustomCursor(options = {}) {
  const {
    dotSize = 8,
    ringSize = 40,
    dotColor = '#6366f1',
    ringColor = '#6366f1',
    ringOpacity = 0.3,
    smoothing = 0.15
  } = options;

  // Skip on touch devices
  if (window.matchMedia('(hover: none)').matches) {
    return () => {};
  }

  // Create cursor elements
  const dot = document.createElement('div');
  dot.className = 'custom-cursor-dot';
  dot.style.cssText = `
    position: fixed;
    width: ${dotSize}px;
    height: ${dotSize}px;
    background: ${dotColor};
    border-radius: 50%;
    pointer-events: none;
    z-index: 10000;
    mix-blend-mode: difference;
    transform: translate(-50%, -50%);
  `;

  const ring = document.createElement('div');
  ring.className = 'custom-cursor-ring';
  ring.style.cssText = `
    position: fixed;
    width: ${ringSize}px;
    height: ${ringSize}px;
    border: 2px solid ${ringColor};
    border-radius: 50%;
    pointer-events: none;
    z-index: 9999;
    opacity: ${ringOpacity};
    transform: translate(-50%, -50%);
    transition: width 200ms ease, height 200ms ease, opacity 200ms ease;
  `;

  document.body.appendChild(dot);
  document.body.appendChild(ring);

  // Hide default cursor
  document.body.style.cursor = 'none';

  let mouseX = 0;
  let mouseY = 0;
  let dotX = 0;
  let dotY = 0;
  let ringX = 0;
  let ringY = 0;
  let rafId = null;

  const animate = () => {
    // Dot follows immediately
    dotX = mouseX;
    dotY = mouseY;
    dot.style.left = `${dotX}px`;
    dot.style.top = `${dotY}px`;

    // Ring follows with smoothing
    ringX += (mouseX - ringX) * smoothing;
    ringY += (mouseY - ringY) * smoothing;
    ring.style.left = `${ringX}px`;
    ring.style.top = `${ringY}px`;

    rafId = requestAnimationFrame(animate);
  };

  const handleMouseMove = (e) => {
    mouseX = e.clientX;
    mouseY = e.clientY;

    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  };

  const handleMouseDown = () => {
    ring.style.width = `${ringSize * 0.8}px`;
    ring.style.height = `${ringSize * 0.8}px`;
  };

  const handleMouseUp = () => {
    ring.style.width = `${ringSize}px`;
    ring.style.height = `${ringSize}px`;
  };

  // Expand on interactive elements
  const handleMouseEnter = () => {
    ring.style.width = `${ringSize * 1.5}px`;
    ring.style.height = `${ringSize * 1.5}px`;
    ring.style.opacity = '0.5';
  };

  const handleMouseLeave = () => {
    ring.style.width = `${ringSize}px`;
    ring.style.height = `${ringSize}px`;
    ring.style.opacity = `${ringOpacity}`;
  };

  document.addEventListener('mousemove', handleMouseMove);
  document.addEventListener('mousedown', handleMouseDown);
  document.addEventListener('mouseup', handleMouseUp);

  // Add listeners to interactive elements
  const interactiveElements = 'a, button, input, textarea, select, [role="button"]';
  document.querySelectorAll(interactiveElements).forEach(el => {
    el.addEventListener('mouseenter', handleMouseEnter);
    el.addEventListener('mouseleave', handleMouseLeave);
  });

  // Start animation
  rafId = requestAnimationFrame(animate);

  // Cleanup
  return () => {
    document.removeEventListener('mousemove', handleMouseMove);
    document.removeEventListener('mousedown', handleMouseDown);
    document.removeEventListener('mouseup', handleMouseUp);
    
    if (rafId) {
      cancelAnimationFrame(rafId);
    }
    
    dot.remove();
    ring.remove();
    document.body.style.cursor = '';
  };
}

export default { enableCustomCursor };
