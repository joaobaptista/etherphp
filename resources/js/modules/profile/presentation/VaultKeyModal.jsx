import React, { useState } from 'react';
import vaultKeyService from '../../../core/services/vaultKeyService.js';
import { MIN_VAULT_PASSWORD_LENGTH } from '../../../core/services/vaultCrypto.js';
import './VaultKeyModal.css';

const TITLES = {
  setup: 'Criar senha do cofre',
  unlock: 'Desbloquear cofre',
  recover: 'Recuperar acesso ao cofre',
  change: 'Trocar senha do cofre',
  regenerate: 'Nova chave de recuperação',
  showRecovery: 'Guarde sua chave de recuperação',
};

export default function VaultKeyModal({ initialMode = 'setup', onClose }) {
  const [mode, setMode] = useState(initialMode);
  const [password, setPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [recoveryInput, setRecoveryInput] = useState('');
  const [remember, setRemember] = useState(false);
  const [recoveryCode, setRecoveryCode] = useState('');
  const [savedConfirmed, setSavedConfirmed] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const needsNewPassword = mode === 'setup' || mode === 'recover' || mode === 'change';
  const passwordToCheck = mode === 'setup' ? password : newPassword;

  const switchMode = (next) => {
    setMode(next);
    setError('');
    setPassword('');
    setNewPassword('');
    setConfirm('');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    if (needsNewPassword) {
      if (passwordToCheck.length < MIN_VAULT_PASSWORD_LENGTH) {
        setError(`A senha do cofre precisa ter pelo menos ${MIN_VAULT_PASSWORD_LENGTH} caracteres.`);
        return;
      }
      if (passwordToCheck !== confirm) {
        setError('As senhas não conferem.');
        return;
      }
    }
    setBusy(true);
    try {
      if (mode === 'setup') {
        setRecoveryCode(await vaultKeyService.setup(password, { remember }));
        switchMode('showRecovery');
      } else if (mode === 'unlock') {
        await vaultKeyService.unlock(password, { remember });
        onClose();
      } else if (mode === 'recover') {
        await vaultKeyService.recover(recoveryInput, newPassword, { remember });
        onClose();
      } else if (mode === 'change') {
        await vaultKeyService.changePassword(password, newPassword);
        onClose();
      } else if (mode === 'regenerate') {
        setRecoveryCode(await vaultKeyService.regenerateRecovery(password));
        switchMode('showRecovery');
      }
    } catch (err) {
      setError(err.message || 'Não foi possível concluir. Tente novamente.');
    } finally {
      setBusy(false);
    }
  };

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(recoveryCode);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  const handleDownload = () => {
    const text = [
      'Ethernity — chave de recuperação do cofre',
      '',
      recoveryCode,
      '',
      'Guarde este arquivo em local seguro e fora deste aparelho.',
      'Sem a senha do cofre e sem esta chave, ninguém consegue recuperar o seu conteúdo — nem a equipe do Ethernity.',
    ].join('\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ethernity-chave-de-recuperacao.txt';
    a.click();
    URL.revokeObjectURL(url);
  };

  const closeAllowed = mode !== 'showRecovery' || savedConfirmed;

  return (
    <div className="vk-overlay" onClick={() => closeAllowed && !busy && onClose()}>
      <div className="vk-modal" role="dialog" aria-modal="true" aria-labelledby="vk-title" onClick={(e) => e.stopPropagation()}>
        <div className="vk-header">
          <div className="vk-icon">
            <i className={`fa-solid ${mode === 'showRecovery' ? 'fa-key' : 'fa-lock'}`} aria-hidden="true"></i>
          </div>
          <h2 id="vk-title">{TITLES[mode]}</h2>
          {closeAllowed && (
            <button type="button" className="vk-close" onClick={onClose} disabled={busy} aria-label="Fechar">
              <i className="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
          )}
        </div>

        {mode === 'showRecovery' ? (
          <div className="vk-body">
            <p className="vk-text">
              Esta chave é a <strong>única forma</strong> de recuperar o cofre se você esquecer a senha. Ela aparece só agora.
              Sem a senha e sem esta chave, ninguém consegue abrir o seu conteúdo — nem a equipe do Ethernity.
            </p>
            <code className="vk-recovery-code">{recoveryCode}</code>
            <div className="vk-row">
              <button type="button" className="vk-btn-secondary" onClick={handleCopy}>
                <i className="fa-regular fa-copy" aria-hidden="true"></i> {copied ? 'Copiada' : 'Copiar'}
              </button>
              <button type="button" className="vk-btn-secondary" onClick={handleDownload}>
                <i className="fa-solid fa-download" aria-hidden="true"></i> Baixar arquivo
              </button>
            </div>
            <label className="vk-check">
              <input type="checkbox" checked={savedConfirmed} onChange={(e) => setSavedConfirmed(e.target.checked)} />
              Guardei a chave de recuperação em um lugar seguro, fora deste aparelho.
            </label>
            <button type="button" className="vk-btn-primary" disabled={!savedConfirmed} onClick={onClose}>
              Concluir
            </button>
          </div>
        ) : (
          <form className="vk-body" onSubmit={handleSubmit}>
            {mode === 'setup' && (
              <p className="vk-text">
                A senha do cofre é separada da senha de login e protege o seu conteúdo no próprio aparelho. Ela nunca é enviada
                ao servidor. Use uma frase longa que só você saiba.
              </p>
            )}
            {mode === 'recover' && (
              <label className="vk-field">
                <span>Chave de recuperação</span>
                <input
                  type="text"
                  autoComplete="off"
                  spellCheck="false"
                  placeholder="XXXX-XXXX-XXXX-…"
                  value={recoveryInput}
                  onChange={(e) => setRecoveryInput(e.target.value)}
                  required
                />
              </label>
            )}
            {(mode === 'setup' || mode === 'unlock' || mode === 'change' || mode === 'regenerate') && (
              <label className="vk-field">
                <span>{mode === 'setup' ? 'Senha do cofre' : 'Senha atual do cofre'}</span>
                <input
                  type="password"
                  autoComplete={mode === 'setup' ? 'new-password' : 'current-password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  autoFocus
                />
              </label>
            )}
            {(mode === 'recover' || mode === 'change') && (
              <label className="vk-field">
                <span>Nova senha do cofre</span>
                <input type="password" autoComplete="new-password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required />
              </label>
            )}
            {needsNewPassword && (
              <label className="vk-field">
                <span>Confirme a {mode === 'setup' ? 'senha' : 'nova senha'}</span>
                <input type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} required />
                <small>Mínimo de {MIN_VAULT_PASSWORD_LENGTH} caracteres.</small>
              </label>
            )}
            {(mode === 'setup' || mode === 'unlock' || mode === 'recover') && (
              <label className="vk-check">
                <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                Manter o cofre desbloqueado neste aparelho até eu sair da conta
              </label>
            )}
            {mode === 'regenerate' && (
              <p className="vk-text">A chave de recuperação atual deixará de funcionar.</p>
            )}

            {error && (
              <p className="vk-error" role="alert">
                {error}
              </p>
            )}

            <button type="submit" className="vk-btn-primary" disabled={busy}>
              {busy ? (
                <>
                  <i className="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Protegendo…
                </>
              ) : mode === 'unlock' ? (
                'Desbloquear'
              ) : mode === 'regenerate' ? (
                'Gerar nova chave'
              ) : (
                'Salvar'
              )}
            </button>

            {mode === 'unlock' && (
              <button type="button" className="vk-link" onClick={() => switchMode('recover')}>
                Esqueci a senha do cofre
              </button>
            )}
          </form>
        )}
      </div>
    </div>
  );
}
