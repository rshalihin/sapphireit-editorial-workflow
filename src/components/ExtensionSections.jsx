/**
 * Sections add-ons render inside the workflow panel.
 */

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';

/**
 * Keeps a section that throws from taking the panel down with it.
 */
class SectionBoundary extends Component {
	constructor( props ) {
		super( props );

		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	componentDidCatch( error ) {
		// eslint-disable-next-line no-console
		console.error(
			`Editorial Workflow: the "${ this.props.name }" section failed.`,
			error
		);
	}

	render() {
		return this.state.hasError ? null : this.props.children;
	}
}

/**
 * Whether a filter result entry can be rendered.
 *
 * @param {*} entry Entry from the filter.
 * @return {boolean} True for `{ name: string, order: number, render: Function }`.
 */
function isValidSection( entry ) {
	return (
		!! entry &&
		typeof entry.name === 'string' &&
		typeof entry.order === 'number' &&
		typeof entry.render === 'function'
	);
}

/**
 * Sorts sections by `order`, then `name`.
 *
 * @param {Object} a Section.
 * @param {Object} b Section.
 * @return {number} Sort order.
 */
function compareSections( a, b ) {
	return a.order - b.order || a.name.localeCompare( b.name );
}

/**
 * The filter's result. A filter callback that throws costs the sections, not
 * the panel.
 *
 * @param {Object} context Section context.
 * @return {*} Filter result, or an empty list.
 */
function filteredSections( context ) {
	try {
		return applyFilters( 'sitCwm.workflowPanel.sections', [], context );
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( error );

		return [];
	}
}

/**
 * Renders the `sitCwm.workflowPanel.sections` filter's result. Renders nothing
 * when no add-on registers a section.
 *
 * @param {Object}  props             Props.
 * @param {number}  props.postId      Post id.
 * @param {boolean} [props.isClassic] Rendered in the classic editor meta box.
 * @param {Object}  props.workflow    Workflow payload from the server.
 * @return {?Element} Sections.
 */
export default function ExtensionSections( {
	postId,
	isClassic = false,
	workflow,
} ) {
	const context = { postId, isClassic, workflow };
	const sections = filteredSections( context );

	if ( ! Array.isArray( sections ) ) {
		return null;
	}

	return sections
		.filter( isValidSection )
		.sort( compareSections )
		.map( ( { name, render: Section } ) => (
			<SectionBoundary key={ name } name={ name }>
				<Section { ...context } />
			</SectionBoundary>
		) );
}
