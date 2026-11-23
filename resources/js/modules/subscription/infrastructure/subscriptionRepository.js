/**
 * subscriptionRepository.js
 * Camada de infraestrutura para planos de assinatura e pagamentos Mercado Pago.
 *
 * Dados de cartão NUNCA passam por aqui: a assinatura com cartão é concluída na
 * página do Mercado Pago (initPoint) e o PIX é gerado pelo backend.
 */
import httpClient from '../../../core/api/httpClient.js';

class SubscriptionRepository {
  /**
   * Planos vigentes, se pagamentos reais estão habilitados e se a simulação está disponível
   */
  async getPlans() {
    return httpClient.get('/subscriptions/plans');
  }

  /**
   * Assinatura atual e uso de limites do usuário autenticado
   */
  async getCurrentSubscription(token) {
    return httpClient.get('/subscriptions/current', token);
  }

  /**
   * Inicia um pagamento real no Mercado Pago.
   * method 'card' → assinatura recorrente (retorna checkout.initPoint para redirecionar)
   * method 'pix'  → cobrança PIX de um período (retorna checkout.pix com QR code)
   */
  async startCheckout(planId, cycle = 'monthly', method = 'card', token = null) {
    return httpClient.post('/subscriptions/checkout', { planId, cycle, method }, token);
  }

  /**
   * Consulta (e sincroniza com o Mercado Pago) o estado de um checkout
   */
  async getCheckout(checkoutId, token = null) {
    return httpClient.get(`/subscriptions/checkouts/${encodeURIComponent(checkoutId)}`, token);
  }

  /**
   * Cancela a renovação automática (o plano segue ativo até o fim do período pago)
   */
  async cancelAutoRenew(token = null) {
    return httpClient.post('/subscriptions/cancel', {}, token);
  }

  /**
   * Simula a aprovação imediata do pagamento (apenas fora de produção)
   */
  async simulatePayment(planId, cycle = 'monthly', paymentId = null, token = null) {
    return httpClient.post(
      '/subscriptions/simulate-payment',
      { planId, cycle, paymentId },
      token
    );
  }
}

export const subscriptionRepository = new SubscriptionRepository();
export default subscriptionRepository;
