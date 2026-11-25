import { useCallback, useEffect, useState } from 'react';

/**
 * Instalação do aplicativo (PWA). `canInstall` é falso quando o site já roda instalado.
 * `install()` abre o pedido do navegador ou devolve a dica de instalação manual (iOS/desktop).
 */
export default function usePwaInstall() {
  const [deferredPrompt, setDeferredPrompt] = useState(null);
  const [isStandalone] = useState(
    () =>
      typeof window !== 'undefined' &&
      ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true)
  );

  useEffect(() => {
    const handleBeforeInstall = (event) => {
      event.preventDefault();
      setDeferredPrompt(event);
    };
    window.addEventListener('beforeinstallprompt', handleBeforeInstall);
    return () => window.removeEventListener('beforeinstallprompt', handleBeforeInstall);
  }, []);

  /** @returns {Promise<'installed' | 'dismissed' | 'ios-hint' | 'desktop-hint'>} */
  const install = useCallback(async () => {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      setDeferredPrompt(null);
      return outcome === 'accepted' ? 'installed' : 'dismissed';
    }
    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    return isIos ? 'ios-hint' : 'desktop-hint';
  }, [deferredPrompt]);

  return { canInstall: !isStandalone, install };
}
