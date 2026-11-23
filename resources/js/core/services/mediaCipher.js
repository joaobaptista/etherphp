/**
 * mediaCipher.js — Fotos, vídeos e áudios cifrados no aparelho (fase 3 do conhecimento zero).
 *
 * Cada arquivo tem a própria chave AES-256-GCM, embrulhada pela chave mestra do cofre. O conteúdo é
 * cifrado em blocos de 64 KB (IV do bloco = IV base XOR número do bloco), e as variantes (miniatura,
 * exibição e pôster) são geradas aqui mesmo. O servidor recebe e devolve apenas bytes cifrados.
 */
import { randomBytes, toBase64, fromBase64 } from './vaultCrypto.js';
import { requireSealingKey, sealText, openText } from './fieldCipher.js';
import vaultKeyService from './vaultKeyService.js';

export const CHUNK_SIZE = 65536;
const TAG_BYTES = 16;
const subtle = () => globalThis.crypto.subtle;

function blockIv(baseIv, index) {
  const iv = new Uint8Array(baseIv);
  const view = new DataView(iv.buffer);
  view.setUint32(8, view.getUint32(8) ^ index);
  return iv;
}

export async function encryptBytes(bytes, key, baseIv) {
  const data = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  const blocks = [];
  const total = Math.max(1, Math.ceil(data.length / CHUNK_SIZE));
  for (let i = 0; i < total; i += 1) {
    const chunk = data.subarray(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE);
    blocks.push(new Uint8Array(await subtle().encrypt({ name: 'AES-GCM', iv: blockIv(baseIv, i) }, key, chunk)));
  }
  return new Blob(blocks, { type: 'application/octet-stream' });
}

export async function decryptBytes(cipherBuffer, key, baseIv) {
  const data = new Uint8Array(cipherBuffer);
  const sealedChunk = CHUNK_SIZE + TAG_BYTES;
  const parts = [];
  let length = 0;
  for (let i = 0, offset = 0; offset < data.length; i += 1, offset += sealedChunk) {
    const plain = new Uint8Array(
      await subtle().decrypt({ name: 'AES-GCM', iv: blockIv(baseIv, i) }, key, data.subarray(offset, offset + sealedChunk))
    );
    parts.push(plain);
    length += plain.length;
  }
  const out = new Uint8Array(length);
  let pos = 0;
  parts.forEach((p) => {
    out.set(p, pos);
    pos += p.length;
  });
  return out;
}

async function wrapItemKey(itemKey, master) {
  const iv = randomBytes(12);
  const wrapped = new Uint8Array(await subtle().wrapKey('raw', itemKey, master, { name: 'AES-GCM', iv }));
  const out = new Uint8Array(12 + wrapped.length);
  out.set(iv);
  out.set(wrapped, 12);
  return toBase64(out);
}

export async function unwrapItemKey(wrappedB64, master) {
  const data = fromBase64(wrappedB64);
  return subtle().unwrapKey('raw', data.slice(12), master, { name: 'AES-GCM', iv: data.slice(0, 12) }, { name: 'AES-GCM' }, false, [
    'decrypt',
  ]);
}

// ---------------------------------------------------------------------------
// Variantes geradas no aparelho
// ---------------------------------------------------------------------------
async function resizeImage(blob, maxSide, quality) {
  if (typeof createImageBitmap !== 'function' || typeof document === 'undefined') return null;
  try {
    const bitmap = await createImageBitmap(blob);
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    return await new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/webp', quality));
  } catch {
    return null;
  }
}

async function videoPosterAndDuration(blob) {
  if (typeof document === 'undefined') return { poster: null, durationSeconds: null };
  const url = URL.createObjectURL(blob);
  try {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'auto';
    video.src = url;
    await new Promise((resolve, reject) => {
      video.onloadeddata = resolve;
      video.onerror = reject;
      setTimeout(reject, 8000);
    });
    const duration = Number.isFinite(video.duration) ? video.duration : null;
    video.currentTime = Math.min(0.5, (duration || 1) / 2);
    await new Promise((resolve) => {
      video.onseeked = resolve;
      setTimeout(resolve, 3000);
    });
    const scale = Math.min(1, 1280 / Math.max(video.videoWidth || 1, video.videoHeight || 1));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round((video.videoWidth || 640) * scale));
    canvas.height = Math.max(1, Math.round((video.videoHeight || 360) * scale));
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const poster = await new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/webp', 0.8));
    return { poster, durationSeconds: duration ? Math.round(duration) : null };
  } catch {
    return { poster: null, durationSeconds: null };
  } finally {
    URL.revokeObjectURL(url);
  }
}

async function audioPeaksAndDuration(blob) {
  const AudioCtx = typeof window !== 'undefined' ? window.AudioContext || window.webkitAudioContext : null;
  if (!AudioCtx) return { peaks: null, durationSeconds: null };
  const ctx = new AudioCtx();
  try {
    const buffer = await ctx.decodeAudioData(await blob.arrayBuffer());
    const channel = buffer.getChannelData(0);
    const bucket = Math.max(1, Math.floor(channel.length / 100));
    const peaks = [];
    for (let i = 0; i < 100; i += 1) {
      let max = 0;
      for (let j = i * bucket; j < Math.min(channel.length, (i + 1) * bucket); j += 1) max = Math.max(max, Math.abs(channel[j]));
      peaks.push(Number(max.toFixed(3)));
    }
    return { peaks, durationSeconds: Math.round(buffer.duration) };
  } catch {
    return { peaks: null, durationSeconds: null };
  } finally {
    ctx.close?.();
  }
}

/**
 * Prepara o envio cifrado. Devolve null quando o cofre ainda não foi criado (envio no formato antigo).
 * Lança VaultLockedError se o cofre estiver bloqueado.
 */
export async function buildSealedUpload(fileOrBlob, { title = '', description = '', capsuleId = 'capsule-default', filename } = {}) {
  const master = await requireSealingKey();
  if (!master) return null;

  const mimeType = (fileOrBlob.type || '').toLowerCase() || 'application/octet-stream';
  const kind = mimeType.startsWith('image/') ? 'IMAGE' : mimeType.startsWith('video/') ? 'VIDEO' : mimeType.startsWith('audio/') ? 'AUDIO' : null;
  if (!kind) throw new Error('Tipo de arquivo não suportado. Envie foto, vídeo ou áudio.');

  const variants = { master: fileOrBlob };
  let durationSeconds = null;
  let waveform = null;
  if (kind === 'IMAGE') {
    variants.display = await resizeImage(fileOrBlob, 2048, 0.88);
    variants.thumb = await resizeImage(fileOrBlob, 480, 0.75);
  } else if (kind === 'VIDEO') {
    const info = await videoPosterAndDuration(fileOrBlob);
    variants.poster = info.poster;
    durationSeconds = info.durationSeconds;
  } else {
    const info = await audioPeaksAndDuration(fileOrBlob);
    durationSeconds = info.durationSeconds;
    if (info.peaks) waveform = await sealText(JSON.stringify(info.peaks));
  }

  const itemKey = await subtle().generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
  const form = new FormData();
  const ivs = {};
  for (const [variant, blob] of Object.entries(variants)) {
    if (!blob) continue;
    const baseIv = randomBytes(12);
    ivs[variant] = toBase64(baseIv);
    const sealed = await encryptBytes(new Uint8Array(await blob.arrayBuffer()), itemKey, baseIv);
    form.append(variant === 'master' ? 'file' : variant, sealed, `${variant}.cenc`);
  }
  form.append('clientEncryption', 'v1');
  form.append('kind', kind);
  form.append('mimeType', mimeType);
  form.append('wrappedKey', await wrapItemKey(itemKey, master));
  form.append('ivs', JSON.stringify(ivs));
  form.append('chunkSize', String(CHUNK_SIZE));
  form.append('capsuleId', capsuleId);
  form.append('title', await sealText(title || filename || 'Arquivo'));
  form.append('description', await sealText(description || ''));
  if (durationSeconds) form.append('durationSeconds', String(durationSeconds));
  if (waveform) form.append('waveform', waveform);
  return form;
}

/** Abre a forma de onda cifrada de um áudio (null se o cofre estiver bloqueado). */
export async function openWaveform(sealed) {
  const json = await openText(sealed);
  if (!json) return null;
  try {
    return JSON.parse(json);
  } catch {
    return null;
  }
}

export function isVaultUnlocked() {
  return Boolean(vaultKeyService.getMasterKey());
}
