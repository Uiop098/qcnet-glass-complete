/**
 * Parallax Effect
 * Scroll-based parallax animation
 */

export function enableParallax(element, options = {}) {
  const {
    speed = 0.5,
    direction = 'vertical',
    offset = 0
  } = options;

  let rafId = null;
  let lastScrollY = window.scrollY;

  const update = () => {
    const scrollY = window.scrollY;
    const rect = element.getBoundingClientRect();
    const elementTop = rect.top + scrollY;
    
    // Calculate parallax offset
    const distance = scrollY - elementTop + offset;
    const parallaxOffset = distance * speed;

    if (direction === 'vertical') {
      element.style.transform = `translateY(${parallaxOffset}px)`;
    } else {
      element.style.transform = `translateX(${parallaxOffset}px)`;
    }
  };

  const handleScroll = () => {
    if (!rafId) {
      rafId = requestAnimationFrame(() => {
        update();
        rafId = null;
      });
    }
  };

  // Initial update
  update();

  window.addEventListener('scroll', handleScroll, { passive: true });

  return () => {
    window.removeEventListener('scroll', handleScroll);
    if (rafId) {
      cancelAnimationFrame(rafId);
    }
    element.style.transform = '';
  };
}

/**
 * Mouse parallax effect
 */
export function enableMouseParallax(container, layers, options = {}) {
  const { strength = 20 } = options;

  const handleMouseMove = (e) => {
    const rect = container.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width - 0.5;
    const y = (e.clientY - rect.top) / rect.height - 0.5;

    layers.forEach((layer, index) => {
      const depth = (index + 1) * strength;
      const moveX = x * depth;
      const moveY = y * depth;
      
      layer.style.transform = `translate(${moveX}px, ${moveY}px)`;
    });
  };

  const handleMouseLeave = () => {
    layers.forEach(layer => {
      layer.style.transform = 'translate(0, 0)';
    });
  };

  container.addEventListener('mousemove', handleMouseMove);
  container.addEventListener('mouseleave', handleMouseLeave);

  return () => {
    container.removeEventListener('mousemove', handleMouseMove);
    container.removeEventListener('mouseleave', handleMouseLeave);
  };
}

export default { enableParallax, enableMouseParallax };
