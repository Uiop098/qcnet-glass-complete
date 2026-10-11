/**
 * Tilt Effect
 * 3D tilt on hover
 */

export function enableTilt(element, options = {}) {
  const {
    maxTilt = 15,
    perspective = 1000,
    scale = 1.05,
    speed = 400,
    glare = false,
    glareMaxOpacity = 0.3
  } = options;

  element.style.transformStyle = 'preserve-3d';
  element.style.transition = `transform ${speed}ms ease-out`;

  let glareElement = null;
  
  if (glare) {
    glareElement = document.createElement('div');
    glareElement.className = 'tilt-glare';
    glareElement.style.cssText = `
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg, rgba(255,255,255,0.5), transparent);
      opacity: 0;
      pointer-events: none;
      transition: opacity ${speed}ms ease-out;
    `;
    element.style.position = element.style.position || 'relative';
    element.style.overflow = 'hidden';
    element.appendChild(glareElement);
  }

  const handleMouseMove = (e) => {
    const rect = element.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;

    const tiltX = (y - 0.5) * maxTilt * -1;
    const tiltY = (x - 0.5) * maxTilt;

    element.style.transform = `
      perspective(${perspective}px)
      rotateX(${tiltX}deg)
      rotateY(${tiltY}deg)
      scale(${scale})
    `;

    if (glareElement) {
      const glareOpacity = Math.max(Math.abs(x - 0.5), Math.abs(y - 0.5)) * glareMaxOpacity;
      glareElement.style.opacity = glareOpacity;
    }
  };

  const handleMouseLeave = () => {
    element.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale(1)';
    
    if (glareElement) {
      glareElement.style.opacity = '0';
    }
  };

  element.addEventListener('mousemove', handleMouseMove);
  element.addEventListener('mouseleave', handleMouseLeave);

  return () => {
    element.removeEventListener('mousemove', handleMouseMove);
    element.removeEventListener('mouseleave', handleMouseLeave);
    element.style.transform = '';
    if (glareElement) {
      glareElement.remove();
    }
  };
}

export default { enableTilt };
