import { useEffect, useState } from 'react';
import vaultKeyService from '../services/vaultKeyService.js';
import { resolveVaultMediaSource } from '../services/vaultMediaResolver.js';

/**
 * Devolve a URL exibível de uma mídia do cofre: a própria URL (mídia antiga) ou uma URL local
 * com o arquivo decifrado no aparelho. `locked` indica que o cofre precisa ser desbloqueado.
 */
export default function useVaultMediaSource(url) {
  const [state, setState] = useState({ src: null, locked: false, error: false, url: null });

  useEffect(() => {
    if (!url) return undefined;
    let active = true;
    const run = () =>
      resolveVaultMediaSource(url)
        .then((result) => active && setState({ src: result.src || null, locked: Boolean(result.locked), error: false, url }))
        .catch(() => active && setState({ src: null, locked: false, error: true, url }));
    run();
    const unsubscribe = vaultKeyService.subscribe((status) => {
      if (status === 'unlocked' || status === 'locked') run();
    });
    return () => {
      active = false;
      unsubscribe();
    };
  }, [url]);

  if (!url) return { src: null, locked: false, error: false };
  return state.url === url ? state : { src: null, locked: false, error: false };
}
