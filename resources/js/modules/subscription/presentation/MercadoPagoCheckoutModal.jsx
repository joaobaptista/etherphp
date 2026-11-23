import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useI18n } from '../../../core/i18n/presentation/useI18n.js';
import subscriptionRepository from '../infrastructure/subscriptionRepository.js';
import { formatCurrency } from '../domain/subscriptionModel.js';
import './MercadoPagoCheckoutModal.css';

const PIX_POLL_INTERVAL_MS = 5000;

/**
 * Checkout Mercado Pago.
 *
 * - Cartão: assinatura com renovação automática, concluída na página segura do
 *   Mercado Pago (o cartão nunca passa pelo nosso servidor).
 * - PIX: cobrança de um período, gerada sob demanda, com confirmação automática.
 */
export default function MercadoPagoCheckoutModal({
  plan,
  cycle = 'monthly',
  token,
  paymentsEnabled = false,
  simulationEnabled = false,
  onClose,
  onPaymentSuccess,
  showToast,
}) {
  const { t } = useI18n();
  const [activeTab, setActiveTab] = useState('card');
  const [redirecting, setRedirecting] = useState(false);
  const [generatingPix, setGeneratingPix] = useState(false);
  const [processingSimulation, setProcessingSimulation] = useState(false);
  const [pixCheckout, setPixCheckout] = useState(null);
  const [pixStatus, setPixStatus] = useState(null);
  const [copied, setCopied] = useState(false);
  const [timeLeft, setTimeLeft] = useState(0);
  const [error, setError] = useState('');
  const finishedRef = useRef(false);

  const amount = cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
  const cycleLabel = cycle === 'yearly' ? t('subscription.yearly') : t('subscription.monthly');
  const periodLabel = cycle === 'yearly' ? '1 ano' : '1 mês';

  const finishWithSuccess = useCallback((subscription) => {
    if (finishedRef.current) return;
    finishedRef.current = true;
    if (showToast) showToast(t('subscription.paymentSuccess'));
    if (onPaymentSuccess) onPaymentSuccess(subscription);
    onClose();
  }, [onClose, onPaymentSuccess, showToast, t]);

  // Cartão: cria a assinatura e leva o usuário à página do Mercado Pago
  const handleSubscribeWithCard = async () => {
    setError('');
    try {
      setRedirecting(true);
      const res = await subscriptionRepository.startCheckout(plan.id, cycle, 'card', token);
      const initPoint = res?.checkout?.initPoint;
      if (!initPoint) throw new Error('O Mercado Pago não retornou o link da assinatura.');
      window.location.assign(initPoint);
    } catch (err) {
      setError(err.message || 'Não foi possível iniciar a assinatura.');
      setRedirecting(false);
    }
  };

  // PIX: gera a cobrança só quando o usuário pede (cada PIX é uma cobrança real)
  const handleGeneratePix = async () => {
    setError('');
    try {
      setGeneratingPix(true);
      const res = await subscriptionRepository.startCheckout(plan.id, cycle, 'pix', token);
      setPixCheckout(res?.checkout || null);
      setPixStatus(res?.checkout?.status || 'pending');
    } catch (err) {
      setError(err.message || 'Não foi possível gerar o PIX.');
    } finally {
      setGeneratingPix(false);
    }
  };

  // Contagem regressiva até o vencimento informado pelo Mercado Pago
  useEffect(() => {
    const expiresAt = pixCheckout?.pix?.expiresAt;
    if (!expiresAt) return undefined;
    const update = () => {
      setTimeLeft(Math.max(0, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000)));
    };
    update();
    const timer = setInterval(update, 1000);
    return () => clearInterval(timer);
  }, [pixCheckout]);

  // Consulta o pagamento até ser aprovado (não depende do webhook chegar)
  useEffect(() => {
    if (!pixCheckout?.id || pixStatus !== 'pending') return undefined;
    let cancelled = false;
    const poll = setInterval(async () => {
      try {
        const res = await subscriptionRepository.getCheckout(pixCheckout.id, token);
        if (cancelled) return;
        const status = res?.checkout?.status;
        setPixStatus(status);
        if (status === 'approved') finishWithSuccess(res.subscription);
      } catch {
        // Falha temporária: tenta de novo no próximo ciclo
      }
    }, PIX_POLL_INTERVAL_MS);
    return () => {
      cancelled = true;
      clearInterval(poll);
    };
  }, [pixCheckout, pixStatus, token, finishWithSuccess]);

  const formatTimer = (seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  };

  const handleCopyPix = () => {
    const qrCode = pixCheckout?.pix?.qrCode;
    if (!qrCode || !navigator.clipboard?.writeText) return;
    navigator.clipboard.writeText(qrCode);
    setCopied(true);
    if (showToast) showToast(t('subscription.pixCopied'));
    setTimeout(() => setCopied(false), 3000);
  };

  // Simulação de aprovação (somente fora de produção)
  const handleSimulateApproval = async () => {
    try {
      setProcessingSimulation(true);
      const res = await subscriptionRepository.simulatePayment(plan.id, cycle, pixCheckout?.id || null, token);
      finishWithSuccess(res.subscription);
    } catch (err) {
      if (showToast) showToast(err.message || 'Falha ao confirmar pagamento.');
    } finally {
      setProcessingSimulation(false);
    }
  };

  const pixExpiresAt = pixCheckout?.pix?.expiresAt ? new Date(pixCheckout.pix.expiresAt).getTime() : null;
  // timeLeft re-renders this every second, so the check stays current
  const pixExpired = pixStatus === 'expired'
    || (pixStatus === 'pending' && pixExpiresAt !== null && timeLeft >= 0 && pixExpiresAt <= Date.now());
  const pixFailed = pixStatus === 'rejected' || pixStatus === 'canceled';

  return (
    <div className="mp-checkout-overlay" onClick={onClose}>
      <div className="mp-checkout-modal" onClick={(e) => e.stopPropagation()}>
        <div className="mp-checkout-header">
          <div className="mp-header-brand">
            <div className="mp-badge-icon">
              <i className="fa-solid fa-shield-check"></i>
            </div>
            <div>
              <h3>Mercado Pago &bull; Checkout Seguro</h3>
              <p>Plano {plan.name} ({cycleLabel})</p>
            </div>
          </div>
          <button type="button" className="btn-close-mp" onClick={onClose} title={t('common.close')}>
            <i className="fa-solid fa-xmark"></i>
          </button>
        </div>

        <div className="mp-amount-summary">
          <span className="mp-summary-label">Total a Pagar:</span>
          <span className="mp-summary-value">{formatCurrency(amount)}</span>
        </div>

        {!paymentsEnabled && !simulationEnabled ? (
          <div className="mp-loading-state">
            <i className="fa-solid fa-circle-info"></i>
            <p>Pagamentos online ainda não estão disponíveis. Tente novamente em breve.</p>
          </div>
        ) : (
          <>
            <div className="mp-method-tabs">
              <button
                type="button"
                className={`mp-tab-btn ${activeTab === 'card' ? 'active' : ''}`}
                onClick={() => setActiveTab('card')}
              >
                <i className="fa-solid fa-credit-card"></i>
                <span>{t('subscription.cardTab')}</span>
              </button>
              <button
                type="button"
                className={`mp-tab-btn ${activeTab === 'pix' ? 'active' : ''}`}
                onClick={() => setActiveTab('pix')}
              >
                <i className="fa-brands fa-pix"></i>
                <span>{t('subscription.pixTitle')}</span>
              </button>
            </div>

            <div className="mp-method-content">
              {error && (
                <div className="mp-form-alert error">
                  <i className="fa-solid fa-triangle-exclamation"></i>
                  <span>{error}</span>
                </div>
              )}

              {activeTab === 'card' ? (
                <div className="mp-card-form">
                  <div className="mp-debit-notice">
                    <i className="fa-solid fa-arrows-rotate" style={{ color: 'var(--color-primary)' }}></i>
                    <span>
                      Assinatura com <strong>renovação automática</strong>: {formatCurrency(amount)} a cada {periodLabel},
                      cobrados no cartão pelo Mercado Pago. Você pode cancelar a renovação quando quiser e o plano
                      continua ativo até o fim do período pago.
                    </span>
                  </div>

                  <div className="mp-security-badge">
                    <i className="fa-solid fa-lock"></i>
                    <span>
                      Os dados do cartão são digitados na página segura do Mercado Pago e nunca passam pelos servidores do Ethernity.
                    </span>
                  </div>

                  <button
                    type="button"
                    className="btn-pay-card"
                    onClick={handleSubscribeWithCard}
                    disabled={redirecting || !paymentsEnabled}
                  >
                    <i className={`fa-solid ${redirecting ? 'fa-arrows-rotate fa-spin' : 'fa-arrow-up-right-from-square'}`}></i>
                    <span>{redirecting ? 'Abrindo o Mercado Pago...' : 'Assinar no Mercado Pago'}</span>
                  </button>
                </div>
              ) : (
                <div className="mp-pix-container">
                  {!pixCheckout ? (
                    <>
                      <p className="mp-pix-instruction">
                        O PIX paga {periodLabel} do plano, <strong>sem renovação automática</strong>. Para continuar depois
                        desse período, basta pagar novamente.
                      </p>
                      <button
                        type="button"
                        className="btn-pay-card"
                        onClick={handleGeneratePix}
                        disabled={generatingPix || !paymentsEnabled}
                      >
                        <i className={`fa-${generatingPix ? 'solid fa-arrows-rotate fa-spin' : 'brands fa-pix'}`}></i>
                        <span>{generatingPix ? 'Gerando PIX...' : `Gerar PIX de ${formatCurrency(amount)}`}</span>
                      </button>
                    </>
                  ) : pixFailed || pixExpired ? (
                    <div className="mp-loading-state">
                      <i className="fa-solid fa-circle-exclamation"></i>
                      <p>{pixExpired ? 'Este PIX venceu sem pagamento.' : 'O pagamento PIX não foi aprovado.'}</p>
                      <button type="button" className="btn-pay-card" onClick={() => { setPixCheckout(null); setPixStatus(null); }}>
                        <i className="fa-brands fa-pix"></i>
                        <span>Gerar um novo PIX</span>
                      </button>
                    </div>
                  ) : (
                    <>
                      <p className="mp-pix-instruction">{t('subscription.pixDesc')}</p>

                      <div className="mp-qr-box">
                        {pixCheckout.pix?.qrCodeBase64 ? (
                          <img
                            className="mp-qr-svg"
                            src={`data:image/png;base64,${pixCheckout.pix.qrCodeBase64}`}
                            alt="QR code PIX para pagamento"
                            width="180"
                            height="180"
                          />
                        ) : (
                          <p className="mp-pix-instruction">Use o código copia e cola abaixo.</p>
                        )}
                        <div className="mp-qr-timer">
                          <i className="fa-solid fa-stopwatch"></i>
                          <span>{t('subscription.pixExpiresIn')} ({formatTimer(timeLeft)})</span>
                        </div>
                      </div>

                      <div className="mp-copia-cola-box">
                        <input
                          type="text"
                          readOnly
                          value={pixCheckout.pix?.qrCode || ''}
                          className="mp-copia-cola-input"
                          aria-label="Código PIX copia e cola"
                        />
                        <button
                          type="button"
                          className={`btn-copy-pix ${copied ? 'copied' : ''}`}
                          onClick={handleCopyPix}
                        >
                          <i className={`fa-solid ${copied ? 'fa-check' : 'fa-copy'}`}></i>
                          <span>{copied ? 'Copiado!' : t('subscription.copyPix')}</span>
                        </button>
                      </div>

                      <div className="mp-security-badge">
                        <i className="fa-solid fa-arrows-rotate fa-spin"></i>
                        <span>Aguardando o pagamento. A confirmação é automática.</span>
                      </div>
                    </>
                  )}
                </div>
              )}
            </div>
          </>
        )}

        {simulationEnabled && (
          <div className="mp-checkout-footer">
            <button
              type="button"
              className="btn-simulate-mp"
              onClick={handleSimulateApproval}
              disabled={processingSimulation}
            >
              <i className={`fa-solid ${processingSimulation ? 'fa-arrows-rotate fa-spin' : 'fa-circle-check'}`}></i>
              <span>{processingSimulation ? 'Validando...' : t('subscription.simulateApproval')}</span>
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
