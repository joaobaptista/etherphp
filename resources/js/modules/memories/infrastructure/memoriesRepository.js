/**
 * memoriesRepository.js — Infrastructure Layer for Memories Context
 * 
 * Interage com o StorageAdapter de forma agnóstica de plataforma (Web & React Native).
 */
import storageAdapter from '../../../core/adapters/storageAdapter.js';
import { sealText, openTextOr } from '../../../core/services/fieldCipher.js';
import httpClient from '../../../core/api/httpClient.js';
import { INITIAL_CURATED_PHOTOS, createCuratedPhoto } from '../domain/memoriesModel.js';

const STORAGE_KEY = 'ethernity_curated_timeline_photos';

function getAuthToken() {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      return (
        window.localStorage.getItem('ethernity_auth_token') || null
      );
    }
  } catch { }
  return null;
}

export class MemoriesRepository {
  async getPhotos() {
    // 1. Tenta carregar do cache local imediato
    let localPhotos = [];
    try {
      const raw = await storageAdapter.getItem(STORAGE_KEY);
      if (raw) {
        const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (Array.isArray(parsed) && parsed.length > 0) {
          localPhotos = parsed;
        }
      }
    } catch (err) {
      console.warn('[MemoriesRepository] Erro ao carregar fotos do cache local:', err);
    }

    // 2. Se autenticado, sincroniza com o banco PostgreSQL via API
    const token = getAuthToken();
    if (token) {
      try {
        const remoteMemories = await httpClient.get('/memories', token);
        if (Array.isArray(remoteMemories) && remoteMemories.length > 0) {
          const formatted = await Promise.all(
            remoteMemories.map(async (m) =>
              createCuratedPhoto({
                id: m.id,
                img: m.mediaUrl || m.media_url || m.img,
                title: (await openTextOr(m.title)) || 'Momento Especial',
                dateLabel: m.dateLabel || 'Recente',
                year: m.year || new Date().getFullYear(),
                story: await openTextOr(m.content || m.story || '', ''),
                audioDuration: '0:35',
                emotions: ['Amor', 'Gratidão'],
              })
            )
          );

          // Mescla itens remotos com os locais
          const existingIds = new Set(formatted.map((f) => f.id));
          const localOnly = localPhotos.filter((lp) => !existingIds.has(lp.id));
          const merged = [...formatted, ...localOnly];
          await this.savePhotos(merged);
          return merged;
        }
      } catch (err) {
        console.warn('[MemoriesRepository] API offline ou inacessível, utilizando cache local:', err);
      }
    }

    return localPhotos.length > 0 ? localPhotos : [...INITIAL_CURATED_PHOTOS];
  }

  getPhotosSync() {
    try {
      const raw = storageAdapter.getItemSync(STORAGE_KEY);
      if (raw) {
        const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (Array.isArray(parsed) && parsed.length > 0) {
          return parsed;
        }
      }
    } catch (err) {
      console.warn('[MemoriesRepository] Erro sincrono ao carregar fotos:', err);
    }
    return [...INITIAL_CURATED_PHOTOS];
  }

  async savePhotos(photos) {
    try {
      await storageAdapter.setItem(STORAGE_KEY, JSON.stringify(photos));
      return true;
    } catch (err) {
      console.error('[MemoriesRepository] Erro ao salvar fotos:', err);
      return false;
    }
  }

  savePhotosSync(photos) {
    try {
      storageAdapter.setItemSync(STORAGE_KEY, JSON.stringify(photos));
      return true;
    } catch (err) {
      console.error('[MemoriesRepository] Erro sincrono ao salvar fotos:', err);
      return false;
    }
  }

  async addPhotos(newPhotosPayload = []) {
    const current = await this.getPhotos();
    const createdPhotos = newPhotosPayload.map((p) => createCuratedPhoto(p));
    const updated = [...createdPhotos, ...current];
    await this.savePhotos(updated);

    // Persiste no banco PostgreSQL via API
    const token = getAuthToken();
    if (token) {
      for (const p of createdPhotos) {
        const mediaUrl = p.img || p.media_url;
        // Foto ainda só no aparelho (data:/blob:) vai ao cofre cifrado pela sincronização da cápsula
        if (!mediaUrl || /^(data|blob):/i.test(mediaUrl)) continue;
        Promise.all([sealText(p.title || 'Foto de Família'), sealText(p.story || '')])
          .then(([title, story]) =>
            httpClient.post(
              '/memories',
              { capsuleId: p.capsuleId || 'general', title, story, mediaUrl, type: 'IMAGE', source: p.source || 'UPLOAD' },
              token
            )
          )
          .catch((err) => {
            console.warn('[MemoriesRepository] Aviso ao sincronizar memória na API:', err);
          });
      }
    }

    return updated;
  }
}

export const memoriesRepository = new MemoriesRepository();
export default memoriesRepository;

