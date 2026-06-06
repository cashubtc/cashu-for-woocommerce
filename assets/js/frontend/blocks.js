const settings = window.wc.wcSettings.getSetting('cashu_default_data', {});
const label = window.wp.htmlEntities.decodeEntities(settings.title || 'Cashu ecash');
const el = window.wp.element.createElement;

const Content = () => {
  return window.wp.htmlEntities.decodeEntities(settings.description || '');
};

const Label = (props) => {
  const PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
  const text = PaymentMethodLabel
    ? el(PaymentMethodLabel, { text: label })
    : el('span', null, label);

  if (!settings.icon) {
    return text;
  }

  return el(
    'span',
    {
      style: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        width: '100%',
      },
    },
    text,
    el('img', {
      src: settings.icon,
      alt: label,
      style: { height: '24px', width: 'auto' },
    }),
  );
};

const CashuBlockGateway = {
  name: 'cashu_default',
  label: el(Label, null),
  content: el(Content, null),
  edit: el(Content, null),
  canMakePayment: () => settings.enabled === 'yes',
  ariaLabel: label,
  supports: {
    features: settings.supports || [],
  },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(CashuBlockGateway);
