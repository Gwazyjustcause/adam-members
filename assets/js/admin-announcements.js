( function ( $, config ) {
	'use strict';

	$( function () {
		const form = document.querySelector( '[data-adam-announcement-form]' );
		if ( ! form ) {
			return;
		}

		const preview = document.getElementById( 'adam-announcement-preview' );
		const status = preview.querySelector( '[data-adam-announcement-preview-status]' );
		const retry = preview.querySelector( '[data-adam-announcement-preview-retry]' );
		const subject = preview.querySelector( '[data-adam-announcement-preview-subject]' );
		const channelButtons = preview.querySelectorAll( '[data-adam-announcement-preview-channel]' );
		const panels = preview.querySelectorAll( '[data-adam-announcement-preview-panel]' );
		const memberSettings = form.querySelector( '[data-adam-announcement-member-settings]' );
		const memberPlacement = memberSettings.querySelector( '[data-adam-announcement-member-placement]' );
		const emailSettings = form.querySelector( '[data-adam-announcement-email-settings]' );
		const team = emailSettings.querySelector( '[data-adam-announcement-email-team]' );
		const members = emailSettings.querySelector( '[data-adam-announcement-email-members]' );
		const fields = [ 'title', 'summary', 'content', 'category', 'priority', 'publish_date', 'expiry_date', 'action_label', 'action_url', 'pinned' ];
		const editorId = 'adam_announcement_content';
		let channel = 'member';
		let timer;
		let request;
		let rendered = false;
		let connectedEditor;

		function updateDelivery() {
			const showInMemberArea = form.elements.show_in_member_area.checked;
			const sendEmail = form.elements.send_email.checked;
			const audience = form.elements.email_audience.value;

			memberSettings.hidden = ! showInMemberArea && ! ( sendEmail && audience === 'legacy_visibility_audience' );
			memberPlacement.hidden = ! showInMemberArea;
			emailSettings.hidden = ! sendEmail;
			team.hidden = audience !== 'specific_team';
			members.hidden = audience !== 'specific_members';
			form.elements.email_team_id.required = sendEmail && ! team.hidden;
			form.elements['email_member_ids[]'].required = sendEmail && ! members.hidden;

			panels[0].querySelector( '[data-adam-announcement-channel-disabled]' ).hidden = showInMemberArea;
			panels[1].querySelector( '[data-adam-announcement-channel-disabled]' ).hidden = sendEmail;
		}

		function updatePanels() {
			panels.forEach( function ( panel ) {
				panel.hidden = ! rendered || panel.dataset.adamAnnouncementPreviewPanel !== channel;
			} );
		}

		async function refresh() {
			const payload = new FormData();
			payload.set( 'action', config.action );
			payload.set( '_ajax_nonce', config.nonce );
			const editor = window.tinymce?.get( editorId );
			if ( editor?.initialized && ! editor.isHidden() ) {
				editor.save();
			}

			fields.forEach( function ( name ) {
				const field = form.elements[name];
				if ( field.type !== 'checkbox' || field.checked ) {
					payload.set( name, field.value );
				}
			} );

			const controller = new AbortController();
			request = controller;

			try {
				const response = await fetch( config.url, { method: 'POST', body: payload, signal: controller.signal } );
				if ( ! response.ok ) {
					throw new Error();
				}

				const result = await response.json();
				if ( request !== controller ) {
					return;
				}
				if ( ! result.success ) {
					throw new Error();
				}

				panels.forEach( function ( panel ) {
					panel.querySelector( 'iframe' ).srcdoc = result.data[panel.dataset.adamAnnouncementPreviewPanel];
				} );
				subject.textContent = result.data.subject;
				rendered = true;
				updatePanels();
				status.textContent = '';
			} catch ( error ) {
				if ( request === controller ) {
					status.textContent = config.error;
					retry.hidden = false;
				}
			}
		}

		function schedule( delay = 350 ) {
			clearTimeout( timer );
			request?.abort();
			request = null;
			retry.hidden = true;

			const hasTitle = form.elements.title.value.trim() !== '';
			if ( ! hasTitle ) {
				rendered = false;
				updatePanels();
			}
			status.textContent = hasTitle ? config.loading : config.empty;
			if ( hasTitle ) {
				timer = setTimeout( refresh, delay );
			}
		}

		form.addEventListener( 'input', function ( event ) {
			if ( fields.includes( event.target.name ) ) {
				schedule();
			}
		} );

		form.addEventListener( 'change', function ( event ) {
			updateDelivery();
			if ( fields.includes( event.target.name ) ) {
				schedule();
			}
		} );

		function connectEditor( editor ) {
			if ( editor?.id === editorId && editor !== connectedEditor ) {
				connectedEditor = editor;
				editor.on( 'input change undo redo show hide', function () {
					schedule();
				} );
			}
		}

		$( document ).on( 'tinymce-editor-init', function ( event, editor ) {
			connectEditor( editor );
		} );
		connectEditor( window.tinymce?.get( editorId ) );
		form.querySelector( '.quicktags-toolbar' )?.addEventListener( 'click', function () {
			schedule();
		} );

		channelButtons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				channel = button.dataset.adamAnnouncementPreviewChannel;
				channelButtons.forEach( function ( candidate ) {
					const selected = candidate === button;
					candidate.setAttribute( 'aria-pressed', String( selected ) );
					candidate.classList.toggle( 'button-primary', selected );
				} );
				updatePanels();
			} );
		} );

		retry.addEventListener( 'click', function () {
			schedule( 0 );
		} );

		updateDelivery();
		schedule( 0 );
	} );
}( jQuery, window.adamAnnouncementPreview ) );
