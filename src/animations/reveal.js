/**
 * Reveal Animation
 * Text and element reveal effects
 */

export function revealText(element, options = {}) {
  const {
    duration = 600,
    stagger = 50,
    animation = 'fadeUp'
  } = options;

  const text = element.textContent;
  const words = text.split(' ');
  
  element.innerHTML = '';
  
  words.forEach((word, index) => {
    const span = document.createElement('span');
    span.textContent = word + ' ';
    span.style.display = 'inline-block';
    span.style.opacity = '0';
    span.style.transform = animation === 'fadeUp' ? 'translateY(20px)' : 'scale(0.8)';
    span.style.transition = `opacity ${duration}ms ease, transform ${duration}ms ease`;
    
    element.appendChild(span);
    
    setTimeout(() => {
      span.style.opacity = '1';
      span.style.transform = 'translateY(0) scale(1)';
    }, index * stagger);
  });
}

export function revealChars(element, options = {}) {
  const {
    duration = 40,
    stagger = 30
  } = options;

  const text = element.textContent;
  const chars = text.split('');
  
  element.innerHTML = '';
  
  chars.forEach((char, index) => {
    const span = document.createElement('span');
    span.textContent = char;
    span.style.opacity = '0';
    span.style.transition = `opacity ${duration}ms ease`;
    
    element.appendChild(span);
    
    setTimeout(() => {
      span.style.opacity = '1';
    }, index * stagger);
  });
}

export default { revealText, revealChars };
