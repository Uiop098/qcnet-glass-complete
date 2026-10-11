/**
 * Device Detection Utilities
 */

export function isMobile() {
  return window.matchMedia('(max-width: 768px)').matches;
}

export function isTablet() {
  return window.matchMedia('(min-width: 769px) and (max-width: 1024px)').matches;
}

export function isDesktop() {
  return window.matchMedia('(min-width: 1025px)').matches;
}

export function isTouchDevice() {
  return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
}

export function hasHover() {
  return window.matchMedia('(hover: hover)').matches;
}

export function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function prefersDarkMode() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function getViewportSize() {
  return {
    width: window.innerWidth,
    height: window.innerHeight
  };
}

export function getDeviceType() {
  if (isMobile()) return 'mobile';
  if (isTablet()) return 'tablet';
  return 'desktop';
}

export function isRetina() {
  return window.devicePixelRatio > 1;
}

export default {
  isMobile,
  isTablet,
  isDesktop,
  isTouchDevice,
  hasHover,
  prefersReducedMotion,
  prefersDarkMode,
  getViewportSize,
  getDeviceType,
  isRetina
};
