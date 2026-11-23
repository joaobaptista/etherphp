/**
 * localDataGuard.js — Isolamento do cache local entre contas no mesmo navegador.
 *
 * Cápsulas, fotos, rascunhos e mídias ficam no localStorage/IndexedDB para uso offline.
 * Esse cache pertence a uma única conta: ao trocar de usuário ou sair, ele é expurgado
 * antes que qualquer tela o leia, e a página recarrega para descartar caches em memória.
 */

const OWNER_KEY = 'ethernity_data_owner';
const AUTH_TOKEN_KEY = 'ethernity_auth_token';
const DEVICE_PREFERENCE_KEYS = new Set([
  'ethernity_theme',
  'ethernity_preferred_language',
  'ethernity_local_ai_quality',
  'ethernity_local_ai_enabled',
]);
const USER_DATABASES = ['Ethernity_Capsules_Media', 'Ethernity_DMS_Local_Vault', 'Ethernity_Vault_Session'];

export function getTokenOwner(token) {
  try {
    const payload = String(token || '').split('.')[1];
    if (!payload) return null;
    const json = atob(payload.replace(/-/g, '+').replace(/_/g, '/'));
    const sub = JSON.parse(decodeURIComponent(escape(json))).sub;
    return sub ? String(sub).trim().toLowerCase() : null;
  } catch {
    return null;
  }
}

function clearStore(store, keep) {
  if (!store) return;
  const keys = [];
  for (let i = 0; i < store.length; i += 1) keys.push(store.key(i));
  keys
    .filter((key) => key && key.startsWith('ethernity_') && !keep.has(key))
    .forEach((key) => store.removeItem(key));
}

function deleteDatabase(name) {
  return new Promise((resolve) => {
    try {
      const request = indexedDB.deleteDatabase(name);
      request.onsuccess = () => resolve();
      request.onerror = () => resolve();
      request.onblocked = () => resolve();
    } catch {
      resolve();
    }
  });
}

/**
 * Remove todos os dados da conta guardados neste navegador (mantém só preferências do aparelho).
 */
export async function wipeLocalUserData({ keepAuthToken = false } = {}) {
  const keep = new Set(DEVICE_PREFERENCE_KEYS);
  if (keepAuthToken) keep.add(AUTH_TOKEN_KEY);
  try {
    clearStore(typeof localStorage !== 'undefined' ? localStorage : null, keep);
    clearStore(typeof sessionStorage !== 'undefined' ? sessionStorage : null, keep);
  } catch {
    // armazenamento indisponível (modo privado)
  }
  if (typeof indexedDB !== 'undefined') {
    await Promise.all(USER_DATABASES.map(deleteDatabase));
  }
}

/**
 * Garante que o cache local pertence à conta do token. Se pertencer a outra conta (ou a
 * ninguém conhecido), expurga tudo antes do uso. Retorna true quando houve expurgo.
 */
export async function ensureLocalDataOwner(token) {
  const owner = getTokenOwner(token);
  if (!owner) return false;
  let current = null;
  try {
    current = localStorage.getItem(OWNER_KEY);
  } catch {
    return false;
  }
  if (current === owner) return false;
  await wipeLocalUserData({ keepAuthToken: true });
  try {
    localStorage.setItem(OWNER_KEY, owner);
  } catch {
    // ignore
  }
  return true;
}
