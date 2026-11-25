import React, { useState, useEffect } from 'react';
import capsulesService from '../../capsules/application/capsulesService.js';
import './VaultView.css';

export const INITIAL_VAULT_ITEMS = [];

export default function VaultView({ onNavigateTab }) {
  const [activeTab, setActiveTab] = useState('capsules');
  const [capsules, setCapsules] = useState(() => capsulesService.getCapsules());

  // Sincronização reativa estrita com o capsulesService (zero mocks, ambiente 100% autêntico)
  useEffect(() => {
    // 1. Expurgo definitivo da chave de cache legada de mocks para evitar ressuscitação de dados (AGENTS.md)
    try {
      localStorage.removeItem('ethernity_vault_capsules_list');
    } catch {}

    // 2. Busca inicial do backend e subscrição reativa
    capsulesService
      .fetchCapsulesFromApi()
      .then((data) => {
        if (Array.isArray(data)) setCapsules(data);
      })
      .catch(() => {});

    const unsubscribe = capsulesService.subscribe((updated) => {
      setCapsules(Array.isArray(updated) ? updated : []);
    });

    return () => unsubscribe();
  }, []);

  // Métricas dinâmicas reais baseadas nas cápsulas salvas
  const totalCapsulas = capsules.length;
  const totalMemorias = capsules.reduce((acc, curr) => {
    const photos = Array.isArray(curr.photos) ? curr.photos.length : 0;
    const audios = Array.isArray(curr.audios) ? curr.audios.length : 0;
    const messages = Array.isArray(curr.messages) ? curr.messages.length : 0;
    const sum = photos + audios + messages;
    return acc + (sum > 0 ? sum : curr.memoriesCount || 0);
  }, 0);

  const handleDelete = async (id, e) => {
    e.stopPropagation();
    if (window.confirm('Deseja excluir definitivamente esta cápsula do cofre? Esta ação é irreversível (expurgo estanque).')) {
      await capsulesService.deleteCapsule(id);
      setCapsules(capsulesService.getCapsules());
    }
  };

  const handleOpenCapsule = (cap) => {
    try {
      sessionStorage.setItem('ethernity_selected_capsule', JSON.stringify(cap));
    } catch {}
    if (onNavigateTab) {
      onNavigateTab('capsules');
    }
  };

  const handleNewCapsule = () => {
    if (onNavigateTab) {
      onNavigateTab('capsules');
    }
  };

  return (
    <div className="vault-screen-wrapper">
      <div className="vault-main-content">
        {/* O caminho fica na barra do topo; aqui só a ação principal */}
        <div className="vault-header-row">
          <button
            type="button"
            className="btn-nova-capsula"
            onClick={handleNewCapsule}
          >
            <span className="btn-icon">+</span> Nova Cápsula
          </button>
        </div>

        {/* Abas de Navegação do Cofre */}
        <div className="vault-nav-tabs">
          <button
            type="button"
            className={`vault-nav-tab-btn ${activeTab === 'capsules' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('capsules')}
          >
            <i className="fa-solid fa-box-archive"></i>
            Cápsulas da Família ({totalCapsulas})
          </button>
        </div>

        {/* ABA 1: CÁPSULAS DA FAMÍLIA */}
        {activeTab === 'capsules' && (
          <>
            {/* Cards de Métricas */}
            <div className="vault-stats-row">
              <div className="vault-metric-card">
                <div className="metric-icon-box clock-icon-box">
                  <i className="fa-regular fa-clock"></i>
                </div>
                <div className="metric-text-content">
                  <span className="metric-label">NO COFRE</span>
                  <span className="metric-value font-handwritten">
                    {totalCapsulas} {totalCapsulas === 1 ? 'Cápsula' : 'Cápsulas'}
                  </span>
                </div>
              </div>

              <div className="vault-metric-card">
                <div className="metric-icon-box mail-icon-box">
                  <i className="fa-regular fa-envelope"></i>
                </div>
                <div className="metric-text-content">
                  <span className="metric-label">TOTAL DE MEMÓRIAS</span>
                  <span className="metric-value font-handwritten">
                    {totalMemorias} {totalMemorias === 1 ? 'Criada' : 'Criadas'}
                  </span>
                </div>
              </div>
            </div>

            {/* Lista de Cápsulas ou Empty State */}
            <div className="vault-capsules-list">
              {capsules.length === 0 ? (
                <div className="vault-empty-state">
                  <div className="vault-empty-icon-circle">
                    <i className="fa-solid fa-box-archive"></i>
                  </div>
                  <h3 className="vault-empty-title">Nenhuma cápsula guardada no cofre ainda</h3>
                  <p className="vault-empty-desc">
                    Suas cápsulas do tempo e surpresas familiares aparecerão aqui, guardadas cifradas e visíveis só para a sua conta.
                  </p>
                  <button
                    type="button"
                    className="btn-nova-capsula"
                    onClick={handleNewCapsule}
                    style={{ marginTop: '16px' }}
                  >
                    + Criar Minha Primeira Cápsula
                  </button>
                </div>
              ) : (
                capsules.map((cap, index) => {
                  const avatarUri = cap.avatar || cap.recipientAvatar;
                  const displayName = cap.recipient || cap.title || 'Cápsula Familiar';
                  const photosCount = Array.isArray(cap.photos)
                    ? cap.photos.length
                    : cap.photosCount || 0;
                  const memoriesCount =
                    (Array.isArray(cap.audios) ? cap.audios.length : 0) +
                    (Array.isArray(cap.messages) ? cap.messages.length : 0) ||
                    cap.memoriesCount ||
                    1;
                  const dateStr = cap.createdAt
                    ? new Date(cap.createdAt).toLocaleDateString('pt-BR')
                    : 'Hoje';

                  return (
                    <div
                      key={cap.id || index}
                      className="vault-capsule-row"
                      onClick={() => handleOpenCapsule(cap)}
                    >
                      <div className="capsule-left-section">
                        {avatarUri ? (
                          <img
                            src={avatarUri}
                            alt={displayName}
                            className="capsule-avatar-img"
                          />
                        ) : (
                          <div className="capsule-avatar-fallback">
                            <span>{displayName.slice(0, 2).toUpperCase()}</span>
                          </div>
                        )}
                        <div className="capsule-info-block">
                          <h3 className="capsule-person-name person-name">{displayName}</h3>
                          <span className="capsule-meta-counts">
                            {memoriesCount} {memoriesCount === 1 ? 'memória' : 'memórias'} • {photosCount} fotos
                          </span>
                        </div>
                      </div>

                      <div className="capsule-right-section">
                        <div className="capsule-date-block">
                          <span className="capsule-date-label">Criado em</span>
                          <span className="capsule-date-value">{dateStr}</span>
                        </div>
                        <button
                          type="button"
                          className="btn-trash-action"
                          onClick={(e) => handleDelete(cap.id, e)}
                          title="Excluir Definitivamente"
                        >
                          <i className="fa-regular fa-trash-can"></i>
                        </button>
                        <div className="capsule-arrow-action">
                          <i className="fa-solid fa-chevron-right"></i>
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
