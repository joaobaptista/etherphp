import React, { useEffect, useState } from 'react';
import vaultKeyService from '../../../core/services/vaultKeyService.js';
import { useI18n } from '../../../core/i18n/presentation/useI18n.js';
import VaultKeyModal from './VaultKeyModal.jsx';
import './ProfileView.css';
import './VaultSecurityView.css';

// Estado do cofre → texto e ação principal
const STATUS = {
  unknown: { text: 'vaultSecurity.statusUnknown', action: null, icon: 'fa-spinner fa-spin', tone: 'neutral' },
  error: { text: 'vaultSecurity.statusError', action: null, icon: 'fa-triangle-exclamation', tone: 'warning' },
  not_configured: { text: 'vaultSecurity.statusNotConfigured', action: 'setup', icon: 'fa-lock', tone: 'warning' },
  locked: { text: 'vaultSecurity.statusLocked', action: 'unlock', icon: 'fa-lock', tone: 'neutral' },
  unlocked: { text: 'vaultSecurity.statusUnlocked', action: null, icon: 'fa-lock-open', tone: 'ok' },
};

const ACTION_LABEL = {
  setup: 'vaultSecurity.create',
  unlock: 'vaultSecurity.unlock',
};

/** Tela "Senha do Cofre": criar, desbloquear, trocar a senha, nova chave de recuperação e bloquear. */
export default function VaultSecurityView({ onNavigateTab }) {
  const { t } = useI18n();
  const [status, setStatus] = useState(vaultKeyService.status);
  const [modalMode, setModalMode] = useState(null);

  useEffect(() => {
    const unsubscribe = vaultKeyService.subscribe(setStatus);
    vaultKeyService.refresh();
    return unsubscribe;
  }, []);

  const info = STATUS[status] || STATUS.unknown;

  return (
    <div className="profile-screen-wrapper fade-in">
      <div className="profile-screen-container">
        <div className="prof-top-bar">
          <div className="prof-left-title-block">
            <nav className="prof-breadcrumbs" aria-label={t('topbar.breadcrumb')}>
              <span className="prof-crumb-link" onClick={() => onNavigateTab && onNavigateTab('profile')}>
                {t('userMenu.settings')}
              </span>
              <span className="prof-crumb-sep">&gt;</span>
              <span className="prof-crumb-current">{t('vaultSecurity.title')}</span>
            </nav>
            <h1 className="prof-main-title">{t('vaultSecurity.title')}</h1>
            <p className="prof-main-subtitle">{t('vaultSecurity.subtitle')}</p>
          </div>
        </div>

        <section className="vsec-card">
          <div className={`vsec-status vsec-${info.tone}`}>
            <div className="vsec-status-icon">
              <i className={`fa-solid ${info.icon}`}></i>
            </div>
            <div>
              <span className="vsec-status-label">{t('vaultSecurity.statusLabel')}</span>
              <strong className="vsec-status-text">{t(info.text)}</strong>
            </div>
          </div>

          <div className="vsec-actions">
            {info.action && (
              <button type="button" className="vsec-btn-primary" onClick={() => setModalMode(info.action)}>
                {t(ACTION_LABEL[info.action])}
              </button>
            )}
            {status === 'unlocked' && (
              <>
                <button type="button" className="vsec-btn" onClick={() => setModalMode('change')}>
                  <i className="fa-solid fa-key"></i> {t('vaultSecurity.change')}
                </button>
                <button type="button" className="vsec-btn" onClick={() => setModalMode('regenerate')}>
                  <i className="fa-solid fa-rotate"></i> {t('vaultSecurity.regenerate')}
                </button>
                <button type="button" className="vsec-btn" onClick={() => vaultKeyService.lock()}>
                  <i className="fa-solid fa-lock"></i> {t('vaultSecurity.lock')}
                </button>
              </>
            )}
            {status === 'locked' && (
              <button type="button" className="vsec-btn" onClick={() => setModalMode('recover')}>
                {t('vaultSecurity.recover')}
              </button>
            )}
          </div>

          <ul className="vsec-notes">
            <li>{t('vaultSecurity.noteZeroKnowledge')}</li>
            <li>{t('vaultSecurity.noteRecovery')}</li>
          </ul>
        </section>
      </div>

      {modalMode && <VaultKeyModal initialMode={modalMode} onClose={() => setModalMode(null)} />}
    </div>
  );
}
