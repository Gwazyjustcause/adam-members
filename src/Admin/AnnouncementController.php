<?php
/**
 * Announcement admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Announcement\Announcement;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Communication\CommunicationCategoryRegistry;

/**
 * Manages the admin-side Communication Centre.
 */
final class AnnouncementController {
	private const CAPABILITY     = 'manage_options';
	private const MENU_SLUG      = 'adam-membership-notices';
	private const EDIT_PAGE_SLUG = 'adam-membership-notice-edit';

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
	 */
	public function __construct( AnnouncementService $announcements ) {
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
	}

	/**
	 * Enqueue the notice editor behavior on its own screen.
	 */
	public function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::EDIT_PAGE_SLUG !== $page ) {
			return;
		}

		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/admin-announcements.js';

		wp_enqueue_script(
			'adam-membership-admin-announcements',
			ADAM_MEMBERSHIP_URL . 'assets/js/admin-announcements.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
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
		<div class="wrap adam-admin-wrap">
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
								<td><?php $this->render_badge( $this->priority_label( $announcement->priority() ), 'announcement-' . $announcement->priority() ); ?></td>
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
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-panel adam-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form">
					<input type="hidden" name="action" value="adam_membership_save_announcement">
					<input type="hidden" name="announcement_id" value="<?php echo esc_attr( (string) ( null !== $announcement ? $announcement->id() : 0 ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_save_announcement' ); ?>
					<div class="adam-admin-edit-grid">
						<label>
							<span><?php esc_html_e( 'Título', 'adam-membership' ); ?></span>
							<input type="text" name="title" value="<?php echo esc_attr( null !== $announcement ? $announcement->title() : '' ); ?>" required>
						</label>
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
									<?php $this->render_select_option( $priority, $this->priority_label( $priority ), null !== $announcement ? $announcement->priority() : Announcement::PRIORITY_INFO ); ?>
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
							<span><?php esc_html_e( 'Data de expiração', 'adam-membership' ); ?></span>
							<input type="date" name="expiry_date" value="<?php echo esc_attr( null !== $announcement ? $announcement->expiry_date() : '' ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Audiência', 'adam-membership' ); ?></span>
							<select name="target_audience">
								<?php foreach ( Announcement::audiences() as $audience ) : ?>
									<?php $this->render_select_option( $audience, $this->audience_label( $audience ), null !== $announcement ? $announcement->target_audience() : Announcement::AUDIENCE_ALL_MEMBERS ); ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Texto do botão', 'adam-membership' ); ?></span>
							<input type="text" name="action_label" value="<?php echo esc_attr( null !== $announcement ? $announcement->action_label() : '' ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'URL do botão', 'adam-membership' ); ?></span>
							<input type="url" name="action_url" value="<?php echo esc_attr( null !== $announcement ? $announcement->action_url() : '' ); ?>">
						</label>
					</div>

					<label class="adam-admin-edit-field adam-admin-edit-field-full">
						<span><?php esc_html_e( 'Resumo curto', 'adam-membership' ); ?></span>
						<textarea name="summary" rows="3"><?php echo esc_textarea( null !== $announcement ? $announcement->summary() : '' ); ?></textarea>
					</label>

					<label class="adam-admin-edit-field adam-admin-edit-field-full">
						<span><?php esc_html_e( 'Conteúdo completo', 'adam-membership' ); ?></span>
						<textarea name="content" rows="12"><?php echo esc_textarea( null !== $announcement ? $announcement->content() : '' ); ?></textarea>
					</label>

					<fieldset class="adam-admin-delivery-options">
						<legend><?php esc_html_e( 'Entrega', 'adam-membership' ); ?></legend>
						<label class="adam-admin-checkbox-field"><input type="checkbox" name="show_in_member_area" value="1" <?php checked( null !== $announcement ? $announcement->show_in_member_area() : true ); ?>> <?php esc_html_e( 'Mostrar na Área do Sócio', 'adam-membership' ); ?></label>
						<label class="adam-admin-checkbox-field"><input type="checkbox" name="send_email" value="1" data-adam-announcement-send-email <?php checked( null !== $announcement ? $announcement->send_email() : false ); ?>> <?php esc_html_e( 'Enviar Email', 'adam-membership' ); ?></label>
						<label class="adam-admin-checkbox-field"><input type="checkbox" name="pinned" value="1" <?php checked( null !== $announcement ? $announcement->pinned() : false ); ?>> <?php esc_html_e( 'Fixar no topo', 'adam-membership' ); ?></label>
						<label class="adam-admin-checkbox-field"><input type="checkbox" name="show_on_member_homepage" value="1" <?php checked( null !== $announcement ? $announcement->show_on_member_homepage() : false ); ?>> <?php esc_html_e( 'Mostrar na página inicial da Área do Sócio', 'adam-membership' ); ?></label>
					</fieldset>

					<fieldset class="adam-admin-email-recipients" data-adam-announcement-email-settings>
						<legend><?php esc_html_e( 'Destinatários do email', 'adam-membership' ); ?></legend>
						<p class="description"><?php esc_html_e( 'Nas categorias opcionais, as preferências de email dos membros são sempre respeitadas. As categorias obrigatórias não permitem exclusão.', 'adam-membership' ); ?></p>

						<?php if ( Announcement::EMAIL_AUDIENCE_LEGACY === $email_audience ) : ?>
							<label class="adam-admin-radio-field"><input type="radio" name="email_audience" value="<?php echo esc_attr( Announcement::EMAIL_AUDIENCE_LEGACY ); ?>" checked> <?php esc_html_e( 'Manter audiência original deste aviso', 'adam-membership' ); ?></label>
						<?php endif; ?>
						<?php foreach ( Announcement::email_audiences() as $audience ) : ?>
							<label class="adam-admin-radio-field"><input type="radio" name="email_audience" value="<?php echo esc_attr( $audience ); ?>" data-adam-announcement-email-audience <?php checked( $email_audience, $audience ); ?>> <?php echo esc_html( $this->email_audience_label( $audience ) ); ?></label>
						<?php endforeach; ?>

						<label class="adam-admin-email-recipient-detail" data-adam-announcement-email-team>
							<span><?php esc_html_e( 'Equipa específica', 'adam-membership' ); ?></span>
							<select name="email_team_id">
								<?php $this->render_select_option( '0', __( 'Selecionar equipa', 'adam-membership' ), (string) $email_team_id ); ?>
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
					</fieldset>

					<div class="adam-admin-actions">
						<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar aviso', 'adam-membership' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar à lista', 'adam-membership' ); ?></a>
					</div>
				</form>
			</div>

			<div class="adam-admin-panel adam-card">
				<h2><?php esc_html_e( 'Pré-visualização', 'adam-membership' ); ?></h2>
				<div class="adam-admin-detail-item">
					<span><?php echo esc_html( null !== $announcement ? $announcement->category() : __( 'Categoria', 'adam-membership' ) ); ?></span>
					<strong><?php echo esc_html( null !== $announcement ? $announcement->title() : __( 'Título do aviso', 'adam-membership' ) ); ?></strong>
				</div>
				<p><?php echo esc_html( null !== $announcement ? $announcement->summary() : __( 'O resumo será mostrado aqui.', 'adam-membership' ) ); ?></p>
				<div><?php echo wp_kses_post( null !== $announcement ? wpautop( $announcement->content() ) : '<p>' . esc_html__( 'O conteúdo completo será mostrado aqui.', 'adam-membership' ) . '</p>' ); ?></div>
			</div>
		</div>
		<?php
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
		return array(
			'title'                   => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
			'summary'                 => isset( $_POST['summary'] ) ? wp_unslash( $_POST['summary'] ) : '',
			'content'                 => isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '',
			'category'                => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '',
			'priority'                => isset( $_POST['priority'] ) ? wp_unslash( $_POST['priority'] ) : '',
			'status'                  => isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : '',
			'publish_date'            => isset( $_POST['publish_date'] ) ? wp_unslash( $_POST['publish_date'] ) : '',
			'expiry_date'             => isset( $_POST['expiry_date'] ) ? wp_unslash( $_POST['expiry_date'] ) : '',
			'target_audience'         => isset( $_POST['target_audience'] ) ? wp_unslash( $_POST['target_audience'] ) : '',
			'show_in_member_area'     => isset( $_POST['show_in_member_area'] ),
			'show_on_member_homepage' => isset( $_POST['show_on_member_homepage'] ),
			'pinned'                  => isset( $_POST['pinned'] ),
			'action_label'            => isset( $_POST['action_label'] ) ? wp_unslash( $_POST['action_label'] ) : '',
			'action_url'              => isset( $_POST['action_url'] ) ? wp_unslash( $_POST['action_url'] ) : '',
			'send_email'              => isset( $_POST['send_email'] ),
			'email_audience'          => isset( $_POST['email_audience'] ) ? wp_unslash( $_POST['email_audience'] ) : Announcement::EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS,
			'email_team_id'           => isset( $_POST['email_team_id'] ) ? absint( wp_unslash( $_POST['email_team_id'] ) ) : 0,
			'email_member_ids'        => isset( $_POST['email_member_ids'] ) && is_array( $_POST['email_member_ids'] ) ? wp_unslash( $_POST['email_member_ids'] ) : array(),
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
	 * Get priority label.
	 *
	 * @param string $priority Priority key.
	 */
	private function priority_label( string $priority ): string {
		return match ( $priority ) {
			Announcement::PRIORITY_IMPORTANT => __( 'Importante', 'adam-membership' ),
			Announcement::PRIORITY_URGENT    => __( 'Urgente', 'adam-membership' ),
			default                          => __( 'Informacao', 'adam-membership' ),
		};
	}

	/**
	 * Get audience label.
	 *
	 * @param string $audience Audience key.
	 */
	private function audience_label( string $audience ): string {
		return match ( $audience ) {
			Announcement::AUDIENCE_ACTIVE_MEMBERS   => __( 'Socios ativos', 'adam-membership' ),
			Announcement::AUDIENCE_RENEWAL_PENDING  => __( 'Renovacao pendente', 'adam-membership' ),
			Announcement::AUDIENCE_EXPIRED_MEMBERS  => __( 'Quotas expiradas', 'adam-membership' ),
			Announcement::AUDIENCE_PENDING_MEMBERS  => __( 'Inscrições pendentes', 'adam-membership' ),
			Announcement::AUDIENCE_REJECTED_MEMBERS => __( 'Inscrições rejeitadas', 'adam-membership' ),
			Announcement::AUDIENCE_ADMINS           => __( 'Admins / Direcao', 'adam-membership' ),
			default                                 => __( 'Todos os socios', 'adam-membership' ),
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
