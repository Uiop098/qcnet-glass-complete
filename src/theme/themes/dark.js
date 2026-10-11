/**
 * Dark Theme
 */

import { colors } from '../tokens/colors.js';

export const darkTheme = {
  name: 'dark',
  
  colors: {
    // Primary
    primary: colors.primary[500],
    primaryHover: colors.primary[400],
    primaryActive: colors.primary[300],
    primaryMuted: colors.primary[950],
    
    // Secondary
    secondary: colors.secondary[500],
    secondaryHover: colors.secondary[400],
    secondaryActive: colors.secondary[300],
    
    // Backgrounds
    background: colors.neutral[950],
    surface: colors.neutral[900],
    surfaceElevated: colors.neutral[800],
    surfaceHover: colors.neutral[800],
    
    // Text
    textPrimary: colors.neutral[50],
    textSecondary: colors.neutral[400],
    textMuted: colors.neutral[500],
    textInverse: colors.neutral[950],
    
    // Borders
    border: colors.neutral[800],
    borderHover: colors.neutral[700],
    borderFocus: colors.primary[500],
    
    // Semantic
    success: colors.success[500],
    successMuted: colors.success[950],
    warning: colors.warning[500],
    warningMuted: colors.warning[950],
    danger: colors.danger[500],
    dangerMuted: colors.danger[950],
    info: colors.info[500],
    infoMuted: colors.info[950],
    
    // Overlays
    overlay: 'rgba(0, 0, 0, 0.7)',
    overlayLight: 'rgba(0, 0, 0, 0.4)',
    overlayHeavy: 'rgba(0, 0, 0, 0.9)',
    
    // Glass
    glass: 'rgba(255, 255, 255, 0.08)',
    glassBorder: 'rgba(255, 255, 255, 0.12)'
  }
};

export default darkTheme;
