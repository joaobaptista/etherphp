import React, { useState, useEffect } from 'react';
import { useI18n } from '../../../core/i18n/presentation/useI18n.js';
import { SUBSCRIPTION_PLANS, formatCurrency } from '../domain/subscriptionModel.js';
import subscriptionRepository from '../infrastructure/subscriptionRepository.js';
import MercadoPagoCheckoutModal from './MercadoPagoCheckoutModal.jsx';
import './SubscriptionPlansView.css';

function formatDate(iso) {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleDateString('pt-BR');
  } catch {
    return '';
  }
}

export default function SubscriptionPlansView({
  currentSubscription,
  token,
  onSubscriptionUpdated,
  showToast,
  onBack,
}) {
  const { t } = useI18n();
  const [billingCycle, setBillingCycle] = useState('monthly');
  const [selectedPlanForCheckout, setSelectedPlanForCheckout] = useState(null);
  const [catalog, setCatalog] = useState({ plans: [], paymentsEnabled: false, simulationEnabled: false });
  const [cancelingRenewal, setCancelingRenewal] = useState(false);

  // Preços e benefícios vêm do backend, que é quem cobra
  useEffect(() => {
    let active = true;
    subscriptionRepository.getPlans()
      .then((res) => {
        if (!active || !res) return;
        setCatalog({
          plans: Array.isArray(res.plans) ? res.plans : [],
          paymentsEnabled: Boolean(res.paymentsEnabled),
          simulationEnabled: Boolean(res.simulationEnabled),
        });
      })
      .catch(() => {});
    return () => { active = false; };
  }, []);

  const currentPlanId = currentSubscription?.planId || 'free';
  const autoRenew = Boolean(currentSubscription?.autoRenew);
  const backendPlans = Object.fromEntries(catalog.plans.map((plan) => [plan.id, plan]));
  const plans = Object.values(SUBSCRIPTION_PLANS)
    .map((plan) => ({
      ...plan,
      ...backendPlans[plan.id],
      features: backendPlans[plan.id]?.features || [],
    }));

  const handleSelectPlan = (plan) => {
    if (plan.id === currentPlanId) return;

    if (plan.id === 'free') {
      showToast?.(autoRenew
        ? 'Para voltar ao plano gratuito, cancele a renovação automática. O plano atual segue ativo até o fim do período pago.'
        : 'Seu plano volta ao gratuito automaticamente ao fim do período pago.');
      return;
    }

    if (autoRenew) {
      showToast?.('Cancele a renovação automática do plano atual antes de trocar de plano.');
      return;
    }

    setSelectedPlanForCheckout(plan);
  };

  const handleCancelRenewal = async () => {
    const confirmed = window.confirm(
      'Cancelar a renovação automática? Nenhuma nova cobrança será feita e seu plano continua ativo até '
        + `${formatDate(currentSubscription?.expiresAt) || 'o fim do período pago'}.`
    );
    if (!confirmed) return;
    try {
      setCancelingRenewal(true);
      const res = await subscriptionRepository.cancelAutoRenew(token);
      if (res?.subscription) onSubscriptionUpdated?.(res.subscription);
      showToast?.(res?.message || 'Renovação automática cancelada.');
    } catch (err) {
      showToast?.(err.message || 'Não foi possível cancelar a renovação.');
    } finally {
      setCancelingRenewal(false);
    }
  };

  const handlePaymentSuccess = (updatedSubscription) => {
    onSubscriptionUpdated?.(updatedSubscription);
    setSelectedPlanForCheckout(null);
  };

  return (
    <>
      <main className="subscription-page" aria-labelledby="subscription-page-title">
        <div className="subscription-page-inner">
          <button
            type="button"
            className="subscription-page-back"
            onClick={onBack}
          >
            <i className="fa-solid fa-arrow-left" aria-hidden="true" />
            <span>{t('common.back')}</span>
          </button>

          <header className="subscription-page-header">
            <div className="subscription-page-heading">
              <span className="subscription-page-eyebrow">
                <i className="fa-solid fa-crown" aria-hidden="true" />
                {t('subscription.menuItem')}
              </span>
              <h1 id="subscription-page-title">{t('subscription.modalTitle')}</h1>
              <p>{t('subscription.modalSubtitle')}</p>
            </div>

            <div className="subscription-page-cycle" aria-label="Ciclo de cobrança">
              <button
                type="button"
                className={billingCycle === 'monthly' ? 'is-active' : ''}
                onClick={() => setBillingCycle('monthly')}
                aria-pressed={billingCycle === 'monthly'}
              >
                {t('subscription.monthly')}
              </button>
              <button
                type="button"
                className={billingCycle === 'yearly' ? 'is-active' : ''}
                onClick={() => setBillingCycle('yearly')}
                aria-pressed={billingCycle === 'yearly'}
              >
                <span>{t('subscription.yearly')}</span>
                <span className="subscription-page-discount">{t('subscription.yearlyDiscount')}</span>
              </button>
            </div>
          </header>

          {currentPlanId !== 'free' && currentPlanId !== 'infinity' && currentSubscription?.expiresAt && (
            <section className="subscription-page-security" aria-label="Situação da assinatura">
              <i className={`fa-solid ${autoRenew ? 'fa-arrows-rotate' : 'fa-calendar-check'}`} aria-hidden="true" />
              <span>
                {autoRenew
                  ? <>Renovação automática ativa. Próxima cobrança em <strong>{formatDate(currentSubscription.expiresAt)}</strong>.</>
                  : <>Plano ativo até <strong>{formatDate(currentSubscription.expiresAt)}</strong>, sem renovação automática.</>}
              </span>
              {autoRenew && (
                <button type="button" onClick={handleCancelRenewal} disabled={cancelingRenewal}>
                  {cancelingRenewal ? 'Cancelando...' : 'Cancelar renovação'}
                </button>
              )}
            </section>
          )}

          <section className="subscription-page-grid" aria-label="Planos disponíveis">
            {plans.map((plan) => {
              const isCurrent = plan.id === currentPlanId;
              const price = billingCycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
              const monthlyEquivalent = billingCycle === 'yearly' && plan.priceYearly
                ? Math.round(plan.priceYearly / 12)
                : null;

              return (
                <article
                  key={plan.id}
                  className={`subscription-page-card ${plan.popular ? 'is-popular' : ''} ${isCurrent ? 'is-current' : ''}`}
                >
                  {plan.popular && (
                    <span className="subscription-page-popular">
                      <i className="fa-solid fa-star" aria-hidden="true" />
                      {t('subscription.popularBadge')}
                    </span>
                  )}

                  <div className="subscription-page-card-heading">
                    <h2>{plan.name}</h2>
                    <div className="subscription-page-price">
                      <span>{price === 0 ? 'Grátis' : formatCurrency(price)}</span>
                      {price > 0 && (
                        <small>
                          {billingCycle === 'yearly'
                            ? t('subscription.perYear')
                            : t('subscription.perMonth')}
                        </small>
                      )}
                    </div>
                    {monthlyEquivalent && (
                      <p className="subscription-page-equivalent">
                        Equivale a {formatCurrency(monthlyEquivalent)}/mês
                      </p>
                    )}
                  </div>

                  <ul className="subscription-page-features">
                    {plan.features.map((feature) => (
                      <li key={feature}>
                        <i className="fa-solid fa-circle-check" aria-hidden="true" />
                        <span>{feature}</span>
                      </li>
                    ))}
                  </ul>

                  <div className="subscription-page-card-action">
                    {isCurrent ? (
                      <span className="subscription-page-current">
                        <i className="fa-solid fa-check" aria-hidden="true" />
                        {t('subscription.currentPlan')}
                      </span>
                    ) : (
                      <button
                        type="button"
                        className={plan.popular ? 'is-primary' : ''}
                        onClick={() => handleSelectPlan(plan)}
                      >
                        <i className="fa-solid fa-crown" aria-hidden="true" />
                        {t('subscription.choosePlan')}
                      </button>
                    )}
                  </div>
                </article>
              );
            })}
          </section>

          <footer className="subscription-page-security">
            <i className="fa-solid fa-lock" aria-hidden="true" />
            <span>
              Pagamento processado com segurança via <strong>Mercado Pago</strong>: cartão com renovação automática ou PIX por período
            </span>
          </footer>
        </div>
      </main>

      {selectedPlanForCheckout && (
        <MercadoPagoCheckoutModal
          plan={selectedPlanForCheckout}
          cycle={billingCycle}
          token={token}
          paymentsEnabled={catalog.paymentsEnabled}
          simulationEnabled={catalog.simulationEnabled}
          onClose={() => setSelectedPlanForCheckout(null)}
          onPaymentSuccess={handlePaymentSuccess}
          showToast={showToast}
        />
      )}
    </>
  );
}
