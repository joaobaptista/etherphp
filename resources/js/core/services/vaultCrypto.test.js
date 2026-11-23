import { describe, it, expect } from 'vitest';
import {
  createVaultKeys,
  openWithPassword,
  openWithRecovery,
  buildPasswordPart,
  importMasterKey,
  formatRecoveryCode,
  parseRecoveryCode,
  randomBytes,
} from './vaultCrypto.js';

const toKeys = (payload) => ({
  kdf: payload.kdf,
  passwordWrappedKey: payload.passwordWrappedKey,
  recoverySalt: payload.recoverySalt,
  recoveryWrappedKey: payload.recoveryWrappedKey,
});

async function sameKey(keyA, rawB) {
  const iv = randomBytes(12);
  const data = new TextEncoder().encode('memória secreta');
  const cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, keyA, data);
  const keyB = await importMasterKey(rawB);
  const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, keyB, cipher);
  return new TextDecoder().decode(plain) === 'memória secreta';
}

describe('vaultCrypto — chaves geradas no aparelho', () => {
  it('cria o cofre e o servidor recebe só dados embrulhados', async () => {
    const { payload, recoveryCode, masterKey } = await createVaultKeys('frase longa do cofre');
    expect(JSON.stringify(payload)).not.toContain('frase longa do cofre');
    expect(Object.keys(payload).sort()).toEqual(
      ['kdf', 'passwordVerifier', 'passwordWrappedKey', 'recoverySalt', 'recoveryVerifier', 'recoveryWrappedKey'].sort()
    );
    expect(payload.kdf.iterations).toBeGreaterThanOrEqual(600000);
    expect(recoveryCode.replace(/-/g, '')).toHaveLength(52);
    await expect(crypto.subtle.exportKey('raw', masterKey)).rejects.toThrow();

    const { masterRaw } = await openWithPassword(toKeys(payload), 'frase longa do cofre');
    expect(await sameKey(masterKey, masterRaw)).toBe(true);
  }, 30000);

  it('recusa senha errada', async () => {
    const { payload } = await createVaultKeys('senha correta 123');
    await expect(openWithPassword(toKeys(payload), 'senha errada 123')).rejects.toThrow('Senha do cofre incorreta.');
  }, 30000);

  it('abre com a chave de recuperação e permite definir nova senha com a mesma chave mestra', async () => {
    const { payload, recoveryCode, masterKey } = await createVaultKeys('senha original 123');
    const { masterRaw, proof } = await openWithRecovery(toKeys(payload), recoveryCode.toLowerCase());
    expect(proof.type).toBe('recovery');
    const newPart = await buildPasswordPart(masterRaw, 'senha nova 456789');
    const reopened = await openWithPassword({ ...toKeys(payload), ...newPart }, 'senha nova 456789');
    expect(await sameKey(masterKey, reopened.masterRaw)).toBe(true);
  }, 30000);

  it('codifica e decodifica a chave de recuperação, tolerando confusões comuns', () => {
    const bytes = randomBytes(32);
    const code = formatRecoveryCode(bytes);
    expect(parseRecoveryCode(code)).toEqual(bytes);
    expect(parseRecoveryCode(code.replace(/0/g, 'O').toLowerCase())).toEqual(bytes);
    expect(() => parseRecoveryCode('ABC')).toThrow();
  });
});
