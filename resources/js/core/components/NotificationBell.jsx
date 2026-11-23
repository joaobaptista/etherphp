import React, { useState, useRef, useEffect, useCallback } from 'react';
import { backgroundRequest, getStoredAuthToken } from '../api/apiUrl.js';
import { openTextOr } from '../services/fieldCipher.js';
import { useI18n } from '../i18n/presentation/useI18n.js';
import './NotificationBell.css';

const TYPE_ICONS = {
  account: { icon: 'fa-solid fa-user-check', color: 'var(--color-primary, #F05A47)' },
  guardian: { icon: 'fa-solid fa-shield-halved', color: 'var(--color-primary, #F05A47)' },
  capsule: { icon: 'fa-solid fa-gift', color: '#10b981' },
  message: { icon: 'fa-solid fa-envelope-open-text', color: '#8b5cf6' },
  vault: { icon: 'fa-solid fa-lock', color: '#3b82f6' },
};

export function formatRelativeTime(value, now = Date.now()) {
  const time = new Date(value).getTime();
  if (Number.isNaN(time)) return '';
  const minutes = Math.max(0, Math.floor((now - time) / 60000));
  if (minutes < 1) return 'agora';
  if (minutes < 60) return `há ${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `há ${hours}h`;
  const days = Math.floor(hours / 24);
  if (days < 30) return days === 1 ? 'há 1 dia' : `há ${days} dias`;
  return new Date(time).toLocaleDateString('pt-BR');
}

export async function composeNotification(item) {
  if (item.desc) return item;
  const subject = await openTextOr(item.subject, 'Conteúdo protegido');
  const recipient = item.recipient ? await openTextOr(item.recipient, '') : '';
  const forWhom = recipient ? ` para ${recipient}` : '';
  let desc = subject;
  if (item.type === 'guardian') desc = `${subject} agora faz parte da sua rede de confiança.`;
  else if (item.type === 'capsule') desc = `${subject}${forWhom}. ${item.detail || ''}`.trim();
  else if (item.type === 'message') desc = `${subject}${forWhom}.`;
  return { ...item, desc };
}

export default function NotificationBell({
  onNavigateTab,
}) {
  const { t } = useI18n();
  const [isOpen, setIsOpen] = useState(false);
  const [items, setItems] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loadFailed, setLoadFailed] = useState(false);

  const loadNotifications = useCallback(async () => {
    const token = getStoredAuthToken();
    if (!token) return;
    try {
      const data = await backgroundRequest('GET', '/notifications', undefined, token);
      const raw = Array.isArray(data?.items) ? data.items : [];
      // Títulos e nomes podem chegar cifrados: o texto final é montado aqui, depois de abrir
      setItems(await Promise.all(raw.map(composeNotification)));
      setUnreadCount(Number(data?.unreadCount) || 0);
      setLoadFailed(false);
    } catch {
      setLoadFailed(true);
    }
  }, []);

  useEffect(() => {
    loadNotifications();
  }, [loadNotifications]);

  const notifications = items.map((n) => ({
    ...n,
    icon: (TYPE_ICONS[n.type] || TYPE_ICONS.account).icon,
    iconColor: (TYPE_ICONS[n.type] || TYPE_ICONS.account).color,
    time: formatRelativeTime(n.createdAt),
  }));

  const popoverRef = useRef(null);

  // Fechar ao clicar fora
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (popoverRef.current && !popoverRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isOpen]);

  const handleMarkAllRead = async () => {
    setItems((prev) => prev.map((n) => ({ ...n, unread: false })));
    setUnreadCount(0);
    try {
      await backgroundRequest('POST', '/notifications/read', {});
    } catch {
      loadNotifications();
    }
  };

  const handleItemClick = (notif) => {
    if (notif.tab && onNavigateTab) {
      onNavigateTab(notif.tab);
    }
    setIsOpen(false);
  };

  return (
    <div className="notification-bell-container" ref={popoverRef}>
      {/* Botão de Sino estilo Facebook / Redes Sociais */}
      <button
        type="button"
        className="header-circle-btn notification-bell-btn"
        onClick={() => {
          if (!isOpen) loadNotifications();
          setIsOpen(!isOpen);
        }}
        aria-expanded={isOpen}
        aria-label={t('notifications.title') || 'Notificações'}
        title={t('notifications.title') || 'Notificações'}
      >
        <i className="fa-solid fa-bell"></i>
        {unreadCount > 0 && (
          <span className="notification-badge-fb animate-badge-pulse">
            {unreadCount}
          </span>
        )}
      </button>

      {/* Popover Flutuante de Notificações */}
      {isOpen && (
        <div className="notification-popover animate-pop">
          {/* Cabeçalho do Popover */}
          <div className="notif-popover-header">
            <div className="notif-header-title">
              <h4>{t('notifications.title') || 'Notificações'}</h4>
              {unreadCount > 0 && (
                <span className="notif-unread-count-pill">
                  {t('notifications.newCount', { count: unreadCount }) || `${unreadCount} novas`}
                </span>
              )}
            </div>
            {unreadCount > 0 && (
              <button
                type="button"
                className="btn-mark-all-read"
                onClick={handleMarkAllRead}
              >
                {t('notifications.markAllRead') || 'Marcar como lidas'}
              </button>
            )}
          </div>

          {/* Lista de Notificações */}
          <div className="notif-popover-list">
            {notifications.length === 0 ? (
              <div className="notif-empty-state">
                <i className="fa-solid fa-bell-slash"></i>
                <p>
                  {loadFailed
                    ? 'Não foi possível carregar as notificações agora.'
                    : t('notifications.empty') || 'Nenhuma notificação no momento.'}
                </p>
              </div>
            ) : (
              notifications.map((notif) => (
                <div
                  key={notif.id}
                  className={`notif-popover-item ${notif.unread ? 'unread' : ''}`}
                  onClick={() => handleItemClick(notif)}
                  role="button"
                  tabIndex={0}
                >
                  <div className="notif-item-icon-wrapper" style={{ color: notif.iconColor }}>
                    <i className={notif.icon}></i>
                  </div>
                  <div className="notif-item-content">
                    <div className="notif-item-title-row">
                      <strong className="notif-item-title">{notif.title}</strong>
                      {notif.unread && <span className="notif-unread-dot"></span>}
                    </div>
                    <p className="notif-item-desc">{notif.desc}</p>
                    <span className="notif-item-time">{notif.time}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
