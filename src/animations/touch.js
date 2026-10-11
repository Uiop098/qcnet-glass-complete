/**
 * Touch Interaction Effects
 * Enhanced touch feedback for mobile
 */

export function enableTouchEffects(element, options = {}) {
  const {
    scaleDown = 0.96,
    duration = 150,
    haptic = false
  } = options;

  let originalTransform = '';
  let isPressed = false;

  const handleTouchStart = (e) => {
    if (isPressed) return;
    
    isPressed = true;
    originalTransform = element.style.transform || '';
    
    element.style.transition = `transform ${duration}ms ease-out`;
    element.style.transform = `${originalTransform} scale(${scaleDown})`;
    
    // Haptic feedback if supported
    if (haptic && navigator.vibrate) {
      navigator.vibrate(10);
    }
    
    element.classList.add('is-pressed');
  };

  const handleTouchEnd = () => {
    if (!isPressed) return;
    
    isPressed = false;
    element.style.transform = originalTransform;
    element.classList.remove('is-pressed');
  };

  element.addEventListener('touchstart', handleTouchStart, { passive: true });
  element.addEventListener('touchend', handleTouchEnd, { passive: true });
  element.addEventListener('touchcancel', handleTouchEnd, { passive: true });

  return () => {
    element.removeEventListener('touchstart', handleTouchStart);
    element.removeEventListener('touchend', handleTouchEnd);
    element.removeEventListener('touchcancel', handleTouchEnd);
    element.style.transform = originalTransform;
    element.classList.remove('is-pressed');
  };
}

/**
 * Swipe detection
 */
export function enableSwipe(element, callbacks = {}) {
  let touchStartX = 0;
  let touchStartY = 0;
  let touchEndX = 0;
  let touchEndY = 0;
  
  const minSwipeDistance = 50;

  const handleTouchStart = (e) => {
    touchStartX = e.changedTouches[0].screenX;
    touchStartY = e.changedTouches[0].screenY;
  };

  const handleTouchEnd = (e) => {
    touchEndX = e.changedTouches[0].screenX;
    touchEndY = e.changedTouches[0].screenY;
    
    handleSwipe();
  };

  const handleSwipe = () => {
    const deltaX = touchEndX - touchStartX;
    const deltaY = touchEndY - touchStartY;
    
    if (Math.abs(deltaX) > Math.abs(deltaY)) {
      // Horizontal swipe
      if (Math.abs(deltaX) > minSwipeDistance) {
        if (deltaX > 0 && callbacks.onSwipeRight) {
          callbacks.onSwipeRight();
        } else if (deltaX < 0 && callbacks.onSwipeLeft) {
          callbacks.onSwipeLeft();
        }
      }
    } else {
      // Vertical swipe
      if (Math.abs(deltaY) > minSwipeDistance) {
        if (deltaY > 0 && callbacks.onSwipeDown) {
          callbacks.onSwipeDown();
        } else if (deltaY < 0 && callbacks.onSwipeUp) {
          callbacks.onSwipeUp();
        }
      }
    }
  };

  element.addEventListener('touchstart', handleTouchStart, { passive: true });
  element.addEventListener('touchend', handleTouchEnd, { passive: true });

  return () => {
    element.removeEventListener('touchstart', handleTouchStart);
    element.removeEventListener('touchend', handleTouchEnd);
  };
}

export default { enableTouchEffects, enableSwipe };
