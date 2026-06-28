<?php
/**
 * Document admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Document\Document;
use AdamMembership\Document\DocumentService;

/**
 * Manages the admin-side Document Centre.
 */
final class DocumentController {
	private const CAPABILITY     = 'manage_options';
	private const MENU_SLUG      = 'adam-membership-documents';
	private const EDIT_PAGE_SLUG = 'adam-membership-document-edit';

	/**
	 * Document service.
	 *
	 * @var DocumentService
	 */
	private DocumentService $documents;

	/**
	 * Constructor.
	 *
	 * @param DocumentService $documents Document service.
	 */
	public function __construct( DocumentService $documents ) {
		$this->documents = $documents;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_save_document', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adam_membership_archive_document', array( $this, 'handle_archive' ) );
		add_action( 'admin_post_adam_membership_delete_document', array( $this, 'handle_delete' ) );
	}

	/**
	 * Register admin menu entries.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			esc_html__( 'Documentos', 'adam-membership' ),
			esc_html__( 'Documentos', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			null,
			esc_html__( 'Editar Documento', 'adam-membership' ),
			esc_html__( 'Editar Documento', 'adam-membership' ),
			self::CAPABILITY,
			self::EDIT_PAGE_SLUG,
			array( $this, 'render_edit_page' )
		);
	}

	/**
	 * Render document list.
	 */
	public function render_list_page(): void {
		$this->ensure_can_manage();

		$filters   = $this->current_filters();
		$documents = $this->documents->admin_list( $filters );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php esc_html_e( 'Documentos', 'adam-membership' ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-actions">
				<a class="button button-primary" href="<?php echo esc_url( $this->edit_url() ); ?>"><?php esc_html_e( 'Novo documento', 'adam-membership' ); ?></a>
			</div>
			<form method="get" class="adam-admin-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label>
					<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Titulo, descricao, ficheiro', 'adam-membership' ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span>
					<select name="category">
						<?php $this->render_select_option( '', __( 'Todas', 'adam-membership' ), $filters['category'] ); ?>
						<?php foreach ( $this->documents->categories() as $category ) : ?>
							<?php $this->render_select_option( $category, $category, $filters['category'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
					<select name="status">
						<?php $this->render_select_option( '', __( 'Todos', 'adam-membership' ), $filters['status'] ); ?>
						<?php foreach ( Document::statuses() as $status ) : ?>
							<?php $this->render_select_option( $status, $this->status_label( $status ), $filters['status'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Aplicar', 'adam-membership' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Limpar', 'adam-membership' ); ?></a>
			</form>

			<?php if ( array() === $documents ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda nao existem documentos.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Titulo', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Visibilidade', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Versao', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Importante', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Atualizado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Acoes', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $documents as $document ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $document->title() ); ?></strong>
									<small><?php echo esc_html( $document->file_name() ); ?></small>
								</td>
								<td><?php echo esc_html( $document->category() ); ?></td>
								<td><?php $this->render_badge( $this->status_label( $document->status() ), 'document-status-' . $document->status() ); ?></td>
								<td><?php echo esc_html( $this->audience_label( $document->target_audience() ) ); ?></td>
								<td><?php echo esc_html( $document->version() ); ?></td>
								<td><?php $this->render_badge( $document->important() ? __( 'Sim', 'adam-membership' ) : __( 'Nao', 'adam-membership' ), $document->important() ? 'document-important' : 'document-normal' ); ?></td>
								<td><?php echo esc_html( $this->format_datetime( $document->updated_at() ) ); ?></td>
								<td class="adam-admin-row-actions">
									<a class="button button-small" href="<?php echo esc_url( $this->documents->download_url( $document ) ); ?>"><?php esc_html_e( 'Ver/download', 'adam-membership' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( $this->edit_url( $document->id() ) ); ?>"><?php esc_html_e( 'Editar', 'adam-membership' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( $this->edit_url( $document->id() ) ); ?>"><?php esc_html_e( 'Substituir ficheiro', 'adam-membership' ); ?></a>
									<?php if ( Document::STATUS_ARCHIVED !== $document->status() ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
											<input type="hidden" name="action" value="adam_membership_archive_document">
											<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $document->id() ); ?>">
											<?php wp_nonce_field( 'adam_membership_archive_document_' . $document->id() ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Arquivar', 'adam-membership' ); ?></button>
										</form>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Eliminar este documento?', 'adam-membership' ) ); ?>');">
										<input type="hidden" name="action" value="adam_membership_delete_document">
										<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $document->id() ); ?>">
										<?php wp_nonce_field( 'adam_membership_delete_document_' . $document->id() ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'adam-membership' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render edit page.
	 */
	public function render_edit_page(): void {
		$this->ensure_can_manage();

		$document = $this->current_document();
		$title    = null === $document ? __( 'Novo documento', 'adam-membership' ) : __( 'Editar documento', 'adam-membership' );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form" enctype="multipart/form-data">
					<input type="hidden" name="action" value="adam_membership_save_document">
					<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) ( null !== $document ? $document->id() : 0 ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_save_document' ); ?>
					<div class="adam-admin-edit-grid">
						<label>
							<span><?php esc_html_e( 'Titulo', 'adam-membership' ); ?></span>
							<input type="text" name="title" value="<?php echo esc_attr( null !== $document ? $document->title() : '' ); ?>" required>
						</label>
						<label>
							<span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span>
							<select name="category">
								<?php foreach ( $this->documents->categories() as $category ) : ?>
									<?php $this->render_select_option( $category, $category, null !== $document ? $document->category() : '' ); ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Versao', 'adam-membership' ); ?></span>
							<input type="text" name="version" value="<?php echo esc_attr( null !== $document ? $document->version() : '1.0' ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
							<select name="status">
								<?php foreach ( Document::statuses() as $status ) : ?>
									<?php $this->render_select_option( $status, $this->status_label( $status ), null !== $document ? $document->status() : Document::STATUS_DRAFT ); ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Visibilidade', 'adam-membership' ); ?></span>
							<select name="target_audience">
								<?php foreach ( Document::audiences() as $audience ) : ?>
									<?php $this->render_select_option( $audience, $this->audience_label( $audience ), null !== $document ? $document->target_audience() : Document::AUDIENCE_ALL_MEMBERS ); ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Ficheiro', 'adam-membership' ); ?></span>
							<input type="file" name="document_file" <?php echo null === $document ? 'required' : ''; ?> accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.txt">
							<?php if ( null !== $document && '' !== $document->file_name() ) : ?>
								<small><?php echo esc_html( sprintf( __( 'Atual: %s', 'adam-membership' ), $document->file_name() ) ); ?></small>
							<?php endif; ?>
						</label>
					</div>
					<label class="adam-admin-edit-field adam-admin-edit-field-full">
						<span><?php esc_html_e( 'Descricao', 'adam-membership' ); ?></span>
						<textarea name="description" rows="4"><?php echo esc_textarea( null !== $document ? $document->description() : '' ); ?></textarea>
					</label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="important" value="1" <?php checked( null !== $document ? $document->important() : false ); ?>> <?php esc_html_e( 'Marcar como importante', 'adam-membership' ); ?></label>
					<div class="adam-admin-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar documento', 'adam-membership' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar a lista', 'adam-membership' ); ?></a>
					</div>
				</form>
			</div>
			<?php if ( null !== $document ) : ?>
				<div class="adam-admin-panel">
					<h2><?php esc_html_e( 'Ligacao protegida', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Use este URL no botao de um aviso para ligar diretamente a este documento com controlo de acesso.', 'adam-membership' ); ?></p>
					<input type="url" class="large-text" readonly value="<?php echo esc_attr( $this->documents->download_url( $document ) ); ?>">
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle save.
	 */
	public function handle_save(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_document' );

		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		$file        = isset( $_FILES['document_file'] ) && is_array( $_FILES['document_file'] ) ? $_FILES['document_file'] : array();
		$result      = $this->documents->save( $this->posted_data(), $file, $document_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), $this->edit_url( $document_id ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Documento guardado com sucesso.', 'adam-membership' ), $this->edit_url( $result->id() ) );
	}

	/**
	 * Handle archive.
	 */
	public function handle_archive(): void {
		$this->ensure_can_manage();
		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_archive_document_' . $document_id );
		$result = $this->documents->archive( $document_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Documento arquivado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Handle delete.
	 */
	public function handle_delete(): void {
		$this->ensure_can_manage();
		$document_id = isset( $_POST['document_id'] ) ? absint( wp_unslash( $_POST['document_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_delete_document_' . $document_id );
		$this->documents->delete( $document_id );
		$this->redirect_with_notice( 'adam_message', __( 'Documento eliminado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Get current filters.
	 *
	 * @return array{search:string,status:string,category:string}
	 */
	private function current_filters(): array {
		return array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'category' => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '',
		);
	}

	/**
	 * Get current document.
	 */
	private function current_document(): ?Document {
		$document_id = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0;

		return $document_id > 0 ? $this->documents->repository()->find( $document_id ) : null;
	}

	/**
	 * Get posted data.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_data(): array {
		return array(
			'title'           => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
			'description'     => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'category'        => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '',
			'version'         => isset( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : '',
			'status'          => isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : '',
			'target_audience' => isset( $_POST['target_audience'] ) ? wp_unslash( $_POST['target_audience'] ) : '',
			'important'       => isset( $_POST['important'] ),
		);
	}

	/**
	 * Render a safe select option.
	 *
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param string $current Current value.
	 */
	private function render_select_option( string $value, string $label, string $current ): void {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render admin notices.
	 */
	private function render_notices(): void {
		$message = isset( $_GET['adam_message'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_message'] ) ) : '';
		$error   = isset( $_GET['adam_error'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_error'] ) ) : '';

		if ( '' !== $message ) {
			printf( '<div class="adam-admin-notice success"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="adam-admin-notice error"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Ensure capability.
	 */
	private function ensure_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ADAM documents.', 'adam-membership' ) );
		}
	}

	/**
	 * Build edit URL.
	 *
	 * @param int $document_id Optional document ID.
	 */
	private function edit_url( int $document_id = 0 ): string {
		$args = array( 'page' => self::EDIT_PAGE_SLUG );

		if ( $document_id > 0 ) {
			$args['document_id'] = $document_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect with notice.
	 *
	 * @param string $key      Query key.
	 * @param string $message  Message.
	 * @param string $redirect Redirect URL.
	 */
	private function redirect_with_notice( string $key, string $message, string $redirect ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					$key => $message,
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Get status label.
	 *
	 * @param string $status Status key.
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			Document::STATUS_DRAFT     => __( 'Rascunho', 'adam-membership' ),
			Document::STATUS_PUBLISHED => __( 'Publicado', 'adam-membership' ),
			Document::STATUS_ARCHIVED  => __( 'Arquivado', 'adam-membership' ),
			default                    => $status,
		};
	}

	/**
	 * Get audience label.
	 *
	 * @param string $audience Audience key.
	 */
	private function audience_label( string $audience ): string {
		return match ( $audience ) {
			Document::AUDIENCE_ACTIVE_MEMBERS  => __( 'Socios ativos', 'adam-membership' ),
			Document::AUDIENCE_RENEWAL_PENDING => __( 'Renovacao pendente', 'adam-membership' ),
			Document::AUDIENCE_EXPIRED_MEMBERS => __( 'Quotas expiradas', 'adam-membership' ),
			Document::AUDIENCE_PENDING_MEMBERS => __( 'Inscricoes pendentes', 'adam-membership' ),
			Document::AUDIENCE_ADMINS          => __( 'Admins / Direcao', 'adam-membership' ),
			default                            => __( 'Todos os socios', 'adam-membership' ),
		};
	}

	/**
	 * Render a badge.
	 *
	 * @param string $label Label.
	 * @param string $class CSS class.
	 */
	private function render_badge( string $label, string $class ): void {
		printf( '<span class="adam-admin-badge %1$s">%2$s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Format stored datetime.
	 *
	 * @param string $datetime Datetime string.
	 */
	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date( 'd/m/Y H:i', $timestamp );
	}
}
