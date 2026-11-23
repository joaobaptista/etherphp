/**
 * authRepository.js — Repositório de Infraestrutura do Domínio Auth
 */
import httpClient from '../../../core/api/httpClient.js';

export const authRepository = {
  /**
   * Realiza login do usuário
   * @param {string} email
   * @param {string} password
   * @returns {Promise<{ access_token: string, token_type: string, user: object }>}
   */
  async login(email, password) {
    return httpClient.post('/login', { email, password });
  },

  /**
   * Cadastra novo usuário familiar
   * @param {object} userData
   */
  async register(userData) {
    return httpClient.post('/register', userData);
  },

  /**
   * Obtém URL de autorização oficial do Google OAuth 2.0 (Gmail Login / Cadastro)
   * @returns {Promise<{ url: string }>}
   */
  async getGoogleAuthUrl() {
    return httpClient.get('/auth/google/url');
  },
};

/**
 * Lê o retorno do login com Google. O token vem no fragmento (#token=...),
 * que o navegador não envia ao servidor; a query (?token=) fica por compatibilidade.
 * @returns {{ token: string|null, rawUser: string|null, error: string|null }}
 */
export function readGoogleAuthCallback(location = window.location) {
  const hash = new URLSearchParams(String(location.hash || '').replace(/^#/, ''));
  const query = new URLSearchParams(location.search || '');
  return {
    token: hash.get('token') || query.get('token'),
    rawUser: hash.get('user') || query.get('user'),
    error: query.get('error') || hash.get('error'),
  };
}

/** Remove token, usuário e erro do endereço, sem recarregar a página. */
export function clearGoogleAuthCallback() {
  window.history.replaceState({}, document.title, window.location.pathname);
}

export default authRepository;
