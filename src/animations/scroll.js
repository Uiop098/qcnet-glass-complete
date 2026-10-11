/**
 * Scroll Reveal Animation
 * Animate elements when they enter viewport
 */

export function enableScrollReveal(elements, options = {}) {
  const {
    threshold = 0.1,
    rootMargin = '0px',
    once = true,
    animation = 'fadeUp',
    delay = 0,
    stagger = 0
  } = options;

  const animations = {
    fadeUp: { opacity: 0, transform: 'translateY(30px)' },
    fadeDown: { opacity: 0, transform: 'translateY(-30px)' },
    fadeLeft: { opacity: 0, transform: 'translateX(30px)' },
    fadeRight: { opacity: 0, transform: 'translateX(-30px)' },
    scale: { opacity: 0, transform: 'scale(0.95)' },
    zoom: { opacity: 0, transform: 'scale(1.1)' }
  };

  const observerOptions = {
    threshold,
    rootMargin
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const element = entry.target;
        const elementDelay = parseInt(element.dataset.revealDelay || delay);
        
        setTimeout(() => {
          element.classList.add('is-visible');
        }, elementDelay);

        if (once) {
          observer.unobserve(element);
        }
      } else if (!once) {
        entry.target.classList.remove('is-visible');
      }
    });
  }, observerOptions);

  // Convert to array if NodeList
  const elementsArray = Array.isArray(elements) ? elements : Array.from(elements);

  elementsArray.forEach((element, index) => {
    // Set initial state
    const animStyle = animations[animation] || animations.fadeUp;
    Object.assign(element.style, animStyle);
    element.style.transition = 'opacity 600ms ease, transform 600ms ease';

    // Set stagger delay
    if (stagger > 0) {
      element.dataset.revealDelay = index * stagger;
    }

    observer.observe(element);
  });

  // Add styles for visible state
  if (!document.getElementById('scroll-reveal-styles')) {
    const style = document.createElement('style');
    style.id = 'scroll-reveal-styles';
    style.textContent = `
      .is-visible {
        opacity: 1 !important;
        transform: translate(0, 0) scale(1) !important;
      }
      
      @media (prefers-reduced-motion: reduce) {
        [data-reveal] {
          opacity: 1 !important;
          transform: none !important;
        }
      }
    `;
    document.head.appendChild(style);
  }

  return () => {
    observer.disconnect();
  };
}

export default { enableScrollReveal };
