<?php
/**
 * Read-only aggregation of member document history.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\Member;
use AdamMembership\Member\RenewalRepository;

/** Aggregates existing Media Library and private-document records without copying files. */
final class MemberDocumentHistoryService {
	public function __construct( private SettingsRepository $settings, private RenewalRepository $renewals, private PrivateDocumentRepository $private_documents, private MemberDocumentHistoryRepository $history_repository, private PrivateDocumentStorage $private_storage, private Logger $logger ) {}

	/** @return array<int,array<string,mixed>> */
	public function for_member( Member $member ): array {
		$items = $this->all_items_for_member( $member );
		$archived = array_flip( $this->history_repository->archived_keys( $member->user_id() ) );
		return array_values( array_filter( $items, static fn ( array $item ): bool => ! isset( $archived[ (string) ( $item['history_key'] ?? '' ) ] ) ) );
	}

	/** Archive one verified item without deleting its source record or file. */
	public function archive_for_member( Member $member, string $history_key ): true|\WP_Error {
		foreach ( $this->all_items_for_member( $member ) as $item ) {
			if ( hash_equals( (string) ( $item['history_key'] ?? '' ), $history_key ) ) {
				return $this->history_repository->archive( $member->user_id(), $history_key, (string) $item['source_type'], absint( $item['source_id'] ?? 0 ) );
			}
		}
		return new \WP_Error( 'adam_membership_history_entry_not_found', __( 'A entrada do histórico não foi encontrada.', 'adam-membership' ) );
	}

	/** Permanently delete one item only after its complete provenance audit passes. */
	public function permanently_delete_for_member( Member $member, string $history_key ): true|\WP_Error {
		$this->trace( 'Private document history deletion trace: service entered.', array( 'stage' => 'service.entered', 'member_id' => $member->user_id(), 'history_key_fingerprint' => hash( 'sha256', $history_key ) ) );
		$item = null;
		foreach ( $this->all_items_for_member( $member ) as $candidate ) {
			if ( hash_equals( (string) ( $candidate['history_key'] ?? '' ), $history_key ) ) {
				$item = $candidate;
				break;
			}
		}
		if ( null === $item ) {
			$this->trace( 'Private document history deletion trace: history key not found.', array( 'stage' => 'service.history_key_resolution', 'error_code' => 'adam_membership_history_entry_not_found' ) );
			return new \WP_Error( 'adam_membership_history_entry_not_found', __( 'A entrada do histórico não foi encontrada.', 'adam-membership' ) );
		}
		$this->trace( 'Private document history deletion trace: source resolved.', array( 'stage' => 'service.source_resolved', 'source_type' => (string) $item['source_type'], 'source_id' => absint( $item['source_id'] ?? 0 ), 'private' => ! empty( $item['private'] ) ) );

		if ( ! empty( $item['private'] ) ) {
			$this->trace( 'Private document history deletion trace: private branch entered.', array( 'stage' => 'service.private_branch' ) );
			$document = $this->private_documents->find( absint( $item['source_id'] ?? 0 ) );
			if ( null === $document ) {
				return new \WP_Error( 'adam_membership_document_not_found', __( 'O documento privado não foi encontrado.', 'adam-membership' ) );
			}
			if ( $document->active() || 'active' === $document->document_status() ) {
				return new \WP_Error( 'adam_membership_active_document', __( 'O documento privado está ativo e não pode ser eliminado. Use primeiro a substituição ou a remoção do histórico.', 'adam-membership' ) );
			}
			foreach ( $this->private_documents->for_file_identifier( $document->file_identifier() ) as $other ) {
				if ( $other->id() !== $document->id() ) {
					return new \WP_Error( 'adam_membership_private_file_shared', __( 'Este ficheiro privado é referenciado por outro registo.', 'adam-membership' ) );
				}
			}
			$this->trace( 'Private document history deletion trace: private audit passed; deleting source.', array( 'stage' => 'service.private_delete_start' ) );
			return $this->private_documents->delete_with_storage( $document, $this->private_storage );
		}

		$attachment_id = absint( $item['source_id'] ?? 0 );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'adam_membership_attachment_not_found', __( 'O anexo não foi encontrado.', 'adam-membership' ) );
		}
		$this->trace( 'Private document history deletion trace: Media Library branch entered.', array( 'stage' => 'service.media_branch', 'source_id' => $attachment_id ) );
		$target_request_id = 0;
		foreach ( $this->renewals->admin_requests() as $request ) {
			$matches = $this->request_contains_attachment( $request, $attachment_id );
			if ( ! $matches ) { continue; }
			if ( $request->request_uuid() !== (string) ( $item['request_reference'] ?? '' ) || ! in_array( $request->status(), array( \AdamMembership\Member\RenewalRequest::STATUS_APPROVED, \AdamMembership\Member\RenewalRequest::STATUS_REJECTED ), true ) ) {
				return new \WP_Error( 'adam_membership_attachment_still_referenced', __( 'Este anexo ainda está referenciado por outro pedido ou por um pedido ativo.', 'adam-membership' ) );
			}
			$target_request_id = $request->id();
		}
		if ( $this->attachment_in_any_user_meta( $attachment_id ) || $this->attachment_in_correction_or_apd_data( $attachment_id ) ) {
			return new \WP_Error( 'adam_membership_attachment_still_referenced', __( 'Este anexo ainda está referenciado por dados de sócio, correção ou APD/ANA.', 'adam-membership' ) );
		}
		$this->trace( 'Private document history deletion trace: Media Library audit passed; deleting attachment.', array( 'stage' => 'service.media_delete_start' ) );
		foreach ( $this->all_items_for_member( $member ) as $other ) {
			if ( (string) ( $other['history_key'] ?? '' ) !== $history_key && absint( $other['source_id'] ?? 0 ) === $attachment_id ) {
				return new \WP_Error( 'adam_membership_attachment_shared', __( 'Este anexo é partilhado por outra entrada histórica.', 'adam-membership' ) );
			}
		}
		if ( false === wp_delete_attachment( $attachment_id, true ) ) {
			return new \WP_Error( 'adam_membership_attachment_delete_failed', __( 'Não foi possível eliminar permanentemente o anexo.', 'adam-membership' ) );
		}
		if ( $target_request_id > 0 ) {
			$request = $this->renewals->find( $target_request_id );
			if ( null !== $request ) {
				$data = $request->submitted_data();
				foreach ( $data as $key => $value ) { if ( (string) $value === (string) $attachment_id ) { unset( $data[ $key ] ); } }
				$this->renewals->update( $request, array( 'proof_of_payment' => (string) $request->proof_of_payment() === (string) $attachment_id ? '' : $request->proof_of_payment(), 'submitted_data' => $data ) );
			}
		}
		return true;
	}

	private function trace( string $message, array $context = array() ): void {
		$this->logger->info( $message, $context );
	}

	private function request_contains_attachment( object $request, int $attachment_id ): bool {
		$values = array_merge( array( $request->proof_of_payment() ), array_values( $request->submitted_data() ) );
		foreach ( $values as $value ) { if ( is_numeric( $value ) && absint( $value ) === $attachment_id ) { return true; } }
		return false;
	}

	private function attachment_in_any_user_meta( int $attachment_id ): bool {
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			foreach ( get_user_meta( (int) $user_id ) as $values ) { foreach ( (array) $values as $value ) { if ( is_numeric( $value ) && absint( $value ) === $attachment_id ) { return true; } } }
		}
		return false;
	}

	private function attachment_in_correction_or_apd_data( int $attachment_id ): bool {
		foreach ( array( 'adam_membership_member_change_requests', 'adam_membership_apd_association_requests' ) as $option ) {
			$rows = get_option( $option, array() );
			if ( $this->nested_contains_attachment( $rows, $attachment_id ) ) { return true; }
		}
		return false;
	}

	private function nested_contains_attachment( mixed $value, int $attachment_id ): bool {
		if ( is_array( $value ) ) { foreach ( $value as $child ) { if ( $this->nested_contains_attachment( $child, $attachment_id ) ) { return true; } } return false; }
		return is_numeric( $value ) && absint( $value ) === $attachment_id;
	}

	/** @return array<int,array<string,mixed>> */
	private function all_items_for_member( Member $member ): array {
		$registration_reference = (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true );
		if ( '' === $registration_reference ) {
			$registration_reference = 'registration:legacy-' . $member->user_id();
		}
		$items      = $this->registration_items( $member, $registration_reference );
		$references = array( $registration_reference );
		$groups     = array( $registration_reference => array( 'year' => $this->registration_year( $member ), 'type' => 'registration', 'label' => 'Inscrição' ) );

		foreach ( $this->renewals->for_user( $member->user_id() ) as $request ) {
			$reference = $request->request_uuid();
			$year      = $this->year_from_date( $request->submitted_at() );
			$groups[ $reference ] = array( 'year' => $year, 'type' => 'renewal', 'label' => 'Renovação' );
			$references[] = $reference;
			$items = array_merge( $items, $this->renewal_items( $request, $reference, $year ) );
		}

		foreach ( $this->private_documents->for_references( $references ) as $document ) {
			if ( $document->active_key() !== $document->request_reference() ) { continue; }
			$group = $groups[ $document->request_reference() ] ?? array( 'year' => $this->year_from_date( $document->created_at() ), 'type' => $document->request_type(), 'label' => 'registration' === $document->request_type() ? 'Inscrição' : 'Renovação' );
			$items[] = array(
				'year'            => $group['year'],
				'request_type'    => $group['type'],
				'request_label'   => $group['label'],
				'request_reference'=> $document->request_reference(),
				'document_type'   => 'Documento de faturação/recibo',
				'filename'        => $document->original_name(),
				'date'            => $document->created_at(),
				'origin'          => 'ADAM',
				'status'          => $document->document_status(),
				'sent'            => 'sent' === $document->send_status(),
				'download_url'    => $this->private_download_url( $document->id() ),
				'private'         => true,
				'document_id'     => $document->id(),
				'history_key'     => 'private:' . $document->id(),
				'source_type'     => 'private',
				'source_id'       => $document->id(),
			);
		}

		usort( $items, static function ( array $left, array $right ): int {
			$year = strcmp( (string) $right['year'], (string) $left['year'] );
			return 0 !== $year ? $year : strcmp( (string) $left['request_type'], (string) $right['request_type'] );
		} );

		return $items;
	}

	/** @param array<int,array<string,mixed>> $items @return array<string,array<int,array<string,mixed>>> */
	public static function group_items( array $items ): array {
		$groups = array();
		foreach ( $items as $item ) {
			$key = (string) ( $item['year'] ?? '—' ) . '|' . (string) ( $item['request_type'] ?? 'other' );
			$groups[ $key ]['year'] = (string) ( $item['year'] ?? '—' );
			$groups[ $key ]['request_type'] = (string) ( $item['request_type'] ?? 'other' );
			$groups[ $key ]['request_label'] = (string) ( $item['request_label'] ?? 'Pedido' );
			$groups[ $key ]['items'][] = $item;
		}
		return array_values( $groups );
	}

	/** @return array<int,array<string,mixed>> */
	private function registration_items( Member $member, string $reference ): array {
		$settings = $this->settings->membership_form_settings();
		$fields   = is_array( $settings['registration_fields'] ?? null ) ? $settings['registration_fields'] : array();
		$items    = array();
		foreach ( $fields as $field => $config ) {
			if ( ! is_array( $config ) || 'file' !== (string) ( $config['type'] ?? '' ) ) { continue; }
			$key = ! empty( $config['locked'] ) ? $this->locked_meta_key( (string) $field ) : 'adam_custom_' . sanitize_key( (string) $field );
			$value = get_user_meta( $member->user_id(), $key, true );
			$label = (string) ( $config['label'] ?? $field );
			$item = is_string( $value ) && str_starts_with( $value, 'private:' ) && absint( substr( $value, 8 ) ) > 0
				? $this->private_item( absint( substr( $value, 8 ) ), $reference, $this->registration_year( $member ), $label )
				: $this->media_item( $value, $reference, 'registration', $this->registration_year( $member ), $label );
			if ( null !== $item ) { $items[] = $item; }
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>> */
	private function renewal_items( object $request, string $reference, string $year ): array {
		$settings = $this->settings->membership_form_settings();
		$fields   = is_array( $settings['renewal_fields'] ?? null ) ? $settings['renewal_fields'] : array();
		$submitted = $request->submitted_data();
		$items = array();
		foreach ( $fields as $field => $config ) {
			if ( ! is_array( $config ) || 'file' !== (string) ( $config['type'] ?? '' ) ) { continue; }
			$value = 'payment_receipt' === $field ? $request->proof_of_payment() : ( $submitted[ $this->locked_meta_key( (string) $field ) ] ?? '' );
			$item = $this->media_item( $value, $reference, 'renewal', $year, (string) ( $config['label'] ?? $field ) );
			if ( null !== $item ) { $items[] = $item; }
		}
		return $items;
	}

	/** @return array<string,mixed>|null */
	private function media_item( mixed $value, string $reference, string $type, string $year, string $label ): ?array {
		$id = is_numeric( $value ) ? absint( $value ) : 0;
		$url = $id > 0 ? (string) wp_get_attachment_url( $id ) : ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ? esc_url_raw( $value ) : '' );
		if ( '' === $url ) { return null; }
		$filename = $id > 0 ? sanitize_file_name( (string) get_post_meta( $id, '_wp_attached_file', true ) ) : sanitize_file_name( wp_basename( (string) parse_url( $url, PHP_URL_PATH ) ) );
		$history_key = 'media:' . hash( 'sha256', $reference . '|' . $type . '|' . $label . '|' . (string) $id . '|' . $url );
		return array( 'year' => $year, 'request_type' => $type, 'request_label' => 'registration' === $type ? 'Inscrição' : 'Renovação', 'request_reference' => $reference, 'document_type' => $label, 'filename' => wp_basename( $filename ), 'date' => $id > 0 ? (string) get_post_field( 'post_date', $id ) : '', 'origin' => 'Sócio', 'status' => 'submitted', 'sent' => false, 'download_url' => $url, 'private' => false, 'document_id' => $id, 'history_key' => $history_key, 'source_type' => 'media', 'source_id' => $id );
	}

	private function private_item( int $id, string $reference, string $year, string $label ): ?array {
		$document = $this->private_documents->find( $id );
		if ( null === $document || $document->request_reference() !== $reference || 'active' !== $document->document_status() ) { return null; }
		return array( 'year' => $year, 'request_type' => 'registration', 'request_label' => 'Inscrição', 'request_reference' => $reference, 'document_type' => $label, 'filename' => $document->original_name(), 'date' => $document->created_at(), 'origin' => 'Sócio', 'status' => $document->document_status(), 'sent' => false, 'download_url' => $this->private_download_url( $id ), 'private' => true, 'document_id' => $id, 'history_key' => 'private:' . $id, 'source_type' => 'private', 'source_id' => $id );
	}

	private function registration_year( Member $member ): string { return (string) ( get_user_meta( $member->user_id(), 'adam_membership_year', true ) ?: $this->year_from_date( (string) $member->field( 'data_adesao' ) ) ); }
	private function year_from_date( string $date ): string { return preg_match( '/^(\d{4})/', $date, $match ) ? $match[1] : '—'; }
	private function locked_meta_key( string $field ): string { return 'external_association_proof' === $field ? 'adam_external_association_proof' : $field; }
	private function private_download_url( int $id ): string { return wp_nonce_url( add_query_arg( array( 'action' => 'adam_membership_download_private_document', 'document_id' => $id ), admin_url( 'admin-post.php' ) ), 'adam_membership_download_private_document_' . $id ); }
}
