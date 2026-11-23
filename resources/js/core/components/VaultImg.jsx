import React from 'react';
import useVaultMediaSource from '../hooks/useVaultMediaSource.js';
import vaultKeyService from '../services/vaultKeyService.js';

/**
 * <img> que abre no aparelho as fotos do cofre cifradas no aparelho. Outras URLs passam direto.
 */
export default function VaultImg({ src, alt = '', className = '', ...rest }) {
  const isVault = typeof src === 'string' && src.includes('/vault/media/');
  const resolved = useVaultMediaSource(isVault ? src : null);

  if (isVault && resolved.locked) {
    return (
      <span
        role="button"
        tabIndex={0}
        className={`${className} vault-img-locked`}
        title="Desbloqueie o cofre para ver"
        onClick={(e) => {
          e.stopPropagation();
          vaultKeyService.requestUnlock();
        }}
        onKeyDown={(e) => e.key === 'Enter' && vaultKeyService.requestUnlock()}
      >
        <i className="fa-solid fa-lock" aria-hidden="true"></i>
      </span>
    );
  }
  const finalSrc = isVault ? resolved.src : src;
  if (!finalSrc) return <span className={`${className} vault-img-loading`} aria-hidden="true" />;
  return <img src={finalSrc} alt={alt} className={className} {...rest} />;
}
