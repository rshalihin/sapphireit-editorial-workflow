/**
 * Workflow comment form. Rendered only when the user may comment.
 *
 * The wrapper is a `<div>`, not a `<form>`: in the classic editor the meta box
 * sits inside `<form id="post">`, where a nested form would be dropped by the
 * browser and "Add comment" would submit (and save) the whole post.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Longest accepted comment (mirrors `ActivityController::MESSAGE_MAX_LENGTH`).
 *
 * @type {number}
 */
const MAX_LENGTH = 5000;

/**
 * @param {Object}   props          Props.
 * @param {Function} props.onSubmit Receives the message; resolves to `null` on
 *                                  success or a normalized error.
 * @param {boolean}  props.isSaving     Whether a workflow change is in flight.
 * @param {boolean}  [props.isDisabled] Disables the form (e.g. post gone).
 * @return {Element} Comment group.
 */
export default function CommentForm( {
	onSubmit,
	isSaving,
	isDisabled = false,
} ) {
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( null );

	const isEmpty = message.trim() === '';

	const handleSubmit = async ( event ) => {
		event?.preventDefault();

		if ( isEmpty || isSaving || isDisabled ) {
			return;
		}

		setError( null );

		const err = await onSubmit( message );

		if ( err ) {
			if ( err.message ) {
				setError( err );
			}

			return;
		}

		// Keep anything typed while the request was in flight.
		setMessage( ( current ) => ( current === message ? '' : current ) );
	};

	// Ctrl/Cmd+Enter submits; plain Enter still adds a newline.
	const handleKeyDown = ( event ) => {
		if ( event.key === 'Enter' && ( event.ctrlKey || event.metaKey ) ) {
			handleSubmit( event );
		}
	};

	return (
		<div
			className="sit-cwm-comment-form"
			role="group"
			aria-label={ __( 'Workflow comment', 'sapphireit-editorial-workflow' ) }
		>
			<TextareaControl
				label={ __( 'Add a workflow comment', 'sapphireit-editorial-workflow' ) }
				value={ message }
				onChange={ setMessage }
				rows={ 3 }
				maxLength={ MAX_LENGTH }
				disabled={ isDisabled }
				onKeyDown={ handleKeyDown }
			/>
			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error.message }
				</Notice>
			) }
			<Button
				type="button"
				variant="secondary"
				onClick={ handleSubmit }
				className="sit-cwm-comment-form__submit"
				disabled={ isEmpty || isSaving || isDisabled }
				isBusy={ isSaving && ! isEmpty }
			>
				{ __( 'Add comment', 'sapphireit-editorial-workflow' ) }
			</Button>
		</div>
	);
}
