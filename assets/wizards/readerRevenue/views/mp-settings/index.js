/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Grid, TextControl, Wizard } from '../../../../components/src';
import { READER_REVENUE_WIZARD_SLUG } from '../../constants';

const MPSettings = () => {
	const wizardData = Wizard.useWizardData( 'reader-revenue' );
	const { updateWizardSettings, saveWizardSettings } = useDispatch( Wizard.STORE_NAMESPACE );

	const changeHandler = ( key, value ) => {
		return updateWizardSettings( {
			slug: READER_REVENUE_WIZARD_SLUG,
			path: [ 'platform_data', key ],
			value,
		} );
	};
	const onSave = () =>
		saveWizardSettings( {
			slug: READER_REVENUE_WIZARD_SLUG,
			payloadPath: [ 'platform_data' ],
		} );

	const settings = wizardData?.platform_data || {};

	return (
		<ActionCard
			hasGreyHeader
			isMedium
			title={ __( 'Memberpress Settings', 'newspack' ) }
			description={ __( 'Configure your site’s connection to Memberpress.', 'newspack' ) }
			actionContent={
				<Button isPrimary onClick={ onSave }>
					{ __( 'Save Settings' ) }
				</Button>
			}
		>
			<div>
				<Grid columns={ 3 }>
					<TextControl
						label={ __( 'Memberpress Membership ID (optional)', 'newspack' ) }
						placeholder="membership_id"
						value={ settings?.mp_membership_id || '' }
						onChange={ value => changeHandler( 'mp_membership_id', value ) }
					/>
				</Grid>
			</div>
		</ActionCard>
	);
};

export default MPSettings;
