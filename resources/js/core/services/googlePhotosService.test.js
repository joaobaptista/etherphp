import { describe, it, expect, beforeEach, vi } from 'vitest';
import googlePhotosService from './googlePhotosService.js';

describe('googlePhotosService', () => {
  let mockStore = {};

  beforeEach(() => {
    mockStore = {};
    const mockStorage = {
      getItem: (key) => mockStore[key] || null,
      setItem: (key, val) => {
        mockStore[key] = String(val);
      },
      removeItem: (key) => {
        delete mockStore[key];
      },
      clear: () => {
        mockStore = {};
      },
    };
    globalThis.localStorage = mockStorage;
  });

  it('initializes with disconnected status when storage is empty', () => {
    const status = googlePhotosService.getConnectionStatus();
    expect(status.connected).toBe(false);
    expect(status.email).toBeNull();
    expect(status.importedPhotos).toEqual([]);
    expect(status.totalImported).toBe(0);
  });

  it('connects account and notifies subscribers', async () => {
    const subscriber = vi.fn();
    const unsubscribe = googlePhotosService.subscribe(subscriber);

    const connected = await googlePhotosService.connectAccount({
      email: 'teste.usuario@gmail.com',
      name: 'Teste Usuário',
    });

    expect(connected.connected).toBe(true);
    expect(connected.email).toBe('teste.usuario@gmail.com');
    expect(subscriber).toHaveBeenCalled();

    const stored = googlePhotosService.getConnectionStatus();
    expect(stored.connected).toBe(true);
    expect(stored.email).toBe('teste.usuario@gmail.com');

    unsubscribe();
  });

  it('filters available photos by category and search', async () => {
    const familyPhotos = await googlePhotosService.getPhotos({ category: 'family', allowMock: true });
    expect(familyPhotos.length).toBeGreaterThan(0);
    expect(familyPhotos.every((p) => p.category === 'family')).toBe(true);

    const searchPhotos = await googlePhotosService.getPhotos({ search: 'Praia', allowMock: true });
    expect(searchPhotos.length).toBeGreaterThan(0);
    expect(searchPhotos[0].title).toContain('Praia');
  });

  it('disconnects account properly', async () => {
    await googlePhotosService.connectAccount({ email: 'user@gmail.com' });
    expect(googlePhotosService.getConnectionStatus().connected).toBe(true);

    const disconnected = await googlePhotosService.disconnectAccount();
    expect(disconnected.connected).toBe(false);
    expect(googlePhotosService.getConnectionStatus().connected).toBe(false);
  });

  it('manages Google Client ID correctly', () => {
    googlePhotosService.setClientId('');
    expect(googlePhotosService.hasConfiguredClientId()).toBe(false);
    googlePhotosService.setClientId('test-client-id.apps.googleusercontent.com');
    expect(googlePhotosService.getClientId()).toBe('test-client-id.apps.googleusercontent.com');
    expect(googlePhotosService.hasConfiguredClientId()).toBe(true);

    googlePhotosService.setClientId('');
    expect(googlePhotosService.hasConfiguredClientId()).toBe(false);
  });

  it('sends JWT Authorization header on Picker status, auth-url, session, poll and import requests', async () => {
    globalThis.localStorage.setItem('ethernity_auth_token', 'jwt-test-token-123');
    const fetchSpy = vi.fn(async (url) => {
      if (String(url).includes('/authorization-url')) {
        return { ok: true, json: async () => ({ authorizationUrl: 'https://accounts.google.com/o/oauth2/v2/auth' }) };
      }
      if (String(url).includes('/status')) {
        return { ok: true, json: async () => ({ integration: { connected: true, displayName: 'Vagner' } }) };
      }
      if (String(url).includes('/import')) {
        return { ok: true, json: async () => ({ importedCount: 1 }) };
      }
      return { ok: true, json: async () => ({ session: { id: 'sess-1', pickerUri: 'https://photospicker.googleapis.com/s/1' } }) };
    });
    const originalFetch = globalThis.fetch;
    globalThis.fetch = fetchSpy;

    try {
      await googlePhotosService.getAuthorizationUrl();
      await googlePhotosService.checkBackendStatus();
      await googlePhotosService.createPickerSession('cap-1');
      await googlePhotosService.pollPickerSession('sess-1', 'cap-1');
      await googlePhotosService.importPickerSession('sess-1', 'cap-1');

      expect(fetchSpy).toHaveBeenCalledTimes(5);
      for (const [, init] of fetchSpy.mock.calls) {
        expect(init?.headers?.Authorization).toBe('Bearer jwt-test-token-123');
      }
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
  describe('status vindo do servidor (issue #6: conta Google "chumbada")', () => {
    const staleFake = JSON.stringify({ connected: true, email: 'vagner.ibas@gmail.com', name: 'Vagner Ibas', isRealGoogle: true });
    const statusResponse = (integration) => vi.fn(async () => ({ ok: true, json: async () => ({ integration }) }));

    it('apaga a conexão guardada no navegador quando o servidor diz que este usuário não está conectado', async () => {
      mockStore.ethernity_google_photos_connection = staleFake;
      globalThis.fetch = statusResponse({ configured: true, connected: false, displayName: null });

      const status = await googlePhotosService.checkBackendStatus();

      expect(status.connected).toBe(false);
      expect(status.email).toBeNull();
      expect(mockStore.ethernity_google_photos_connection).toBeUndefined();
    });

    it('mostra a conta que o servidor tem para o usuário, não a que estava no navegador', async () => {
      mockStore.ethernity_google_photos_connection = staleFake;
      globalThis.fetch = statusResponse({ configured: true, connected: true, displayName: 'ana@example.com' });

      const status = await googlePhotosService.checkBackendStatus();

      expect(status.connected).toBe(true);
      expect(status.email).toBe('ana@example.com');
    });

    it('mantém o último estado conhecido se o servidor estiver fora do ar', async () => {
      mockStore.ethernity_google_photos_connection = JSON.stringify({ connected: true, email: 'ana@example.com' });
      globalThis.fetch = vi.fn(async () => {
        throw new Error('offline');
      });

      const status = await googlePhotosService.checkBackendStatus();

      expect(status.connected).toBe(true);
      expect(status.email).toBe('ana@example.com');
    });
  });
  it('acompanha a importação em segundo plano até concluir e devolve as contagens', async () => {
    mockStore.ethernity_auth_token = 'jwt-teste';
    const states = [
      { status: 'IMPORTING', selectedCount: 3, importedCount: 0, skippedVideos: 0 },
      { status: 'IMPORTING', selectedCount: 3, importedCount: 1, skippedVideos: 1 },
      { status: 'COMPLETED', selectedCount: 3, importedCount: 2, skippedVideos: 1, skippedCount: 0, items: [{ id: 1 }, { id: 2 }] },
    ];
    let call = 0;
    globalThis.fetch = vi.fn(async (url, options = {}) => {
      const state = states[Math.min(call++, states.length - 1)];
      const body = (options.method || 'GET') === 'POST' ? { success: true, ...state } : { session: state };
      return { ok: true, json: async () => body };
    });
    const progress = [];

    const result = await googlePhotosService.importPickerSessionAndWait('7', {
      intervalMs: 1,
      onProgress: (s) => progress.push(`${s.importedCount}/${s.selectedCount}`),
    });

    expect(result).toEqual({ importedCount: 2, skippedCount: 0, skippedVideos: 1, alreadyInApp: 0, memories: [{ id: 1 }, { id: 2 }] });
    expect(progress).toEqual(['0/3', '1/3']);
  });

  it('falha com a mensagem do servidor quando a importação dá erro', async () => {
    mockStore.ethernity_auth_token = 'jwt-teste';
    globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ status: 'ERROR', error: 'Falha ao listar itens selecionados no Google Fotos.' }) }));

    await expect(googlePhotosService.importPickerSessionAndWait('7', { intervalMs: 1 }))
      .rejects.toThrow('Falha ao listar itens selecionados no Google Fotos.');
  });
  it('monta as URLs autenticadas (miniatura e exibição) das fotos importadas do Google que estão no cofre', async () => {
    mockStore.ethernity_auth_token = 'jwt-teste';
    globalThis.fetch = vi.fn(async () => ({
      ok: true,
      json: async () => [{ id: 585, title: 'IMG_1.jpg', mediaUrl: '/api/vault/media/mem-abc', source: 'GOOGLE_PHOTOS' }],
    }));

    const [photo] = await googlePhotosService.getPhotos();

    expect(photo.thumbnailUrl).toContain('/api/vault/media/mem-abc?variant=thumb&token=jwt-teste');
    expect(photo.fullUrl).toContain('/api/vault/media/mem-abc?variant=display&token=jwt-teste');
  });
});
