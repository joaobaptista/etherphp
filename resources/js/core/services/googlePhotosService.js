/**
 * googlePhotosService.js
 * Comprehensive Google Photos integration service.
 * Supports Google OAuth 2.0 / Google Photos Picker API sessions,
 * selective photo import, AES-256 encrypted vault persistence, and event notifications.
 */
import { resolveMediaUrl } from '../api/apiUrl.js';

const STORAGE_KEY = 'ethernity_google_photos_connection';
const CLIENT_ID_KEY = 'ethernity_google_client_id';
const CACHED_PHOTOS_KEY = 'ethernity_google_cached_photos';

// Origem da API sem o sufixo /api (VITE_API_URL costuma terminar em /api e as
// rotas abaixo já incluem o prefixo). Sem URLs localhost fixas em produção.
const API_ORIGIN = ((typeof import.meta !== 'undefined' && import.meta.env?.VITE_API_URL) || '')
  .replace(/\/api\/?$/, '')
  .replace(/\/$/, '');

const GOOGLE_SCOPES = [
  'https://www.googleapis.com/auth/photoslibrary.readonly',
  'https://www.googleapis.com/auth/photospicker.mediaitems.readonly',
  'https://www.googleapis.com/auth/userinfo.email',
  'https://www.googleapis.com/auth/userinfo.profile',
  'openid',
].join(' ');

// High-fidelity curated dataset for fallback/simulation and preview
export const DEFAULT_GOOGLE_PHOTOS = [
  {
    id: 'gp-photo-1',
    title: 'Viagem em Família — Serra da Mantiqueira',
    description: 'Passeio inesquecível com as crianças e piquenique ao pôr do sol.',
    category: 'family',
    categoryLabel: 'Família & Filhos',
    date: '15 de Julho, 2024',
    timestamp: '2024-07-15T16:30:00Z',
    location: 'Campos do Jordão, SP',
    dimensions: { width: 3840, height: 2160 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['família', 'filhos', 'natureza', 'viagem'],
  },
  {
    id: 'gp-photo-2',
    title: 'Primeiros Passos da Beatriz',
    description: 'Momento mágico na sala de estar dando os primeiros passinhos sozinha.',
    category: 'special',
    categoryLabel: 'Momentos Especiais',
    date: '22 de Outubro, 2023',
    timestamp: '2023-10-22T10:15:00Z',
    location: 'Casa da Família, São Paulo',
    dimensions: { width: 3000, height: 2000 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['bebê', 'filha', 'infância', 'amor'],
  },
  {
    id: 'gp-photo-3',
    title: 'Aniversário de 5 Anos do Lucas',
    description: 'Comemoração dos 5 aninhos com todos os primos e avós reunidos.',
    category: 'family',
    categoryLabel: 'Família & Filhos',
    date: '04 de Abril, 2024',
    timestamp: '2024-04-04T18:00:00Z',
    location: 'Salão de Festas, SP',
    dimensions: { width: 3200, height: 2400 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['aniversário', 'festa', 'alegria', 'primos'],
  },
  {
    id: 'gp-photo-4',
    title: 'Férias de Verão na Praia com os Avós',
    description: 'Construindo castelos de areia e banho de mar na praia de Juquehy.',
    category: 'travel',
    categoryLabel: 'Viagens',
    date: '12 de Janeiro, 2025',
    timestamp: '2025-01-12T11:45:00Z',
    location: 'Praia de Juquehy, Litoral Norte',
    dimensions: { width: 4000, height: 2250 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['praia', 'avós', 'férias', 'mar'],
  },
  {
    id: 'gp-photo-5',
    title: 'Casamento — Votos e Declaração de Amor',
    description: 'O dia em que selamos nossa união perante todos os nossos entes queridos.',
    category: 'special',
    categoryLabel: 'Momentos Especiais',
    date: '18 de Novembro, 2017',
    timestamp: '2017-11-18T19:30:00Z',
    location: 'Igreja Matriz, Gramado - RS',
    dimensions: { width: 3600, height: 2400 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['casamento', 'amor', 'aliança', 'votos'],
  },
  {
    id: 'gp-photo-6',
    title: 'Carta Manuscrita do Vovô Antônio (1985)',
    description: 'Documento histórico e conselhos de vida guardados com carinho.',
    category: 'documents',
    categoryLabel: 'Documentos Históricos',
    date: '10 de Setembro, 1985',
    timestamp: '1985-09-10T14:00:00Z',
    location: 'Acervo de Família',
    dimensions: { width: 2400, height: 3200 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['carta', 'documento', 'vovô', 'história'],
  },
  {
    id: 'gp-photo-7',
    title: 'Formatura no Ensino Fundamental',
    description: 'Orgulho imenso vendo nosso pequeno receber seu diploma.',
    category: 'special',
    categoryLabel: 'Momentos Especiais',
    date: '08 de Dezembro, 2025',
    timestamp: '2025-12-08T20:00:00Z',
    location: 'Teatro Municipal',
    dimensions: { width: 3500, height: 2333 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['formatura', 'estudos', 'conquista'],
  },
  {
    id: 'gp-photo-8',
    title: 'Almoço de Domingo com a Família Toda',
    description: 'Mesa farta na casa dos avós, risadas e a receita secreta da nonna.',
    category: 'family',
    categoryLabel: 'Família & Filhos',
    date: '20 de Fevereiro, 2026',
    timestamp: '2026-02-20T13:30:00Z',
    location: 'Casa da Vó, SP',
    dimensions: { width: 3800, height: 2533 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['almoço', 'domingo', 'família', 'reunião'],
  },
  {
    id: 'gp-photo-rock-1',
    title: 'Show The Rolling Stones — Turnê Histórica',
    description: 'Palco iluminado, solo de guitarra inesquecível e energia eletrizante no estádio com 70 mil pessoas.',
    category: 'special',
    categoryLabel: 'Shows & Música',
    date: '14 de Outubro, 2023',
    timestamp: '2023-10-14T21:30:00Z',
    location: 'Allianz Parque, São Paulo',
    dimensions: { width: 3840, height: 2160 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['show de rock', 'rock', 'concerto', 'música', 'festival', 'palco', 'guitarra', 'rolling stones'],
  },
  {
    id: 'gp-photo-rock-2',
    title: 'Rock in Rio — Palco Mundo ao Pôr do Sol',
    description: 'Multidão cantando junto, clássicos do rock e o melhor fim de semana do ano com os amigos.',
    category: 'special',
    categoryLabel: 'Shows & Música',
    date: '08 de Setembro, 2024',
    timestamp: '2024-09-08T19:00:00Z',
    location: 'Cidade do Rock, Rio de Janeiro',
    dimensions: { width: 4000, height: 2500 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['show de rock', 'rock in rio', 'festival', 'rock', 'banda', 'música', 'multidão'],
  },
  {
    id: 'gp-photo-rock-3',
    title: 'Show Foo Fighters — Solo de Guitarra na Chuva',
    description: 'Gritos da plateia, riffs pesados e uma noite inesquecível gravada na memória.',
    category: 'special',
    categoryLabel: 'Shows & Música',
    date: '19 de Maio, 2022',
    timestamp: '2022-05-19T22:15:00Z',
    location: 'Estádio do Morumbi, SP',
    dimensions: { width: 3600, height: 2400 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['show de rock', 'foo fighters', 'rock', 'guitarra', 'palco', 'música'],
  },
  {
    id: 'gp-photo-rock-4',
    title: 'Paul McCartney — Got Back Tour',
    description: 'Hey Jude cantada por 60 mil vozes juntas, lembrança inesquecível da vida toda.',
    category: 'special',
    categoryLabel: 'Shows & Música',
    date: '09 de Dezembro, 2023',
    timestamp: '2023-12-09T21:00:00Z',
    location: 'Maracanã, RJ',
    dimensions: { width: 3800, height: 2400 },
    thumbnailUrl: null,
    fullUrl: null,
    tags: ['show de rock', 'paul mccartney', 'rock', 'beatles', 'estádio', 'música'],
  },
];

/**
 * Fotos importadas do Google ficam no cofre cifrado (/api/vault/media/{id}), que exige login.
 * Uma <img> não envia o cabeçalho Authorization, então a URL leva o token (?token=).
 * thumbnailUrl = miniatura leve para a grade; fullUrl = versão de exibição para ampliar.
 */
function withVaultImageUrls(memory) {
  const mediaPath = memory.mediaUrl || memory.media_url || '';
  if (!mediaPath.includes('/vault/media/')) return memory;
  const base = mediaPath.split('?')[0];
  return {
    ...memory,
    thumbnailUrl: resolveMediaUrl(`${base}?variant=thumb`),
    fullUrl: resolveMediaUrl(`${base}?variant=display`),
  };
}

class GooglePhotosService {
  constructor() {
    this.listeners = new Set();
    this.gsiLoadedPromise = null;
    this.cachedLivePhotos = [];
    this.nextPageToken = null;
  }

  /**
   * Subscribe to connection changes
   */
  subscribe(callback) {
    this.listeners.add(callback);
    return () => this.listeners.delete(callback);
  }

  _notify(data) {
    this.listeners.forEach((cb) => {
      try {
        cb(data);
      } catch (err) {
        console.error('[GooglePhotosService] listener error:', err);
      }
    });

    if (typeof window !== 'undefined') {
      window.dispatchEvent(
        new CustomEvent('ethernity_google_photos_updated', { detail: data })
      );
    }
  }

  /**
   * Get Google Cloud OAuth Client ID
   */
  getClientId() {
    if (typeof this._explicitClientId === 'string') {
      return this._explicitClientId;
    }
    try {
      if (typeof localStorage !== 'undefined') {
        const stored = localStorage.getItem(CLIENT_ID_KEY);
        if (stored) return stored.trim();
      }
      if (typeof import.meta !== 'undefined' && import.meta.env?.VITE_GOOGLE_CLIENT_ID) {
        const envVal = import.meta.env.VITE_GOOGLE_CLIENT_ID.trim();
        if (envVal) return envVal;
      }
    } catch {
      // ignore
    }
    return '';
  }

  /**
   * Set or update Google Cloud Client ID
   */
  setClientId(clientId) {
    const cleaned = (clientId || '').trim();
    this._explicitClientId = cleaned;
    try {
      if (cleaned) {
        localStorage.setItem(CLIENT_ID_KEY, cleaned);
      } else {
        localStorage.removeItem(CLIENT_ID_KEY);
      }
    } catch (e) {
      console.warn('[GooglePhotosService] error setting Client ID:', e);
    }
    return cleaned;
  }

  /**
   * Has configured Client ID?
   */
  hasConfiguredClientId() {
    return Boolean(this.getClientId());
  }

  /**
   * Get current connection status from storage
   */
  getConnectionStatus() {
    try {
      if (typeof localStorage !== 'undefined') {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
          return JSON.parse(raw);
        }
      }
    } catch (e) {
      console.error('[GooglePhotosService] error reading connection status:', e);
    }

    return {
      connected: false,
      email: null,
      name: null,
      avatarUrl: null,
      accessToken: null,
      connectedAt: null,
      isRealGoogle: false,
      importedPhotos: [],
      totalImported: 0,
    };
  }

  /**
   * Load Google Identity Services (GSI) script dynamically
   */
  loadGsiScript() {
    if (typeof window === 'undefined') return Promise.resolve(false);
    if (window.google?.accounts?.oauth2) return Promise.resolve(true);

    if (this.gsiLoadedPromise) return this.gsiLoadedPromise;

    this.gsiLoadedPromise = new Promise((resolve) => {
      const existing = document.querySelector('script[src="https://accounts.google.com/gsi/client"]');
      if (existing) {
        existing.addEventListener('load', () => resolve(true));
        existing.addEventListener('error', () => resolve(false));
        return;
      }

      const script = document.createElement('script');
      script.src = 'https://accounts.google.com/gsi/client';
      script.async = true;
      script.defer = true;
      script.onload = () => resolve(true);
      script.onerror = (err) => {
        console.warn('[GooglePhotosService] failed to load GSI script:', err);
        resolve(false);
      };
      document.head.appendChild(script);
    });

    return this.gsiLoadedPromise;
  }

  /**
   * Build headers including the user's JWT bearer token when logged in
   */
  _getAuthHeaders(extraHeaders = {}) {
    const headers = { ...extraHeaders };
    try {
      const token = typeof localStorage !== 'undefined' ? localStorage.getItem('ethernity_auth_token') : null;
      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }
    } catch {
      // ignore
    }
    return headers;
  }

  /**
   * Get Google Photos authorization URL from backend API
   */
  async getAuthorizationUrl() {
    const apiUrl = API_ORIGIN;
    const headers = this._getAuthHeaders();
    const res = await fetch(`${apiUrl}/api/integrations/google-photos/authorization-url`, { headers });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(data.detail || data.error || 'Falha ao obter URL de autorização do Google Fotos.');
    }
    return data.authorizationUrl || data.url;
  }

  /**
   * Connect account via official Google OAuth 2.0 Backend Flow
   */
  async loginWithGoogle() {
    const authUrl = await this.getAuthorizationUrl();
    if (!authUrl) {
      throw new Error('Não foi possível obter a URL oficial de conexão do Google Fotos.');
    }
    if (typeof window !== 'undefined') {
      window.location.assign(authUrl);
    }
    return authUrl;
  }

  /**
   * Check connection status with the backend
   */
  async checkBackendStatus() {
    const apiUrl = API_ORIGIN;
    try {
      const res = await fetch(`${apiUrl}/api/integrations/google-photos/status`, {
        headers: this._getAuthHeaders(),
      });
      if (res.ok) {
        const data = await res.json();
        const integration = data.integration || data;
        const current = this.getConnectionStatus();
        if (integration.connected) {
          // A conta exibida é sempre a que o servidor tem para ESTE usuário (issue #6)
          const updated = {
            ...current,
            connected: true,
            isRealGoogle: true,
            email: integration.displayName || 'Conta Google Conectada',
            name: integration.displayName || 'Usuário Google',
          };
          if (typeof localStorage !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(updated));
          }
          this._notify(updated);
          return updated;
        }

        // O servidor é a fonte da verdade: sem conexão lá, descarta qualquer "Conectado"
        // guardado neste navegador (ex.: conta falsa gravada pelo app antigo).
        try {
          if (typeof localStorage !== 'undefined') {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.removeItem(CACHED_PHOTOS_KEY);
          }
        } catch {
          // ignore
        }
        const disconnected = { ...this.getConnectionStatus(), configured: integration.configured !== false };
        this._notify(disconnected);
        return disconnected;
      }
    } catch {
      // Servidor fora do ar: mantém o último estado conhecido
    }
    return this.getConnectionStatus();
  }

  /**
   * Create a new Google Photos Picker API session
   */
  async createPickerSession(capsuleId = 'capsule-default') {
    const apiUrl = API_ORIGIN;
    const headers = this._getAuthHeaders({ 'Content-Type': 'application/json' });
    let res;
    try {
      res = await fetch(`${apiUrl}/api/integrations/google-photos/sessions`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ capsuleId }),
      });
    } catch {
      res = await fetch(`${API_ORIGIN}/api/integrations/google-photos/sessions`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ capsuleId }),
      });
    }

    if (!res.ok) {
      const fallbackRes = await fetch(`${apiUrl}/api/capsules/${capsuleId}/google-photos/sessions`, {
        method: 'POST',
        headers,
      }).catch(() => null);

      if (fallbackRes && fallbackRes.ok) {
        const data = await fallbackRes.json();
        return data.session || data.data?.session || data;
      }

      const err = await res.json().catch(() => ({}));
      throw new Error(err.error || err.detail || 'Falha ao criar sessão do Google Photos Picker.');
    }

    const data = await res.json();
    return data.session || data.data?.session || data;
  }

  /**
   * Poll Google Photos Picker API session for selection readiness
   */
  async pollPickerSession(sessionId, capsuleId = 'capsule-default') {
    const apiUrl = API_ORIGIN;
    const headers = this._getAuthHeaders();
    let res;
    try {
      res = await fetch(`${apiUrl}/api/integrations/google-photos/sessions/${encodeURIComponent(sessionId)}`, {
        headers,
      });
    } catch {
      res = await fetch(`${API_ORIGIN}/api/integrations/google-photos/sessions/${encodeURIComponent(sessionId)}`, {
        headers,
      });
    }

    if (!res.ok) {
      const fallbackRes = await fetch(
        `${apiUrl}/api/capsules/${capsuleId}/google-photos/sessions/${encodeURIComponent(sessionId)}`,
        { headers }
      ).catch(() => null);
      if (fallbackRes && fallbackRes.ok) {
        const data = await fallbackRes.json();
        return data.session || data.data?.session || data;
      }
      throw new Error('Falha ao consultar status da sessão no Google Fotos.');
    }

    const data = await res.json();
    return data.session || data.data?.session || data;
  }

  /**
   * Import photos from ready Google Photos Picker session
   */
  /**
   * Starts the import of the photos chosen in the Google picker and waits until the
   * server finishes it in the background, reporting progress through onProgress.
   * Resolves with { importedCount, skippedCount, skippedVideos, memories }.
   */
  async importPickerSessionAndWait(sessionId, { capsuleId = 'capsule-default', onProgress, intervalMs = 2000, timeoutMs = 30 * 60 * 1000 } = {}) {
    let state = await this.importPickerSession(sessionId, capsuleId);
    const startedAt = Date.now();
    while (state && state.status !== 'COMPLETED' && state.status !== 'ERROR') {
      if (onProgress) onProgress(state);
      if (Date.now() - startedAt > timeoutMs) {
        throw new Error('A importação está demorando mais que o esperado. As fotos continuam sendo importadas e aparecerão no seu cofre.');
      }
      await new Promise((resolve) => setTimeout(resolve, intervalMs));
      state = await this.pollPickerSession(sessionId, capsuleId);
    }
    if (!state || state.status === 'ERROR') {
      throw new Error(state?.error || 'Falha ao importar as fotos selecionadas.');
    }
    return {
      importedCount: state.importedCount || 0,
      skippedCount: state.skippedCount || 0,
      skippedVideos: state.skippedVideos || 0,
      alreadyInApp: state.alreadyInApp || 0,
      memories: state.items || [],
    };
  }

  async importPickerSession(sessionId, capsuleId = 'capsule-default') {
    const apiUrl = API_ORIGIN;
    const headers = this._getAuthHeaders({ 'Content-Type': 'application/json' });
    let res;
    try {
      res = await fetch(`${apiUrl}/api/integrations/google-photos/sessions/${encodeURIComponent(sessionId)}/import`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ capsuleId }),
      });
    } catch {
      res = await fetch(`${API_ORIGIN}/api/integrations/google-photos/sessions/${encodeURIComponent(sessionId)}/import`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ capsuleId }),
      });
    }

    if (!res.ok) {
      const fallbackRes = await fetch(
        `${apiUrl}/api/capsules/${capsuleId}/google-photos/sessions/${encodeURIComponent(sessionId)}/import`,
        {
          method: 'POST',
          headers,
        }
      ).catch(() => null);

      if (fallbackRes && fallbackRes.ok) {
        return await fallbackRes.json();
      }

      const err = await res.json().catch(() => ({}));
      throw new Error(err.error || err.detail || 'Falha ao importar fotos selecionadas.');
    }

    return await res.json();
  }

  /**
   * Fetch user info from Google OAuth2 endpoint
   */
  async _fetchGoogleUserProfile(accessToken) {
    if (!accessToken) return {};

    try {
      const res = await fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
        headers: { Authorization: `Bearer ${accessToken}` },
      });

      if (res.ok) {
        return await res.json();
      }
    } catch (err) {
      console.warn('[GooglePhotosService] direct userinfo failed, trying backend proxy:', err);
    }

    return {};
  }

  /**
   * Connect account with Google Credentials / Profile
   */
  async connectAccount(profile = {}) {
    const defaultEmail = 'usuario.google@gmail.com';
    const defaultName = 'Usuário Google Photos';
    const defaultAvatar = null;

    const current = this.getConnectionStatus();
    const updated = {
      connected: true,
      email: profile.email || current.email || defaultEmail,
      name: profile.name || current.name || defaultName,
      avatarUrl: profile.avatarUrl || current.avatarUrl || defaultAvatar,
      connectedAt: new Date().toISOString(),
      accessToken: profile.accessToken || current.accessToken || `token-gp-${Date.now()}`,
      isRealGoogle: profile.isRealGoogle !== undefined ? profile.isRealGoogle : (Boolean(profile.accessToken) || current.isRealGoogle),
      importedPhotos: current.importedPhotos || [],
      totalImported: (current.importedPhotos || []).length,
    };

    try {
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(updated));
      }
    } catch (e) {
      console.warn('[GooglePhotosService] localStorage write error:', e);
    }

    this._notify(updated);
    return updated;
  }

  /**
   * Disconnect account
   */
  async disconnectAccount() {
    const current = this.getConnectionStatus();

    if (current.accessToken && typeof window !== 'undefined' && window.google?.accounts?.oauth2?.revoke) {
      try {
        window.google.accounts.oauth2.revoke(current.accessToken, () => {});
      } catch {
        // ignore
      }
    }

    const disconnected = {
      connected: false,
      email: null,
      name: null,
      avatarUrl: null,
      accessToken: null,
      connectedAt: null,
      isRealGoogle: false,
      importedPhotos: [],
      totalImported: 0,
    };

    try {
      if (typeof localStorage !== 'undefined') {
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(CACHED_PHOTOS_KEY);
      }
    } catch (e) {
      console.warn('[GooglePhotosService] localStorage remove error:', e);
    }

    // Notify backend
    try {
      const apiUrl = API_ORIGIN;
      const userAuthToken = typeof localStorage !== 'undefined' ? (localStorage.getItem('ethernity_auth_token')) : null;
      if (userAuthToken) {
        fetch(`${apiUrl}/api/integrations/google-photos/disconnect`, {
          method: 'POST',
          headers: this._getAuthHeaders({ 'Content-Type': 'application/json' }),
        }).catch(() => {});
      }
    } catch {
      // ignore
    }

    this.cachedLivePhotos = [];
    this.nextPageToken = null;
    this._notify(disconnected);
    return disconnected;
  }

  /**
   * Fetch real photos directly from Google Photos Library REST API
   */
  async fetchRealPhotos({ pageToken = '', pageSize = 50 } = {}) {
    const conn = this.getConnectionStatus();
    if (!conn.accessToken) {
      throw new Error('Nenhum token do Google disponível. Faça login primeiro.');
    }

    // Direct client fetch to Google Photos Library REST API
    try {
      let googleUrl = `https://photoslibrary.googleapis.com/v1/mediaItems?pageSize=${Math.min(pageSize, 100)}`;
      if (pageToken) googleUrl += `&pageToken=${encodeURIComponent(pageToken)}`;

      const googleRes = await fetch(googleUrl, {
        headers: {
          Authorization: `Bearer ${conn.accessToken}`,
          'Content-Type': 'application/json',
        },
      });

      if (!googleRes.ok) {
        const errJson = await googleRes.json().catch(() => ({}));
        throw new Error(errJson.error?.message || `Erro da API do Google Photos (${googleRes.status})`);
      }

      const gData = await googleRes.json();
      const rawList = Array.isArray(gData.mediaItems) ? gData.mediaItems : [];
      const formatted = rawList.map((item) => {
        const metadata = item.mediaMetadata || {};
        const photoMeta = metadata.photo || {};
        const creationTime = metadata.creationTime || new Date().toISOString();
        const camera = [photoMeta.cameraMake, photoMeta.cameraModel].filter(Boolean).join(' ') || null;

        return {
          id: item.id,
          title: item.description || item.filename || 'Foto do Google Fotos',
          description: item.description || `Foto sincronizada (${item.filename})`,
          filename: item.filename,
          category: 'family',
          categoryLabel: 'Google Fotos',
          date: new Date(creationTime).toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
          }),
          timestamp: creationTime,
          location: 'Google Fotos',
          dimensions: {
            width: Number(metadata.width) || 1920,
            height: Number(metadata.height) || 1080,
          },
          camera,
          thumbnailUrl: `${item.baseUrl}=w800-h800`,
          fullUrl: `${item.baseUrl}=w2048-h2048`,
          downloadUrl: `${item.baseUrl}=d`,
          tags: ['google-photos', item.filename?.split('.').pop()?.toLowerCase()].filter(Boolean),
          source: 'google_photos',
          isEncrypted: true,
        };
      });

      return {
        photos: formatted,
        nextPageToken: gData.nextPageToken || null,
        total: formatted.length,
        isLive: true,
      };
    } catch (directErr) {
      console.error('[GooglePhotosService] direct Google Photos REST call error:', directErr);
      throw directErr;
    }
  }

  /**
   * Fetch available Google Photos with optional filtering and pagination
   */
  async getPhotos({
    category = 'all',
    search = '',
    pageToken = '',
    pageSize = 50,
    forceLive = false,
    allowMock = false,
    // GOOGLE_PHOTOS (default) or ICLOUD (photos sent by the iOS app): same "waiting for a capsule" list
    source = 'GOOGLE_PHOTOS',
  } = {}) {
    const conn = this.getConnectionStatus();
    const memorySource = source === 'ICLOUD' ? 'ICLOUD' : 'GOOGLE_PHOTOS';
    let items = [];
    let isLiveResult = false;
    let nextPage = null;

    // 1. Fetch real memories imported from Google Photos via authenticated API
    const apiUrl = API_ORIGIN;
    const authHeaders = this._getAuthHeaders();
    if (authHeaders.Authorization) {
      try {
        // Só fotos ainda sem cápsula: cada foto fica num lugar só (ao ganhar destino, sai daqui)
        const res = await fetch(`${apiUrl}/api/memories?source=${memorySource}&unassigned=1`, {
          headers: authHeaders,
        });
        if (res.ok) {
          const data = await res.json();
          if (Array.isArray(data) && data.length > 0) {
            // As fotos ficam no cofre (rota autenticada): a <img> precisa do token na URL
            items = data.map((m) => withVaultImageUrls(m));
            isLiveResult = true;
          }
        }
      } catch {
        // Backend fetch failed or offline
      }
    }

    // 3. Fallback to mock ONLY when explicitly requested (e.g. unit tests)
    if (items.length === 0 && allowMock) {
      items = [...DEFAULT_GOOGLE_PHOTOS];
    }

    if (category && category !== 'all') {
      items = items.filter((item) => item.category === category);
    }

    if (search && search.trim()) {
      const q = search.trim().toLowerCase();
      items = items.filter(
        (item) =>
          (item.title && item.title.toLowerCase().includes(q)) ||
          (item.description && item.description.toLowerCase().includes(q)) ||
          (item.filename && item.filename.toLowerCase().includes(q)) ||
          (item.location && item.location.toLowerCase().includes(q)) ||
          (item.camera && item.camera.toLowerCase().includes(q)) ||
          (item.tags && item.tags.some((t) => t.toLowerCase().includes(q)))
      );
    }

    items.nextPageToken = nextPage;
    items.isLive = isLiveResult;
    items.connection = conn;

    return items;
  }

}

export const googlePhotosService = new GooglePhotosService();
export default googlePhotosService;
