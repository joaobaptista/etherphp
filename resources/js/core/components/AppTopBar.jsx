import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import NotificationBell from './NotificationBell.jsx';
import BrandWordmark from './BrandWordmark.jsx';
import logoIconSvg from '../../assets/logo-icon.svg';
import { useI18n } from '../i18n/presentation/useI18n.js';
import vaultKeyService from '../services/vaultKeyService.js';
import capsulesService from '../../modules/capsules/application/capsulesService.js';
import { LOCKED_TEXT } from '../services/fieldCipher.js';
import { getUserInitials, getAbsolutePhotoUrl } from '../../modules/profile/domain/profileModel.js';
import './AppTopBar.css';

// Páginas que a busca encontra e que aparecem na trilha (label = chave de tradução)
const PERSONAL_PAGES = [
  { id: 'memories', label: 'nav.memories', icon: 'fa-regular fa-compass' },
  { id: 'guardian', label: 'nav.guardian', icon: 'fa-solid fa-shield-halved' },
  { id: 'vault', label: 'nav.vault', icon: 'fa-regular fa-square-plus' },
  { id: 'capsules', label: 'nav.capsules', icon: 'fa-solid fa-gift' },
  { id: 'avatar', label: 'nav.avatar', icon: 'fa-regular fa-circle-dot' },
  { id: 'calendar', label: 'nav.calendar', icon: 'fa-regular fa-calendar' },
  { id: 'help', label: 'nav.help', icon: 'fa-regular fa-circle-question' },
  { id: 'profile', label: 'userMenu.settings', icon: 'fa-solid fa-gear' },
  { id: 'subscription', label: 'profile.myPlanCardTitle', icon: 'fa-solid fa-crown' },
  { id: 'vault_security', label: 'vaultSecurity.title', icon: 'fa-solid fa-lock' },
];
const ADMIN_PAGES = [
  { id: 'admin_dashboard', label: 'admin.adminDashboard', icon: 'fa-solid fa-chart-line' },
  { id: 'admin_users', label: 'admin.adminUsers', icon: 'fa-solid fa-users-gear' },
];

const VAULT_STATUS_TEXT = {
  not_configured: 'vaultSecurity.statusNotConfigured',
  locked: 'vaultSecurity.statusLocked',
  unlocked: 'vaultSecurity.statusUnlocked',
};

// Compara sem acento e sem diferença de maiúsculas
const normalize = (value) =>
  String(value || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();

/**
 * Barra do topo: trilha da página à esquerda; busca, atalhos e menu da conta à direita.
 */
export default function AppTopBar({
  activeTab,
  onNavigateTab,
  profile,
  isAdmin = false,
  operatingMode = 'personal',
  onSwitchOperatingMode,
  subscription,
  onOpenSubscription,
  onOpenCapsule,
  onLogout,
}) {
  const { t } = useI18n();

  // ---- Trilha (breadcrumb)
  const pages = useMemo(() => (isAdmin ? [...PERSONAL_PAGES, ...ADMIN_PAGES] : PERSONAL_PAGES), [isAdmin]);
  const currentPage = pages.find((page) => page.id === activeTab);

  // ---- Busca
  const [query, setQuery] = useState('');
  const [isSearchOpen, setIsSearchOpen] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const searchRef = useRef(null);
  const searchInputRef = useRef(null);

  // Cápsulas da busca: o cache deste aparelho, atualizado pelo servidor na primeira busca
  const [capsules, setCapsules] = useState(() => capsulesService.getCapsules() || []);
  const capsulesFetched = useRef(false);
  useEffect(
    () =>
      capsulesService.subscribe((updated) => {
        if (Array.isArray(updated)) setCapsules(updated);
      }),
    []
  );
  const loadCapsules = useCallback(() => {
    if (capsulesFetched.current) return;
    capsulesFetched.current = true;
    capsulesService
      .fetchCapsulesFromApi()
      .then((list) => Array.isArray(list) && setCapsules(list))
      .catch(() => {});
  }, []);

  const results = useMemo(() => {
    const q = normalize(query);
    if (!q) return [];
    const pageResults = pages
      .filter((page) => normalize(t(page.label)).includes(q))
      .map((page) => ({ kind: 'page', id: page.id, title: t(page.label), icon: page.icon }));
    const capsuleResults = capsules
      .filter((cap) => cap && cap.id && !cap.lockedContent && cap.title !== LOCKED_TEXT)
      .filter((cap) => normalize(`${cap.title} ${cap.recipient || ''}`).includes(q))
      .slice(0, 6)
      .map((cap) => ({ kind: 'capsule', id: cap.id, title: cap.title, subtitle: cap.recipient, icon: 'fa-solid fa-gift' }));
    return [...pageResults, ...capsuleResults];
  }, [query, pages, capsules, t]);

  const closeSearch = useCallback(() => {
    setIsSearchOpen(false);
    setQuery('');
    setHighlighted(0);
  }, []);

  const chooseResult = useCallback(
    (result) => {
      if (!result) return;
      if (result.kind === 'capsule') onOpenCapsule?.(result.id);
      else onNavigateTab?.(result.id);
      closeSearch();
      searchInputRef.current?.blur();
    },
    [onOpenCapsule, onNavigateTab, closeSearch]
  );

  const handleSearchKeyDown = (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setHighlighted((i) => Math.min(i + 1, Math.max(results.length - 1, 0)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setHighlighted((i) => Math.max(i - 1, 0));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      chooseResult(results[highlighted]);
    } else if (event.key === 'Escape') {
      closeSearch();
      searchInputRef.current?.blur();
    }
  };

  // Trocar de página fecha a busca
  useEffect(() => {
    closeSearch();
  }, [activeTab, closeSearch]);

  // "/" em qualquer lugar (fora de campos de texto) abre a busca
  useEffect(() => {
    const onKey = (event) => {
      if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
      const target = event.target;
      const typing = target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
      if (typing) return;
      event.preventDefault();
      searchInputRef.current?.focus();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  // ---- Menu da conta
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const menuRef = useRef(null);
  const menuButtonRef = useRef(null);
  const [vaultStatus, setVaultStatus] = useState(vaultKeyService.status);
  useEffect(() => vaultKeyService.subscribe(setVaultStatus), []);

  // Fecha busca e menu ao clicar fora
  useEffect(() => {
    const onPointerDown = (event) => {
      if (searchRef.current && !searchRef.current.contains(event.target)) setIsSearchOpen(false);
      if (menuRef.current && !menuRef.current.contains(event.target)) setIsMenuOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    return () => document.removeEventListener('mousedown', onPointerDown);
  }, []);

  useEffect(() => {
    if (!isMenuOpen) return undefined;
    const onKey = (event) => {
      if (event.key === 'Escape') {
        setIsMenuOpen(false);
        menuButtonRef.current?.focus();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isMenuOpen]);

  const goTo = (tab) => {
    setIsMenuOpen(false);
    onNavigateTab?.(tab);
  };

  // ---- Avatar: foto ou as iniciais do nome
  const name = profile?.name || '';
  const photo = getAbsolutePhotoUrl(profile?.avatar_url || profile?.photo_url || profile?.avatar);
  const [photoFailed, setPhotoFailed] = useState(false);
  useEffect(() => setPhotoFailed(false), [photo]);
  const avatar = (size) => (
    <span className={`topbar-avatar topbar-avatar-${size}`} aria-hidden="true">
      {photo && !photoFailed ? (
        <img src={photo} alt="" referrerPolicy="no-referrer" onError={() => setPhotoFailed(true)} />
      ) : (
        getUserInitials(name)
      )}
    </span>
  );

  return (
    <header className="app-topbar">
      {/* Esquerda: marca (celular) e trilha (computador) */}
      <div className="topbar-left">
        <img src={logoIconSvg} alt="Ethernity DMs" className="topbar-brand-icon" />
        <BrandWordmark className="topbar-brand-text" />
        <nav className="topbar-breadcrumb" aria-label={t('topbar.breadcrumb')}>
          <button type="button" className="topbar-crumb" onClick={() => onNavigateTab?.('memories')}>
            {t('nav.home')}
          </button>
          {currentPage && currentPage.id !== 'memories' && (
            <>
              <span className="topbar-crumb-sep" aria-hidden="true">/</span>
              <span className="topbar-crumb-current" aria-current="page">{t(currentPage.label)}</span>
            </>
          )}
        </nav>
      </div>

      {/* Direita: busca, atalhos e conta */}
      <div className="topbar-right">
        <div className="topbar-search" ref={searchRef}>
          <i className="fa-solid fa-magnifying-glass topbar-search-icon" aria-hidden="true"></i>
          <input
            ref={searchInputRef}
            type="search"
            className="topbar-search-input"
            placeholder={t('topbar.searchPlaceholder')}
            aria-label={t('topbar.searchPlaceholder')}
            value={query}
            onChange={(e) => {
              loadCapsules();
              setQuery(e.target.value);
              setHighlighted(0);
              setIsSearchOpen(true);
            }}
            onFocus={() => {
              setIsSearchOpen(true);
              loadCapsules();
            }}
            onKeyDown={handleSearchKeyDown}
            role="combobox"
            aria-expanded={isSearchOpen && query.trim() !== ''}
            aria-controls="topbar-search-results"
            autoComplete="off"
          />
          <kbd className="topbar-search-kbd" aria-hidden="true">/</kbd>

          {isSearchOpen && query.trim() !== '' && (
            <div className="topbar-search-results" id="topbar-search-results" role="listbox">
              {results.length === 0 ? (
                <p className="topbar-search-empty">{t('topbar.searchEmpty', { query: query.trim() })}</p>
              ) : (
                results.map((result, index) => (
                  <React.Fragment key={`${result.kind}-${result.id}`}>
                    {(index === 0 || results[index - 1].kind !== result.kind) && (
                      <span className="topbar-search-group">
                        {result.kind === 'page' ? t('topbar.searchPages') : t('topbar.searchCapsules')}
                      </span>
                    )}
                    <button
                      type="button"
                      role="option"
                      aria-selected={index === highlighted}
                      className={`topbar-search-item${index === highlighted ? ' active' : ''}`}
                      onMouseEnter={() => setHighlighted(index)}
                      onClick={() => chooseResult(result)}
                    >
                      <i className={result.icon} aria-hidden="true"></i>
                      <span className="topbar-search-item-title">{result.title}</span>
                      {result.subtitle && <span className="topbar-search-item-sub">{result.subtitle}</span>}
                    </button>
                  </React.Fragment>
                ))
              )}
            </div>
          )}
        </div>

        <button
          type="button"
          className="topbar-icon-btn topbar-shortcut"
          onClick={() => onNavigateTab?.('calendar')}
          title={t('nav.calendar')}
          aria-label={t('nav.calendar')}
        >
          <i className="fa-regular fa-calendar"></i>
        </button>
        <button
          type="button"
          className="topbar-icon-btn topbar-shortcut"
          onClick={() => onNavigateTab?.('help')}
          title={t('nav.help')}
          aria-label={t('nav.help')}
        >
          <i className="fa-regular fa-circle-question"></i>
        </button>
        <NotificationBell onNavigateTab={onNavigateTab} />

        {/* Menu da conta */}
        <div className="topbar-account" ref={menuRef}>
          <button
            ref={menuButtonRef}
            type="button"
            className="topbar-avatar-btn"
            onClick={() => setIsMenuOpen((open) => !open)}
            aria-haspopup="menu"
            aria-expanded={isMenuOpen}
            aria-label={t('topbar.accountMenu')}
            title={name || t('topbar.accountMenu')}
          >
            {avatar('sm')}
          </button>

          {isMenuOpen && (
            <div className="topbar-menu" role="menu">
              <div className="topbar-menu-header">
                {avatar('lg')}
                <div className="topbar-menu-identity">
                  <strong className={name ? 'person-name' : undefined}>{name || t('topbar.accountMenu')}</strong>
                  {profile?.email && <span>{profile.email}</span>}
                </div>
                {/* Só administradores confirmados pelo servidor (isAdmin vem do MainApp); para os demais, nada aparece */}
                {isAdmin && (
                  <button
                    type="button"
                    className={`topbar-mode-switch${operatingMode === 'admin' ? ' is-admin' : ''}`}
                    onClick={() => {
                      setIsMenuOpen(false);
                      onSwitchOperatingMode?.(operatingMode === 'admin' ? 'personal' : 'admin');
                    }}
                    title={operatingMode === 'admin' ? t('userMenu.switchToPersonal') : t('userMenu.switchToAdmin')}
                    aria-label={operatingMode === 'admin' ? t('userMenu.switchToPersonal') : t('userMenu.switchToAdmin')}
                  >
                    <i className="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i>
                    <span>{operatingMode === 'admin' ? t('admin.modePersonalBadge') : t('admin.modeAdminBadge')}</span>
                  </button>
                )}
              </div>

              <div className="topbar-menu-section">
                <button type="button" role="menuitem" className="topbar-menu-item" onClick={() => goTo('profile')}>
                  <i className="fa-solid fa-gear"></i>
                  <span className="topbar-menu-text">
                    {t('userMenu.settings')}
                    <small>{t('userMenu.settingsSub')}</small>
                  </span>
                </button>
                <button
                  type="button"
                  role="menuitem"
                  className="topbar-menu-item"
                  onClick={() => {
                    setIsMenuOpen(false);
                    onOpenSubscription?.();
                  }}
                >
                  <i className="fa-solid fa-crown"></i>
                  <span className="topbar-menu-text">
                    {t('profile.myPlanCardTitle')}
                    {subscription?.planName && <small>{subscription.planName}</small>}
                  </span>
                </button>
                <button type="button" role="menuitem" className="topbar-menu-item" onClick={() => goTo('vault_security')}>
                  <i className={`fa-solid ${vaultStatus === 'unlocked' ? 'fa-lock-open' : 'fa-lock'}`}></i>
                  <span className="topbar-menu-text">
                    {t('vaultSecurity.title')}
                    {VAULT_STATUS_TEXT[vaultStatus] && <small>{t(VAULT_STATUS_TEXT[vaultStatus])}</small>}
                  </span>
                </button>
              </div>

              <div className="topbar-menu-section">
                <button type="button" role="menuitem" className="topbar-menu-item" onClick={() => goTo('help')}>
                  <i className="fa-regular fa-circle-question"></i>
                  <span className="topbar-menu-text">{t('nav.help')}</span>
                </button>
              </div>

              <div className="topbar-menu-section">
                <button
                  type="button"
                  role="menuitem"
                  className="topbar-menu-item danger"
                  onClick={() => {
                    setIsMenuOpen(false);
                    onLogout?.();
                  }}
                >
                  <i className="fa-solid fa-arrow-right-from-bracket"></i>
                  <span className="topbar-menu-text">{t('profile.logoutBtn')}</span>
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
