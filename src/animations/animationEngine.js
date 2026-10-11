/**
 * Animation Engine
 * Central animation system with RequestAnimationFrame support
 */

export class AnimationEngine {
  constructor() {
    this.animations = new Map();
    this.rafId = null;
    this.isRunning = false;
    this.reducedMotion = false;
    
    this.init();
  }

  /**
   * Initialize animation engine
   */
  init() {
    // Check reduced motion preference
    this.checkReducedMotion();
    
    // Listen for reduced motion changes
    window.matchMedia('(prefers-reduced-motion: reduce)')
      .addEventListener('change', () => this.checkReducedMotion());
  }

  /**
   * Check reduced motion preference
   */
  checkReducedMotion() {
    this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /**
   * Register animation
   */
  register(id, animation) {
    this.animations.set(id, {
      ...animation,
      startTime: performance.now(),
      paused: false
    });
    
    if (!this.isRunning) {
      this.start();
    }
  }

  /**
   * Unregister animation
   */
  unregister(id) {
    this.animations.delete(id);
    
    if (this.animations.size === 0) {
      this.stop();
    }
  }

  /**
   * Start animation loop
   */
  start() {
    if (this.isRunning) return;
    
    this.isRunning = true;
    this.tick();
  }

  /**
   * Stop animation loop
   */
  stop() {
    this.isRunning = false;
    
    if (this.rafId) {
      cancelAnimationFrame(this.rafId);
      this.rafId = null;
    }
  }

  /**
   * Animation loop tick
   */
  tick() {
    if (!this.isRunning) return;
    
    const now = performance.now();
    
    this.animations.forEach((animation, id) => {
      if (animation.paused) return;
      
      const elapsed = now - animation.startTime;
      const progress = Math.min(elapsed / animation.duration, 1);
      
      if (animation.update) {
        animation.update(progress, elapsed);
      }
      
      if (progress >= 1) {
        if (animation.onComplete) {
          animation.onComplete();
        }
        
        if (!animation.loop) {
          this.unregister(id);
        } else {
          animation.startTime = now;
        }
      }
    });
    
    this.rafId = requestAnimationFrame(() => this.tick());
  }

  /**
   * Pause animation
   */
  pause(id) {
    const animation = this.animations.get(id);
    if (animation) {
      animation.paused = true;
    }
  }

  /**
   * Resume animation
   */
  resume(id) {
    const animation = this.animations.get(id);
    if (animation) {
      animation.paused = false;
      animation.startTime = performance.now();
    }
  }

  /**
   * Animate element with options
   */
  animate(element, options) {
    if (this.reducedMotion && !options.respectReducedMotion === false) {
      // Skip animation for reduced motion
      if (options.onComplete) {
        options.onComplete();
      }
      return null;
    }

    const id = `anim_${Date.now()}_${Math.random()}`;
    
    this.register(id, {
      element,
      duration: options.duration || 300,
      loop: options.loop || false,
      update: (progress) => {
        const eased = this.ease(progress, options.easing || 'ease');
        
        if (options.update) {
          options.update(eased, element);
        }
      },
      onComplete: options.onComplete
    });
    
    return id;
  }

  /**
   * Easing functions
   */
  ease(t, type = 'ease') {
    switch (type) {
      case 'linear':
        return t;
      
      case 'easeIn':
        return t * t;
      
      case 'easeOut':
        return t * (2 - t);
      
      case 'easeInOut':
        return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
      
      case 'spring':
        return 1 + Math.sin((t - 1) * Math.PI * 2.5) * Math.exp(-t * 6);
      
      case 'bounce':
        if (t < 1 / 2.75) {
          return 7.5625 * t * t;
        } else if (t < 2 / 2.75) {
          return 7.5625 * (t -= 1.5 / 2.75) * t + 0.75;
        } else if (t < 2.5 / 2.75) {
          return 7.5625 * (t -= 2.25 / 2.75) * t + 0.9375;
        } else {
          return 7.5625 * (t -= 2.625 / 2.75) * t + 0.984375;
        }
      
      default:
        return t;
    }
  }

  /**
   * Clear all animations
   */
  clear() {
    this.animations.clear();
    this.stop();
  }
}

// Singleton instance
export const animationEngine = new AnimationEngine();

export default animationEngine;
