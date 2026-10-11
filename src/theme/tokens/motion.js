/**
 * Motion Design Tokens
 * Duration and easing functions for animations
 */

export const motion = {
  // Duration
  duration: {
    instant: '0ms',
    fast: '120ms',
    normal: '240ms',
    slow: '450ms',
    slower: '600ms',
    slowest: '900ms'
  },

  // Easing functions
  easing: {
    // Standard easing
    linear: 'linear',
    ease: 'ease',
    easeIn: 'ease-in',
    easeOut: 'ease-out',
    easeInOut: 'ease-in-out',
    
    // Custom cubic-bezier
    standard: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
    spring: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
    elastic: 'cubic-bezier(0.68, -0.55, 0.265, 1.55)',
    bounce: 'cubic-bezier(0.175, 0.885, 0.32, 1.275)',
    smooth: 'cubic-bezier(0.4, 0, 0.2, 1)',
    snappy: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
    
    // Material Design
    emphasized: 'cubic-bezier(0.4, 0, 0.2, 1)',
    decelerate: 'cubic-bezier(0, 0, 0.2, 1)',
    accelerate: 'cubic-bezier(0.4, 0, 1, 1)'
  }
};

export default motion;
