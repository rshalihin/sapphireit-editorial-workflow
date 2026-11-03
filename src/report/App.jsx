/**
 * Governance report screen: period selector, KPI tiles and AI-use breakdowns
 * over `GET /sit-cwm/v1/reports/governance` (F12).
 *
 * Every figure is the route's own; this only formats them.
 */

/**
 * WordPress dependencies
 */
import {
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { createPortal, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getGovernanceReport, isAbortError } from '../api/client';
import Breakdown from './Breakdown';
import HeadlineTile from './HeadlineTile';
import KpiTile from './KpiTile';

/**
 * Periods the route accepts, in days.
 *
 * @type {number[]}
 */
export const PERIODS = [ 30, 90, 365 ];

/**
 * Period used when the URL names none (or an unknown one).
 *
 * @type {number}
 */
export const DEFAULT_PERIOD = 90;

/**
 * Query argument holding the period.
 *
 * @type {string}
 */
const PERIOD_ARG = 'period';

/**
 * Id of the title-row slot (printed by GovernanceReportPage) the Period
 * control is portalled into. Without it the control stays in the app.
 *
 * @type {string}
 */
export const PERIOD_SLOT_ID = 'sit-cwm-report-period';

/**
 * The period named in the current URL, or the default.
 *
 * @return {number} Period in days.
 */
function periodFromUrl() {
	const value = parseInt(
		new URLSearchParams( window.location.search ).get( PERIOD_ARG ),
		10
	);

	return PERIODS.includes( value ) ? value : DEFAULT_PERIOD;
}

/**
 * Writes the period into the URL without a navigation, so a reload keeps it.
 *
 * @param {number} period Period in days.
 */
function rememberPeriod( period ) {
	const url = new URL( window.location.href );

	url.searchParams.set( PERIOD_ARG, String( period ) );
	window.history.replaceState( window.history.state, '', url.toString() );
}

/**
 * Sum of numeric values, treating anything else as 0.
 *
 * @param {...*} values Values.
 * @return {number} Sum.
 */
function sum( ...values ) {
	return values.reduce(
		( total, value ) => total + ( Number( value ) || 0 ),
		0
	);
}

/**
 * Options of the period selector (F16 copy).
 *
 * @return {Array<Object>} `SelectControl` options.
 */
function periodOptions() {
	return [
		{
			value: '30',
			label: __( 'Last 30 days', 'sapphireit-editorial-workflow' ),
		},
		{
			value: '90',
			label: __( 'Last 90 days', 'sapphireit-editorial-workflow' ),
		},
		{
			value: '365',
			label: __( 'Last 12 months', 'sapphireit-editorial-workflow' ),
		},
	];
}

/**
 * The governance report.
 *
 * @return {Element} Screen.
 */
export default function App() {
	const [ period, setPeriod ] = useState( periodFromUrl );
	const [ report, setReport ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		const controller = new window.AbortController();

		setIsLoading( true );
		setError( null );

		getGovernanceReport( period, { signal: controller.signal } )
			.then( ( result ) => setReport( result ) )
			.catch( ( err ) => {
				if ( ! isAbortError( err ) ) {
					// Figures of another period would be misleading.
					setReport( null );
					setError( err );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
				}
			} );

		return () => controller.abort();
	}, [ period, attempt ] );

	const changePeriod = ( value ) => {
		const next = parseInt( value, 10 );

		if ( PERIODS.includes( next ) ) {
			rememberPeriod( next );
			setPeriod( next );
		}
	};

	const slot = document.getElementById( PERIOD_SLOT_ID );
	const toolbar = (
		<div className="sit-cwm-report__toolbar">
			{ isLoading && <Spinner /> }
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className="sit-cwm-report__period"
				label={ __( 'Period', 'sapphireit-editorial-workflow' ) }
				value={ String( period ) }
				options={ periodOptions() }
				onChange={ changePeriod }
			/>
		</div>
	);

	const numberFormat = new Intl.NumberFormat();
	const formatNumber = ( value ) => numberFormat.format( value );

	return (
		<div className="sit-cwm-report">
			{ slot ? createPortal( toolbar, slot ) : toolbar }
			{ error && (
				<Notice
					className="sit-cwm-report__error"
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __(
								'Retry',
								'sapphireit-editorial-workflow'
							),
							onClick: () => setAttempt( ( n ) => n + 1 ),
						},
					] }
				>
					{ error.message }
				</Notice>
			) }
			{ report && (
				<Figures report={ report } formatNumber={ formatNumber } />
			) }
		</div>
	);
}

/**
 * Tiles and breakdowns of a loaded report.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.report       Route response.
 * @param {Function} props.formatNumber Formats a count.
 * @return {Element} Figures.
 */
function Figures( { report, formatNumber } ) {
	const publication = report.publication || {};
	const byGateMode = publication.by_gate_mode || {};
	const gate = report.gate || {};
	const bypassed = gate.bypassed || {};
	const aiOrigin = report.ai_origin || {};
	const total = Number( publication.total ) || 0;
	const approved = Number( publication.approved ) || 0;
	const share = publication.approved_share;

	const percent = new Intl.NumberFormat( undefined, {
		style: 'percent',
		maximumFractionDigits: 1,
	} );

	const approvedHelp =
		total > 0
			? sprintf(
					/* translators: 1: publications made after approval, 2: all publications in the period. */
					_n(
						'%1$s of %2$s publication',
						'%1$s of %2$s publications',
						total,
						'sapphireit-editorial-workflow'
					),
					formatNumber( approved ),
					formatNumber( total )
				)
			: undefined;

	return (
		<>
			<div className="sit-cwm-report__tiles">
				<HeadlineTile
					label={ __(
						'Published after approval',
						'sapphireit-editorial-workflow'
					) }
					value={
						typeof share === 'number'
							? percent.format( share )
							: '—'
					}
					help={ approvedHelp }
					approved={ approved }
					total={ total }
					formatNumber={ formatNumber }
				/>
				<KpiTile
					label={ __(
						'Published without approval',
						'sapphireit-editorial-workflow'
					) }
					value={ formatNumber(
						sum(
							bypassed.log_only,
							bypassed.capability,
							bypassed.error
						)
					) }
				/>
				<KpiTile
					label={ __(
						'Published while the gate was off or logging only',
						'sapphireit-editorial-workflow'
					) }
					value={ formatNumber(
						sum( byGateMode.off, byGateMode.log_only )
					) }
				/>
				<KpiTile
					label={ __(
						'Publishing blocked',
						'sapphireit-editorial-workflow'
					) }
					value={ formatNumber( sum( gate.refused ) ) }
				/>
				<KpiTile
					label={ __(
						'Overdue reviews',
						'sapphireit-editorial-workflow'
					) }
					value={ formatNumber( sum( report.overdue?.count ) ) }
					tone="danger"
				/>
			</div>
			<div className="sit-cwm-report__breakdowns">
				<Card>
					<CardBody>
						<Breakdown
							title={ __(
								'AI use: all workflow content',
								'sapphireit-editorial-workflow'
							) }
							counts={ aiOrigin.current }
							totalLabel={ ( count ) =>
								sprintf(
									/* translators: %s: number of workflow items. */
									_n(
										'%s item',
										'%s items',
										count,
										'sapphireit-editorial-workflow'
									),
									formatNumber( count )
								)
							}
							formatNumber={ formatNumber }
						/>
					</CardBody>
				</Card>
				<Card>
					<CardBody>
						<Breakdown
							title={ __(
								'AI use: published in this period',
								'sapphireit-editorial-workflow'
							) }
							counts={ aiOrigin.published }
							totalLabel={ ( count ) =>
								sprintf(
									/* translators: %s: number of publications. */
									_n(
										'%s publication',
										'%s publications',
										count,
										'sapphireit-editorial-workflow'
									),
									formatNumber( count )
								)
							}
							formatNumber={ formatNumber }
							withNotRecorded
							empty={
								total === 0
									? __(
											'Nothing was published in this period.',
											'sapphireit-editorial-workflow'
										)
									: undefined
							}
							footnote={
								total > 0
									? __(
											'“Not recorded” counts publications logged before version 1.2, which did not record AI use.',
											'sapphireit-editorial-workflow'
										)
									: undefined
							}
						/>
					</CardBody>
				</Card>
			</div>
		</>
	);
}
