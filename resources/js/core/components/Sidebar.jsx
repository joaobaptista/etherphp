import React from 'react';
import logoIconSvg from '../../assets/logo-icon.svg';
import { useI18n } from '../i18n/presentation/useI18n.js';
import './Sidebar.css';
import { APP_VERSION, APP_VERSION_LABEL } from '../appVersion.js';


// O modo administrador só chega aqui confirmado pelo servidor (MainApp); a troca fica no avatar da barra do topo.
export default function Sidebar({
  activeTab,
  setActiveTab,
  isCollapsed = false,
  onToggleCollapse,
  operatingMode = 'personal',
}) {
  const { t } = useI18n();

  const isCurrentAdminMode = operatingMode === 'admin';

  // Menu Pessoal Clássico
  const personalNavItems = [
    {
      id: 'memories',
      label: t('nav.memories') || 'Comece Aqui',
      icon: 'fa-regular fa-compass',
    },
    {
      id: 'guardian',
      label: t('nav.guardian') || 'Guardião',
      icon: 'fa-solid fa-shield-halved',
    },
    {
      id: 'vault',
      label: t('nav.vault') || 'Meu Cofre',
      icon: 'fa-regular fa-square-plus',
    },
    {
      id: 'capsules',
      label: t('nav.capsules') || 'Cápsulas',
      icon: 'fa-solid fa-gift',
    },
    {
      id: 'avatar',
      label: t('nav.avatar') || 'Avatar e Voz',
      icon: 'fa-regular fa-circle-dot',
    },
    {
      id: 'calendar',
      label: t('nav.calendar') || 'Calendário',
      icon: 'fa-regular fa-calendar',
    },
  ];

  // Menu Administrativo
  const adminNavItems = [
    {
      id: 'admin_dashboard',
      label: t('admin.adminDashboard') || 'Dashboard Admin',
      icon: 'fa-solid fa-chart-line',
    },
    {
      id: 'admin_users',
      label: t('admin.adminUsers') || 'Gestão de Usuários',
      icon: 'fa-solid fa-users-gear',
      hasDot: true,
    },
  ];

  const navItems = isCurrentAdminMode ? adminNavItems : personalNavItems;

  const handleItemClick = (item) => {
    setActiveTab(item.id);
  };

  return (
    <aside className={`sidebar ${isCollapsed ? 'collapsed' : ''} ${isCurrentAdminMode ? 'admin-mode-active' : ''}`}>
      {/* Brand no topo da Sidebar com Logo e Botão de Abrir / Fechar */}
      <div className="sidebar-brand-wrapper">
        <div className="sidebar-brand">
          <div
            className="brand-logo-area"
            onClick={() => setActiveTab(isCurrentAdminMode ? 'admin_dashboard' : 'memories')}
            style={{ cursor: 'pointer' }}
          >
            <img src={logoIconSvg} alt="Ethernity DMs" className="sidebar-logo-icon" />
            {!isCollapsed && (
              <div className="brand-text-block">
                <span className="brand-title">Ethernity DMs</span>
                <span className="brand-subtitle">
                  {isCurrentAdminMode ? (t('admin.modeAdminBadge') || 'MODO ADMINISTRADOR') : (t('common.digitalLegacy') || 'LEGADO DIGITAL')}
                </span>
              </div>
            )}
          </div>

          {/* Botão para Alternar Abrir / Fechar */}
          <button
            type="button"
            className="btn-toggle-sidebar"
            onClick={onToggleCollapse}
            title={isCollapsed ? t('nav.expand') : t('nav.collapse')}
            aria-label={isCollapsed ? t('nav.expand') : t('nav.collapse')}
          >
            <i className={`fa-solid ${isCollapsed ? 'fa-bars' : 'fa-angles-left'}`}></i>
          </button>
        </div>

      </div>

      {/* Navegação Principal com Ícones e Atalhos */}
      <nav className="sidebar-nav">
        {navItems.map((item) => {
          const isActive = activeTab === item.id;
          return (
            <button
              key={item.id}
              type="button"
              className={`nav-item ${isActive ? 'active' : ''}`}
              onClick={() => handleItemClick(item)}
              title={item.label}
            >
              <div className="nav-item-icon-wrapper">
                <i className={item.icon}></i>
              </div>

              {!isCollapsed && <span className="nav-item-label">{item.label}</span>}
              {!isCollapsed && item.hasDot && (
                <span className="nav-coral-dot"></span>
              )}
            </button>
          );
        })}
      </nav>

      {/* Rodapé: só a versão (perfil e configurações ficam no avatar da barra do topo) */}
      {!isCollapsed && (
        <div className="sidebar-footer">
          <div className="sidebar-bottom-row">
            <span className="sidebar-version" aria-label={`Versão ${APP_VERSION}`}>
              Ethernity {APP_VERSION_LABEL}
            </span>
          </div>
        </div>
      )}
    </aside>
  );
}
