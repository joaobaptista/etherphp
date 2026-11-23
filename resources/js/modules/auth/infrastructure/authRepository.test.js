import { describe, it, expect } from 'vitest';
import { readGoogleAuthCallback } from './authRepository.js';

describe('readGoogleAuthCallback', () => {
  it('lê token e usuário do fragmento (#), formato atual do backend', () => {
    const user = JSON.stringify({ email: 'ana@test.com', name: 'Ana 100%' });
    const result = readGoogleAuthCallback({
      search: '',
      hash: `#token=${encodeURIComponent('jwt.abc')}&user=${encodeURIComponent(user)}`,
    });
    expect(result.token).toBe('jwt.abc');
    expect(JSON.parse(result.rawUser)).toEqual({ email: 'ana@test.com', name: 'Ana 100%' });
    expect(result.error).toBeNull();
  });

  it('aceita a query (?token=) do formato antigo', () => {
    const result = readGoogleAuthCallback({ search: '?token=jwt.old', hash: '' });
    expect(result.token).toBe('jwt.old');
  });

  it('lê a mensagem de erro já decodificada', () => {
    const result = readGoogleAuthCallback({
      search: `?error=${encodeURIComponent('E-mail não verificado')}`,
      hash: '',
    });
    expect(result.token).toBeNull();
    expect(result.error).toBe('E-mail não verificado');
  });

  it('devolve tudo vazio fora do retorno do Google', () => {
    expect(readGoogleAuthCallback({ search: '', hash: '' })).toEqual({ token: null, rawUser: null, error: null });
  });
});
