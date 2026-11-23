import { describe, it, expect } from 'vitest';
import { encryptBytes, decryptBytes, unwrapItemKey, CHUNK_SIZE } from './mediaCipher.js';
import { randomBytes, importMasterKey, toBase64 } from './vaultCrypto.js';
import { parseVaultMediaUrl } from './vaultMediaResolver.js';

function bigRandom(length) {
  const out = new Uint8Array(length);
  for (let i = 0; i < length; i += 65536) out.set(randomBytes(Math.min(65536, length - i)), i);
  return out;
}

const newKey = () => crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);

describe('mediaCipher — arquivos cifrados no aparelho', () => {
  it('cifra e decifra arquivos de vários blocos sem perder bytes', async () => {
    const key = await newKey();
    const iv = randomBytes(12);
    const original = bigRandom(CHUNK_SIZE * 2 + 1234);
    const sealed = await encryptBytes(original, key, iv);
    expect(sealed.size).toBe(original.length + 3 * 16);
    const back = await decryptBytes(await sealed.arrayBuffer(), key, iv);
    expect(back).toEqual(original);
  });

  it('chave errada ou bloco adulterado não abre', async () => {
    const key = await newKey();
    const iv = randomBytes(12);
    const sealed = new Uint8Array(await (await encryptBytes(randomBytes(5000), key, iv)).arrayBuffer());
    await expect(decryptBytes(sealed.buffer, await newKey(), iv)).rejects.toThrow();
    sealed[10] ^= 0xff;
    await expect(decryptBytes(sealed.buffer, key, iv)).rejects.toThrow();
  });

  it('a chave do arquivo só sai embrulhada pela chave mestra', async () => {
    const master = await importMasterKey(randomBytes(32));
    const itemKey = await newKey();
    const iv = randomBytes(12);
    const wrapped = new Uint8Array(await crypto.subtle.wrapKey('raw', itemKey, master, { name: 'AES-GCM', iv }));
    const packed = new Uint8Array(12 + wrapped.length);
    packed.set(iv);
    packed.set(wrapped, 12);
    const opened = await unwrapItemKey(toBase64(packed), master);
    const data = randomBytes(100);
    const sealed = await encryptBytes(data, itemKey, iv);
    expect(await decryptBytes(await sealed.arrayBuffer(), opened, iv)).toEqual(data);
    await expect(unwrapItemKey(toBase64(packed), await importMasterKey(randomBytes(32)))).rejects.toThrow();
  });

  it('entende as URLs de mídia do cofre', () => {
    expect(parseVaultMediaUrl('/api/vault/media/mem-ab12?variant=thumb&token=x')).toEqual({ mediaId: 'mem-ab12', variant: 'thumb' });
    expect(parseVaultMediaUrl('http://h/api/vault/media/mem-ab12/stream?token=x')).toEqual({ mediaId: 'mem-ab12', variant: 'master' });
    expect(parseVaultMediaUrl('/api/vault/media/mem-ab12/poster')).toEqual({ mediaId: 'mem-ab12', variant: 'poster' });
    expect(parseVaultMediaUrl('/api/vault/media/mem-ab12')).toEqual({ mediaId: 'mem-ab12', variant: 'display' });
    expect(parseVaultMediaUrl('blob:abc')).toBeNull();
  });
});
