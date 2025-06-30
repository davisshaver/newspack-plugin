/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { withWizardScreen } from '../../../../components/src';
import { useWizardData } from '../../../../components/src/wizard/store/utils';
import WizardsTab from '../../../wizards-tab';
import Platform from '../../components/platform';
import PaymentGateways from '../../components/payment-methods';
import NRHSettings from '../../components/nrh-settings';
import BillingFields from '../../components/billing-fields';
import CheckoutConfiguration from '../../components/checkout-configuration';
import SubscriptionSettings from '../../components/subscription-settings';

export default withWizardScreen( function () {
	const data = useWizardData( 'newspack-audience/payment' );

	return (
		<WizardsTab
			title={ __( 'Checkout & Payment', 'newspack-plugin' ) }
			description={ __( 'Reader revenue configuration for donations and subscriptions.', 'newspack-plugin' ) }
		>
			<Platform />
			{ data?.platform_data?.platform === 'wc' && <PaymentGateways /> }
			{ data?.platform_data?.platform === 'wc' && <BillingFields /> }
			{ data?.platform_data?.platform === 'nrh' && <NRHSettings /> }
			<CheckoutConfiguration />
			{ data?.platform_data?.platform === 'wc' && <SubscriptionSettings /> }
		</WizardsTab>
	);
} );
