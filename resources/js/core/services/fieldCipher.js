/**
 * fieldCipher.js — Textos cifrados no aparelho com a chave do cofre (fase 2 do conhecimento zero).
 *
 * Formato: enc:v1:<chave do item embrulhada pela chave mestra>:<iv>:<texto cifrado>, tudo em base64.
 * Cada texto tem a própria chave AES-256-GCM, para poder ser compartilhado na entrega sem expor o resto.
 * Sem cofre criado, o texto segue como antes (a migração é a fase 4). Com o cofre bloqueado, gravar
 * exige desbloquear: nada sai do aparelho em texto aberto.
 */
import vaultKeyService from './vaultKeyService.js';
import { randomBytes, toBase64, fromBase64 } from './vaultCrypto.js';

export const SEALED_PREFIX = 'enc:v1:';
export const LOCKED_TEXT = 'Conteúdo protegido — desbloqueie o cofre';

const encoder = new TextEncoder();
const decoder = new TextDecoder();
const subtle = () => globalThis.crypto.subtle;

export class VaultLockedError extends Error {
  constructor(message = 'Desbloqueie o cofre para salvar este conteúdo.') {
    super(message);
    this.name = 'VaultLockedError';
  }
}

export function isSealed(value) {
  return typeof value === 'string' && value.startsWith(SEALED_PREFIX);
}

/** Diz se gravações devem ser cifradas agora (cofre criado). Lança se estiver bloqueado. */
export async function requireSealingKey() {
  const status = await vaultKeyService.ensureReady();
  const master = vaultKeyService.getMasterKey();
  if (master) return master;
  if (status === 'not_configured') return null;
  vaultKeyService.requestUnlock();
  throw new VaultLockedError();
}

export async function sealText(value) {
  if (value === null || value === undefined || value === '' || isSealed(value)) return value;
  const master = await requireSealingKey();
  if (!master) return value;

  const dek = await subtle().generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
  const wrapIv = randomBytes(12);
  const wrapped = new Uint8Array(await subtle().wrapKey('raw', dek, master, { name: 'AES-GCM', iv: wrapIv }));
  const iv = randomBytes(12);
  const cipher = new Uint8Array(await subtle().encrypt({ name: 'AES-GCM', iv }, dek, encoder.encode(String(value))));
  const wrappedWithIv = new Uint8Array(wrapIv.length + wrapped.length);
  wrappedWithIv.set(wrapIv);
  wrappedWithIv.set(wrapped, wrapIv.length);
  return `${SEALED_PREFIX}${toBase64(wrappedWithIv)}:${toBase64(iv)}:${toBase64(cipher)}`;
}

/** Abre um texto cifrado. Devolve null se o cofre estiver bloqueado ou o texto não abrir. */
export async function openText(value) {
  if (!isSealed(value)) return value;
  await vaultKeyService.ensureReady();
  const master = vaultKeyService.getMasterKey();
  if (!master) return null;
  try {
    const [wrappedB64, ivB64, cipherB64] = value.slice(SEALED_PREFIX.length).split(':');
    const wrappedWithIv = fromBase64(wrappedB64);
    const dek = await subtle().unwrapKey(
      'raw',
      wrappedWithIv.slice(12),
      master,
      { name: 'AES-GCM', iv: wrappedWithIv.slice(0, 12) },
      { name: 'AES-GCM' },
      false,
      ['decrypt']
    );
    const plain = await subtle().decrypt({ name: 'AES-GCM', iv: fromBase64(ivB64) }, dek, fromBase64(cipherB64));
    return decoder.decode(plain);
  } catch {
    return null;
  }
}

/** Abre o texto ou devolve um aviso legível quando o cofre está bloqueado. */
export async function openTextOr(value, fallback = LOCKED_TEXT) {
  const opened = await openText(value);
  return opened === null ? fallback : opened;
}
