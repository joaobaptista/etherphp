import React, { useState, useEffect } from 'react';
import { useI18n } from '../../../core/i18n/presentation/useI18n.js';
import guardianRepository from '../infrastructure/guardianRepository.js';
import { getAbsolutePhotoUrl } from '../../profile/domain/profileModel.js';
import GuardianAvatar from './GuardianAvatar.jsx';
import './GuardianView.css';

export const INITIAL_GUARDIANS = [];

export const SAMPLE_FIXTURE_GUARDIANS = [
  {
    id: 'g-1',
    name: 'João da Silva',
    relationship: 'Cônjuge',
    email: 'joao@email.com',
    phone: '+55 (11) 99999-1234',
    status: 'active',
    canTriggerDelivery: true,
    photoCount: 312,
    avatar: null,
  },
  {
    id: 'g-2',
    name: 'Pedro Silva',
    relationship: 'Filho',
    email: 'pedro@email.com',
    phone: '+55 (11) 98888-5678',
    status: 'active',
    canTriggerDelivery: true,
    photoCount: 184,
    avatar: null,
  },
  {
    id: 'g-3',
    name: 'Ana Oliveira',
    relationship: 'Irmã',
    email: 'ana@email.com',
    phone: '+55 (21) 97777-4321',
    status: 'pending',
    canTriggerDelivery: true,
    photoCount: 96,
    avatar: null,
  },
];

export function filterUnlockedMedia(items = [], filter = 'all') {
  if (!Array.isArray(items)) return [];
  if (!filter || filter === 'all') return items;
  return items.filter((item) => String(item.type).toLowerCase() === String(filter).toLowerCase());
}


export default function GuardianView({ onNavigateTab }) {
  const { t } = useI18n();
  const [activeTab, setActiveTab] = useState('guardians');
  const [guardians, setGuardians] = useState(INITIAL_GUARDIANS);
  const [viewMode, setViewMode] = useState('list'); // 'list' | 'form' | 'profile'
  const [editingGuardian, setEditingGuardian] = useState(null);
  const [selectedProfile, setSelectedProfile] = useState(null);
  const [formName, setFormName] = useState('');
  const [formRel, setFormRel] = useState('');
  const [formEmail, setFormEmail] = useState('');
  const [formPhone, setFormPhone] = useState('');
  const [formAvatar, setFormAvatar] = useState('');
  const [formFile, setFormFile] = useState(null);
  const [isSaving, setIsSaving] = useState(false);

  const [toastMessage, setToastMessage] = useState('');

  useEffect(() => {
    try {
      const token =
        typeof window !== 'undefined' && window.localStorage
          ? localStorage.getItem('ethernity_auth_token')
          : null;
      if (token) {
        guardianRepository
          .getGuardians(token)
          .then((remoteList) => {
            if (Array.isArray(remoteList) && remoteList.length > 0) {
              setGuardians(remoteList);
            }
          })
          .catch((err) => {
            console.warn('[GuardianView] Usando guardiões em cache local:', err);
          });
      }
    } catch {}
  }, []);

  const showToast = (msg) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(''), 3500);
  };

  const handleOpenAdd = () => {
    setEditingGuardian(null);
    setFormName('');
    setFormRel('');
    setFormEmail('');
    setFormPhone('');
    setFormAvatar('');
    setFormFile(null);
    setViewMode('form');
  };

  const handleOpenEdit = (g) => {
    setEditingGuardian(g);
    setFormName(g.name || '');
    setFormRel(g.relationship || '');
    setFormEmail(g.email || '');
    setFormPhone(g.phone || '');
    setFormAvatar(g.avatar || '');
    setFormFile(null);
    setViewMode('form');
  };

  const handleOpenProfile = (g) => {
    setSelectedProfile(g);
    setViewMode('profile');
  };

  const getToken = () =>
    typeof window !== 'undefined' && window.localStorage ? localStorage.getItem('ethernity_auth_token') : null;

  const handleAvatarFileChange = (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      showToast(t('guardianView.invalidImage'));
      return;
    }
    if (formAvatar.startsWith('blob:')) URL.revokeObjectURL(formAvatar);
    setFormFile(file);
    setFormAvatar(URL.createObjectURL(file));
  };

  const handleRemovePhoto = async () => {
    if (formFile) {
      if (formAvatar.startsWith('blob:')) URL.revokeObjectURL(formAvatar);
      setFormFile(null);
      setFormAvatar(editingGuardian?.avatar || '');
      return;
    }
    if (!editingGuardian?.avatar) return;
    try {
      const saved = await guardianRepository.deleteGuardianPhoto(getToken(), editingGuardian.id);
      setGuardians((prev) => prev.map((g) => (g.id === saved.id ? { ...g, ...saved } : g)));
      setEditingGuardian((prev) => ({ ...prev, avatar: null }));
      setFormAvatar('');
    } catch (err) {
      showToast(err.message || t('guardianView.saveFailed'));
    }
  };

  const handleDelete = (id, name) => {
    if (window.confirm(t('guardianView.deleteConfirm', { name }))) {
      setGuardians((prev) => prev.filter((g) => g.id !== id));
      setConfirmedGuardianIds((prev) => prev.filter((gid) => gid !== id));

      try {
        const token =
          typeof window !== 'undefined' && window.localStorage
            ? localStorage.getItem('ethernity_auth_token')
            : null;
        if (token && id) {
          guardianRepository.deleteGuardian(token, id).catch((err) => {
            console.warn('[GuardianView] Falha ao expurgar guardião na API:', err);
          });
        }
      } catch {}
    }
  };

  const handleSave = async (e) => {
    e.preventDefault();
    if (!formName.trim() || !formEmail.trim()) {
      alert(t('guardianView.validationAlert'));
      return;
    }

    const token = getToken();
    if (!token) {
      showToast(t('guardianView.saveFailed'));
      return;
    }

    const payload = {
      name: formName.trim(),
      relationship: formRel,
      email: formEmail.trim(),
      phone: formPhone.trim(),
    };

    setIsSaving(true);
    try {
      if (editingGuardian) {
        let saved = await guardianRepository.updateGuardian(token, editingGuardian.id, payload);
        if (formFile) {
          saved = await guardianRepository.uploadGuardianPhoto(token, editingGuardian.id, formFile);
          if (formAvatar.startsWith('blob:')) URL.revokeObjectURL(formAvatar);
          setFormFile(null);
        }
        setGuardians((prev) => prev.map((g) => (g.id === saved.id ? { ...g, ...saved } : g)));
        setViewMode('list');
      } else {
        const created = await guardianRepository.createGuardian(token, payload);
        setGuardians((prev) => [...prev, created]);
        showToast(t('guardianView.createdAddPhoto'));
        handleOpenEdit(created);
      }
    } catch (err) {
      showToast(err.message || t('guardianView.saveFailed'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="guardians-screen-container fade-in">
      {/* Toast de Feedback */}
      {toastMessage && (
        <div className="guardian-floating-toast fade-in">
          <i className="fa-solid fa-circle-check"></i>
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Breadcrumb Geral */}
      <div className="guardians-breadcrumb">
        <span className="breadcrumb-parent" onClick={() => onNavigateTab && onNavigateTab('vault')}>
          {t('nav.vault')}
        </span>
        <span className="breadcrumb-arrow">&gt;</span>
        <span
          className={activeTab === 'guardians' && viewMode === 'list' ? 'breadcrumb-current' : 'breadcrumb-parent'}
          onClick={() => { setActiveTab('guardians'); setViewMode('list'); }}
        >
          {t('nav.guardian')}
        </span>
      </div>

      {/* Barra de Abas Principais */}
      <div className="guardian-tabs-nav">
        <button
          type="button"
          className={`guardian-nav-tab ${activeTab === 'guardians' ? 'active' : ''}`}
          onClick={() => { setActiveTab('guardians'); setViewMode('list'); }}
        >
          <i className="fa-solid fa-users"></i>
          <span>{t('guardianView.tabGuardians')}</span>
          <span className="tab-pill-badge neutral">{guardians.length}</span>
        </button>
      </div>

      {/* =============================================================
          ABA 1: MEUS GUARDIÕES (GESTÃO DE CONTATOS E PERFIS)
         ============================================================= */}
      {activeTab === 'guardians' && (
        <>
          {/* MODO 1: LISTAGEM DE GUARDIÕES */}
          {viewMode === 'list' && (
            <>
              <div className="guardians-header-row">
                <div className="guardians-header-text">
                  <h1 className="guardians-main-title">{t('guardianView.title')}</h1>
                  <p className="guardians-main-subtitle">
                    {t('guardianView.subtitle')}
                  </p>
                </div>

                <button type="button" className="btn-add-guardian-primary" onClick={handleOpenAdd}>
                  <i className="fa-solid fa-plus"></i> {t('guardianView.addBtn')}
                </button>
              </div>

              <div className="guardians-cards-list">
                {guardians.length === 0 ? (
                  <div className="guardians-empty-state-card">
                    <div className="guardians-empty-icon-wrapper">
                      <i className="fa-solid fa-user-shield"></i>
                    </div>
                    <h3 className="guardians-empty-title">Nenhum Guardião Nomeado</h3>
                    <p className="guardians-empty-desc">
                      Guardiões são pessoas de sua máxima confiança responsáveis por liberar o acesso aos seus legados e diretivas confidenciais.
                    </p>
                    <button type="button" className="btn-add-guardian-primary" onClick={handleOpenAdd}>
                      <i className="fa-solid fa-plus"></i> Nomear Primeiro Guardião
                    </button>
                  </div>
                ) : (
                  guardians.map((g) => (
                    <div key={g.id} className="guardian-row-card">
                      {/* Foto + Nome + Parentesco */}
                      <div 
                        className="guardian-identity-col" 
                        onClick={() => handleOpenProfile(g)}
                        style={{ cursor: 'pointer' }}
                        title="Ver perfil de memórias"
                      >
                        <GuardianAvatar src={g.avatar} name={g.name} className="guardian-circle-avatar" />
                        <div className="guardian-name-rel">
                          <h3 className="guardian-person-name person-name">{g.name}</h3>
                          <span className="guardian-rel-tag">{g.relationship}</span>
                        </div>
                      </div>

                      {/* E-mail e Telefone */}
                      <div className="guardian-contact-col">
                        <div className="guardian-contact-line">
                          <i className="fa-regular fa-envelope"></i>
                          <span>{g.email}</span>
                        </div>
                        <div className="guardian-contact-line">
                          <i className="fa-solid fa-phone"></i>
                          <span>{g.phone}</span>
                        </div>
                      </div>

                      {/* Status */}
                      <div className="guardian-status-col">
                        <span className={`guardian-state-badge ${g.status}`}>
                          {g.status === 'active' ? t('guardianView.statusActive') : t('guardianView.statusPending')}
                        </span>
                      </div>

                      {/* Ações */}
                      <div className="guardian-actions-col">
                        <button
                          type="button"
                          className="btn-guardian-edit"
                          title={t('guardianView.editGuardian')}
                          onClick={() => handleOpenEdit(g)}
                        >
                          <i className="fa-regular fa-pen-to-square"></i>
                        </button>
                        <button
                          type="button"
                          className="btn-guardian-delete"
                          title={t('guardianView.deleteGuardian')}
                          onClick={() => handleDelete(g.id, g.name)}
                        >
                          <i className="fa-regular fa-trash-can"></i>
                        </button>
                      </div>
                    </div>
                  ))
                )}
              </div>
            </>
          )}

          {/* MODO 1.5: PERFIL ESTILO INSTAGRAM */}
          {viewMode === 'profile' && selectedProfile && (
            <div className="guardian-profile-ig fade-in">
              <div className="form-navigation-topbar" style={{ paddingBottom: '16px', borderBottom: '1px solid var(--border-color)', marginBottom: '24px' }}>
                <button type="button" className="btn-back-shortcut" onClick={() => setViewMode('list')}>
                  <i className="fa-solid fa-arrow-left"></i> {t('common.back')}
                </button>
                <div className="profile-username-ig" style={{ fontWeight: 700, display: 'flex', alignItems: 'center', gap: '6px' }}>
                  {selectedProfile.name.toLowerCase().replace(/\s/g, '')} <i className="fa-solid fa-circle-check" style={{ color: 'var(--color-primary)' }}></i>
                </div>
                <div style={{ width: '80px' }}></div>
              </div>

              <div className="profile-header-ig">
                <div className="profile-avatar-wrap-ig">
                  <GuardianAvatar src={selectedProfile.avatar} name={selectedProfile.name} className="profile-avatar-ig" />
                </div>
                <div className="profile-stats-ig">
                  <div className="stat-ig">
                    <strong>{selectedProfile.photoCount || 0}</strong>
                    <span>{t('guardianView.memoriesStat')}</span>
                  </div>
                  <div className="stat-ig">
                    <strong>—</strong>
                    <span>{t('guardianView.vaultsStat')}</span>
                  </div>
                  <div className="stat-ig">
                    <strong>{selectedProfile.status === 'active' ? '100%' : '50%'}</strong>
                    <span>{t('guardianView.accessStat')}</span>
                  </div>
                </div>
              </div>

              <div className="profile-bio-ig">
                <h3 className="profile-bio-name person-name">{selectedProfile.name}</h3>
                <p className="profile-bio-desc">
                  {t('guardianView.bioDesc', { relationship: selectedProfile.relationship })}<br/>
                  <a href={`mailto:${selectedProfile.email}`} style={{color: 'var(--color-primary)', textDecoration: 'none'}}>{selectedProfile.email}</a>
                </p>
                <div className="profile-actions-ig">
                  <button className="btn-ig-action btn-ig-edit" onClick={() => handleOpenEdit(selectedProfile)}>
                    {t('guardianView.editBtn')}
                  </button>
                  <button className="btn-ig-action btn-ig-share" onClick={() => onNavigateTab && onNavigateTab('capsules')}>
                    {t('guardianView.vaultsBtn')}
                  </button>
                  <button className="btn-ig-action btn-ig-primary" onClick={() => handleOpenEdit(selectedProfile)}>
                    {t('guardianView.addPhotosBtn')}
                  </button>
                </div>
              </div>

              <div className="profile-tabs-ig">
                <div className="tab-ig active"><i className="fa-solid fa-table-cells"></i></div>
                <div className="tab-ig"><i className="fa-solid fa-clapperboard"></i></div>
              </div>

              <div className="profile-timeline-container profile-timeline-empty">
                <i className="fa-regular fa-images" aria-hidden="true"></i>
                <p>{t('guardianView.timelineEmpty')}</p>
              </div>
            </div>
          )}

          {/* MODO 2: FORMULÁRIO DE CADASTRO / EDIÇÃO */}
          {viewMode === 'form' && (
            <div className="guardian-inline-form-wrapper fade-in">
              <div className="form-navigation-topbar">
                <button
                  type="button"
                  className="btn-back-shortcut"
                  onClick={() => setViewMode('list')}
                >
                  <i className="fa-solid fa-arrow-left"></i> {t('guardianView.backToList')}
                </button>
                <div className="guardians-breadcrumb">
                  <span className="breadcrumb-parent" onClick={() => setViewMode('list')}>
                    {t('nav.guardian')}
                  </span>
                  <span className="breadcrumb-arrow">&gt;</span>
                  <span className="breadcrumb-current">
                    {editingGuardian ? t('guardianView.editGuardianTitle', { name: formName || t('nav.guardian') }) : t('guardianView.newGuardian')}
                  </span>
                </div>
              </div>

              <div className="special-person-card">
                <div className="modal-person-top">
                  {editingGuardian ? (
                  <label
                    className={`modal-avatar-wrapper clickable ${formAvatar ? '' : 'is-empty'}`}
                    title={t('guardianView.photoPlaceholder', 'Clique para escolher foto')}
                    aria-label={t('guardianView.photoPlaceholder', 'Clique para escolher foto')}
                  >
                    <input
                      type="file"
                      accept="image/*"
                      onChange={handleAvatarFileChange}
                      style={{ display: 'none' }}
                    />
                    {formAvatar ? (
                      <img
                        src={getAbsolutePhotoUrl(formAvatar)}
                        alt={formName}
                        className="modal-avatar-image"
                      />
                    ) : (
                      <div className="empty-avatar-placeholder">
                        <i className="fa-solid fa-user" aria-hidden="true"></i>
                      </div>
                    )}
                    <span className="modal-avatar-edit-badge" aria-hidden="true">
                      <i className="fa-solid fa-camera"></i>
                    </span>
                  </label>
                  ) : (
                    <div className="modal-avatar-wrapper is-empty" aria-hidden="true">
                      <div className="empty-avatar-placeholder">
                        <i className="fa-solid fa-user"></i>
                      </div>
                    </div>
                  )}
                  <div className="modal-photos-badge">
                    <span>
                      {!editingGuardian
                        ? t('guardianView.photoAfterSave')
                        : formAvatar
                          ? t('guardianView.changePhoto')
                          : t('guardianView.photoPlaceholder')}
                    </span>
                  </div>
                  {editingGuardian && formAvatar && (
                    <button type="button" className="btn-remove-avatar" onClick={handleRemovePhoto}>
                      <i className="fa-regular fa-trash-can" aria-hidden="true"></i> {t('guardianView.removePhoto')}
                    </button>
                  )}
                </div>

                <div className="modal-person-heading">
                  <h2 className="modal-person-title">{t('guardianView.formTitle')}</h2>
                  <p className="modal-person-subtitle">
                    {t('guardianView.formSubtitle')}
                  </p>
                </div>

                <div className="modal-future-notice">
                  <div className="notice-icon">
                    <i className="fa-solid fa-shield-halved"></i>
                  </div>
                  <p className="notice-text">
                    {t('guardianView.futureNotice')}
                  </p>
                </div>

                <form onSubmit={handleSave} className="modal-person-form">
                  <div className="person-form-field">
                    <label className="person-label">{t('guardianView.nameLabel')}</label>
                    <input
                      type="text"
                      required
                      className="person-input"
                      placeholder={t('guardianView.namePlaceholder')}
                      value={formName}
                      onChange={(e) => setFormName(e.target.value)}
                      autoFocus
                    />
                  </div>

                  <div className="person-form-field">
                    <label className="person-label">{t('guardianView.relationshipLabel')}</label>
                    <div className="person-select-wrapper active-relation">
                      <i className="fa-regular fa-heart relation-heart-icon"></i>
                      <select
                        className="person-select"
                        value={formRel}
                        onChange={(e) => setFormRel(e.target.value)}
                      >
                        <option value="" disabled>{t('guardianView.selectRelation')}</option>
                        <option value="Filha">Filha</option>
                        <option value="Filho">Filho</option>
                        <option value="Cônjuge">Cônjuge</option>
                        <option value="Mãe">Mãe</option>
                        <option value="Pai">Pai</option>
                        <option value="Irmã">Irmã</option>
                        <option value="Irmão">Irmão</option>
                        <option value="Amigo(a)">Amigo(a) Próximo(a)</option>
                        <option value="Outro">Outro</option>
                      </select>
                      <i className="fa-solid fa-chevron-down relation-chevron-icon"></i>
                    </div>
                  </div>

                  <div className="person-form-field">
                    <label className="person-label">{t('guardianView.phoneLabel')}</label>
                    <div className="person-input-with-icon">
                      <i className="fa-solid fa-phone input-icon"></i>
                      <input
                        type="tel"
                        className="person-input icon-padded"
                        placeholder={t('guardianView.phonePlaceholder')}
                        value={formPhone}
                        onChange={(e) => setFormPhone(e.target.value)}
                      />
                    </div>
                  </div>

                  <div className="person-form-field">
                    <label className="person-label">{t('guardianView.emailLabel')}</label>
                    <div className="person-input-with-icon">
                      <i className="fa-regular fa-envelope input-icon"></i>
                      <input
                        type="email"
                        required
                        className="person-input icon-padded"
                        placeholder={t('guardianView.emailPlaceholder')}
                        value={formEmail}
                        onChange={(e) => setFormEmail(e.target.value)}
                      />
                    </div>
                  </div>

                  <div className="modal-person-footer">
                    <button
                      type="button"
                      className="btn-skip-person"
                      onClick={() => setViewMode('list')}
                    >
                      {t('guardianView.skipPerson')}
                    </button>
                    <button type="submit" className="btn-next-person" disabled={isSaving}>
                      {editingGuardian ? t('guardianView.saveChanges') : t('guardianView.nextPerson')} <i className="fa-solid fa-arrow-right"></i>
                    </button>
                  </div>
                </form>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
