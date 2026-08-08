<?php
/**
 * Permanent member deletion service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Announcement\AnnouncementRepository;
use AdamMembership\Event\EventRepository;
use AdamMembership\Helpers\Logger;
use AdamMembership\Points\PointsRepository;
use AdamMembership\Reward\RewardRepository;
use WP_Error;

/**
 * Permanently removes a member and the ADAM data linked to that member.
 */
final class MemberDeletionService {
	private const REGISTRATION_FORM_ID = 178;
	private const RENEWAL_FORM_ID      = 280;

	/**
	 * Create the deletion service.
	 *
	 * @param RenewalRepository      $renewals     Renewal repository.
	 * @param HistoryRepository      $history      History repository.
	 * @param PointsRepository       $points       Points repository.
	 * @param RewardRepository       $rewards      Reward repository.
	 * @param EventRepository        $events       Event repository.
	 * @param AnnouncementRepository $announcements Announcement repository.
	 * @param Logger                 $logger       Logger helper.
	 */
	public function __construct(
		private RenewalRepository $renewals,
		private HistoryRepository $history,
		private PointsRepository $points,
		private RewardRepository $rewards,
		private EventRepository $events,
		private AnnouncementRepository $announcements,
		private Logger $logger
	) {}

	/**
	 * Register cleanup for direct WordPress user deletions as well as the ADAM
	 * administrator deletion workflow. NIF locks are transient options keyed by
	 * the NIF, so they must not survive the user/application they protect.
	 */
	public function register(): void {
		add_action( 'delete_user', array( $this, 'clear_user_nif_lock' ), 1 );
	}

	/**
	 * Permanently delete a member.
	 *
	 * @param int $user_id Member user ID.
	 * @return true|WP_Error
	 */
	public function delete( int $user_id ): true|WP_Error {
		$validation = $this->validate_request( $user_id );

		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		$actor_id     = get_current_user_id();
		$reference    = wp_generate_uuid4();
		$renewals     = $this->renewals->for_user( $user_id );
		$history      = $this->history->for_member( $user_id, 0 );
		$media        = $this->member_media_references( $user_id, $renewals );
		$form_entries = $this->forminator_entries( $renewals, $history );
		$form_result  = $this->delete_forminator_entries( $form_entries );

		if ( $form_result instanceof WP_Error ) {
			return $form_result;
		}

		/**
		 * Fires before ADAM removes its member data and the WordPress user.
		 *
		 * Other ADAM plugins should use this hook to remove records keyed by the
		 * member's user ID. The opaque reference may be used in non-personal logs.
		 */
		do_action( 'adam_membership_before_member_permanent_deletion', $user_id, $reference );

		$media_result = $this->delete_media_references( $media );

		if ( $media_result instanceof WP_Error ) {
			return $media_result;
		}

		$this->renewals->delete_for_user( $user_id );
		$this->points->delete_for_member( $user_id );
		$this->rewards->delete_redemptions( array( 'member_id' => $user_id ) );
		$this->events->delete_member_interactions( $user_id );
		$this->announcements->remove_member_references( $user_id );
		$this->history->delete_for_member( $user_id );
		$this->clear_user_nif_lock( $user_id );

		$deleted = $this->delete_wordpress_user( $user_id );

		if ( ! $deleted ) {
			$this->logger->error(
				'WordPress user deletion failed after related member cleanup.',
				array(
					'actor_id'           => $actor_id,
					'deletion_reference' => $reference,
				)
			);

			return new WP_Error(
				'adam_membership_member_delete_failed',
				__( 'The WordPress user could not be deleted. Some related records may already have been removed.', 'adam-membership' )
			);
		}

		$this->create_audit_entry( $actor_id, $reference );
		$this->logger->info(
			'Member permanently deleted.',
			array(
				'actor_id'           => $actor_id,
				'deletion_reference' => $reference,
			)
		);

		/**
		 * Fires after a member has been permanently deleted.
		 *
		 * The deleted user's ID and personal data are deliberately omitted.
		 */
		do_action( 'adam_membership_member_permanently_deleted', $reference, $actor_id );

		return true;
	}

	/**
	 * Remove the registration lock associated with a user NIF.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function clear_user_nif_lock( int $user_id ): void {
		$nif = NifValidator::normalize( get_user_meta( $user_id, 'nif', true ) );
		if ( '' === $nif ) {
			return;
		}

		delete_option( 'adam_membership_nif_lock_' . hash( 'sha256', $nif ) );
	}

	/**
	 * Validate every safety condition before changing data.
	 *
	 * @param int $user_id Member user ID.
	 * @return true|WP_Error
	 */
	private function validate_request( int $user_id ): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_user', $user_id ) ) {
			return new WP_Error(
				'adam_membership_member_delete_forbidden',
				__( 'You do not have permission to permanently delete ADAM members.', 'adam-membership' )
			);
		}

		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! metadata_exists( 'user', $user_id, 'estado' ) ) {
			return new WP_Error(
				'adam_membership_member_delete_invalid',
				__( 'Invalid ADAM member.', 'adam-membership' )
			);
		}

		if ( get_current_user_id() === $user_id ) {
			return new WP_Error(
				'adam_membership_member_delete_self',
				__( 'Safety rule: administrators cannot permanently delete their own account.', 'adam-membership' )
			);
		}

		if ( is_multisite() && ! is_super_admin( get_current_user_id() ) ) {
			return new WP_Error(
				'adam_membership_member_delete_multisite_forbidden',
				__( 'Only a network administrator can permanently delete a member on this multisite installation.', 'adam-membership' )
			);
		}

		return true;
	}

	/**
	 * Find all uploaded media references owned by the member workflows.
	 *
	 * @param int                        $user_id  Member user ID.
	 * @param array<int, RenewalRequest> $renewals Renewal requests.
	 * @return array<int, mixed>
	 */
	private function member_media_references( int $user_id, array $renewals ): array {
		$references = array();
		$meta       = get_user_meta( $user_id );

		foreach ( $meta as $key => $values ) {
			if ( ! in_array( $key, array( 'profile_photo', 'payment_receipt', 'adam_external_association_proof' ), true ) && ! str_starts_with( (string) $key, 'adam_custom_' ) ) {
				continue;
			}

			$references[] = $values;
		}

		foreach ( $renewals as $renewal ) {
			$references[] = $renewal->proof_of_payment();

			foreach ( $renewal->submitted_data() as $key => $value ) {
				if ( 'adam_external_association_proof' === $key || str_starts_with( (string) $key, 'adam_custom_' ) ) {
					$references[] = $value;
				}
			}
		}

		return $this->flatten_references( $references );
	}

	/**
	 * Find Forminator entries used by the member's registration and renewals.
	 *
	 * @param array<int, RenewalRequest> $renewals Renewal requests.
	 * @param array<int, HistoryEntry>   $history  Member history.
	 * @return array<int, array{form_id:int,entry_id:int}>
	 */
	private function forminator_entries( array $renewals, array $history ): array {
		$entries = array();

		foreach ( $renewals as $renewal ) {
			if ( $renewal->submission_id() > 0 ) {
				$entries[ self::RENEWAL_FORM_ID . ':' . $renewal->submission_id() ] = array(
					'form_id'  => self::RENEWAL_FORM_ID,
					'entry_id' => $renewal->submission_id(),
				);
			}
		}

		foreach ( $history as $entry ) {
			$details  = $entry->details();
			$form_id  = 0;
			$entry_id = 0;

			if ( 'registration_submitted' === $entry->action_key() ) {
				$form_id  = self::REGISTRATION_FORM_ID;
				$entry_id = absint( $details['entry_id'] ?? 0 );
			} elseif ( 'renewal_submitted' === $entry->action_key() ) {
				$form_id  = self::RENEWAL_FORM_ID;
				$entry_id = absint( $details['submission_id'] ?? 0 );
			}

			if ( $form_id > 0 && $entry_id > 0 ) {
				$entries[ $form_id . ':' . $entry_id ] = array(
					'form_id'  => $form_id,
					'entry_id' => $entry_id,
				);
			}
		}

		return array_values( $entries );
	}

	/**
	 * Delete Forminator entries when Forminator is available.
	 *
	 * @param array<int, array{form_id:int,entry_id:int}> $entries Entries.
	 * @return true|WP_Error
	 */
	private function delete_forminator_entries( array $entries ): true|WP_Error {
		if ( array() === $entries || ! class_exists( '\Forminator_API' ) ) {
			return true;
		}

		foreach ( $entries as $entry ) {
			$result = \Forminator_API::delete_form_entry( $entry['form_id'], $entry['entry_id'] );

			if ( $result instanceof WP_Error ) {
				return new WP_Error(
					'adam_membership_forminator_entry_delete_failed',
					__( 'A form submission linked to this member could not be deleted. No ADAM member records were removed.', 'adam-membership' )
				);
			}
		}

		return true;
	}

	/**
	 * Recursively flatten stored upload values.
	 *
	 * @param mixed $value Stored value.
	 * @return array<int, mixed>
	 */
	private function flatten_references( mixed $value ): array {
		$flattened = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$flattened = array_merge( $flattened, $this->flatten_references( maybe_unserialize( $item ) ) );
			}

			return $flattened;
		}

		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$flattened[] = $value;
		}

		return $flattened;
	}

	/**
	 * Permanently delete attachments and upload files referenced by the member.
	 *
	 * @param array<int, mixed> $references Media references.
	 * @return true|WP_Error
	 */
	private function delete_media_references( array $references ): true|WP_Error {
		$deleted_attachments = array();
		$deleted_paths       = array();
		$uploads             = wp_get_upload_dir();
		$uploads_base_url    = trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) );
		$uploads_base_dir    = wp_normalize_path( trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) );

		foreach ( $references as $reference ) {
			$value         = trim( (string) $reference );
			$attachment_id = ctype_digit( $value ) ? absint( $value ) : attachment_url_to_postid( $value );

			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				if ( ! isset( $deleted_attachments[ $attachment_id ] ) ) {
					if ( false === wp_delete_attachment( $attachment_id, true ) ) {
						return new WP_Error(
							'adam_membership_member_attachment_delete_failed',
							__( 'An uploaded document linked to this member could not be deleted. The member account was not deleted.', 'adam-membership' )
						);
					}

					$deleted_attachments[ $attachment_id ] = true;
				}

				continue;
			}

			if ( '' === $uploads_base_url || ! str_starts_with( $value, $uploads_base_url ) ) {
				continue;
			}

			$relative = ltrim( rawurldecode( substr( $value, strlen( $uploads_base_url ) ) ), '/\\' );
			$path     = wp_normalize_path( $uploads_base_dir . $relative );

			if ( '' === $uploads_base_dir || ! str_starts_with( $path, $uploads_base_dir ) || isset( $deleted_paths[ $path ] ) || ! is_file( $path ) ) {
				continue;
			}

			wp_delete_file( $path );

			if ( is_file( $path ) ) {
				return new WP_Error(
					'adam_membership_member_file_delete_failed',
					__( 'An uploaded file linked to this member could not be deleted. The member account was not deleted.', 'adam-membership' )
				);
			}

			$deleted_paths[ $path ] = true;
		}

		return true;
	}

	/**
	 * Delete the underlying WordPress account.
	 *
	 * @param int $user_id Member user ID.
	 */
	private function delete_wordpress_user( int $user_id ): bool {
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';

			return (bool) wpmu_delete_user( $user_id );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		return (bool) wp_delete_user( $user_id );
	}

	/**
	 * Keep an accountability record without retaining target personal data.
	 *
	 * @param int    $actor_id  Administrator user ID.
	 * @param string $reference Opaque deletion reference.
	 */
	private function create_audit_entry( int $actor_id, string $reference ): void {
		$actor = get_userdata( $actor_id );

		$this->history->create(
			array(
				'member_id'     => 0,
				'member_number' => '',
				'member_name'   => '',
				'member_email'  => '',
				'action_key'    => 'member_permanently_deleted',
				'action_label'  => __( 'Member permanently deleted', 'adam-membership' ),
				'actor_type'    => 'admin',
				'actor_id'      => $actor_id,
				'actor_name'    => $actor ? $actor->display_name : '',
				'description'   => __( 'An administrator permanently deleted a member record.', 'adam-membership' ),
				'details'       => array(
					'deletion_reference' => $reference,
				),
				'created_at'    => wp_date( 'Y-m-d H:i:s' ),
			)
		);
	}
}
