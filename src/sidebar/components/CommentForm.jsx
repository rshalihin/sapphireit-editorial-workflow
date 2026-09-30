/**
 * Workflow comment form. Rendered only when the user may comment.
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
 * @return {Element} Form.
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
		event.preventDefault();

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

		setMessage( '' );
	};

	return (
		<form className="sit-cwm-comment-form" onSubmit={ handleSubmit }>
			<TextareaControl
				label={ __( 'Add a workflow comment', 'sapphireit-editorial-workflow' ) }
				value={ message }
				onChange={ setMessage }
				rows={ 3 }
				maxLength={ MAX_LENGTH }
				disabled={ isDisabled }
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
				type="submit"
				variant="secondary"
				disabled={ isEmpty || isSaving || isDisabled }
				isBusy={ isSaving && ! isEmpty }
			>
				{ __( 'Add comment', 'sapphireit-editorial-workflow' ) }
			</Button>
		</form>
	);
}
