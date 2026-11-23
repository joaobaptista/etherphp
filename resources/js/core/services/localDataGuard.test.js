import { describe, it, expect, beforeEach } from 'vitest';
import { getTokenOwner, ensureLocalDataOwner, wipeLocalUserData } from './localDataGuard.js';

const tokenFor = (email) => `h.${btoa(JSON.stringify({ sub: email })).replace(/=+$/, '')}.s`;

function memoryStorage() {
  const data = new Map();
  return {
    get length() {
      return data.size;
    },
    key: (i) => [...data.keys()][i] ?? null,
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    removeItem: (k) => data.delete(k),
    clear: () => data.clear(),
  };
}

describe('localDataGuard — isolamento do cache entre contas', () => {
  beforeEach(() => {
    globalThis.localStorage = memoryStorage();
    globalThis.sessionStorage = memoryStorage();
  });

  it('identifica o dono pelo token', () => {
    expect(getTokenOwner(tokenFor('Ana@Exemplo.com'))).toBe('ana@exemplo.com');
    expect(getTokenOwner('inválido')).toBeNull();
  });

  it('expurga cápsulas e rascunhos de outra conta e mantém só preferências do aparelho', async () => {
    localStorage.setItem('ethernity_data_owner', 'dono@anterior.com');
    localStorage.setItem('ethernity_user_capsules', '[{"id":"cap-de-outro"}]');
    localStorage.setItem('ethernity_message_draft_general', '{"title":"segredo"}');
    localStorage.setItem('ethernity_user', '{"email":"dono@anterior.com"}');
    localStorage.setItem('ethernity_theme', 'dark');
    sessionStorage.setItem('ethernity_active_photos_for_capsules', '[1]');
    const novoToken = tokenFor('novo@usuario.com');
    localStorage.setItem('ethernity_auth_token', novoToken);

    expect(await ensureLocalDataOwner(novoToken)).toBe(true);

    expect(localStorage.getItem('ethernity_user_capsules')).toBeNull();
    expect(localStorage.getItem('ethernity_message_draft_general')).toBeNull();
    expect(localStorage.getItem('ethernity_user')).toBeNull();
    expect(sessionStorage.getItem('ethernity_active_photos_for_capsules')).toBeNull();
    expect(localStorage.getItem('ethernity_theme')).toBe('dark');
    expect(localStorage.getItem('ethernity_auth_token')).toBe(novoToken);
    expect(localStorage.getItem('ethernity_data_owner')).toBe('novo@usuario.com');
  });

  it('cache sem dono conhecido também é expurgado', async () => {
    localStorage.setItem('ethernity_user_capsules', '[{"id":"cap-sem-dono"}]');
    expect(await ensureLocalDataOwner(tokenFor('a@b.com'))).toBe(true);
    expect(localStorage.getItem('ethernity_user_capsules')).toBeNull();
  });

  it('não apaga nada quando a conta é a mesma', async () => {
    localStorage.setItem('ethernity_data_owner', 'a@b.com');
    localStorage.setItem('ethernity_user_capsules', '[{"id":"minha"}]');
    expect(await ensureLocalDataOwner(tokenFor('a@b.com'))).toBe(false);
    expect(localStorage.getItem('ethernity_user_capsules')).toBe('[{"id":"minha"}]');
  });

  it('logout remove tudo da conta, inclusive o token', async () => {
    localStorage.setItem('ethernity_auth_token', 'x');
    localStorage.setItem('ethernity_data_owner', 'a@b.com');
    localStorage.setItem('ethernity_user_capsules', '[]');
    await wipeLocalUserData();
    expect(localStorage.getItem('ethernity_auth_token')).toBeNull();
    expect(localStorage.getItem('ethernity_data_owner')).toBeNull();
    expect(localStorage.getItem('ethernity_user_capsules')).toBeNull();
  });
});
