/* global newspack_engagement_wizard */
/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { ExternalLink, TextareaControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	ActionCard,
	Button,
	Card,
	Grid,
	Notice,
	PluginInstaller,
	SectionHeader,
	TextControl,
	Waiting,
	withWizardScreen,
	utils,
} from '../../../../components/src';
import Prerequisite from '../../components/prerequisite';
import ActiveCampaign from '../../components/active-campaign';
import MetadataFields from '../../components/metadata-fields';
import Mailchimp from '../../components/mailchimp';
import { HANDOFF_KEY } from '../../../../components/src/consts';
import SortableNewsletterListControl from '../../../../components/src/sortable-newsletter-list-control';

export default withWizardScreen( ( { wizardApiFetch } ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ config, setConfig ] = useState( {} );
	const [ membershipsConfig, setMembershipsConfig ] = useState( {} );
	const [ error, setError ] = useState( false );
	const [ allReady, setAllReady ] = useState( false );
	const [ isActiveCampaign, setIsActiveCampaign ] = useState( false );
	const [ isMailchimp, setIsMailchimp ] = useState( false );
	const [ prerequisites, setPrerequisites ] = useState( null );
	const [ missingPlugins, setMissingPlugins ] = useState( [] );
	const [ showAdvanced, setShowAdvanced ] = useState( false );
	const [ espSyncErrors, setEspSyncErrors ] = useState( [] );
	const updateConfig = ( key, val ) => {
		setConfig( { ...config, [ key ]: val } );
	};
	const fetchConfig = () => {
		setError( false );
		setInFlight( true );
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-engagement-wizard/reader-activation',
		} )
			.then( ( { config: fetchedConfig, prerequisites_status, memberships, can_esp_sync } ) => {
				setPrerequisites( prerequisites_status );
				setConfig( fetchedConfig );
				setMembershipsConfig( memberships );
				setEspSyncErrors( can_esp_sync.errors );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	const saveConfig = data => {
		setError( false );
		setInFlight( true );
		wizardApiFetch( {
			path: '/newspack/v1/wizard/newspack-engagement-wizard/reader-activation',
			method: 'post',
			quiet: true,
			data,
		} )
			.then( ( { config: fetchedConfig, prerequisites_status, memberships, can_esp_sync } ) => {
				setPrerequisites( prerequisites_status );
				setConfig( fetchedConfig );
				setMembershipsConfig( memberships );
				setEspSyncErrors( can_esp_sync.errors );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	const resetEmail = postId => {
		setError( false );
		setInFlight( true );
		wizardApiFetch( {
			path: `/newspack/v1/wizard/newspack-engagement-wizard/reader-activation/emails/${ postId }`,
			method: 'DELETE',
			quiet: true,
		} )
			.then( emails => setConfig( { ...config, emails } ) )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	useEffect( () => {
		window.scrollTo( 0, 0 );
		fetchConfig();

		// Clear the handoff when the component mounts.
		window.localStorage.removeItem( HANDOFF_KEY );
	}, [] );
	useEffect( () => {
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-engagement-wizard/newsletters',
		} ).then( data => {
			setIsMailchimp(
				data?.settings?.newspack_newsletters_service_provider?.value === 'mailchimp'
			);
			setIsActiveCampaign(
				data?.settings?.newspack_newsletters_service_provider?.value === 'active_campaign'
			);
		} );
	}, [] );
	useEffect( () => {
		const _allReady =
			! missingPlugins.length &&
			prerequisites &&
			Object.keys( prerequisites ).every(
				key => prerequisites[ key ]?.active || prerequisites[ key ]?.skipped
			);

		setAllReady( _allReady );

		if ( prerequisites ) {
			setMissingPlugins(
				Object.keys( prerequisites ).reduce( ( acc, slug ) => {
					const prerequisite = prerequisites[ slug ];
					if ( prerequisite.plugins ) {
						for ( const pluginSlug in prerequisite.plugins ) {
							if ( ! prerequisite.plugins[ pluginSlug ] ) {
								acc.push( pluginSlug );
							}
						}
					}
					return acc;
				}, [] )
			);
		}
	}, [ prerequisites ] );

	const getSharedProps = ( configKey, type = 'checkbox' ) => {
		const props = {
			onChange: val => updateConfig( configKey, val ),
		};
		if ( configKey !== 'enabled' ) {
			props.disabled = inFlight;
		}
		switch ( type ) {
			case 'checkbox':
				props.checked = Boolean( config[ configKey ] );
				break;
			case 'text':
				props.value = config[ configKey ] || '';
				break;
		}

		return props;
	};

	const emails = Object.values( config.emails || {} );

	const getContentGateDescription = () => {
		let message = __(
			'Configure the gate rendered on content with restricted access.',
			'newspack-plugin'
		);
		if ( 'publish' === membershipsConfig?.gate_status ) {
			message += ' ' + __( 'The gate is currently published.', 'newspack-plugin' );
		} else if (
			'draft' === membershipsConfig?.gate_status ||
			'trash' === membershipsConfig?.gate_status
		) {
			message += ' ' + __( 'The gate is currently a draft.', 'newspack-plugin' );
		}
		return message;
	};

	return (
		<>
			<SectionHeader
				title={ __( 'Reader Activation', 'newspack-plugin' ) }
				description={ () => (
					<>
						{ __(
							'Newspack’s Reader Activation system is a set of features that aim to increase reader loyalty, promote engagement, and drive revenue. ',
							'newspack-plugin'
						) }
						<ExternalLink href={ 'https://help.newspack.com/engagement/reader-activation-system' }>
							{ __( 'Learn more', 'newspack-plugin' ) }
						</ExternalLink>
					</>
				) }
			/>
			{ error && (
				<Notice
					noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) }
					isError
				/>
			) }
			{ 0 < missingPlugins.length && (
				<Notice
					noticeText={ __( 'The following plugins are required.', 'newspack-plugin' ) }
					isWarning
				/>
			) }
			{ 0 === missingPlugins.length && prerequisites && ! allReady && (
				<Notice
					noticeText={ __(
						'Complete these settings to enable Reader Activation.',
						'newspack-plugin'
					) }
					isWarning
				/>
			) }
			{ prerequisites && allReady && config.enabled && (
				<Notice noticeText={ __( 'Reader Activation is enabled.', 'newspack-plugin' ) } isSuccess />
			) }
			{ ! prerequisites && (
				<>
					<Waiting isLeft />
					{ __( 'Retrieving status…', 'newspack-plugin' ) }
				</>
			) }
			{ 0 < missingPlugins.length && prerequisites && (
				<PluginInstaller
					plugins={ missingPlugins }
					withoutFooterButton
					onStatus={ ( { complete } ) => complete && fetchConfig() }
				/>
			) }
			{ ! missingPlugins.length &&
				prerequisites &&
				Object.keys( prerequisites ).map( key => (
					<Prerequisite
						key={ key }
						config={ config }
						getSharedProps={ getSharedProps }
						inFlight={ inFlight }
						prerequisite={ prerequisites[ key ] }
						fetchConfig={ fetchConfig }
						saveConfig={ saveConfig }
					/>
				) ) }
			{ config.enabled && (
				<>
					<hr />
					<Button variant="secondary" onClick={ () => setShowAdvanced( ! showAdvanced ) }>
						{ sprintf(
							// Translators: Show or Hide advanced settings.
							__( '%s Advanced Settings', 'newspack-plugin' ),
							showAdvanced ? __( 'Hide', 'newspack-plugin' ) : __( 'Show', 'newspack-plugin' )
						) }
					</Button>
				</>
			) }
			{ showAdvanced && (
				<Card noBorder>
					{ newspack_engagement_wizard.has_memberships && membershipsConfig ? (
						<>
							<SectionHeader
								title={ __( 'Memberships Integration', 'newspack-plugin' ) }
								description={ __(
									'Improve the reader experience on content gating.',
									'newspack-plugin'
								) }
							/>
							<ActionCard
								title={ __( 'Content Gate', 'newspack-plugin' ) }
								titleLink={ membershipsConfig.edit_gate_url }
								href={ membershipsConfig.edit_gate_url }
								description={ getContentGateDescription() }
								actionText={ __( 'Configure', 'newspack-plugin' ) }
							/>
							{ membershipsConfig?.plans && 1 < membershipsConfig.plans.length && (
								<ActionCard
									title={ __( 'Require membership in all plans', 'newspack-plugin' ) }
									description={ __(
										'When enabled, readers must belong to all membership plans that apply to a restricted content item before they are granted access. Otherwise, they will be able to unlock access to that item with membership in any single plan that applies to it.',
										'newspack-plugin'
									) }
									toggleOnChange={ value =>
										setMembershipsConfig( { ...membershipsConfig, require_all_plans: value } )
									}
									toggleChecked={ membershipsConfig.require_all_plans }
								/>
							) }
							<ActionCard
								title={ __( 'Display memberships on the subscriptions tab', 'newspack-plugin' ) }
								description={ __(
									"Display memberships that don't have active subscriptions on the My Account Subscriptions tab, so readers can see information like expiration dates.",
									'newspack-plugin'
								) }
								toggleOnChange={ value =>
									setMembershipsConfig( { ...membershipsConfig, show_on_subscription_tab: value } )
								}
								toggleChecked={ membershipsConfig.show_on_subscription_tab }
							/>
							<hr />
						</>
					) : null }

					{ emails?.length > 0 && (
						<>
							<SectionHeader
								title={ __( 'Transactional Email Content', 'newspack-plugin' ) }
								description={ __(
									'Customize the content of transactional emails.',
									'newspack-plugin'
								) }
							/>
							{ emails.map( email => (
								<ActionCard
									key={ email.post_id }
									title={ email.label }
									titleLink={ email.edit_link }
									href={ email.edit_link }
									description={ email.description }
									actionText={ __( 'Edit', 'newspack-plugin' ) }
									onSecondaryActionClick={ () => {
										if (
											utils.confirmAction(
												__(
													'Are you sure you want to reset the contents of this email?',
													'newspack-plugin'
												)
											)
										) {
											resetEmail( email.post_id );
										}
									} }
									secondaryActionText={ __( 'Reset', 'newspack-plugin' ) }
									secondaryDestructive={ true }
									isSmall
								/>
							) ) }
							<hr />
						</>
					) }

					<SectionHeader title={ __( 'Newsletter Subscription Lists', 'newspack-plugin' ) } />
					<ActionCard
						title={ __(
							'Present newsletter signup after checkout and registration',
							'newspack-plugin'
						) }
						description={ __(
							'Ask readers to sign up for newsletters after creating an account or completing a purchase.',
							'newspack-plugin'
						) }
						toggleChecked={ config.use_custom_lists }
						toggleOnChange={ value => updateConfig( 'use_custom_lists', value ) }
					/>
					{ config.use_custom_lists && (
						<SortableNewsletterListControl
							lists={ newspack_engagement_wizard.available_newsletter_lists }
							selected={ config.newsletter_lists }
							onChange={ selected => updateConfig( 'newsletter_lists', selected ) }
						/>
					) }

					<hr />

					<SectionHeader
						title={ __( 'Email Service Provider (ESP) Advanced Settings', 'newspack-plugin' ) }
						description={ __(
							'Settings for Newspack Newsletters integration.',
							'newspack-plugin'
						) }
					/>
					<TextControl
						label={ __( 'Newsletter subscription text on registration', 'newspack-plugin' ) }
						help={ __(
							'The text to display while subscribing to newsletters from the sign-in modal.',
							'newspack-plugin'
						) }
						{ ...getSharedProps( 'newsletters_label', 'text' ) }
					/>
					<ActionCard
						description={ __(
							'Configure options for syncing reader data to the connected ESP.',
							'newspack-plugin'
						) }
						hasGreyHeader={ true }
						isMedium
						title={ __( 'Sync contacts to ESP', 'newspack-plugin' ) }
						toggleChecked={ config.sync_esp }
						toggleOnChange={ value => updateConfig( 'sync_esp', value ) }
					>
						{ config.sync_esp && (
							<>
								{ 0 < Object.keys(espSyncErrors).length && (
									<Notice
										noticeText={ Object.values(espSyncErrors).join( ' ' ) }
										isError
									/>
								) }
								{ isMailchimp && (
									<Mailchimp
										value={ {
											audienceId: config.mailchimp_audience_id,
											readerDefaultStatus: config.mailchimp_reader_default_status,
										} }
										onChange={ ( key, value ) => {
											if ( key === 'audienceId' ) {
												updateConfig( 'mailchimp_audience_id', value );
											}
											if ( key === 'readerDefaultStatus' ) {
												updateConfig( 'mailchimp_reader_default_status', value );
											}
										} }
									/>
								) }
								{ isActiveCampaign && (
									<ActiveCampaign
										value={ { masterList: config.active_campaign_master_list } }
										onChange={ ( key, value ) => {
											if ( key === 'masterList' ) {
												updateConfig( 'active_campaign_master_list', value );
											}
										} }
									/>
								) }
								<MetadataFields
									availableFields={ newspack_engagement_wizard.esp_metadata_fields || [] }
									selectedFields={ config.metadata_fields }
									updateConfig={ updateConfig }
									getSharedProps={ getSharedProps }
								/>
							</>
						) }
					</ActionCard>

					<hr />

					<SectionHeader title={ __( 'Checkout Configuration', 'newspack-plugin' ) } />

					<ToggleControl
						label={ __(
							'Require sign in or create account before checkout',
							'newspack-plugin'
						) }
						help={ __(
							'Prompt users who are not logged in to sign in or register a new account before proceeding to checkout. When disabled, an account will automatically be created with the email address used at checkout.',
							'newspack-plugin'
						) }
						checked={ config.woocommerce_registration_required }
						onChange={ value => updateConfig( 'woocommerce_registration_required', value ) }
					/>
					<Grid>
						<TextareaControl
							label={ __( 'Post-checkout success message', 'newspack-plugin' ) }
							help={ __(
								'The success message to display to readers after completing checkout.',
								'newspack-plugin'
							) }
							{ ...getSharedProps( 'woocommerce_post_checkout_success_text', 'text' ) }
						/>
						{ ! config.woocommerce_registration_required && (
							<TextareaControl
								label={ __( 'Post-checkout registration success message', 'newspack-plugin' ) }
								help={ __(
									'The success message to display to new readers that have an account automatically created after completing checkout.',
									'newspack-plugin'
								) }
								{ ...getSharedProps( 'woocommerce_post_checkout_registration_success_text', 'text' ) }
							/>
						) }
					</Grid>
					<Grid>
						<TextareaControl
							label={ __( 'Checkout privacy policy text', 'newspack-plugin' ) }
							help={ __(
								'The privacy policy text to display at time of checkout for existing users. This will not show up unless a privacy page is set.',
								'newspack-plugin'
							) }
							{ ...getSharedProps( 'woocommerce_checkout_privacy_policy_text', 'text' ) }
						/>
					</Grid>
					<div className="newspack-buttons-card">
						<Button
							isPrimary
							onClick={ () => {
								if ( config.sync_esp ) {
									if (isMailchimp && config.mailchimp_audience_id === '') {
										// eslint-disable-next-line no-alert
										alert( __( 'Please select a Mailchimp Audience ID.', 'newspack-plugin' ) );
										return
									}
									if (isActiveCampaign && config.active_campaign_master_list === '') {
										// eslint-disable-next-line no-alert
										alert( __( 'Please select an ActiveCampaign Master List.', 'newspack-plugin' ) );
										return
									}
								}
								saveConfig( {
									newsletters_label: config.newsletters_label, // TODO: Deprecate this in favor of user input via the prompt copy wizard.
									mailchimp_audience_id: config.mailchimp_audience_id,
									mailchimp_reader_default_status: config.mailchimp_reader_default_status,
									active_campaign_master_list: config.active_campaign_master_list,
									memberships_require_all_plans: membershipsConfig.require_all_plans,
									memberships_show_on_subscription_tab: membershipsConfig.show_on_subscription_tab,
									use_custom_lists: config.use_custom_lists,
									newsletter_lists: config.newsletter_lists,
									sync_esp: config.sync_esp,
									metadata_fields: config.metadata_fields,
									metadata_prefix: config.metadata_prefix,
									woocommerce_registration_required: config.woocommerce_registration_required,
									woocommerce_checkout_privacy_policy_text: config.woocommerce_checkout_privacy_policy_text,
									woocommerce_post_checkout_success_text: config.woocommerce_post_checkout_success_text,
									woocommerce_post_checkout_registration_success_text: config.woocommerce_post_checkout_registration_success_text,
								} );
							} }
							disabled={ inFlight }
						>
							{ __( 'Save advanced settings', 'newspack-plugin' ) }
						</Button>
					</div>
				</Card>
			) }
		</>
	);
} );
