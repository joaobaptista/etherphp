/**
 * vaultKeyService.js — Sessão do cofre de conhecimento zero.
 *
 * A chave mestra fica só na memória como CryptoKey não extraível. Com "lembrar neste aparelho",
 * ela é guardada no IndexedDB ainda não extraível (o navegador não permite exportá-la) e vinculada
 * à conta; o logout e a troca de usuário apagam esse banco (localDataGuard).
 */
import { backgroundRequest, getStoredAuthToken } from '../api/apiUrl.js';
import { getTokenOwner } from './localDataGuard.js';
import {
  MIN_VAULT_PASSWORD_LENGTH,
  createVaultKeys,
  openWithPassword,
  openWithRecovery,
  buildPasswordPart,
  buildRecoveryPart,
  importMasterKey,
} from './vaultCrypto.js';

export const SESSION_DB = 'Ethernity_Vault_Session';
const SESSION_STORE = 'keys';

function openSessionDb() {
  return new Promise((resolve, reject) => {
    if (typeof indexedDB === 'undefined') {
      reject(new Error('IndexedDB indisponível'));
      return;
    }
    const req = indexedDB.open(SESSION_DB, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(SESSION_STORE);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function sessionOp(mode, action) {
  try {
    const db = await openSessionDb();
    return await new Promise((resolve) => {
      const tx = db.transaction(SESSION_STORE, mode);
      const req = action(tx.objectStore(SESSION_STORE));
      tx.oncomplete = () => {
        db.close();
        resolve(req?.result ?? null);
      };
      tx.onerror = () => {
        db.close();
        resolve(null);
      };
    });
  } catch {
    return null;
  }
}

function validatePassword(password) {
  if (String(password || '').length < MIN_VAULT_PASSWORD_LENGTH) {
    throw new Error(`A senha do cofre precisa ter pelo menos ${MIN_VAULT_PASSWORD_LENGTH} caracteres.`);
  }
}

class VaultKeyService {
  constructor() {
    this.status = 'unknown'; // 'unknown' | 'not_configured' | 'locked' | 'unlocked' | 'error'
    this.keys = null;
    this.masterKey = null;
    this.listeners = new Set();
    this.unlockRequestListeners = new Set();
    this._refreshing = null;
  }

  /** Garante que o estado do cofre foi consultado antes de cifrar ou decifrar. */
  async ensureReady() {
    if (this.status === 'unknown' || this.status === 'error') {
      if (!this._refreshing) this._refreshing = this.refresh().finally(() => (this._refreshing = null));
      await this._refreshing;
    }
    return this.status;
  }

  /** Pede à interface que abra a tela de desbloqueio. */
  requestUnlock() {
    this.unlockRequestListeners.forEach((fn) => {
      try {
        fn();
      } catch {
        // ignore
      }
    });
  }

  onUnlockRequest(listener) {
    this.unlockRequestListeners.add(listener);
    return () => this.unlockRequestListeners.delete(listener);
  }

  subscribe(listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  _setStatus(status) {
    this.status = status;
    this.listeners.forEach((fn) => {
      try {
        fn(status);
      } catch {
        // ignore
      }
    });
  }

  _owner() {
    return getTokenOwner(getStoredAuthToken());
  }

  getMasterKey() {
    return this.masterKey;
  }

  async refresh() {
    try {
      const keys = await backgroundRequest('GET', '/vault/keys');
      if (!keys?.configured) {
        this.keys = null;
        this.masterKey = null;
        this._setStatus('not_configured');
        return this.status;
      }
      this.keys = keys;
      if (!this.masterKey) {
        const remembered = await sessionOp('readonly', (store) => store.get(this._owner()));
        if (remembered instanceof CryptoKey) this.masterKey = remembered;
      }
      this._setStatus(this.masterKey ? 'unlocked' : 'locked');
    } catch {
      this._setStatus('error');
    }
    return this.status;
  }

  async _activate(masterRaw, remember) {
    this.masterKey = await importMasterKey(masterRaw);
    masterRaw.fill(0);
    if (remember) {
      await sessionOp('readwrite', (store) => store.put(this.masterKey, this._owner()));
    }
    this._setStatus('unlocked');
  }

  /** Cria o cofre e devolve a chave de recuperação, que deve ser mostrada uma única vez. */
  async setup(password, { remember = false } = {}) {
    validatePassword(password);
    const { payload, recoveryCode, masterKey } = await createVaultKeys(password);
    this.keys = await backgroundRequest('POST', '/vault/keys', payload);
    this.masterKey = masterKey;
    if (remember) await sessionOp('readwrite', (store) => store.put(masterKey, this._owner()));
    this._setStatus('unlocked');
    return recoveryCode;
  }

  async unlock(password, { remember = false } = {}) {
    if (!this.keys) await this.refresh();
    const { masterRaw } = await openWithPassword(this.keys, password);
    await this._activate(masterRaw, remember);
  }

  async changePassword(currentPassword, newPassword) {
    validatePassword(newPassword);
    const { masterRaw, proof } = await openWithPassword(this.keys, currentPassword);
    const part = await buildPasswordPart(masterRaw, newPassword);
    this.keys = await backgroundRequest('PUT', '/vault/keys', { proof, ...part });
    await this._activate(masterRaw, false);
  }

  /** Esqueceu a senha: abre com a chave de recuperação e define uma senha nova. */
  async recover(recoveryCode, newPassword, { remember = false } = {}) {
    validatePassword(newPassword);
    if (!this.keys) await this.refresh();
    const { masterRaw, proof } = await openWithRecovery(this.keys, recoveryCode);
    const part = await buildPasswordPart(masterRaw, newPassword);
    this.keys = await backgroundRequest('PUT', '/vault/keys', { proof, ...part });
    await this._activate(masterRaw, remember);
  }

  /** Gera uma chave de recuperação nova; a anterior deixa de funcionar. */
  async regenerateRecovery(password) {
    const { masterRaw, proof } = await openWithPassword(this.keys, password);
    const { recoveryCode, part } = await buildRecoveryPart(masterRaw);
    masterRaw.fill(0);
    this.keys = await backgroundRequest('PUT', '/vault/keys', { proof, ...part });
    return recoveryCode;
  }

  async lock() {
    this.masterKey = null;
    await sessionOp('readwrite', (store) => store.clear());
    this._setStatus(this.keys ? 'locked' : this.status);
  }
}

const vaultKeyService = new VaultKeyService();
export default vaultKeyService;
