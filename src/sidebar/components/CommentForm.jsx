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
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Longest accepted comment (mirrors `ActivityController::MESSAGE_MAX_LENGTH`).
 *
 * @type {number}
 */
const MAX_LENGTH = 5000;

/**
 * "Add comment" clicked while another workflow change is saving is queued and
 * sends the text once that save finishes, instead of the click being swallowed
 * by a disabled button.
 *
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
	// This form's own request is in flight.
	const [ isSending, setIsSending ] = useState( false );
	const [ isQueued, setIsQueued ] = useState( false );

	const isEmpty = message.trim() === '';

	const send = async ( text ) => {
		setError( null );
		setIsSending( true );

		const err = await onSubmit( text );

		setIsSending( false );

		if ( err ) {
			if ( err.message ) {
				setError( err );
			}

			return;
		}

		// Keep anything typed while the request was in flight.
		setMessage( ( current ) => ( current === text ? '' : current ) );
	};

	// Sends the queued comment, as it reads now, once the other save is done.
	useEffect( () => {
		if ( ! isQueued || isSaving ) {
			return;
		}

		setIsQueued( false );

		if ( ! isDisabled && message.trim() !== '' ) {
			send( message );
		}
		// `send` is rebuilt every render; the queue only advances on these.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isQueued, isSaving, isDisabled ] );

	const handleSubmit = ( event ) => {
		event?.preventDefault();

		if ( isEmpty || isDisabled || isSending || isQueued ) {
			return;
		}

		if ( isSaving ) {
			setIsQueued( true );
		} else {
			send( message );
		}
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
				// Only this form's own request blocks it; any other save in
				// flight just queues the click.
				disabled={ isEmpty || isDisabled || isSending || isQueued }
				isBusy={ isSending || isQueued }
			>
				{ __( 'Add comment', 'sapphireit-editorial-workflow' ) }
			</Button>
		</div>
	);
}
