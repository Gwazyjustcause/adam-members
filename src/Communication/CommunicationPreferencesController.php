<?php
/**
 * Communication preferences AJAX controller.
 *
 * @package AdamMembership\Communication
 */

declare(strict_types=1);

namespace AdamMembership\Communication;

use AdamMembership\Member\MemberRepository;

/**
 * Saves member communication preferences without leaving the dashboard.
 */
final class CommunicationPreferencesController {
	private const AJAX_ACTION  = 'adam_membership_save_communication_preferences';
	private const NONCE_ACTION = 'adam_membership_communication_preferences';

	/**
	 * Preferences service.
	 *
	 * @var CommunicationPreferences
	 */
	private CommunicationPreferences $preferences;

	/**
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Constructor.
	 *
	 * @param CommunicationPreferences $preferences Preferences service.
	 * @param MemberRepository         $members     Member repository.
	 */
	public function __construct( CommunicationPreferences $preferences, MemberRepository $members ) {
		$this->preferences = $preferences;
		$this->members     = $members;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'save' ) );
	}

	/**
	 * Get public JavaScript configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_config(): array {
		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'action'   => self::AJAX_ACTION,
			'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			'messages' => array(
				'saving' => __( 'A guardar…', 'adam-membership' ),
				'saved'  => __( 'Preferências guardadas.', 'adam-membership' ),
				'error'  => __( 'Não foi possível guardar as preferências. Tente novamente.', 'adam-membership' ),
			),
		);
	}

	/**
	 * Save the current member's email subscriptions.
	 */
	public function save(): void {
		$user_id = get_current_user_id();
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( 0 === $user_id || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Pedido inválido. Atualize a página e tente novamente.', 'adam-membership' ) ), 403 );
		}

		if ( null === $this->members->find( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Não foi encontrada uma inscrição associada a esta conta.', 'adam-membership' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every scalar value is sanitized immediately below.
		$enabled = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? wp_unslash( $_POST['categories'] ) : array();
		$enabled = array_values(
			array_filter(
				array_map(
					static fn ( mixed $category_id ): string => is_scalar( $category_id ) ? sanitize_title( (string) $category_id ) : '',
					$enabled
				)
			)
		);

		$this->preferences->save_subscriptions( $user_id, CommunicationPreferences::CHANNEL_EMAIL, $enabled );

		wp_send_json_success( array( 'message' => __( 'Preferências de comunicação guardadas.', 'adam-membership' ) ) );
	}
}
