import React, { useState, useEffect, useMemo } from 'react';
import { createPortal } from 'react-dom';
import googlePhotosService from '../../../core/services/googlePhotosService.js';
import capsulesService from '../../capsules/application/capsulesService.js';
import { useI18n } from '../../../core/i18n/presentation/useI18n.js';
import './GooglePhotosPickerModal.css';

export default function GooglePhotosPickerModal({
  isOpen,
  onClose,
  onNavigateTab,
  // 'google': Google Photos picker; 'icloud': photos sent by the Ethernity iOS app (same flow to a capsule)
  source = 'google',
}) {
  const { t } = useI18n();
  const isIcloud = source === 'icloud';
  const sourceName = isIcloud ? 'iPhone' : 'Google Fotos';
  const [selectedPhotoIds, setSelectedPhotoIds] = useState([]);
  const [photos, setPhotos] = useState([]);
  const [lightboxPhoto, setLightboxPhoto] = useState(null);
  const [isImporting, setIsImporting] = useState(false);
  const [isLoadingPhotos, setIsLoadingPhotos] = useState(false);
  const [nextPageToken, setNextPageToken] = useState(null);
  const [importFeedback, setImportFeedback] = useState(null);
  const [apiError, setApiError] = useState(null);
  const [connection, setConnection] = useState(() => googlePhotosService.getConnectionStatus());

  // Estados do fluxo oficial do Google Photos Picker API
  const [pickerSession, setPickerSession] = useState(null);
  const [isOpeningPicker, setIsOpeningPicker] = useState(false);
  const [isPollingPicker, setIsPollingPicker] = useState(false);
  const [pickerStatusText, setPickerStatusText] = useState('');
  // Destino das fotos selecionadas: uma cápsula existente ou uma nova
  const [isCapsuleChooserOpen, setIsCapsuleChooserOpen] = useState(false);
  const [capsuleOptions, setCapsuleOptions] = useState([]);
  const [targetCapsuleId, setTargetCapsuleId] = useState('new');
  const [newRecipientName, setNewRecipientName] = useState('');
  const [newRelationship, setNewRelationship] = useState('');

  const isConnected = isIcloud || Boolean(connection.connected || connection.isRealGoogle);

  const displayedPhotos = photos;

  // Capturar status do callback oficial do Google (?google_photos_status=connected)
  useEffect(() => {
    if (isIcloud) return;
    if (typeof window !== 'undefined' && window.location.search) {
      const params = new URLSearchParams(window.location.search);
      const gpStatus = params.get('google_photos_status');
      const errorMsg = params.get('message') || params.get('error');

      if (gpStatus === 'connected') {
        setImportFeedback({
          type: 'success',
          text: '✨ Conta Google conectada com sucesso! Você já pode selecionar suas fotos reais.',
        });
        setTimeout(() => setImportFeedback(null), 4500);

        googlePhotosService.checkBackendStatus().then((updated) => {
          setConnection(updated);
          loadPhotos({ reset: true });
        });

        const nextUrl = window.location.pathname + window.location.hash;
        window.history.replaceState({}, document.title, nextUrl);
      } else if (gpStatus === 'error' || errorMsg) {
        setApiError(decodeURIComponent(errorMsg || 'Erro na autorização com o Google.'));
        const nextUrl = window.location.pathname + window.location.hash;
        window.history.replaceState({}, document.title, nextUrl);
      }
    }
  }, []);

  // Listen to connection updates
  useEffect(() => {
    const unsubscribe = googlePhotosService.subscribe((updated) => {
      setConnection(updated);
    });
    return () => unsubscribe();
  }, []);

  // Polling ativo da sessão do Google Photos Picker
  useEffect(() => {
    if (!pickerSession?.id || pickerSession?.mediaItemsSet || !isPollingPicker) return undefined;

    let cancelled = false;
    let timer;

    async function poll() {
      try {
        const nextSession = await googlePhotosService.pollPickerSession(pickerSession.id);
        if (cancelled) return;

        setPickerSession(nextSession);

        if (nextSession.mediaItemsSet) {
          setIsPollingPicker(false);
          setPickerStatusText('✨ Seleção finalizada no Google Fotos! Baixando fotos com alta resolução...');

          try {
            // O servidor importa em segundo plano; acompanhamos o progresso até terminar
            const result = await googlePhotosService.importPickerSessionAndWait(pickerSession.id, {
              onProgress: (state) => {
                if (cancelled) return;
                const total = state.selectedCount || 0;
                const done = (state.importedCount || 0) + (state.skippedCount || 0) + (state.skippedVideos || 0);
                setPickerStatusText(total > 0
                  ? `Importando suas fotos com segurança... ${done} de ${total}`
                  : 'Importando suas fotos com segurança...');
              },
            });
            const count = result.importedCount || (result.memories || []).length;
            const notes = [];
            if (result.alreadyInApp > 0) {
              notes.push(`${result.alreadyInApp} foto(s) já estavam no app e não foram duplicadas`);
            }
            if (result.skippedVideos > 0) {
              notes.push(`${result.skippedVideos} vídeo(s) não importado(s): por enquanto só fotos são suportadas`);
            }
            if (result.skippedCount > 0) {
              notes.push(`${result.skippedCount} foto(s) não puderam ser importadas`);
            }
            setImportFeedback({
              type: count > 0 ? 'success' : 'info',
              text: `✨ ${count} foto(s) importada(s) do Google Fotos para o seu legado!${notes.length ? ` (${notes.join('; ')})` : ''}`,
            });
            setTimeout(() => setImportFeedback(null), notes.length ? 10000 : 4500);

            setPickerSession(null);
            setPickerStatusText('');
            await loadPhotos({ reset: true });

            // As fotos recém-trazidas ficam marcadas, prontas para "Adicionar à Cápsula"
            const importedIds = (result.memories || []).map((m) => String(m.id));
            if (importedIds.length > 0) setSelectedPhotoIds(importedIds);
          } catch (impErr) {
            console.error('[GooglePhotosPickerModal] Import error:', impErr);
            setApiError(impErr.message || 'Falha ao importar as fotos selecionadas.');
            setIsPollingPicker(false);
          }
        } else {
          timer = setTimeout(poll, 2500);
        }
      } catch (pollErr) {
        if (!cancelled) {
          console.warn('[GooglePhotosPickerModal] Polling error:', pollErr);
        }
      }
    }

    timer = setTimeout(poll, 2000);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [pickerSession, isPollingPicker]);

  // Ao abrir: confere a conexão no servidor e carrega as fotos já importadas
  useEffect(() => {
    if (isOpen) {
      if (!isIcloud) {
        googlePhotosService.checkBackendStatus().then((status) => {
          setConnection(status);
        });
      }
      loadPhotos({ pageToken: '', reset: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const loadPhotos = async ({ pageToken = '', reset = false } = {}) => {
    setIsLoadingPhotos(true);
    setApiError(null);

    try {
      const list = await googlePhotosService.getPhotos({
        pageToken,
        source: isIcloud ? 'ICLOUD' : 'GOOGLE_PHOTOS',
        allowMock: false, // 100% fotos reais — sem fotos mockadas
      });

      if (reset || !pageToken) {
        setPhotos(list);
        // Fotos que ainda não estão em nenhuma cápsula já vêm marcadas, prontas para "Adicionar à Cápsula"
        const loose = list.filter((p) => !p.capsuleId || p.capsuleId === 'capsule-default').map((p) => p.id);
        setSelectedPhotoIds(loose);
      } else {
        setPhotos((prev) => [...prev, ...list]);
      }

      setNextPageToken(list.nextPageToken || null);
    } catch (err) {
      console.warn('[GooglePhotosPickerModal] Erro ao carregar fotos:', err);
      setApiError(err.message || `Não foi possível carregar as fotos do ${sourceName}.`);
    } finally {
      setIsLoadingPhotos(false);
    }
  };

  const handleLoadMore = async () => {
    if (!nextPageToken || isLoadingPhotos) return;
    await loadPhotos({ pageToken: nextPageToken, reset: false });
  };

  // Conectar conta via Google OAuth 2.0 oficial no Backend
  const handleConnectGoogle = async () => {
    setApiError(null);
    setIsLoadingPhotos(true);
    try {
      await googlePhotosService.loginWithGoogle();
    } catch (err) {
      console.error('[GooglePhotosPickerModal] Login error:', err);
      setApiError(err.message || 'Falha ao autenticar com o Google.');
      setIsLoadingPhotos(false);
    }
  };

  // Iniciar fluxo do Google Photos Picker oficial
  const handleStartGooglePicker = async () => {
    setIsOpeningPicker(true);
    setApiError(null);
    setPickerStatusText('Iniciando o seletor oficial do Google Fotos...');

    try {
      const session = await googlePhotosService.createPickerSession('capsule-default');
      const pickerUri = session.pickerUri || session.picker_uri;

      if (!session?.id || !pickerUri) {
        throw new Error('A API não retornou uma sessão válida do Google Fotos.');
      }

      setPickerSession(session);
      setIsPollingPicker(true);
      setPickerStatusText('Aguardando você selecionar fotos no Google Fotos...');

      const targetUrl = `${pickerUri.replace(/\/$/, '')}/autoclose`;
      window.open(targetUrl, '_blank', 'noopener,noreferrer');
    } catch (err) {
      console.error('[GooglePhotosPickerModal] Error starting picker:', err);
      setApiError(err.message || 'Não foi possível abrir o seletor do Google Fotos.');
      setPickerSession(null);
      setIsPollingPicker(false);
    } finally {
      setIsOpeningPicker(false);
    }
  };

  const handleDisconnect = async () => {
    await googlePhotosService.disconnectAccount();
    setSelectedPhotoIds([]);
    setPhotos([]);
    setNextPageToken(null);
    setPickerSession(null);
    setIsPollingPicker(false);
    setImportFeedback({
      type: 'info',
      text: 'Conta do Google Fotos desconectada.',
    });
    setTimeout(() => setImportFeedback(null), 2500);
    await loadPhotos({ reset: true });
  };

  const handleToggleSelect = (photoId) => {
    setSelectedPhotoIds((prev) =>
      prev.includes(photoId) ? prev.filter((id) => id !== photoId) : [...prev, photoId]
    );
  };

  const handleSelectAll = () => {
    const currentList = displayedPhotos;
    const isAllCurrentSelected =
      currentList.length > 0 && currentList.every((p) => selectedPhotoIds.includes(p.id));
    if (isAllCurrentSelected) {
      setSelectedPhotoIds((prev) => prev.filter((id) => !currentList.some((p) => p.id === id)));
    } else {
      setSelectedPhotoIds((prev) => Array.from(new Set([...prev, ...currentList.map((p) => p.id)])));
    }
  };

  const recipientLabel = (cap) => String(cap.recipient || cap.title || 'Cápsula').split('(')[0].trim();

  const handleOpenCapsuleChooser = async () => {
    if (selectedPhotoIds.length === 0) return;
    // Busca as cápsulas no servidor: num navegador novo a cópia local ainda está vazia
    await capsulesService.fetchCapsulesFromApi().catch(() => null);
    const list = capsulesService.getCapsules().filter((c) => c && c.id && !c.lockedContent);
    setCapsuleOptions(list);
    setTargetCapsuleId(list[0]?.id || 'new');
    setNewRecipientName('');
    setNewRelationship('');
    setIsCapsuleChooserOpen(true);
  };

  // As fotos (Google ou iPhone) já estão no cofre: entram na cápsula por referência (sem reenvio)
  const toCapsulePhoto = (photo) => {
    const mediaUrl = photo.mediaUrl || photo.media_url || '';
    const mediaId = mediaUrl.includes('/vault/media/') ? mediaUrl.split('/vault/media/')[1].split(/[/?]/)[0] : null;
    return {
      id: `${isIcloud ? 'ic' : 'gp'}-${photo.id}`,
      mediaId,
      memoryId: typeof photo.id === 'number' || /^\d+$/.test(String(photo.id)) ? Number(photo.id) : null,
      img: photo.fullUrl || photo.thumbnailUrl || null,
      title: photo.title || `Foto do ${sourceName}`,
      dateLabel: sourceName,
    };
  };

  const handleAddToCapsule = async () => {
    const selected = photos.filter((p) => selectedPhotoIds.includes(p.id)).map(toCapsulePhoto);
    if (selected.length === 0) return;

    let capsuleId = targetCapsuleId;
    let recipientName = '';
    if (targetCapsuleId === 'new') {
      recipientName = newRecipientName.trim();
      if (!recipientName) {
        setApiError('Informe para quem é a nova cápsula.');
        return;
      }
      capsuleId = `cap-${Date.now()}`;
    } else {
      recipientName = recipientLabel(capsuleOptions.find((c) => c.id === capsuleId) || {});
    }

    setIsImporting(true);
    setApiError(null);
    try {
      if (targetCapsuleId === 'new') {
        // Criada já com as fotos escolhidas (por referência ao cofre): sem fotos "de reserva"
        capsulesService.addCapsules({
          id: capsuleId,
          title: `Cápsula para ${recipientName}`,
          recipient: `${recipientName} (${newRelationship.trim() || 'Família'})`,
          avatar: null,
          sealColor: '#F05A47',
          photosCount: `${selected.length} ${selected.length === 1 ? 'foto' : 'fotos'}`,
          photos: selected.map((p) => ({ ...p, linkPending: Boolean(p.memoryId) })),
        });
      } else {
        await capsulesService.addPhotosToCapsule(capsuleId, selected);
      }
      setIsCapsuleChooserOpen(false);
      // A foto foi movida para a cápsula: sai desta área (cada foto fica num lugar só)
      const movedIds = new Set(selectedPhotoIds);
      setPhotos((prev) => prev.filter((p) => !movedIds.has(p.id)));
      setSelectedPhotoIds([]);
      setImportFeedback({
        type: 'success',
        text: `✨ ${selected.length} foto(s) adicionada(s) à cápsula de ${recipientName}!`,
      });
      setTimeout(() => {
        setIsImporting(false);
        setImportFeedback(null);
        setSelectedPhotoIds([]);
        onClose();
        try {
          sessionStorage.setItem('ethernity_capsules_initial_mode', 'list');
        } catch {
          // ignore
        }
        if (onNavigateTab) onNavigateTab('capsules');
      }, 1400);
    } catch (err) {
      console.error('Erro ao adicionar fotos à cápsula:', err);
      setIsImporting(false);
      setApiError('Não foi possível adicionar as fotos à cápsula.');
    }
  };

  if (!isOpen) return null;

  const modalContent = (
    <div className="gp-modal-overlay fade-in" onClick={onClose}>
      <div className="gp-modal-container" onClick={(e) => e.stopPropagation()}>
        {/* Top Header */}
        <div className="gp-modal-header">
          <div className="gp-header-brand">
            <div className="gp-logo-icon">
              <i className={isIcloud ? 'fa-brands fa-apple' : 'fa-solid fa-asterisk'}></i>
            </div>
            <div>
              <h2 className="gp-modal-title">{isIcloud ? 'Fotos do iPhone' : 'Google Fotos'}</h2>
              {!isIcloud && (
              <div className="gp-account-pill">
                {isConnected ? (
                  <>
                    {connection.avatarUrl && (
                      <img
                        src={connection.avatarUrl}
                        alt={connection.name || 'Google'}
                        className="gp-avatar-mini"
                      />
                    )}
                    <span className="gp-status-dot connected"></span>
                    <span>Conectado como: <strong>{connection.email || 'Conta Google Oficial'}</strong></span>
                    <span className="gp-oauth-tag official">Google Oficial</span>
                    <button
                      type="button"
                      className="gp-btn-switch-account"
                      onClick={handleDisconnect}
                      title="Desconectar ou alternar conta Google"
                    >
                      <i className="fa-solid fa-arrow-right-from-bracket"></i> Desconectar
                    </button>
                  </>
                ) : (
                  <>
                    <span className="gp-status-dot demo"></span>
                    <span>Conta não conectada</span>
                    <button
                      type="button"
                      className="gp-btn-connect-header"
                      onClick={handleConnectGoogle}
                    >
                      <i className="fa-brands fa-google"></i> Conectar Minha Conta Google
                    </button>
                  </>
                )}
              </div>
              )}
            </div>
          </div>

          <div className="gp-header-actions">
            <button
              type="button"
              className="gp-btn-close"
              onClick={onClose}
              title="Fechar galeria"
              aria-label="Fechar"
            >
              <i className="fa-solid fa-xmark"></i>
            </button>
          </div>
        </div>

        {/* Error Feedback Banner */}
        {apiError && (
          <div className="gp-api-error-banner fade-in">
            <div className="gp-api-error-content">
              <i className="fa-solid fa-circle-exclamation"></i>
              <div>
                <strong>Atenção:</strong> {apiError}
              </div>
            </div>
            <div className="gp-api-error-actions">
              <button
                type="button"
                className="gp-btn-retry-auth"
                onClick={handleConnectGoogle}
              >
                <i className="fa-brands fa-google"></i> Conectar com Google
              </button>
              <button
                type="button"
                className="gp-btn-dismiss-error"
                onClick={() => setApiError(null)}
              >
                <i className="fa-solid fa-xmark"></i>
              </button>
            </div>
          </div>
        )}

        {/* Feedback Banner */}
        {importFeedback && (
          <div className={`gp-import-feedback ${importFeedback.type}`}>
            {importFeedback.type === 'loading' && (
              <div className="gp-spinner-sm"></div>
            )}
            {importFeedback.type === 'success' && (
              <i className="fa-solid fa-circle-check"></i>
            )}
            {importFeedback.type === 'error' && (
              <i className="fa-solid fa-circle-exclamation"></i>
            )}
            {importFeedback.type === 'info' && (
              <i className="fa-solid fa-circle-info"></i>
            )}
            <span>{importFeedback.text}</span>
          </div>
        )}

        {/* =========================================================================
            CASO 1: CONTA NÃO CONECTADA (HERO CARD COM CTA REAL — SEM FOTOS FALSAS)
            ========================================================================= */}
        {!isConnected ? (
          <div className="gp-welcome-hero fade-in">
            <div className="gp-welcome-hero-card">
              <div className="gp-hero-icon-bubble">
                <i className="fa-solid fa-asterisk"></i>
              </div>
              <h3 className="gp-hero-title">Conecte sua Conta do Google Fotos</h3>
              <p className="gp-hero-subtitle">
                Escolha suas recordações diretamente no Google; as fotos importadas ficam cifradas (AES-256) no seu cofre.
                Nenhuma foto falsa ou simulada é exibida — você escolhe diretamente no Google Fotos quais memórias deseja trazer para o Ethernity.
              </p>

              <div className="gp-hero-features-grid">
                <div className="gp-hero-feature-item">
                  <i className="fa-solid fa-shield-halved"></i>
                  <div>
                    <strong>Privacidade Garantida</strong>
                    <span>Apenas as fotos que você selecionar manualmente no Google serão salvas.</span>
                  </div>
                </div>
                <div className="gp-hero-feature-item">
                  <i className="fa-solid fa-lock"></i>
                  <div>
                    <strong>Criptografia AES-256</strong>
                    <span>Cada foto é gravada com segurança de nível militar no seu Cofre Digital.</span>
                  </div>
                </div>
                <div className="gp-hero-feature-item">
                  <i className="fa-solid fa-cloud-arrow-down"></i>
                  <div>
                    <strong>Importação Sob Demanda</strong>
                    <span>Escolha facilmente as fotos que deseja trazer para o seu cofre.</span>
                  </div>
                </div>
                <div className="gp-hero-feature-item">
                  <i className="fa-brands fa-google"></i>
                  <div>
                    <strong>Google Photos Picker API</strong>
                    <span>Integração oficial em alta resolução, direto da nuvem do Google.</span>
                  </div>
                </div>
              </div>

              <div className="gp-hero-cta-wrap">
                <button
                  type="button"
                  className="gp-btn-hero-connect"
                  onClick={handleConnectGoogle}
                  disabled={isLoadingPhotos}
                >
                  <i className="fa-brands fa-google"></i>
                  <span>{isLoadingPhotos ? 'Conectando...' : 'Conectar Minha Conta Google'}</span>
                </button>
                <span className="gp-hero-security-note">
                  <i className="fa-solid fa-lock"></i> Autorização oficial via Google Cloud OAuth 2.0
                </span>
              </div>
            </div>
          </div>
        ) : (
          /* =========================================================================
              CASO 2: CONTA CONECTADA (SELETOR REAL GOOGLE PICKER & GALERIA)
              ========================================================================= */
          <>
            {/* iPhone: as fotos chegam pelo app Ethernity Fotos; aqui só se escolhe o destino */}
            {isIcloud ? (
            <div className="gp-picker-action-banner">
              <div className="gp-picker-action-left">
                <div className="gp-picker-action-icon">
                  <i className="fa-brands fa-apple"></i>
                </div>
                <div className="gp-picker-action-text">
                  <strong>Fotos enviadas pelo app do iPhone</strong>
                  <p>Escolha as fotos no app Ethernity Fotos, entrando com esta mesma conta. Elas aparecem aqui para você colocar numa cápsula.</p>
                </div>
              </div>
              <div className="gp-picker-action-right">
                <button
                  type="button"
                  className="gp-btn-open-picker"
                  onClick={() => loadPhotos({ reset: true })}
                  disabled={isLoadingPhotos}
                >
                  <i className="fa-solid fa-rotate"></i>
                  <span>{isLoadingPhotos ? 'Atualizando...' : 'Atualizar'}</span>
                </button>
              </div>
            </div>
            ) : (
            /* Banner de Ação do Google Photos Picker Oficial */
            <div className="gp-picker-action-banner">
              <div className="gp-picker-action-left">
                <div className="gp-picker-action-icon">
                  <i className="fa-brands fa-google"></i>
                </div>
                <div className="gp-picker-action-text">
                  <strong>Escolha fotos da sua conta no Google Fotos</strong>
                  <p>Abra a biblioteca oficial do Google para selecionar fotos de pessoas, datas ou álbuns com total privacidade.</p>
                </div>
              </div>

              <div className="gp-picker-action-right">
                {isPollingPicker ? (
                  <div className="gp-picker-polling-state">
                    <div className="gp-spinner-sm"></div>
                    <span className="gp-polling-msg">{pickerStatusText || 'Aguardando seleção no Google Fotos...'}</span>
                    {pickerSession?.pickerUri && (
                      <a
                        href={`${pickerSession.pickerUri.replace(/\/$/, '')}/autoclose`}
                        target="_blank"
                        rel="noreferrer"
                        className="gp-btn-reopen-picker"
                      >
                        <i className="fa-solid fa-arrow-up-right-from-square"></i> Abrir Seletor
                      </a>
                    )}
                    <button
                      type="button"
                      className="gp-btn-cancel-picker"
                      onClick={() => {
                        setIsPollingPicker(false);
                        setPickerSession(null);
                      }}
                    >
                      Cancelar
                    </button>
                  </div>
                ) : (
                  <button
                    type="button"
                    className="gp-btn-open-picker"
                    onClick={handleStartGooglePicker}
                    disabled={isOpeningPicker}
                  >
                    <i className="fa-solid fa-cloud-arrow-down"></i>
                    <span>{isOpeningPicker ? 'Abrindo Seletor...' : 'Escolher Fotos no Google Fotos'}</span>
                  </button>
                )}
              </div>
            </div>
            )}

            {/* Selection Stats Bar */}
            <div className="gp-selection-bar">
              <div className="gp-selection-stats">
                <span>
                  <i className="fa-solid fa-cloud-check" style={{ color: '#34A853', marginRight: '6px' }}></i>
                  {isIcloud ? 'Fotos recebidas do ' : 'Fotos reais importadas do seu '}<strong>{sourceName}</strong> ({photos.length} disponíveis)
                </span>
                {selectedPhotoIds.length > 0 ? (
                  <span className="gp-selected-badge">
                    <i className="fa-solid fa-check"></i> {selectedPhotoIds.length} selecionada(s)
                  </span>
                ) : photos.length > 0 && (
                  <span className="gp-select-hint">
                    <i className="fa-regular fa-hand-pointer"></i> Toque nas fotos para marcar e depois em "Adicionar à Cápsula"
                  </span>
                )}
              </div>

              <div className="gp-selection-actions">
                <button
                  type="button"
                  className="gp-btn-select-all"
                  onClick={handleSelectAll}
                >
                  {selectedPhotoIds.length === displayedPhotos.length && displayedPhotos.length > 0
                    ? 'Desmarcar Todas'
                    : 'Selecionar Todas'}
                </button>
              </div>
            </div>

            {/* Photos Scroll Area */}
            <div className="gp-photos-scroll-area">
              {isLoadingPhotos && photos.length === 0 ? (
                <div className="gp-loading-state">
                  <div className="gp-spinner-lg"></div>
                  <h3>Carregando suas fotos do {sourceName}...</h3>
                  <p>Consultando sua galeria segura e protegida.</p>
                </div>
              ) : displayedPhotos.length === 0 ? (
                <div className="gp-empty-state">
                  <div className="gp-empty-icon-wrap">
                    <i className="fa-solid fa-images gp-empty-icon"></i>
                  </div>
                  <h3>{isIcloud ? 'Nenhuma foto do iPhone esperando cápsula' : 'Nenhuma foto importada ainda'}</h3>
                  <p>
                    {isIcloud
                      ? 'Abra o app Ethernity Fotos no iPhone, entre com esta conta e escolha as fotos. Depois toque em "Atualizar".'
                      : 'Sua conta está conectada! Clique no botão abaixo para abrir o seletor oficial do Google Fotos e escolher quais recordações trazer para o Ethernity.'}
                  </p>
                  {!isIcloud && (
                  <button
                    type="button"
                    className="gp-btn-connect-primary"
                    onClick={handleStartGooglePicker}
                    disabled={isOpeningPicker || isPollingPicker}
                  >
                    <i className="fa-solid fa-cloud-arrow-down"></i>
                    <span>{isOpeningPicker ? 'Abrindo...' : 'Escolher Fotos no Google Fotos'}</span>
                  </button>
                  )}
                </div>
              ) : (
                <>
                  <div className="gp-photos-grid">
                    {displayedPhotos.map((photo) => {
                      const isSelected = selectedPhotoIds.includes(photo.id);
                      return (
                        <div
                          key={photo.id}
                          className={`gp-photo-card ${isSelected ? 'selected' : ''}`}
                          onClick={() => handleToggleSelect(photo.id)}
                        >
                          <div className="gp-photo-thumb-wrap">
                            <img
                              src={photo.thumbnailUrl || photo.fullUrl}
                              alt={photo.title}
                              className="gp-photo-img"
                              loading="lazy"
                            />
                            <div className={`gp-photo-check ${isSelected ? 'active' : ''}`}>
                              <i className="fa-solid fa-check"></i>
                            </div>

                            <button
                              type="button"
                              className="gp-btn-lightbox-trigger"
                              onClick={(e) => {
                                e.stopPropagation();
                                setLightboxPhoto(photo);
                              }}
                              title="Ampliar visualização"
                            >
                              <i className="fa-solid fa-expand"></i>
                            </button>

                            <div className="gp-photo-overlay-info">
                              <span className="gp-photo-date">
                                <i className="fa-regular fa-calendar"></i> {photo.date}
                              </span>
                              {photo.camera && (
                                <span className="gp-photo-camera">
                                  <i className="fa-solid fa-camera"></i> {photo.camera}
                                </span>
                              )}
                            </div>
                          </div>

                          <div className="gp-photo-meta">
                            <h4 className="gp-photo-title">{photo.title}</h4>
                            {photo.description && (
                              <p className="gp-photo-desc">{photo.description}</p>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>

                  {/* Load More Button */}
                  {nextPageToken && (
                    <div className="gp-load-more-wrap">
                      <button
                        type="button"
                        className="gp-btn-load-more"
                        onClick={handleLoadMore}
                        disabled={isLoadingPhotos}
                      >
                        {isLoadingPhotos ? (
                          <>
                            <div className="gp-spinner-sm"></div>
                            <span>Carregando mais fotos...</span>
                          </>
                        ) : (
                          <>
                            <i className="fa-solid fa-arrow-down"></i>
                            <span>Carregar Mais Fotos</span>
                          </>
                        )}
                      </button>
                    </div>
                  )}
                </>
              )}
            </div>

            {/* Footer Actions */}
            <div className="gp-modal-footer">
              <div className="gp-footer-security">
                <i className="fa-solid fa-shield-halved"></i>
                <span>
                  Privacidade Garantida: Apenas as fotos que você selecionar são importadas e criptografadas.
                </span>
              </div>

              <div className="gp-footer-buttons">
                <button
                  type="button"
                  className="gp-btn-primary"
                  onClick={handleOpenCapsuleChooser}
                  disabled={selectedPhotoIds.length === 0 || isImporting}
                >
                  <i className="fa-solid fa-gift"></i>
                  <span>Adicionar à Cápsula ({selectedPhotoIds.length})</span>
                </button>
              </div>
            </div>

            {/* Escolha do destino: cápsula existente ou nova */}
            {isCapsuleChooserOpen && (
              <div className="gp-capsule-chooser-backdrop fade-in" onClick={() => !isImporting && setIsCapsuleChooserOpen(false)}>
                <div className="gp-capsule-chooser" role="dialog" aria-label="Escolher cápsula" onClick={(e) => e.stopPropagation()}>
                  <h3>Adicionar {selectedPhotoIds.length} foto(s) a uma cápsula</h3>
                  <p className="gp-capsule-chooser-sub">Escolha uma cápsula sua ou crie uma nova.</p>

                  <div className="gp-capsule-options">
                    {capsuleOptions.map((cap) => (
                      <label key={cap.id} className={`gp-capsule-option ${targetCapsuleId === cap.id ? 'active' : ''}`}>
                        <input
                          type="radio"
                          name="gp-target-capsule"
                          checked={targetCapsuleId === cap.id}
                          onChange={() => setTargetCapsuleId(cap.id)}
                        />
                        <span className="gp-capsule-option-icon"><i className="fa-solid fa-gift"></i></span>
                        <span className="gp-capsule-option-text">
                          <strong className="person-name">{recipientLabel(cap)}</strong>
                          <small>{cap.photosCount || 'Sem fotos'}</small>
                        </span>
                      </label>
                    ))}

                    <label className={`gp-capsule-option ${targetCapsuleId === 'new' ? 'active' : ''}`}>
                      <input
                        type="radio"
                        name="gp-target-capsule"
                        checked={targetCapsuleId === 'new'}
                        onChange={() => setTargetCapsuleId('new')}
                      />
                      <span className="gp-capsule-option-icon new"><i className="fa-solid fa-plus"></i></span>
                      <span className="gp-capsule-option-text">
                        <strong>Criar nova cápsula</strong>
                        <small>Para alguém especial</small>
                      </span>
                    </label>
                  </div>

                  {targetCapsuleId === 'new' && (
                    <div className="gp-new-capsule-fields">
                      <input
                        type="text"
                        placeholder="Para quem é? (nome)"
                        value={newRecipientName}
                        onChange={(e) => setNewRecipientName(e.target.value)}
                        maxLength={80}
                        autoFocus
                      />
                      <input
                        type="text"
                        placeholder="Relação (ex.: Filha, Neto, Amigo)"
                        value={newRelationship}
                        onChange={(e) => setNewRelationship(e.target.value)}
                        maxLength={40}
                      />
                    </div>
                  )}

                  <div className="gp-capsule-chooser-actions">
                    <button type="button" className="gp-btn-secondary" onClick={() => setIsCapsuleChooserOpen(false)} disabled={isImporting}>
                      Cancelar
                    </button>
                    <button
                      type="button"
                      className="gp-btn-primary"
                      onClick={handleAddToCapsule}
                      disabled={isImporting || (targetCapsuleId === 'new' && !newRecipientName.trim())}
                    >
                      <i className={`fa-solid ${isImporting ? 'fa-arrows-rotate fa-spin' : 'fa-check'}`}></i>
                      <span>{isImporting ? 'Adicionando...' : 'Adicionar'}</span>
                    </button>
                  </div>
                </div>
              </div>
            )}
          </>
        )}

        {/* Fullscreen Lightbox Preview */}
        {lightboxPhoto && (
          <div
            className="gp-lightbox-backdrop fade-in"
            onClick={() => setLightboxPhoto(null)}
          >
            <div
              className="gp-lightbox-content"
              onClick={(e) => e.stopPropagation()}
            >
              <button
                type="button"
                className="gp-lightbox-close"
                onClick={() => setLightboxPhoto(null)}
              >
                <i className="fa-solid fa-xmark"></i>
              </button>

              <div className="gp-lightbox-media-wrap">
                <img
                  src={lightboxPhoto.fullUrl || lightboxPhoto.thumbnailUrl}
                  alt={lightboxPhoto.title}
                  className="gp-lightbox-img"
                />
              </div>

              <div className="gp-lightbox-sidebar">
                <div className="gp-lightbox-badge">
                  <i className={isIcloud ? 'fa-brands fa-apple' : 'fa-solid fa-asterisk'}></i> {sourceName} HD
                </div>
                <h3>{lightboxPhoto.title}</h3>
                {lightboxPhoto.description && (
                  <p className="gp-lb-desc">{lightboxPhoto.description}</p>
                )}

                <div className="gp-lb-details-list">
                  <div className="gp-lb-detail-item">
                    <i className="fa-regular fa-calendar"></i>
                    <div>
                      <label>Data da Memória</label>
                      <span>{lightboxPhoto.date}</span>
                    </div>
                  </div>

                  <div className="gp-lb-detail-item">
                    <i className="fa-solid fa-location-dot"></i>
                    <div>
                      <label>Localização</label>
                      <span>{lightboxPhoto.location || sourceName}</span>
                    </div>
                  </div>

                  <div className="gp-lb-detail-item">
                    <i className="fa-solid fa-lock"></i>
                    <div>
                      <label>Segurança de Importação</label>
                      <span>Guardadas cifradas (AES-256-GCM)</span>
                    </div>
                  </div>
                </div>

                <div className="gp-lb-actions">
                  <button
                    type="button"
                    className={`gp-lb-select-btn ${
                      selectedPhotoIds.includes(lightboxPhoto.id) ? 'selected' : ''
                    }`}
                    onClick={() => handleToggleSelect(lightboxPhoto.id)}
                  >
                    {selectedPhotoIds.includes(lightboxPhoto.id) ? (
                      <>
                        <i className="fa-solid fa-check"></i> Foto Selecionada
                      </>
                    ) : (
                      <>
                        <i className="fa-solid fa-plus"></i> Selecionar para Importar
                      </>
                    )}
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );

  return typeof document !== 'undefined' ? createPortal(modalContent, document.body) : modalContent;
}
