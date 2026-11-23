import React, { useState, useEffect, useCallback, Suspense, lazy } from 'react';
import Sidebar from '../../../core/components/Sidebar.jsx';
import BottomNav from '../../../core/components/BottomNav.jsx';
import HeaderActions from '../../../core/components/HeaderActions.jsx';
import NetworkStatusBar from '../../../core/components/NetworkStatusBar.jsx';
import logoIconSvg from '../../../assets/logo-icon.svg';
import BrandWordmark from '../../../core/components/BrandWordmark.jsx';
import profileRepository from '../../profile/infrastructure/profileRepository.js';
import { isUserAdmin as checkIsUserAdmin } from '../../profile/domain/profileModel.js';
import subscriptionRepository from '../../subscription/infrastructure/subscriptionRepository.js';
import { isUserUnlimited, getEffectiveUserPlan } from '../../subscription/domain/subscriptionModel.js';
import loadingService from '../../../core/services/loadingService.js';
import ErrorBoundary from '../../../core/components/ErrorBoundary.jsx';
import { useI18n } from '../../../core/i18n/presentation/useI18n.js';
import '../../../styles/MainApp.css';
import vaultKeyService from '../../../core/services/vaultKeyService.js';
import VaultKeyModal from '../../profile/presentation/VaultKeyModal.jsx';

// Code Splitting sob demanda para carregamento instantâneo do bundle
const ConnectMemoriesView = lazy(() => import('../../memories/presentation/ConnectMemoriesView.jsx'));
const VaultView = lazy(() => import('../../vault/presentation/VaultView.jsx'));
const GuardianView = lazy(() => import('../../guardian/presentation/GuardianView.jsx'));
const CapsulesView = lazy(() => import('../../capsules/presentation/CapsulesView.jsx'));
const AvatarVoiceView = lazy(() => import('../../avatar/presentation/AvatarVoiceView.jsx'));
const LegacyCalendarView = lazy(() => import('../../calendar/presentation/LegacyCalendarView.jsx'));
const ProfileView = lazy(() => import('../../profile/presentation/ProfileView.jsx'));
const SubscriptionPlansView = lazy(() => import('../../subscription/presentation/SubscriptionPlansView.jsx'));
const AdminUsersView = lazy(() => import('../../admin/presentation/AdminUsersView.jsx'));
const AdminDashboardView = lazy(() => import('../../admin/presentation/AdminDashboardView.jsx'));
const HelpView = lazy(() => import('../../help/presentation/HelpView.jsx'));

function TabLoadingFallback() {
  const { t } = useI18n();
  return (
    <div
      className="tab-loading-fallback"
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: '320px',
        padding: '40px 20px',
        gap: '14px',
        color: 'var(--color-text-muted, #64748b)',
      }}
    >
      <div
        style={{
          width: '36px',
          height: '36px',
          border: '3px solid rgba(238, 89, 69, 0.2)',
          borderTopColor: 'var(--color-primary, #EE5945)',
          borderRadius: '50%',
          animation: 'spin 0.8s linear infinite',
        }}
      />
      <span style={{ fontSize: '13px', fontWeight: 500 }}>
        {t('common.loading') || 'Carregando...'}
      </span>
    </div>
  );
}

// Abas removidas do menu: uma sessão antiga que as tenha salvo abre o início.
const REMOVED_TABS = ['messages_new'];

export default function MainApp({ token, onLogout }) {
  const { t } = useI18n();
  const [activeTab, setActiveTab] = useState(() => {
    try {
      const saved = sessionStorage.getItem('ethernity_active_tab');
      // Abas que não existem mais voltam para o início (ex.: Mensagens, issue #11)
      return saved && !REMOVED_TABS.includes(saved) ? saved : 'memories';
    } catch {
      return 'memories';
    }
  });

  const handleNavigateTab = useCallback((tabId) => {
    if (tabId !== activeTab) {
      try {
        loadingService.start();
      } catch {}
      setActiveTab(tabId);
      setTimeout(() => {
        try {
          loadingService.done();
        } catch {}
      }, 180);
    }
  }, [activeTab]);

  const [profile, setProfile] = useState(() => {
    try {
      const stored = localStorage.getItem('ethernity_user');
      return stored ? JSON.parse(stored) : null;
    } catch {
      return null;
    }
  });

  const [subscription, setSubscription] = useState(null);
  const [subscriptionReturnTab, setSubscriptionReturnTab] = useState('profile');
  const [toastMessage, setToastMessage] = useState('');
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
  const [theme, setTheme] = useState(() => localStorage.getItem('ethernity_theme') || 'light');
  const [operatingMode, setOperatingMode] = useState(() => {
    try {
      return sessionStorage.getItem('ethernity_operating_mode') || 'personal';
    } catch {
      return 'personal';
    }
  });

  // O perfil em cache pode ser de outra sessão: o acesso admin só vale depois que o servidor confirma.
  const [isProfileVerified, setIsProfileVerified] = useState(false);
  const [isVaultUnlockOpen, setIsVaultUnlockOpen] = useState(false);

  // Cofre de conhecimento zero: pede a senha ao abrir o app bloqueado e sempre que algo precisar da chave.
  useEffect(() => {
    const unsubscribe = vaultKeyService.onUnlockRequest(() => setIsVaultUnlockOpen(true));
    vaultKeyService.refresh().then((status) => {
      if (status === 'locked') setIsVaultUnlockOpen(true);
    });
    return unsubscribe;
  }, []);
  const isUserAdmin = isProfileVerified && checkIsUserAdmin(profile);
  const effectiveOperatingMode = isUserAdmin ? operatingMode : 'personal';

  const handleSwitchOperatingMode = useCallback((mode) => {
    setOperatingMode(mode);
    try {
      sessionStorage.setItem('ethernity_operating_mode', mode);
    } catch {}
  }, []);

  useEffect(() => {
    if (!isProfileVerified || isUserAdmin) return;
    if (operatingMode !== 'personal') handleSwitchOperatingMode('personal');
    if (String(activeTab).startsWith('admin_')) handleNavigateTab('memories');
  }, [isProfileVerified, isUserAdmin, operatingMode, activeTab, handleSwitchOperatingMode, handleNavigateTab]);

  const handleOpenSubscription = useCallback(() => {
    setSubscriptionReturnTab(activeTab === 'subscription' ? 'profile' : activeTab);
    handleNavigateTab('subscription');
  }, [activeTab, handleNavigateTab]);

  // Sincronizar tema com atributo data-theme no HTML
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('ethernity_theme', theme);
  }, [theme]);

  // Sincronizar aba ativa para persistir durante recarregamento
  useEffect(() => {
    try {
      sessionStorage.setItem('ethernity_active_tab', activeTab);
    } catch (err) {
      console.error('Erro ao salvar aba ativa:', err);
    }
  }, [activeTab]);

  const showToast = useCallback((msg) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(''), 4000);
  }, []);

  const toggleTheme = () => {
    setTheme((prev) => {
      const next = prev === 'light' ? 'dark' : 'light';
      showToast(`Tema alterado para ${next === 'dark' ? 'Modo Escuro' : 'Modo Claro'}`);
      return next;
    });
  };

  // Carrega os dados da API ao iniciar
  const loadData = useCallback(async () => {
    try {
      const [profData, subData] = await Promise.all([
        profileRepository.getProfile(token),
        subscriptionRepository.getCurrentSubscription(token).catch(() => null),
      ]);
      if (profData) {
        setProfile(profData);
        setIsProfileVerified(true);
        try {
          localStorage.setItem('ethernity_user', JSON.stringify(profData));
        } catch {}
      }
      if (isUserUnlimited(profData)) {
        const infinityPlan = getEffectiveUserPlan(null, profData);
        setSubscription({
          planId: infinityPlan.id,
          planName: infinityPlan.name,
          status: 'active',
          cycle: 'yearly',
          maxDevices: infinityPlan.maxDevices,
          maxGeofences: infinityPlan.maxGeofences,
          maxRecipients: infinityPlan.maxRecipients,
          historyDays: infinityPlan.historyDays,
          expiresAt: null,
        });
      } else if (subData?.subscription) {
        setSubscription(subData.subscription);
      }
    } catch (err) {
      console.error('Erro ao carregar dados do app:', err);
      const errMsg = err?.message || '';
      if (
        err?.status === 401 ||
        err?.status === 403 ||
        errMsg.includes('401') ||
        errMsg.toLowerCase().includes('unauthorized') ||
        errMsg.toLowerCase().includes('token') ||
        errMsg.toLowerCase().includes('não autorizado') ||
        errMsg.toLowerCase().includes('credentials')
      ) {
        if (onLogout) onLogout();
      }
    }
  }, [token, onLogout]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Retorno da página de assinatura do Mercado Pago (back_url com ?checkout=<id>)
  useEffect(() => {
    if (!token || typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    const checkoutId = params.get('checkout');
    if (!checkoutId) return;

    params.delete('checkout');
    const query = params.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);

    subscriptionRepository.getCheckout(checkoutId, token)
      .then((res) => {
        if (res?.subscription) setSubscription(res.subscription);
        const status = res?.checkout?.status;
        if (status === 'approved') {
          showToast?.('Assinatura confirmada! Seu plano já está ativo.');
        } else if (status === 'canceled' || status === 'rejected') {
          showToast?.('A assinatura não foi concluída no Mercado Pago.');
        } else {
          showToast?.('Assinatura recebida! O plano é liberado assim que o Mercado Pago confirmar a primeira cobrança.');
        }
      })
      .catch(() => {});
  }, [token, showToast]);

  return (
    <div className={`app-container ${isSidebarCollapsed ? 'sidebar-collapsed' : ''}`}>
      {/* Indicador Global de Rede (PWA Offline / Reconnected) */}
      <NetworkStatusBar />

      {/* 1. SIDEBAR DESKTOP (Menu à esquerda) */}
      <Sidebar
        activeTab={activeTab}
        setActiveTab={handleNavigateTab}
        onLogout={onLogout}
        isCollapsed={isSidebarCollapsed}
        onToggleCollapse={() => setIsSidebarCollapsed(!isSidebarCollapsed)}
        theme={theme}
        onToggleTheme={toggleTheme}
        profile={profile}
        operatingMode={effectiveOperatingMode}
        onToggleOperatingMode={handleSwitchOperatingMode}
      />

      {/* 2. ÁREA PRINCIPAL */}
      <div className="main-content" style={{ position: 'relative' }}>
        
        {/* Topbar Mobile (Visível apenas em telas menores) */}
        <header className="mobile-topbar">
          <div className="mobile-topbar-spacer" aria-hidden="true"></div>
          <div className="mobile-brand">
            <img src={logoIconSvg} alt="Ethernity DMs" className="mobile-logo-icon" />
            <BrandWordmark className="mobile-logo-text" />
          </div>
          <HeaderActions
            onNavigateTab={handleNavigateTab}
          />
        </header>

        {/* Notificação Toast Flutuante */}
        {toastMessage && (
          <div
            style={{
              position: 'fixed',
              top: '24px',
              right: '24px',
              backgroundColor: 'var(--color-primary-dark, #2D1917)',
              color: '#FFFFFF',
              padding: '12px 20px',
              borderRadius: 'var(--radius-md, 12px)',
              boxShadow: 'var(--shadow-lg, 0 10px 25px rgba(0,0,0,0.15))',
              zIndex: 200,
              fontSize: '13px',
              fontWeight: 500,
              display: 'flex',
              alignItems: 'center',
              gap: '10px',
            }}
          >
            <i className="fa-solid fa-bell" style={{ color: 'var(--color-warning, #f59e0b)' }}></i>
            <span>{toastMessage}</span>
          </div>
        )}

        {/* -------------------------------------------------------------
            ABA: COMECE AQUI / CONEXÃO & CURADORIA DE MEMÓRIAS (TELA PRINCIPAL)
           ------------------------------------------------------------- */}
        {activeTab === 'memories' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <ConnectMemoriesView onNavigateTab={handleNavigateTab} profile={profile} />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: MEU COFRE DIGITAL (CÁPSULAS E MEMÓRIAS)
           ------------------------------------------------------------- */}
        {activeTab === 'vault' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <VaultView onNavigateTab={handleNavigateTab} profile={profile} />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: GUARDIÃO & PROTOCOLO DE CONFIANÇA
           ------------------------------------------------------------- */}
        {activeTab === 'guardian' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <GuardianView onNavigateTab={handleNavigateTab} />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: CÁPSULAS DO TEMPO (ENTREGAS PROGRAMADAS & PÓSTUMAS)
           ------------------------------------------------------------- */}
        {activeTab === 'capsules' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <CapsulesView onNavigateTab={handleNavigateTab} profile={profile} />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: AVATAR E ETERNIZAÇÃO DE VOZ
           ------------------------------------------------------------- */}
        {activeTab === 'avatar' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <AvatarVoiceView onNavigateTab={handleNavigateTab} />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: CALENDÁRIO DE LEGADO
           ------------------------------------------------------------- */}
        {activeTab === 'calendar' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <LegacyCalendarView onNavigateTab={handleNavigateTab} />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: MEU PLANO & ASSINATURA
           ------------------------------------------------------------- */}
        {activeTab === 'subscription' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <SubscriptionPlansView
                currentSubscription={subscription}
                token={token}
                onSubscriptionUpdated={setSubscription}
                showToast={showToast}
                onBack={() => handleNavigateTab(subscriptionReturnTab)}
              />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: PERFIL FAMILIAR (EDITÁVEL & UPLOAD DE FOTO)
           ------------------------------------------------------------- */}
        {activeTab === 'profile' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <ProfileView
                profile={profile}
                setProfile={setProfile}
                token={token}
                onNavigateTab={handleNavigateTab}
                onLogout={onLogout}
                showToast={showToast}
                theme={theme}
                toggleTheme={toggleTheme}
                subscription={subscription}
                onOpenSubscription={handleOpenSubscription}
              />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: ADMIN - GESTÃO DE USUÁRIOS (Acesso Restrito)
           ------------------------------------------------------------- */}
        {activeTab === 'admin_users' && isUserAdmin && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <AdminUsersView
                token={token}
                profile={profile}
                onNavigateTab={handleNavigateTab}
                showToast={showToast}
                onSwitchMode={handleSwitchOperatingMode}
              />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: ADMIN - DASHBOARD EXECUTIVO (Acesso Restrito)
           ------------------------------------------------------------- */}
        {activeTab === 'admin_dashboard' && isUserAdmin && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <AdminDashboardView
                token={token}
                profile={profile}
                onNavigateTab={handleNavigateTab}
                onSwitchMode={handleSwitchOperatingMode}
              />
            </Suspense>
          </ErrorBoundary>
        )}

        {/* -------------------------------------------------------------
            ABA: CENTRAL DE AJUDA & FAQ
           ------------------------------------------------------------- */}
        {activeTab === 'help' && (
          <ErrorBoundary isEmbedded={true}>
            <Suspense fallback={<TabLoadingFallback />}>
              <HelpView onNavigateTab={handleNavigateTab} />
            </Suspense>
          </ErrorBoundary>
        )}

      </div>

      {/* 3. MOBILE BOTTOM NAVIGATION BAR */}
      <BottomNav
        activeTab={activeTab}
        setActiveTab={handleNavigateTab}
      />

      {isVaultUnlockOpen && <VaultKeyModal initialMode="unlock" onClose={() => setIsVaultUnlockOpen(false)} />}
    </div>
  );
}

