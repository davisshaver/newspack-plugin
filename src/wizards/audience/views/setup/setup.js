/* global newspackAudience */
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	ActionCard,
	Button,
	Card,
	Notice,
	PluginInstaller,
	SectionHeader,
	TextControl,
	Waiting,
	withWizardScreen,
} from '../../../../components/src';
import WizardsTab from '../../../wizards-tab';
import Prerequisite from '../../components/prerequisite';
import ActiveCampaign from '../../components/active-campaign';
import MetadataFields from '../../components/metadata-fields';
import Mailchimp from '../../components/mailchimp';
import { HANDOFF_KEY } from '../../../../components/src/consts';
import SortableNewsletterListControl from '../../../../components/src/sortable-newsletter-list-control';
import Salesforce from '../../components/salesforce';

export default withWizardScreen(
	(
		{
			config,
			fetchConfig,
			updateConfig,
			getSharedProps,
			saveConfig,
			skipPrerequisite,
			prerequisites,
			espSyncErrors,
			error,
			inFlight
		}
	) => {
	const [ allReady, setAllReady ] = useState( false );
	const [ isActiveCampaign, setIsActiveCampaign ] = useState( false );
	const [ isMailchimp, setIsMailchimp ] = useState( false );
	const [ missingPlugins, setMissingPlugins ] = useState( [] );

	useEffect( () => {
		window.scrollTo( 0, 0 );
		// Clear the handoff when the component mounts.
		window.localStorage.removeItem( HANDOFF_KEY );
	}, [] );

	useEffect( () => {
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-newsletters/settings',
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
				key => prerequisites[ key ]?.active || prerequisites[ key ]?.is_skipped
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

	return (
		<WizardsTab
			title={ __( 'Audience Management', 'newspack-plugin' ) }
			description={
				<>
					{ __(
						"Newspack's Audience Management system is a set of features that aim to increase reader loyalty, promote engagement, and drive revenue. ",
						'newspack-plugin'
					) }
					<ExternalLink
						href={
							'https://help.newspack.com/engagement/audience-management-system'
						}
					>
						{ __( 'Learn more', 'newspack-plugin' ) }
					</ExternalLink>
				</>
			}
		>
			{ error && (
				<Notice
					noticeText={
						error?.message ||
						__( 'Something went wrong.', 'newspack-plugin' )
					}
					isError
				/>
			) }
			{ 0 < missingPlugins.length && (
				<Notice
					noticeText={ __(
						'The following plugins are required.',
						'newspack-plugin'
					) }
					isWarning
				/>
			) }
			{ 0 === missingPlugins.length && prerequisites && ! allReady && (
				<Notice
					noticeText={ __(
						'Complete these settings to enable Audience Management.',
						'newspack-plugin'
					) }
					isWarning
				/>
			) }
			{ prerequisites && allReady && config.enabled && (
				<Notice
					noticeText={ __(
						'Audience Management is enabled.',
						'newspack-plugin'
					) }
					isSuccess
				/>
			) }
			{ ! prerequisites && (
				<>
					<Waiting isLeft />
					{ __( 'Fetching status…', 'newspack-plugin' ) }
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
						slug={ key }
						config={ config }
						getSharedProps={ getSharedProps }
						inFlight={ inFlight }
						prerequisite={ prerequisites[ key ] }
						fetchConfig={ fetchConfig }
						saveConfig={ saveConfig }
						skipPrerequisite={ skipPrerequisite }
					/>
				) ) }
			{ config.enabled && (
				<Card noBorder>
					<hr />
					<SectionHeader
						title={ __(
							'Newsletter Subscription Lists',
							'newspack-plugin'
						) }
					/>
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
						toggleOnChange={ value =>
							updateConfig( 'use_custom_lists', value )
						}
					/>
					{ config.use_custom_lists && (
						<SortableNewsletterListControl
							lists={
								newspackAudience.available_newsletter_lists
							}
							selected={ config.newsletter_lists }
							onChange={ selected =>
								updateConfig( 'newsletter_lists', selected )
							}
						/>
					) }

					<hr />

					<SectionHeader
						title={ __(
							'Email Service Provider (ESP) Advanced Settings',
							'newspack-plugin'
						) }
						description={ __(
							'Settings for Newspack Newsletters integration.',
							'newspack-plugin'
						) }
					/>
					<TextControl
						label={ __(
							'Newsletter subscription text on registration',
							'newspack-plugin'
						) }
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
						title={ __(
							'Sync contacts to ESP',
							'newspack-plugin'
						) }
						toggleChecked={ config.sync_esp }
						toggleOnChange={ value =>
							updateConfig( 'sync_esp', value )
						}
					>
						{ config.sync_esp && (
							<>
								{ 0 < Object.keys( espSyncErrors ).length && (
									<Notice
										noticeText={ Object.values(
											espSyncErrors
										).join( ' ' ) }
										isError
									/>
								) }
								{ isMailchimp && (
									<Mailchimp
										value={ {
											audienceId:
												config.mailchimp_audience_id,
											readerDefaultStatus:
												config.mailchimp_reader_default_status,
										} }
										onChange={ ( key, value ) => {
											if ( key === 'audienceId' ) {
												updateConfig(
													'mailchimp_audience_id',
													value
												);
											}
											if (
												key === 'readerDefaultStatus'
											) {
												updateConfig(
													'mailchimp_reader_default_status',
													value
												);
											}
										} }
									/>
								) }
								{ isActiveCampaign && (
									<ActiveCampaign
										value={ {
											masterList:
												config.active_campaign_master_list,
										} }
										onChange={ ( key, value ) => {
											if ( key === 'masterList' ) {
												updateConfig(
													'active_campaign_master_list',
													value
												);
											}
										} }
									/>
								) }
								<MetadataFields
									availableFields={
										newspackAudience.esp_metadata_fields ||
										[]
									}
									selectedFields={ config.metadata_fields }
									updateConfig={ updateConfig }
									getSharedProps={ getSharedProps }
								/>
							</>
						) }
					</ActionCard>
					<div className="newspack-buttons-card">
						<Button
							isPrimary
							onClick={ () => {
								if ( config.sync_esp ) {
									if (
										isMailchimp &&
										config.mailchimp_audience_id === ''
									) {
										// eslint-disable-next-line no-alert
										alert(
											__(
												'Please select a Mailchimp Audience ID.',
												'newspack-plugin'
											)
										);
										return;
									}
									if (
										isActiveCampaign &&
										config.active_campaign_master_list ===
											''
									) {
										// eslint-disable-next-line no-alert
										alert(
											__(
												'Please select an ActiveCampaign Master List.',
												'newspack-plugin'
											)
										);
										return;
									}
								}
								saveConfig( {
									newsletters_label: config.newsletters_label, // TODO: Deprecate this in favor of user input via the prompt copy wizard.
									mailchimp_audience_id:
										config.mailchimp_audience_id,
									mailchimp_reader_default_status:
										config.mailchimp_reader_default_status,
									active_campaign_master_list:
										config.active_campaign_master_list,
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
							{ __(
								'Save Settings',
								'newspack-plugin'
							) }
						</Button>
					</div>
				</Card>
			) }
			{ newspackAudience.can_use_salesforce && (
				<Card noBorder>
					<hr />
					<Salesforce />
				</Card>
			) }
		</WizardsTab>
	);
} );
