/**
 * vaultMediaResolver.js — Abre no aparelho as mídias do cofre cifradas no aparelho.
 *
 * Mídias antigas (cifradas pelo servidor) seguem pela URL de sempre. Mídias cifradas no aparelho
 * são baixadas como bytes cifrados (com o login no cabeçalho), decifradas aqui e entregues como
 * URL local (blob:). Sem a chave do cofre, nada abre.
 */
import { API_ORIGIN, getStoredAuthToken } from '../api/apiUrl.js';
import vaultKeyService from './vaultKeyService.js';
import { fromBase64 } from './vaultCrypto.js';
import { decryptBytes, unwrapItemKey } from './mediaCipher.js';

const metaCache = new Map();
const sourceCache = new Map();

export function parseVaultMediaUrl(url) {
  const match = String(url || '').match(/\/vault\/media\/(mem-[A-Za-z0-9-]+)(?:\/(stream|poster|live-video|export|waveform))?(?:\?([^#]*))?/);
  if (!match) return null;
  const params = new URLSearchParams(match[3] || '');
  const sub = match[2];
  let variant = null;
  if (sub === 'poster') variant = 'poster';
  else if (sub === 'stream') variant = 'master';
  else if (!sub) variant = ['thumb', 'display', 'master'].includes(params.get('variant')) ? params.get('variant') : 'display';
  return { mediaId: match[1], variant };
}

async function vaultFetch(suffix) {
  const token = getStoredAuthToken();
  const res = await fetch(`${API_ORIGIN}/api/vault/media/${suffix}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  });
  if (!res.ok) throw new Error(`Mídia indisponível (${res.status})`);
  return res;
}

export function getVaultMediaMeta(mediaId) {
  if (!metaCache.has(mediaId)) {
    const pending = vaultFetch(`${mediaId}/meta`)
      .then((res) => res.json())
      .catch((err) => {
        metaCache.delete(mediaId);
        throw err;
      });
    metaCache.set(mediaId, pending);
  }
  return metaCache.get(mediaId);
}

/**
 * Resolve a URL exibível de uma mídia. Retorna { src } ou { locked: true } se o cofre estiver bloqueado.
 */
export async function resolveVaultMediaSource(url) {
  const parsed = parseVaultMediaUrl(url);
  if (!parsed || !parsed.variant) return { src: url };

  const meta = await getVaultMediaMeta(parsed.mediaId);
  if (meta.mode !== 'client') return { src: url };

  const master = vaultKeyService.getMasterKey();
  if (!master) return { locked: true };

  const variant = meta.variants.includes(parsed.variant) ? parsed.variant : 'master';
  const cacheKey = `${parsed.mediaId}:${variant}`;
  if (!sourceCache.has(cacheKey)) {
    const pending = (async () => {
      const suffix =
        variant === 'poster'
          ? `${parsed.mediaId}/poster`
          : variant === 'master' && meta.type !== 'IMAGE'
            ? `${parsed.mediaId}/stream`
            : `${parsed.mediaId}?variant=${variant}`;
      const cipher = await (await vaultFetch(suffix)).arrayBuffer();
      const itemKey = await unwrapItemKey(meta.wrappedKey, master);
      const plain = await decryptBytes(cipher, itemKey, fromBase64(meta.ivs[variant]));
      const type = variant === 'master' ? meta.mimeType : 'image/webp';
      return URL.createObjectURL(new Blob([plain], { type }));
    })().catch((err) => {
      sourceCache.delete(cacheKey);
      throw err;
    });
    sourceCache.set(cacheKey, pending);
  }
  return { src: await sourceCache.get(cacheKey), mimeType: variant === 'master' ? meta.mimeType : 'image/webp' };
}

/** Diz se uma mídia foi cifrada no aparelho (para exportar/baixar aqui em vez do servidor). */
export async function isClientSealedMedia(url) {
  const parsed = parseVaultMediaUrl(url);
  if (!parsed) return false;
  try {
    return (await getVaultMediaMeta(parsed.mediaId)).mode === 'client';
  } catch {
    return false;
  }
}

/** Libera as URLs locais (ex.: ao bloquear o cofre). */
export function clearVaultMediaCache() {
  sourceCache.forEach((pending) => pending.then((u) => URL.revokeObjectURL(u)).catch(() => {}));
  sourceCache.clear();
  metaCache.clear();
}

vaultKeyService.subscribe((status) => {
  if (status === 'locked') clearVaultMediaCache();
});
