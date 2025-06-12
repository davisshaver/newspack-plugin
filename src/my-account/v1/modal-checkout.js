/* global newspackMyAccountV1 */
/**
 * Implement modal checkout for My Account buttons.
 */

/**
 * Internal dependencies.
 */
import { domReady } from '../../utils';
import { registerModalCheckoutButton } from './utils';

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	/**
	 * Resubscribe.
	 */
	const resubscribe = document.querySelectorAll( '.resubscribe' );
	resubscribe.forEach( button => {
		registerModalCheckoutButton(
			button,
			newspackMyAccountV1.labels.resubscribe_title,
			'resubscribe',
			data => {
				// Track the subscription reactivation.
				window.newspackRAS.push( [
					'subscription_reactivated',
					{
						subscription_id: data.subscription_ids?.[ 0 ],
					},
				] );
			}
		);
	} );

	/**
	 * Renewal early.
	 */
	const renewalEarly = document.querySelectorAll(
		'.subscription_renewal_early'
	);
	renewalEarly.forEach( button => {
		registerModalCheckoutButton(
			button,
			newspackMyAccountV1.labels.renewal_early_title,
			'renewal_early',
			data => {
				// Track the renewal early.
				window.newspackRAS.push( [
					'renewal_early',
					{ subscription_id: data.subscription_renewal },
				] );
			}
		);
	} );

	/**
	 * Change payment method.
	 */
	const changePaymentMethod = document.querySelectorAll(
		'.change_payment_method'
	);
	changePaymentMethod.forEach( button => {
		registerModalCheckoutButton(
			button,
			newspackMyAccountV1.labels.change_payment_method_title,
			'change_payment_method',
			data => {
				// Track the change payment method.
				window.newspackRAS.push( [
					'change_payment_method',
					{ subscription_id: data.subscription_ids?.[ 0 ] },
				] );
			}
		);
	} );
} );
