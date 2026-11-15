<?php
/**
 * Announcement admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Announcement\Announcement;
use AdamMembership\Announcement\AnnouncementAttachments;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Announcement\AnnouncementView;
use AdamMembership\Communication\CommunicationCategoryRegistry;
use AdamMembership\Emails\EmailService;

/**
 * Manages the admin-side Communication Centre.
 */
final class AnnouncementController {
	private const CAPABILITY     = 'manage_options';
	private const MENU_SLUG      = 'adam-membership-notices';
	private const EDIT_PAGE_SLUG = 'adam-membership-notice-edit';
	private const PREVIEW_ACTION = 'adam_membership_preview_announcement';

	/**
	 * Announcement service.
	 *
	 * @var AnnouncementService
	 */
	private AnnouncementService $announcements;

	/**
	 * Constructor.
	 *
	 * @param AnnouncementService $announcements Announcement service.
	 * @param EmailService         $email         Email service.
	 */
	public function __construct( AnnouncementService $announcements, private EmailService $email ) {
		$this->announcements = $announcements;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_adam_membership_save_announcement', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adam_membership_archive_announcement', array( $this, 'handle_archive' ) );
		add_action( 'admin_post_adam_membership_delete_announcement', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
	}

	/**
	 * Enqueue the notice editor behavior on its own screen.
	 */
	public function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::EDIT_PAGE_SLUG !== $page ) {
			return;
		}

		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/admin-announcements.js';

		wp_enqueue_script(
			'adam-membership-admin-announcements',
			ADAM_MEMBERSHIP_URL . 'assets/js/admin-announcements.js',
			array( 'jquery' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);
		wp_localize_script(
			'adam-membership-admin-announcements',
			'adamAnnouncementPreview',
			array(
				'url'         => admin_url( 'admin-ajax.php' ),
				'action'      => self::PREVIEW_ACTION,
				'nonce'       => wp_create_nonce( self::PREVIEW_ACTION ),
				'empty'       => __( 'Escreva o título para iniciar a pré-visualização.', 'adam-membership' ),
				'loading'     => __( 'A atualizar a pré-visualização…', 'adam-membership' ),
				'error'       => __( 'Não foi possível atualizar a pré-visualização. Tente novamente.', 'adam-membership' ),
				'attachments' => array(
					'mimes'   => array_values( AnnouncementAttachments::mimes() ),
					'select'  => __( 'Selecionar documentos', 'adam-membership' ),
					'add'     => __( 'Anexar ao email', 'adam-membership' ),
					'remove'  => __( 'Remover', 'adam-membership' ),
				),
			)
		);
	}

	/**
	 * Register admin menu entries.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			esc_html__( 'Centro de Avisos', 'adam-membership' ),
			esc_html__( 'Centro de Avisos', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			'adam-membership',
			esc_html__( 'Editar Aviso', 'adam-membership' ),
			esc_html__( 'Editar Aviso', 'adam-membership' ),
			self::CAPABILITY,
			self::EDIT_PAGE_SLUG,
			array( $this, 'render_edit_page' )
		);
	}

	/**
	 * Render announcements list.
	 */
	public function render_list_page(): void {
		$this->ensure_can_manage();

		$filters       = $this->current_filters();
		$announcements = $this->announcements->admin_list( $filters );
		?>
		<div class="wrap adam-admin-wrap adam-admin-announcements">
			<div class="adam-admin-titlebar">
				<h1><?php esc_html_e( 'Centro de Avisos', 'adam-membership' ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-actions">
				<a class="button button-primary adam-button" href="<?php echo esc_url( $this->edit_url() ); ?>"><?php esc_html_e( 'Novo aviso', 'adam-membership' ); ?></a>
			</div>
			<form method="get" class="adam-admin-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label>
					<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Título, resumo, categoria', 'adam-membership' ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
					<select name="status">
						<?php $this->render_select_option( '', __( 'Todos', 'adam-membership' ), $filters['status'] ); ?>
						<?php foreach ( Announcement::statuses() as $status ) : ?>
							<?php $this->render_select_option( $status, $this->status_label( $status ), $filters['status'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Aplicar', 'adam-membership' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Limpar', 'adam-membership' ); ?></a>
			</form>

			<?php if ( array() === $announcements ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem avisos.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table adam-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Título', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Prioridade', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Publicação', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Expira', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Audiência', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Leitura', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $announcements as $announcement ) : ?>
							<?php $stats = $this->announcements->stats( $announcement ); ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $announcement->title() ); ?></strong>
									<?php if ( $announcement->pinned() ) : ?>
										<small><?php esc_html_e( 'Fixado', 'adam-membership' ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $announcement->category() ); ?></td>
								<td><?php $this->render_badge( AnnouncementView::priority_label( $announcement->priority() ), 'announcement-' . $announcement->priority() ); ?></td>
								<td><?php $this->render_badge( $this->status_label( $announcement->effective_status() ), 'announcement-status-' . $announcement->effective_status() ); ?></td>
								<td><?php echo esc_html( $this->format_date( $announcement->publish_date() ) ); ?></td>
								<td><?php echo esc_html( $this->format_date( $announcement->expiry_date() ) ); ?></td>
								<td><?php echo esc_html( $this->audience_label( $announcement->target_audience() ) ); ?></td>
								<td><?php echo esc_html( sprintf( '%1$d / %2$d', $stats['read'], $stats['targeted'] ) ); ?></td>
								<td class="adam-admin-row-actions">
									<a class="button button-small" href="<?php echo esc_url( $this->edit_url( $announcement->id() ) ); ?>"><?php esc_html_e( 'Editar', 'adam-membership' ); ?></a>
									<?php if ( Announcement::STATUS_ARCHIVED !== $announcement->effective_status() ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
											<input type="hidden" name="action" value="adam_membership_archive_announcement">
											<input type="hidden" name="announcement_id" value="<?php echo esc_attr( (string) $announcement->id() ); ?>">
											<?php wp_nonce_field( 'adam_membership_archive_announcement_' . $announcement->id() ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Arquivar', 'adam-membership' ); ?></button>
										</form>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Eliminar este aviso?', 'adam-membership' ) ); ?>');">
										<input type="hidden" name="action" value="adam_membership_delete_announcement">
										<input type="hidden" name="announcement_id" value="<?php echo esc_attr( (string) $announcement->id() ); ?>">
										<?php wp_nonce_field( 'adam_membership_delete_announcement_' . $announcement->id() ); ?>
										<button type="submit" class="button button-small button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Eliminar', 'adam-membership' ); ?></button>
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

		$announcement     = $this->current_announcement();
		$is_new           = null === $announcement;
		$title            = $is_new ? __( 'Novo aviso', 'adam-membership' ) : __( 'Editar aviso', 'adam-membership' );
		$email_audience   = null !== $announcement ? $announcement->email_audience() : Announcement::EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS;
		$email_team_id    = null !== $announcement ? $announcement->email_team_id() : 0;
		$email_member_ids = null !== $announcement ? $announcement->email_member_ids() : array();
		?>
		<div class="wrap adam-admin-wrap adam-admin-announcements">
			<div class="adam-admin-titlebar">
				<h1><?php echo esc_html( $title ); ?></h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar ao Centro de Avisos', 'adam-membership' ); ?></a>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-announcement-workspace">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form" data-adam-announcement-form>
					<input type="hidden" name="action" value="adam_membership_save_announcement">
					<input type="hidden" name="announcement_id" value="<?php echo esc_attr( (string) ( null !== $announcement ? $announcement->id() : 0 ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_save_announcement' ); ?>
					<section class="adam-admin-panel adam-card adam-announcement-editor-section" aria-labelledby="adam-announcement-message-heading">
						<h2 id="adam-announcement-message-heading"><?php esc_html_e( 'Mensagem', 'adam-membership' ); ?></h2>
						<label class="adam-admin-edit-field">
							<span><?php esc_html_e( 'Título do aviso e assunto do email', 'adam-membership' ); ?></span>
							<input type="text" name="title" value="<?php echo esc_attr( $announcement?->title() ?? '' ); ?>" required>
						</label>
						<label class="adam-admin-edit-field">
							<span><?php esc_html_e( 'Resumo (opcional)', 'adam-membership' ); ?></span>
							<textarea name="summary" rows="2" aria-describedby="adam-announcement-summary-help"><?php echo esc_textarea( $announcement?->summary() ?? '' ); ?></textarea>
							<small id="adam-announcement-summary-help" class="description"><?php esc_html_e( 'Uma ou duas frases para o cartão na Área do Sócio e para a abertura do email.', 'adam-membership' ); ?></small>
						</label>
						<div class="adam-admin-edit-field">
							<label for="adam_announcement_content"><?php esc_html_e( 'Mensagem completa', 'adam-membership' ); ?></label>
							<p class="description"><?php esc_html_e( 'Indique o que aconteceu, a quem se aplica e, se for preciso agir, o que fazer e até quando. Este texto aparece no detalhe do aviso e no email.', 'adam-membership' ); ?></p>
							<?php
							wp_editor(
								$announcement?->content() ?? '',
								'adam_announcement_content',
								array(
									'textarea_name' => 'content',
									'textarea_rows' => 8,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
								)
							);
							?>
						</div>
						<div class="adam-admin-edit-field">
							<strong><?php esc_html_e( 'Botão de ação (opcional)', 'adam-membership' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Preencha o texto e o endereço para incluir um link na Área do Sócio e no email.', 'adam-membership' ); ?></p>
							<div class="adam-admin-edit-grid">
								<label>
									<span><?php esc_html_e( 'Texto do botão', 'adam-membership' ); ?></span>
									<input type="text" name="action_label" value="<?php echo esc_attr( $announcement?->action_label() ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Consultar convocatória', 'adam-membership' ); ?>">
								</label>
								<label>
									<span><?php esc_html_e( 'Endereço do botão', 'adam-membership' ); ?></span>
									<input type="url" name="action_url" value="<?php echo esc_attr( $announcement?->action_url() ?? '' ); ?>" placeholder="https://">
								</label>
							</div>
						</div>
					</section>

					<section class="adam-admin-panel adam-card adam-announcement-editor-section" aria-labelledby="adam-announcement-publication-heading">
						<h2 id="adam-announcement-publication-heading"><?php esc_html_e( 'Publicação', 'adam-membership' ); ?></h2>
						<div class="adam-admin-edit-grid">
							<label>
								<span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span>
								<select name="category">
									<?php $this->render_category_options( null !== $announcement ? $announcement->category() : '' ); ?>
								</select>
							</label>
							<label>
								<span><?php esc_html_e( 'Prioridade', 'adam-membership' ); ?></span>
								<select name="priority">
									<?php foreach ( Announcement::priorities() as $priority ) : ?>
										<?php $this->render_select_option( $priority, AnnouncementView::priority_label( $priority ), $announcement?->priority() ?? Announcement::PRIORITY_INFO ); ?>
									<?php endforeach; ?>
								</select>
							</label>
							<label>
								<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
								<select name="status">
									<?php foreach ( array( Announcement::STATUS_DRAFT, Announcement::STATUS_SCHEDULED, Announcement::STATUS_PUBLISHED, Announcement::STATUS_ARCHIVED ) as $status ) : ?>
										<?php $this->render_select_option( $status, $this->status_label( $status ), null !== $announcement ? $announcement->status() : Announcement::STATUS_DRAFT ); ?>
									<?php endforeach; ?>
								</select>
							</label>
							<label>
								<span><?php esc_html_e( 'Data de publicação', 'adam-membership' ); ?></span>
								<input type="date" name="publish_date" value="<?php echo esc_attr( null !== $announcement ? $announcement->publish_date() : wp_date( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>">
							</label>
							<label>
								<span><?php esc_html_e( 'Data de expiração (opcional)', 'adam-membership' ); ?></span>
								<input type="date" name="expiry_date" value="<?php echo esc_attr( null !== $announcement ? $announcement->expiry_date() : '' ); ?>">
							</label>
						</div>
					</section>

					<section class="adam-admin-panel adam-card adam-announcement-editor-section" aria-labelledby="adam-announcement-delivery-heading">
						<h2 id="adam-announcement-delivery-heading"><?php esc_html_e( 'Canais e destinatários', 'adam-membership' ); ?></h2>
						<fieldset class="adam-admin-delivery-options">
							<legend><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></legend>
							<label class="adam-admin-checkbox-field"><input type="checkbox" name="show_in_member_area" value="1" <?php checked( $announcement?->show_in_member_area() ?? true ); ?>> <?php esc_html_e( 'Mostrar na Área do Sócio', 'adam-membership' ); ?></label>
							<div class="adam-announcement-channel-settings" data-adam-announcement-member-settings>
								<label class="adam-admin-edit-field">
									<span><?php esc_html_e( 'Audiência do aviso', 'adam-membership' ); ?></span>
									<select name="target_audience">
										<?php foreach ( Announcement::audiences() as $audience ) : ?>
											<?php $this->render_select_option( $audience, $this->audience_label( $audience ), $announcement?->target_audience() ?? Announcement::AUDIENCE_ALL_MEMBERS ); ?>
										<?php endforeach; ?>
									</select>
								</label>
								<div class="adam-announcement-channel-settings" data-adam-announcement-member-placement>
									<label class="adam-admin-checkbox-field"><input type="checkbox" name="pinned" value="1" <?php checked( $announcement?->pinned() ?? false ); ?>> <?php esc_html_e( 'Fixar no topo dos avisos', 'adam-membership' ); ?></label>
									<label class="adam-admin-checkbox-field"><input type="checkbox" name="show_on_member_homepage" value="1" <?php checked( $announcement?->show_on_member_homepage() ?? false ); ?>> <?php esc_html_e( 'Mostrar também no painel do sócio', 'adam-membership' ); ?></label>
								</div>
							</div>
						</fieldset>
						<fieldset class="adam-admin-delivery-options">
							<legend><?php esc_html_e( 'Email', 'adam-membership' ); ?></legend>
							<label class="adam-admin-checkbox-field"><input type="checkbox" name="send_email" value="1" <?php checked( $announcement?->send_email() ?? false ); ?>> <?php esc_html_e( 'Enviar por email', 'adam-membership' ); ?></label>
							<?php if ( '' !== ( $announcement?->email_sent_at() ?? '' ) ) : ?>
								<p class="description"><?php esc_html_e( 'O envio deste aviso já foi processado. Guardar alterações não volta a enviar o email.', 'adam-membership' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Com esta opção ativa, guardar um aviso com publicação em vigor pode enviar o email imediatamente.', 'adam-membership' ); ?></p>
							<?php endif; ?>
							<div class="adam-announcement-channel-settings" data-adam-announcement-email-settings>
								<strong><?php esc_html_e( 'Quem recebe o email', 'adam-membership' ); ?></strong>
								<p class="description"><?php esc_html_e( 'Nas categorias opcionais, as preferências de email dos membros são sempre respeitadas. As categorias obrigatórias não permitem exclusão.', 'adam-membership' ); ?></p>

								<?php if ( Announcement::EMAIL_AUDIENCE_LEGACY === $email_audience ) : ?>
									<label class="adam-admin-radio-field"><input type="radio" name="email_audience" value="<?php echo esc_attr( Announcement::EMAIL_AUDIENCE_LEGACY ); ?>" checked> <?php esc_html_e( 'Usar a audiência do aviso (configuração original)', 'adam-membership' ); ?></label>
								<?php endif; ?>
								<?php foreach ( Announcement::email_audiences() as $audience ) : ?>
									<label class="adam-admin-radio-field"><input type="radio" name="email_audience" value="<?php echo esc_attr( $audience ); ?>" <?php checked( $email_audience, $audience ); ?>> <?php echo esc_html( $this->email_audience_label( $audience ) ); ?></label>
								<?php endforeach; ?>

								<label class="adam-admin-email-recipient-detail" data-adam-announcement-email-team>
									<span><?php esc_html_e( 'Equipa específica', 'adam-membership' ); ?></span>
									<select name="email_team_id">
										<?php $this->render_select_option( '', __( 'Selecionar equipa', 'adam-membership' ), $email_team_id > 0 ? (string) $email_team_id : '' ); ?>
										<?php foreach ( $this->announcements->team_choices() as $team_id => $team_name ) : ?>
											<?php $this->render_select_option( (string) $team_id, $team_name, (string) $email_team_id ); ?>
										<?php endforeach; ?>
									</select>
								</label>

								<label class="adam-admin-email-recipient-detail" data-adam-announcement-email-members>
									<span><?php esc_html_e( 'Sócio(s) específico(s)', 'adam-membership' ); ?></span>
									<select name="email_member_ids[]" multiple size="8">
										<?php foreach ( $this->announcements->member_choices() as $member_id => $member_label ) : ?>
											<option value="<?php echo esc_attr( (string) $member_id ); ?>" <?php selected( in_array( $member_id, $email_member_ids, true ) ); ?>><?php echo esc_html( $member_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<small><?php esc_html_e( 'Use Ctrl/Cmd para selecionar vários membros.', 'adam-membership' ); ?></small>
								</label>
							</div>
							<?php $this->render_email_attachments( $announcement?->email_attachment_ids() ?? array() ); ?>
						</fieldset>
					</section>

					<div class="adam-admin-actions">
						<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar aviso', 'adam-membership' ); ?></button>
						<a class="button adam-announcement-preview-jump" href="#adam-announcement-preview"><?php esc_html_e( 'Ver pré-visualização', 'adam-membership' ); ?></a>
					</div>
				</form>
				<?php $this->render_preview_panel(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Media Library selections independently of the email body.
	 *
	 * @param int[] $attachment_ids Saved attachment IDs.
	 */
	private function render_email_attachments( array $attachment_ids ): void {
		?>
		<div class="adam-announcement-channel-settings adam-announcement-attachments" data-adam-announcement-attachments>
			<strong><?php esc_html_e( 'Anexos do email (opcional)', 'adam-membership' ); ?></strong>
			<p class="description"><?php esc_html_e( 'Selecione ou carregue documentos na Biblioteca multimédia. Use ficheiros que possam ficar acessíveis por URL pública.', 'adam-membership' ); ?></p>
			<input type="hidden" name="email_attachment_ids" value="<?php echo esc_attr( implode( ',', $attachment_ids ) ); ?>">
			<ul class="adam-announcement-attachment-list" data-adam-announcement-attachment-list>
				<?php foreach ( $attachment_ids as $attachment_id ) : ?>
					<li data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
						<span><?php echo esc_html( AnnouncementAttachments::filename( $attachment_id ) ); ?></span>
						<button type="button" class="button-link" data-adam-remove-attachment><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( current_user_can( 'upload_files' ) ) : ?>
				<button type="button" class="button" data-adam-add-attachments><?php esc_html_e( 'Adicionar documentos', 'adam-membership' ); ?></button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the preview controls and isolated display frames.
	 */
	private function render_preview_panel(): void {
		?>
		<aside id="adam-announcement-preview" class="adam-admin-panel adam-card adam-announcement-preview" aria-labelledby="adam-announcement-preview-heading">
			<h2 id="adam-announcement-preview-heading"><?php esc_html_e( 'Pré-visualização', 'adam-membership' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Veja as alterações antes de guardar o aviso.', 'adam-membership' ); ?></p>
			<div class="adam-announcement-preview-controls" role="group" aria-label="<?php esc_attr_e( 'Canal da pré-visualização', 'adam-membership' ); ?>">
				<button class="button button-primary" type="button" data-adam-announcement-preview-channel="member" aria-pressed="true" aria-controls="adam-announcement-preview-member"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></button>
				<button class="button" type="button" data-adam-announcement-preview-channel="email" aria-pressed="false" aria-controls="adam-announcement-preview-email"><?php esc_html_e( 'Email', 'adam-membership' ); ?></button>
			</div>
			<p data-adam-announcement-preview-status role="status"></p>
			<button class="button" type="button" data-adam-announcement-preview-retry hidden><?php esc_html_e( 'Atualizar pré-visualização', 'adam-membership' ); ?></button>
			<div id="adam-announcement-preview-member" data-adam-announcement-preview-panel="member" hidden>
				<p class="description" data-adam-announcement-channel-disabled hidden><?php esc_html_e( 'A apresentação na Área do Sócio está desativada para este aviso.', 'adam-membership' ); ?></p>
				<iframe title="<?php esc_attr_e( 'Cartão e detalhe na Área do Sócio', 'adam-membership' ); ?>" sandbox></iframe>
			</div>
			<div id="adam-announcement-preview-email" data-adam-announcement-preview-panel="email" hidden>
				<p class="description" data-adam-announcement-channel-disabled hidden><?php esc_html_e( 'O envio por email está desativado para este aviso.', 'adam-membership' ); ?></p>
				<p class="adam-announcement-subject">
					<strong><?php esc_html_e( 'Assunto', 'adam-membership' ); ?></strong>
					<span data-adam-announcement-preview-subject></span>
				</p>
				<p class="description"><?php esc_html_e( 'A saudação usa um nome de exemplo.', 'adam-membership' ); ?></p>
				<div class="adam-announcement-subject" data-adam-announcement-preview-attachments hidden>
					<strong><?php esc_html_e( 'Anexos', 'adam-membership' ); ?></strong>
					<ul class="adam-announcement-attachment-list"></ul>
				</div>
				<iframe title="<?php esc_attr_e( 'Corpo do email do aviso', 'adam-membership' ); ?>" sandbox></iframe>
			</div>
			<noscript><p><?php esc_html_e( 'Ative o JavaScript para ver a pré-visualização.', 'adam-membership' ); ?></p></noscript>
		</aside>
		<?php
	}

	/**
	 * Render both channels using unsaved editor data.
	 */
	public function handle_preview(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}

		check_ajax_referer( self::PREVIEW_ACTION );

		$announcement = $this->announcements->preview( $this->posted_data() );
		$email        = $this->email->render_announcement_email( $announcement, __( 'João Exemplo', 'adam-membership' ) );
		$stylesheet   = add_query_arg( 'ver', (string) filemtime( ADAM_MEMBERSHIP_PATH . 'assets/css/member-area.css' ), ADAM_MEMBERSHIP_URL . 'assets/css/member-area.css' );

		ob_start();
		?>
		<!doctype html>
		<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<base target="_blank">
			<link rel="stylesheet" href="<?php echo esc_url( $stylesheet ); ?>">
			<style>
				body { margin: 0; background: #f4f7f3; font: 16px/1.55 system-ui, sans-serif; }
				.adam-member-area { margin: 0 auto; padding: 1rem; overflow-wrap: anywhere; }
				.adam-member-area.adam-member-dashboard { gap: 1rem; }
				.adam-member-area h2 { font-size: .85rem; margin: 0 0 .5rem; }
			</style>
		</head>
		<body>
			<main class="adam-member-area adam-member-dashboard">
				<div>
					<h2><?php esc_html_e( 'Cartão na lista de avisos', 'adam-membership' ); ?></h2>
					<?php AnnouncementView::render_card( $announcement, true ); ?>
				</div>
				<div>
					<h2><?php esc_html_e( 'Detalhe ao selecionar «Ler mais»', 'adam-membership' ); ?></h2>
					<?php AnnouncementView::render_detail( $announcement ); ?>
				</div>
			</main>
		</body>
		</html>
		<?php
		wp_send_json_success(
			array(
				'member'  => (string) ob_get_clean(),
				'email'   => $email['html'],
				'subject' => $email['subject'],
			)
		);
	}

	/**
	 * Handle announcement save.
	 */
	public function handle_save(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_announcement' );

		$announcement_id = isset( $_POST['announcement_id'] ) ? absint( wp_unslash( $_POST['announcement_id'] ) ) : 0;
		$result          = $this->announcements->save( $this->posted_data(), $announcement_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), $this->edit_url( $announcement_id ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Aviso guardado com sucesso.', 'adam-membership' ), $this->edit_url( $result->id() ) );
	}

	/**
	 * Handle archive.
	 */
	public function handle_archive(): void {
		$this->ensure_can_manage();
		$announcement_id = isset( $_POST['announcement_id'] ) ? absint( wp_unslash( $_POST['announcement_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_archive_announcement_' . $announcement_id );
		$result = $this->announcements->archive( $announcement_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Aviso arquivado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Handle delete.
	 */
	public function handle_delete(): void {
		$this->ensure_can_manage();
		$announcement_id = isset( $_POST['announcement_id'] ) ? absint( wp_unslash( $_POST['announcement_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_delete_announcement_' . $announcement_id );
		$this->announcements->delete( $announcement_id );
		$this->redirect_with_notice( 'adam_message', __( 'Aviso eliminado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * Get current filters.
	 *
	 * @return array{search:string,status:string}
	 */
	private function current_filters(): array {
		return array(
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		);
	}

	/**
	 * Get current announcement.
	 */
	private function current_announcement(): ?Announcement {
		$announcement_id = isset( $_GET['announcement_id'] ) ? absint( wp_unslash( $_GET['announcement_id'] ) ) : 0;

		return $announcement_id > 0 ? $this->announcements->repository()->find( $announcement_id ) : null;
	}

	/**
	 * Get posted data.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_data(): array {
		$fields = wp_unslash( array_filter( $_POST, 'is_string' ) );

		return array(
			'title'                   => $fields['title'] ?? '',
			'summary'                 => $fields['summary'] ?? '',
			'content'                 => $fields['content'] ?? '',
			'category'                => $fields['category'] ?? '',
			'priority'                => $fields['priority'] ?? '',
			'status'                  => $fields['status'] ?? '',
			'publish_date'            => $fields['publish_date'] ?? '',
			'expiry_date'             => $fields['expiry_date'] ?? '',
			'target_audience'         => $fields['target_audience'] ?? '',
			'show_in_member_area'     => isset( $fields['show_in_member_area'] ),
			'show_on_member_homepage' => isset( $fields['show_on_member_homepage'] ),
			'pinned'                  => isset( $fields['pinned'] ),
			'action_label'            => $fields['action_label'] ?? '',
			'action_url'              => $fields['action_url'] ?? '',
			'send_email'              => isset( $fields['send_email'] ),
			'email_audience'          => $fields['email_audience'] ?? Announcement::EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS,
			'email_team_id'           => absint( $fields['email_team_id'] ?? 0 ),
			'email_member_ids'        => isset( $_POST['email_member_ids'] ) && is_array( $_POST['email_member_ids'] ) ? wp_unslash( array_filter( $_POST['email_member_ids'], 'is_string' ) ) : array(),
			'email_attachment_ids'    => explode( ',', $fields['email_attachment_ids'] ?? '' ),
		);
	}

	/**
	 * Render categories grouped by communication type.
	 *
	 * @param string $current Current stored category label.
	 */
	private function render_category_options( string $current ): void {
		$definitions = $this->announcements->category_definitions();
		$known       = false;

		foreach ( $definitions as $definition ) {
			if ( sanitize_title( $current ) === sanitize_title( $definition['label'] ) || in_array( $current, $definition['aliases'], true ) ) {
				$known = true;
				break;
			}
		}

		if ( '' !== $current && ! $known ) {
			$this->render_select_option( $current, sprintf( __( '%s (categoria existente)', 'adam-membership' ), $current ), $current );
		}

		foreach (
			array(
				CommunicationCategoryRegistry::TYPE_MANDATORY => __( 'Comunicações obrigatórias', 'adam-membership' ),
				CommunicationCategoryRegistry::TYPE_OPTIONAL  => __( 'Comunicações opcionais', 'adam-membership' ),
			) as $type => $group_label
		) {
			printf( '<optgroup label="%s">', esc_attr( $group_label ) );

			foreach ( $definitions as $definition ) {
				if ( $type !== $definition['type'] ) {
					continue;
				}

				$selected_category = sanitize_title( $current ) === sanitize_title( $definition['label'] )
					|| in_array( $current, $definition['aliases'], true ) ? $definition['label'] : $current;
				$this->render_select_option( $definition['label'], $definition['label'], $selected_category );
			}

			echo '</optgroup>';
		}
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
			printf( '<div class="adam-admin-notice success adam-notice adam-notice--success"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="adam-admin-notice error adam-notice adam-notice--danger"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Ensure capability.
	 */
	private function ensure_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ADAM announcements.', 'adam-membership' ) );
		}
	}

	/**
	 * Build edit URL.
	 *
	 * @param int $announcement_id Optional announcement ID.
	 */
	private function edit_url( int $announcement_id = 0 ): string {
		$args = array( 'page' => self::EDIT_PAGE_SLUG );

		if ( $announcement_id > 0 ) {
			$args['announcement_id'] = $announcement_id;
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
			Announcement::STATUS_DRAFT     => __( 'Rascunho', 'adam-membership' ),
			Announcement::STATUS_SCHEDULED => __( 'Agendado', 'adam-membership' ),
			Announcement::STATUS_PUBLISHED => __( 'Publicado', 'adam-membership' ),
			Announcement::STATUS_ARCHIVED  => __( 'Arquivado', 'adam-membership' ),
			Announcement::STATUS_EXPIRED   => __( 'Expirado', 'adam-membership' ),
			default                        => $status,
		};
	}

	/**
	 * Get audience label.
	 *
	 * @param string $audience Audience key.
	 */
	private function audience_label( string $audience ): string {
		return match ( $audience ) {
			Announcement::AUDIENCE_ACTIVE_MEMBERS   => __( 'Sócios ativos', 'adam-membership' ),
			Announcement::AUDIENCE_RENEWAL_PENDING  => __( 'Renovação pendente', 'adam-membership' ),
			Announcement::AUDIENCE_EXPIRED_MEMBERS  => __( 'Quotas expiradas', 'adam-membership' ),
			Announcement::AUDIENCE_PENDING_MEMBERS  => __( 'Inscrições pendentes', 'adam-membership' ),
			Announcement::AUDIENCE_REJECTED_MEMBERS => __( 'Inscrições rejeitadas', 'adam-membership' ),
			Announcement::AUDIENCE_ADMINS           => __( 'Administradores / Direção', 'adam-membership' ),
			default                                 => __( 'Todos os sócios', 'adam-membership' ),
		};
	}

	/**
	 * Get an email recipient audience label.
	 *
	 * @param string $audience Email audience key.
	 */
	private function email_audience_label( string $audience ): string {
		return match ( $audience ) {
			Announcement::EMAIL_AUDIENCE_ALL_MEMBERS      => __( 'Todos os sócios', 'adam-membership' ),
			Announcement::EMAIL_AUDIENCE_ACTIVE_MEMBERS   => __( 'Apenas sócios ativos', 'adam-membership' ),
			Announcement::EMAIL_AUDIENCE_TEAM             => __( 'Equipa específica', 'adam-membership' ),
			Announcement::EMAIL_AUDIENCE_SPECIFIC_MEMBERS => __( 'Sócio(s) específico(s)', 'adam-membership' ),
			default                                       => __( 'Apenas membros inscritos nesta categoria', 'adam-membership' ),
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
	 * Format stored date.
	 *
	 * @param string $date Date.
	 */
	private function format_date( string $date ): string {
		if ( '' === $date ) {
			return '';
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $date, 0, 4 ) : $date;
	}
}
