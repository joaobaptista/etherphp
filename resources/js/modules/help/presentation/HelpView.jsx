import React, { useState } from 'react';
import './HelpView.css';

export default function HelpView() {
  const [openFaqIndex, setOpenFaqIndex] = useState(null);

  const faqs = [
    {
      question: 'O que são as Cápsulas do Tempo do Ethernity?',
      answer:
        'Cápsulas do tempo são repositórios criptografados contendo fotos originais, áudios com a sua própria voz, vídeos e cartas que só serão entregues aos seus destinatários em uma data pré-agendada ou através do protocolo póstumo com seus guardiões.',
      category: 'capsules',
    },
    {
      question: 'Como funciona o protocolo de liberação com os Guardiões?',
      answer:
        'Você cadastra pessoas de sua máxima confiança (Guardiões). Caso o protocolo de ausência prolongada (Dead Man\'s Switch) seja ativado ou mediante apresentação de certidão e quórum mínimo de aprovação entre os guardiões, as instruções e cápsulas designadas a cada familiar são liberadas com segurança militar.',
      category: 'guardian',
    },
    {
      question: 'Meus arquivos e fotos estão realmente protegidos?',
      answer:
        'Sim. As mídias do cofre são gravadas no servidor cifradas com AES-256-GCM, trafegam por conexão segura (HTTPS) e só podem ser acessadas pela sua conta. Uma cópia local fica no seu aparelho para abrir mais rápido, e a exclusão de um item apaga as duas cópias.',
      category: 'security',
    },
    {
      question: 'O que acontece quando eu excluo uma cápsula ou item?',
      answer:
        'Conforme as diretrizes estritas do Ethernity, a exclusão é definitiva (expurgo imediato e irreversível de memórias, rascunhos e do banco de dados). Não mantemos cópias ocultas nem ressuscitamos dados apagados.',
      category: 'privacy',
    },
    {
      question: 'Como conectar minha conta do Google Fotos?',
      answer:
        'Na aba "Comece Aqui" ou no "Meu Cofre", clique no botão Google Fotos. Você será direcionado com segurança via OAuth 2.0 oficial para selecionar apenas as fotos de memórias que deseja importar, com preservação da resolução nativa.',
      category: 'integrations',
    },
  ];

  const toggleFaq = (idx) => {
    setOpenFaqIndex(openFaqIndex === idx ? null : idx);
  };

  return (
    <div className="help-view-wrapper fade-in">
      {/* FAQ Sanfona */}
      <div className="help-faq-section">
        <h2 className="help-section-title">Perguntas Frequentes (FAQ)</h2>
        <div className="help-faq-list">
          {faqs.map((faq, idx) => {
            const isOpen = openFaqIndex === idx;
            return (
              <div
                key={idx}
                className={`help-faq-item ${isOpen ? 'is-open' : ''}`}
                onClick={() => toggleFaq(idx)}
              >
                <div className="help-faq-question-row">
                  <span className="help-faq-q-text">{faq.question}</span>
                  <i
                    className={`fa-solid ${
                      isOpen ? 'fa-chevron-up' : 'fa-chevron-down'
                    } help-faq-chevron`}
                  ></i>
                </div>
                {isOpen && (
                  <div className="help-faq-answer">
                    <p>{faq.answer}</p>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Card de Atendimento ao Cliente */}
      <div className="help-support-banner">
        <div className="help-support-content">
          <div className="help-support-icon">
            <i className="fa-solid fa-headset"></i>
          </div>
          <div className="help-support-text">
            <h3>Precisa de suporte personalizado?</h3>
            <p>Nossa equipe de especialistas em legado digital está disponível para orientar sua família.</p>
          </div>
        </div>
        <a
          href="mailto:suporte@ethernity.com"
          className="help-support-contact-btn"
        >
          <i className="fa-solid fa-envelope"></i> Contatar Suporte Ethernity
        </a>
      </div>
    </div>
  );
}
