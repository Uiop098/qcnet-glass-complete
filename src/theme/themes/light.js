/**
 * Light Theme
 */

import { colors } from '../tokens/colors.js';

export const lightTheme = {
  name: 'light',
  
  colors: {
    // Primary
    primary: colors.primary[600],
    primaryHover: colors.primary[700],
    primaryActive: colors.primary[800],
    primaryMuted: colors.primary[100],
    
    // Secondary
    secondary: colors.secondary[600],
    secondaryHover: colors.secondary[700],
    secondaryActive: colors.secondary[800],
    
    // Backgrounds
    background: colors.white,
    surface: colors.neutral[50],
    surfaceElevated: colors.white,
    surfaceHover: colors.neutral[100],
    
    // Text
    textPrimary: colors.neutral[900],
    textSecondary: colors.neutral[600],
    textMuted: colors.neutral[500],
    textInverse: colors.white,
    
    // Borders
    border: colors.neutral[200],
    borderHover: colors.neutral[300],
    borderFocus: colors.primary[400],
    
    // Semantic
    success: colors.success[500],
    successMuted: colors.success[50],
    warning: colors.warning[500],
    warningMuted: colors.warning[50],
    danger: colors.danger[500],
    dangerMuted: colors.danger[50],
    info: colors.info[500],
    infoMuted: colors.info[50],
    
    // Overlays
    overlay: 'rgba(0, 0, 0, 0.5)',
    overlayLight: 'rgba(0, 0, 0, 0.2)',
    overlayHeavy: 'rgba(0, 0, 0, 0.8)',
    
    // Glass
    glass: 'rgba(255, 255, 255, 0.08)',
    glassBorder: 'rgba(255, 255, 255, 0.12)'
  }
};

export default lightTheme;
