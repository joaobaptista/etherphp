/**
 * EncryptedImage.jsx
 * Componente de alta performance para exibição de imagens WebP multi-tier
 * protegidas pelo Cofre Criptografado (AES-256-GCM no servidor).
 *
 * Funcionalidades:
 *  - Transição progressiva com blur-up (Thumb 400px -> Display 1080p).
 *  - Injeção transparente de autorização JWT via query parameter (?token=).
 *  - Badge de segurança "Cofre Protegido".
 *  - Suporte a download Fine-Art 300 DPI (TIFF/WebP Master).
 */
import React, { useState, useMemo } from 'react';
import './EncryptedImage.css';
import { resolveMediaUrl } from '../api/apiUrl.js';
import useVaultMediaSource from '../hooks/useVaultMediaSource.js';
import vaultKeyService from '../services/vaultKeyService.js';
import { isClientSealedMedia, resolveVaultMediaSource } from '../services/vaultMediaResolver.js';

const AUTH_TOKEN_KEY = 'ethernity_auth_token';

export default function EncryptedImage({
  src,
  alt = 'Imagem do Cofre',
  className = '',
  variant = 'display', // 'thumb' | 'display' | 'master'
  enableProgressive = true,
  showSecurityBadge = false,
  allowPrintExport = false,
  dpi = 300,
  token: explicitToken,
  style = {},
  onClick,
}) {
  const [hasError, setHasError] = useState(false);

  const token = useMemo(() => {
    if (explicitToken) return explicitToken;
    try {
      return localStorage.getItem(AUTH_TOKEN_KEY) || '';
    } catch {
      return '';
    }
  }, [explicitToken]);

  // Monta URLs com variante e token de autenticação
  const { thumbUrl, displayUrl, exportUrl, isVaultMedia } = useMemo(() => {
    if (!src) return { thumbUrl: '', displayUrl: '', exportUrl: '', isVaultMedia: false };

    const isVault = src.includes('/vault/media/');
    if (!isVault) {
      return { thumbUrl: src, displayUrl: src, exportUrl: src, isVaultMedia: false };
    }

    const cleanBase = resolveMediaUrl(src.split('?')[0], null);
    const tokenParam = token ? `&token=${encodeURIComponent(token)}` : '';

    return {
      thumbUrl: `${cleanBase}?variant=thumb${tokenParam}`,
      displayUrl: `${cleanBase}?variant=${variant}${tokenParam}`,
      exportUrl: `${cleanBase}/export?format=tiff${tokenParam}`,
      isVaultMedia: true,
    };
  }, [src, variant, token]);

  // Mídias cifradas no aparelho são decifradas aqui; as antigas seguem pela URL do servidor
  const thumb = useVaultMediaSource(isVaultMedia && enableProgressive && variant !== 'thumb' ? thumbUrl : null);
  const display = useVaultMediaSource(isVaultMedia ? displayUrl : null);
  const currentSrc = isVaultMedia ? display.src || thumb.src : src;
  const isDisplayLoaded = !isVaultMedia || Boolean(display.src);
  const isLocked = isVaultMedia && (display.locked || thumb.locked);

  const handleExport = async (e) => {
    e.stopPropagation();
    e.preventDefault();
    const a = document.createElement('a');
    if (await isClientSealedMedia(src)) {
      // Arquivo cifrado no aparelho: o original é decifrado e salvo aqui, sem passar aberto pelo servidor
      const master = await resolveVaultMediaSource(`${resolveMediaUrl(src.split('?')[0], null)}?variant=master`);
      if (!master.src) {
        vaultKeyService.requestUnlock();
        return;
      }
      a.href = master.src;
      a.download = `memoria-ethernity.${(master.mimeType || 'image/jpeg').split('/')[1] || 'jpg'}`;
    } else {
      a.href = exportUrl;
      a.download = 'memoria-ethernity-300dpi.tiff';
    }
    a.click();
  };

  if (isLocked) {
    return (
      <div className={`encrypted-image-container ${className}`} style={style}>
        <button type="button" className="encrypted-image-fallback" onClick={() => vaultKeyService.requestUnlock()}>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg>
          <span>Desbloqueie o cofre para ver</span>
        </button>
      </div>
    );
  }

  if (hasError || (isVaultMedia && display.error && !thumb.src)) {
    return (
      <div className={`encrypted-image-container ${className}`} style={style}>
        <div className="encrypted-image-fallback">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg>
          <span>Arquivo protegido indisponível</span>
        </div>
      </div>
    );
  }

  return (
    <div
      className={`encrypted-image-container ${className}`}
      style={style}
      onClick={onClick}
    >
      {showSecurityBadge && isVaultMedia && (
        <div className="encrypted-image-badge-shield" title="Arquivo guardado cifrado com AES-256-GCM">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
          </svg>
          <span>Cofre Criptografado</span>
        </div>
      )}

      {currentSrc && (
        <img
          src={currentSrc}
          alt={alt}
          className={`encrypted-image ${!isDisplayLoaded && enableProgressive ? 'is-loading-display' : 'is-loaded'}`}
          onError={() => setHasError(true)}
          loading="lazy"
        />
      )}

      {allowPrintExport && isVaultMedia && exportUrl && (
        <a
          href={exportUrl}
          download="memoria-ethernity-300dpi.tiff"
          className="encrypted-image-print-btn"
          title={`Exportar em resolução máxima para impressão (${dpi} DPI)`}
          onClick={handleExport}
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <polyline points="6 9 6 2 18 2 18 9" />
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
            <rect x="6" y="14" width="12" height="8" />
          </svg>
          <span>Imprimir (300 DPI)</span>
        </a>
      )}
    </div>
  );
}
