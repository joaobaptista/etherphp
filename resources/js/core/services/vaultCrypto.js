/**
 * vaultCrypto.js — Chaves do cofre de conhecimento zero (Web Crypto API, só no aparelho).
 *
 * - Chave mestra: 256 bits aleatórios gerados aqui. Nunca sai do aparelho em claro.
 * - Senha do cofre -> PBKDF2-SHA-256 (600 mil iterações) -> 512 bits: metade embrulha a chave mestra,
 *   metade é o verificador (o servidor guarda só o hash dele, para autorizar trocas).
 * - Chave de recuperação: 256 bits aleatórios exibidos em base32 -> HKDF -> mesma divisão.
 */

export const KDF_ITERATIONS = 600000;
export const MIN_VAULT_PASSWORD_LENGTH = 10;

const encoder = new TextEncoder();
const WRAP_AAD = encoder.encode('ethernity-vault-master-v1');
const RECOVERY_INFO = encoder.encode('ethernity-vault-recovery-v1');
const BASE32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

const subtle = () => globalThis.crypto.subtle;

export function randomBytes(length) {
  return globalThis.crypto.getRandomValues(new Uint8Array(length));
}

export function toBase64(bytes) {
  let binary = '';
  bytes.forEach((b) => {
    binary += String.fromCharCode(b);
  });
  return btoa(binary);
}

export function fromBase64(value) {
  return Uint8Array.from(atob(value), (c) => c.charCodeAt(0));
}

export function formatRecoveryCode(bytes) {
  let bits = '';
  bytes.forEach((b) => {
    bits += b.toString(2).padStart(8, '0');
  });
  bits = bits.padEnd(Math.ceil(bits.length / 5) * 5, '0');
  let out = '';
  for (let i = 0; i < bits.length; i += 5) out += BASE32[parseInt(bits.slice(i, i + 5), 2)];
  return out.match(/.{1,4}/g).join('-');
}

export function parseRecoveryCode(code) {
  const clean = String(code || '')
    .toUpperCase()
    .replace(/[\s-]/g, '')
    .replace(/[IL]/g, '1')
    .replace(/O/g, '0');
  if (clean.length !== 52) throw new Error('A chave de recuperação deve ter 52 caracteres.');
  let bits = '';
  for (const ch of clean) {
    const value = BASE32.indexOf(ch);
    if (value < 0) throw new Error('A chave de recuperação tem caracteres inválidos.');
    bits += value.toString(2).padStart(5, '0');
  }
  const bytes = new Uint8Array(32);
  for (let i = 0; i < 32; i += 1) bytes[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2);
  return bytes;
}

async function deriveFromPassword(password, salt, iterations) {
  const base = await subtle().importKey('raw', encoder.encode(String(password).normalize('NFKC')), 'PBKDF2', false, ['deriveBits']);
  const bits = new Uint8Array(await subtle().deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, base, 512));
  return { kek: bits.slice(0, 32), verifier: bits.slice(32) };
}

async function deriveFromRecovery(recoveryBytes, salt) {
  const base = await subtle().importKey('raw', recoveryBytes, 'HKDF', false, ['deriveBits']);
  const bits = new Uint8Array(
    await subtle().deriveBits({ name: 'HKDF', hash: 'SHA-256', salt, info: RECOVERY_INFO }, base, 512)
  );
  return { kek: bits.slice(0, 32), verifier: bits.slice(32) };
}

async function wrapMaster(kekBytes, masterRaw) {
  const kek = await subtle().importKey('raw', kekBytes, 'AES-GCM', false, ['encrypt']);
  const iv = randomBytes(12);
  const cipher = new Uint8Array(await subtle().encrypt({ name: 'AES-GCM', iv, additionalData: WRAP_AAD }, kek, masterRaw));
  const out = new Uint8Array(iv.length + cipher.length);
  out.set(iv);
  out.set(cipher, iv.length);
  return toBase64(out);
}

async function unwrapMaster(kekBytes, wrapped) {
  const data = fromBase64(wrapped);
  const kek = await subtle().importKey('raw', kekBytes, 'AES-GCM', false, ['decrypt']);
  return new Uint8Array(
    await subtle().decrypt({ name: 'AES-GCM', iv: data.slice(0, 12), additionalData: WRAP_AAD }, kek, data.slice(12))
  );
}

export function importMasterKey(raw) {
  return subtle().importKey('raw', raw, 'AES-GCM', false, ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']);
}

export async function buildPasswordPart(masterRaw, password) {
  const salt = randomBytes(16);
  const { kek, verifier } = await deriveFromPassword(password, salt, KDF_ITERATIONS);
  return {
    kdf: { iterations: KDF_ITERATIONS, salt: toBase64(salt) },
    passwordWrappedKey: await wrapMaster(kek, masterRaw),
    passwordVerifier: toBase64(verifier),
  };
}

export async function buildRecoveryPart(masterRaw) {
  const recovery = randomBytes(32);
  const salt = randomBytes(16);
  const { kek, verifier } = await deriveFromRecovery(recovery, salt);
  return {
    recoveryCode: formatRecoveryCode(recovery),
    part: {
      recoverySalt: toBase64(salt),
      recoveryWrappedKey: await wrapMaster(kek, masterRaw),
      recoveryVerifier: toBase64(verifier),
    },
  };
}

/** Cria o cofre: gera a chave mestra e as duas cópias embrulhadas. */
export async function createVaultKeys(password) {
  const masterRaw = randomBytes(32);
  const passwordPart = await buildPasswordPart(masterRaw, password);
  const { recoveryCode, part } = await buildRecoveryPart(masterRaw);
  const masterKey = await importMasterKey(masterRaw);
  masterRaw.fill(0);
  return { payload: { ...passwordPart, ...part }, recoveryCode, masterKey };
}

/** Abre a chave mestra com a senha do cofre. Lança erro se a senha estiver errada. */
export async function openWithPassword(keys, password) {
  const { kek, verifier } = await deriveFromPassword(password, fromBase64(keys.kdf.salt), keys.kdf.iterations);
  try {
    const masterRaw = await unwrapMaster(kek, keys.passwordWrappedKey);
    return { masterRaw, proof: { type: 'password', verifier: toBase64(verifier) } };
  } catch {
    throw new Error('Senha do cofre incorreta.');
  }
}

/** Abre a chave mestra com a chave de recuperação. */
export async function openWithRecovery(keys, recoveryCode) {
  const recovery = parseRecoveryCode(recoveryCode);
  const { kek, verifier } = await deriveFromRecovery(recovery, fromBase64(keys.recoverySalt));
  try {
    const masterRaw = await unwrapMaster(kek, keys.recoveryWrappedKey);
    return { masterRaw, proof: { type: 'recovery', verifier: toBase64(verifier) } };
  } catch {
    throw new Error('Chave de recuperação incorreta.');
  }
}
