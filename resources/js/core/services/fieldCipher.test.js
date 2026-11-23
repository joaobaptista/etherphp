import { describe, it, expect, beforeEach } from 'vitest';
import vaultKeyService from './vaultKeyService.js';
import { sealText, openText, openTextOr, isSealed, VaultLockedError, LOCKED_TEXT } from './fieldCipher.js';
import { importMasterKey, randomBytes } from './vaultCrypto.js';

describe('fieldCipher — textos cifrados no aparelho', () => {
  beforeEach(() => {
    vaultKeyService.masterKey = null;
    vaultKeyService.status = 'unknown';
  });

  it('sem cofre criado, o texto segue como antes', async () => {
    vaultKeyService.status = 'not_configured';
    expect(await sealText('Carta para Ana')).toBe('Carta para Ana');
  });

  it('com o cofre aberto, cifra com chave própria por item e abre de volta', async () => {
    vaultKeyService.status = 'unlocked';
    vaultKeyService.masterKey = await importMasterKey(randomBytes(32));
    const a = await sealText('Carta para Ana');
    const b = await sealText('Carta para Ana');
    expect(isSealed(a)).toBe(true);
    expect(a).not.toContain('Ana');
    expect(a).not.toBe(b);
    expect(await openText(a)).toBe('Carta para Ana');
  });

  it('com o cofre bloqueado, não grava e pede desbloqueio; leitura mostra aviso', async () => {
    vaultKeyService.status = 'unlocked';
    vaultKeyService.masterKey = await importMasterKey(randomBytes(32));
    const sealed = await sealText('segredo');

    vaultKeyService.masterKey = null;
    vaultKeyService.status = 'locked';
    let asked = false;
    const off = vaultKeyService.onUnlockRequest(() => {
      asked = true;
    });
    await expect(sealText('novo texto')).rejects.toBeInstanceOf(VaultLockedError);
    expect(asked).toBe(true);
    off();
    expect(await openText(sealed)).toBeNull();
    expect(await openTextOr(sealed)).toBe(LOCKED_TEXT);
  });

  it('chave de outra conta não abre o texto', async () => {
    vaultKeyService.status = 'unlocked';
    vaultKeyService.masterKey = await importMasterKey(randomBytes(32));
    const sealed = await sealText('só meu');
    vaultKeyService.masterKey = await importMasterKey(randomBytes(32));
    expect(await openText(sealed)).toBeNull();
  });
});
