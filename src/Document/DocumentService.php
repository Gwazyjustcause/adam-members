<?php
/**
 * Document service.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use WP_Error;

/**
 * Coordinates document storage, visibility, and protected downloads.
 */
final class DocumentService {
	/**
	 * Repository.
	 *
	 * @var DocumentRepository
	 */
	private DocumentRepository $repository;

	/**
	 * Members.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * History repository.
	 *
	 * @var HistoryRepository
	 */
	private HistoryRepository $history;

	/**
	 * Constructor.
	 */
	public function __construct( DocumentRepository $repository, MemberRepository $members, Logger $logger, HistoryRepository $history ) {
		$this->repository = $repository;
		$this->members    = $members;
		$this->logger     = $logger;
		$this->history    = $history;
	}

	/**
	 * Register download hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_adam_membership_download_document', array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_adam_membership_download_document', array( $this, 'handle_download' ) );
	}

	/**
	 * Save a document.
	 *
	 * @param array<string, mixed> $data Document data.
	 * @param array<string, mixed> $file Uploaded file array.
	 * @param int                  $id   Optional existing ID.
	 * @return Document|WP_Error
	 */
	public function save( array $data, array $file = array(), int $id = 0 ): Document|WP_Error {
		$prepared = $this->sanitize_data( $data );

		if ( '' === $prepared['title'] ) {
			return new WP_Error( 'adam_membership_document_title_required', __( 'Document title is required.', 'adam-membership' ) );
		}

		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		if ( 0 === $id ) {
			$upload = $this->handle_upload( $file );

			if ( is_wp_error( $upload ) ) {
				return $upload;
			}

			$prepared = array_merge( $prepared, $upload );
			$prepared['upload_date'] = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
			$prepared['created_at']  = $now;
			$prepared['updated_at']  = $now;
			$document                = $this->repository->create( $prepared );
			$this->logger->info( 'Document created.', array( 'document_id' => $document->id() ) );
			$this->record_history( $document, 'document_created', __( 'Document created', 'adam-membership' ) );

			return $document;
		}

		$current = $this->repository->find( $id );

		if ( null === $current ) {
			return new WP_Error( 'adam_membership_document_not_found', __( 'Document not found.', 'adam-membership' ) );
		}

		if ( $this->has_uploaded_file( $file ) ) {
			$upload = $this->handle_upload( $file );

			if ( is_wp_error( $upload ) ) {
				return $upload;
			}

			$prepared = array_merge( $prepared, $upload );
			$prepared['upload_date'] = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
			$this->delete_file( $current );
		}

		$prepared['updated_at'] = $now;
		$document               = $this->repository->update( $current, $prepared );
		$this->logger->info( 'Document updated.', array( 'document_id' => $document->id() ) );
		$this->record_history( $document, 'document_updated', __( 'Document updated', 'adam-membership' ) );

		return $document;
	}

	/**
	 * Archive a document.
	 *
	 * @param int $document_id Document ID.
	 * @return true|WP_Error
	 */
	public function archive( int $document_id ): true|WP_Error {
		$document = $this->repository->find( $document_id );

		if ( null === $document ) {
			return new WP_Error( 'adam_membership_document_not_found', __( 'Document not found.', 'adam-membership' ) );
		}

		$archived = $this->repository->update(
			$document,
			array(
				'status'     => Document::STATUS_ARCHIVED,
				'updated_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);

		$this->logger->info( 'Document archived.', array( 'document_id' => $document_id ) );
		$this->record_history( $archived, 'document_archived', __( 'Document archived', 'adam-membership' ) );

		return true;
	}

	/**
	 * Delete a document and file.
	 *
	 * @param int $document_id Document ID.
	 */
	public function delete( int $document_id ): void {
		$document = $this->repository->find( $document_id );

		if ( null !== $document ) {
			$this->delete_file( $document );
			$this->record_history( $document, 'document_deleted', __( 'Document deleted', 'adam-membership' ) );
		}

		$this->repository->delete( $document_id );
		$this->logger->info( 'Document deleted.', array( 'document_id' => $document_id ) );
	}

	/**
	 * Get visible documents for a member.
	 *
	 * @param Member                $member  Member.
	 * @param array<string, mixed>  $filters Filters.
	 * @return array<int, Document>
	 */
	public function visible_for_member( Member $member, array $filters = array() ): array {
		$filters['status'] = Document::STATUS_PUBLISHED;
		$documents         = $this->repository->query( $filters );

		return array_values(
			array_filter(
				$documents,
				fn ( Document $document ): bool => $this->matches_member( $document, $member )
			)
		);
	}

	/**
	 * Find a visible member document.
	 *
	 * @param Member $member      Member.
	 * @param int    $document_id Document ID.
	 */
	public function visible_document( Member $member, int $document_id ): ?Document {
		$document = $this->repository->find( $document_id );

		if ( null === $document || ! $document->is_visible() || ! $this->matches_member( $document, $member ) ) {
			return null;
		}

		return $document;
	}

	/**
	 * Get admin list data.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, Document>
	 */
	public function admin_list( array $filters = array() ): array {
		return $this->repository->query( $filters );
	}

	/**
	 * Get repository instance.
	 */
	public function repository(): DocumentRepository {
		return $this->repository;
	}

	/**
	 * Build a protected download URL.
	 *
	 * @param Document $document Document.
	 */
	public function download_url( Document $document ): string {
		return add_query_arg(
			array(
				'action'      => 'adam_membership_download_document',
				'document_id' => $document->id(),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Handle a protected download request.
	 */
	public function handle_download(): void {
		$document_id = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0;

		if ( $document_id <= 0 ) {
			wp_die( esc_html__( 'Invalid document download request.', 'adam-membership' ), '', array( 'response' => 403 ) );
		}

		$document = $this->repository->find( $document_id );

		if ( null === $document ) {
			wp_die( esc_html__( 'Document not found.', 'adam-membership' ), '', array( 'response' => 404 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$member = $this->members->find( get_current_user_id() );

			if ( null === $member || null === $this->visible_document( $member, $document_id ) ) {
				wp_die( esc_html__( 'You do not have permission to access this document.', 'adam-membership' ), '', array( 'response' => 403 ) );
			}
		}

		$path = $this->absolute_file_path( $document );

		if ( '' === $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Document file is unavailable.', 'adam-membership' ), '', array( 'response' => 404 ) );
		}

		$this->logger->info(
			'Document downloaded.',
			array(
				'document_id' => $document->id(),
				'user_id'     => get_current_user_id(),
			)
		);

		nocache_headers();
		header( 'Content-Type: ' . ( '' !== $document->mime_type() ? $document->mime_type() : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $document->file_name() ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Get category labels.
	 *
	 * @return array<int, string>
	 */
	public function categories(): array {
		return array(
			'Estatutos',
			'Regulamentos',
			'Seguro',
			'Assembleias Gerais',
			'Atas',
			'Eventos',
			'Seguranca',
			'Quotas',
			'Formularios',
			'Informacao Geral',
		);
	}

	/**
	 * Sanitize document data.
	 *
	 * @param array<string, mixed> $data Raw document data.
	 * @return array<string, mixed>
	 */
	private function sanitize_data( array $data ): array {
		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : Document::STATUS_DRAFT;

		if ( ! in_array( $status, Document::statuses(), true ) ) {
			$status = Document::STATUS_DRAFT;
		}

		$audience = isset( $data['target_audience'] ) ? sanitize_key( (string) $data['target_audience'] ) : Document::AUDIENCE_ALL_MEMBERS;

		if ( ! in_array( $audience, Document::audiences(), true ) ) {
			$audience = Document::AUDIENCE_ALL_MEMBERS;
		}

		return array(
			'title'           => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
			'description'     => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
			'category'        => isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : '',
			'version'         => isset( $data['version'] ) ? sanitize_text_field( (string) $data['version'] ) : '1.0',
			'status'          => $status,
			'target_audience' => $audience,
			'important'       => ! empty( $data['important'] ),
		);
	}

	/**
	 * Handle a validated file upload.
	 *
	 * @param array<string, mixed> $file Uploaded file array.
	 * @return array<string, mixed>|WP_Error
	 */
	private function handle_upload( array $file ): array|WP_Error {
		if ( ! $this->has_uploaded_file( $file ) ) {
			return new WP_Error( 'adam_membership_document_file_required', __( 'Please upload a document file.', 'adam-membership' ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		$allowed = $this->allowed_mime_types();
		$checked = wp_check_filetype_and_ext( $tmp, $name, $allowed );
		$type    = isset( $checked['type'] ) && is_string( $checked['type'] ) ? $checked['type'] : '';
		$ext     = isset( $checked['ext'] ) && is_string( $checked['ext'] ) ? $checked['ext'] : '';

		if ( '' === $type || '' === $ext ) {
			return new WP_Error( 'adam_membership_document_file_type', __( 'This file type is not allowed for documents.', 'adam-membership' ) );
		}

		$directory = $this->storage_directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'adam_membership_document_storage', __( 'Could not prepare secure document storage.', 'adam-membership' ) );
		}

		$this->protect_storage_directory( $directory );

		$filename    = wp_unique_filename( $directory, $name );
		$destination = trailingslashit( $directory ) . $filename;

		if ( ! @move_uploaded_file( $tmp, $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'adam_membership_document_upload_failed', __( 'Could not store the uploaded document.', 'adam-membership' ) );
		}

		return array(
			'file_path' => $filename,
			'file_name' => $name,
			'mime_type' => $type,
			'file_size' => filesize( $destination ) ?: 0,
		);
	}

	/**
	 * Check whether a file was uploaded.
	 *
	 * @param array<string, mixed> $file File data.
	 */
	private function has_uploaded_file( array $file ): bool {
		return isset( $file['tmp_name'], $file['error'] ) && UPLOAD_ERR_OK === (int) $file['error'] && is_uploaded_file( (string) $file['tmp_name'] );
	}

	/**
	 * Get allowed document MIME types.
	 *
	 * @return array<string, string>
	 */
	private function allowed_mime_types(): array {
		return array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'txt'  => 'text/plain',
		);
	}

	/**
	 * Get private storage directory.
	 */
	private function storage_directory(): string {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

		return trailingslashit( $base ) . 'adam-private-documents';
	}

	/**
	 * Add direct-access protection files where supported.
	 *
	 * @param string $directory Storage directory.
	 */
	private function protect_storage_directory( string $directory ): void {
		$htaccess = trailingslashit( $directory ) . '.htaccess';
		$index    = trailingslashit( $directory ) . 'index.html';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Delete a document file.
	 *
	 * @param Document $document Document.
	 */
	private function delete_file( Document $document ): void {
		$path = $this->absolute_file_path( $document );

		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Get absolute file path, constrained to the private directory.
	 *
	 * @param Document $document Document.
	 */
	private function absolute_file_path( Document $document ): string {
		$directory = realpath( $this->storage_directory() );

		if ( false === $directory || '' === $document->file_path() ) {
			return '';
		}

		$path = realpath( trailingslashit( $directory ) . basename( $document->file_path() ) );

		if ( false === $path || ! str_starts_with( $path, $directory ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Check whether a member matches the target audience.
	 *
	 * @param Document $document Document.
	 * @param Member   $member   Member.
	 */
	private function matches_member( Document $document, Member $member ): bool {
		$user = $member->user();

		return match ( $document->target_audience() ) {
			Document::AUDIENCE_ACTIVE_MEMBERS  => $member->isActive(),
			Document::AUDIENCE_RENEWAL_PENDING => $member->isRenewalPending(),
			Document::AUDIENCE_EXPIRED_MEMBERS => $member->isExpired(),
			Document::AUDIENCE_PENDING_MEMBERS => $member->isPending(),
			Document::AUDIENCE_ADMINS          => null !== $user && $user->has_cap( 'manage_options' ),
			default                            => true,
		};
	}

	/**
	 * Record document activity in the member history table when available.
	 *
	 * @param Document $document     Document.
	 * @param string   $action_key   Action key.
	 * @param string   $action_label Action label.
	 */
	private function record_history( Document $document, string $action_key, string $action_label ): void {
		$admin = wp_get_current_user();

		$this->history->create(
			array(
				'member_id'     => 0,
				'member_number' => '',
				'member_name'   => '',
				'member_email'  => '',
				'action_key'    => sanitize_key( $action_key ),
				'action_label'  => sanitize_text_field( $action_label ),
				'actor_type'    => 'admin',
				'actor_id'      => get_current_user_id(),
				'actor_name'    => $admin->exists() ? sanitize_text_field( $admin->display_name ) : __( 'Administrator', 'adam-membership' ),
				'description'   => sanitize_text_field( $document->title() ),
				'details'       => array(
					'document_id'     => $document->id(),
					'category'        => $document->category(),
					'version'         => $document->version(),
					'status'          => $document->status(),
					'target_audience' => $document->target_audience(),
					'important'       => $document->important(),
				),
				'created_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);
	}
}
