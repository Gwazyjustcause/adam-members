<?php
/**
 * Rewards admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Reward\Reward;
use AdamMembership\Reward\RewardRedemption;
use AdamMembership\Reward\RewardService;

/**
 * Manages admin-side rewards.
 */
final class RewardController {
	private const CAPABILITY     = 'manage_options';
	private const MENU_SLUG      = 'adam-membership-rewards';
	private const EDIT_PAGE_SLUG = 'adam-membership-reward-edit';

	private RewardService $rewards;
	private MemberRepository $members;

	public function __construct( RewardService $rewards, MemberRepository $members ) {
		$this->rewards = $rewards;
		$this->members = $members;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_save_reward', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adam_membership_delete_reward', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_adam_membership_approve_reward_redemption', array( $this, 'handle_approve_redemption' ) );
		add_action( 'admin_post_adam_membership_reject_reward_redemption', array( $this, 'handle_reject_redemption' ) );
		add_action( 'admin_post_adam_membership_deliver_reward_redemption', array( $this, 'handle_deliver_redemption' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			esc_html__( 'Recompensas', 'adam-membership' ),
			esc_html__( 'Recompensas', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			'adam-membership',
			esc_html__( 'Editar Recompensa', 'adam-membership' ),
			esc_html__( 'Editar Recompensa', 'adam-membership' ),
			self::CAPABILITY,
			self::EDIT_PAGE_SLUG,
			array( $this, 'render_edit_page' )
		);
	}

	public function render_list_page(): void {
		$this->ensure_can_manage();
		$filters     = $this->current_reward_filters();
		$rewards     = $this->rewards->admin_rewards( $filters );
		$redemptions = $this->rewards->admin_redemptions( $this->current_redemption_filters() );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php esc_html_e( 'Recompensas', 'adam-membership' ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-actions">
				<a class="button button-primary" href="<?php echo esc_url( $this->edit_url() ); ?>"><?php esc_html_e( 'Nova recompensa', 'adam-membership' ); ?></a>
			</div>

			<form method="get" class="adam-admin-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label><span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
				<label><span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span>
					<select name="category">
						<?php $this->render_select_option( '', __( 'Todas', 'adam-membership' ), $filters['category'] ); ?>
						<?php foreach ( $this->rewards->categories() as $category ) : ?>
							<?php $this->render_select_option( $category, $category, $filters['category'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></span>
					<select name="type">
						<?php $this->render_select_option( '', __( 'Todos', 'adam-membership' ), $filters['type'] ); ?>
						<?php foreach ( $this->rewards->type_labels() as $type => $label ) : ?>
							<?php $this->render_select_option( $type, $label, $filters['type'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Aplicar', 'adam-membership' ); ?></button>
			</form>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Catálogo de recompensas', 'adam-membership' ); ?></h2>
				<?php if ( array() === $rewards ) : ?>
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem recompensas.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Recompensa', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Disponibilidade', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $rewards as $reward ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $reward->name() ); ?></strong><br><small><?php echo esc_html( $reward->description() ); ?></small></td>
									<td><?php echo esc_html( $reward->category() ); ?></td>
									<td><?php echo esc_html( $this->rewards->type_labels()[ $reward->type() ] ?? $reward->type() ); ?></td>
									<td><?php echo esc_html( (string) $reward->points_cost() ); ?></td>
									<td><?php echo esc_html( $reward->active() ? __( 'Ativa', 'adam-membership' ) : __( 'Inativa', 'adam-membership' ) ); ?></td>
									<td class="adam-admin-row-actions">
										<a class="button button-small" href="<?php echo esc_url( $this->edit_url( $reward->id() ) ); ?>"><?php esc_html_e( 'Editar', 'adam-membership' ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Eliminar esta recompensa?', 'adam-membership' ) ); ?>');">
											<input type="hidden" name="action" value="adam_membership_delete_reward">
											<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) $reward->id() ); ?>">
											<?php wp_nonce_field( 'adam_membership_delete_reward_' . $reward->id() ); ?>
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'adam-membership' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Pedidos de resgate', 'adam-membership' ); ?></h2>
				<?php if ( array() === $redemptions ) : ?>
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem pedidos de resgate.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Recompensa', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $redemptions as $redemption ) : ?>
								<?php $member = $this->members->find( $redemption->member_id() ); ?>
								<tr>
									<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></td>
									<td><?php echo esc_html( $redemption->reward_name() ); ?></td>
									<td><?php echo esc_html( $this->rewards->redemption_status_labels()[ $redemption->status() ] ?? $redemption->status() ); ?></td>
									<td><?php echo esc_html( (string) $redemption->points_cost() ); ?></td>
									<td><?php echo esc_html( $this->format_datetime( $redemption->created_at() ) ); ?></td>
									<td class="adam-admin-row-actions">
										<?php if ( RewardRedemption::STATUS_PENDING === $redemption->status() ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
												<input type="hidden" name="action" value="adam_membership_approve_reward_redemption">
												<input type="hidden" name="redemption_id" value="<?php echo esc_attr( (string) $redemption->id() ); ?>">
												<?php wp_nonce_field( 'adam_membership_approve_reward_redemption_' . $redemption->id() ); ?>
												<button type="submit" class="button button-small"><?php esc_html_e( 'Aprovar', 'adam-membership' ); ?></button>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
												<input type="hidden" name="action" value="adam_membership_reject_reward_redemption">
												<input type="hidden" name="redemption_id" value="<?php echo esc_attr( (string) $redemption->id() ); ?>">
												<?php wp_nonce_field( 'adam_membership_reject_reward_redemption_' . $redemption->id() ); ?>
												<button type="submit" class="button button-small"><?php esc_html_e( 'Rejeitar', 'adam-membership' ); ?></button>
											</form>
										<?php elseif ( RewardRedemption::STATUS_APPROVED === $redemption->status() ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
												<input type="hidden" name="action" value="adam_membership_deliver_reward_redemption">
												<input type="hidden" name="redemption_id" value="<?php echo esc_attr( (string) $redemption->id() ); ?>">
												<?php wp_nonce_field( 'adam_membership_deliver_reward_redemption_' . $redemption->id() ); ?>
												<button type="submit" class="button button-small"><?php esc_html_e( 'Marcar como entregue', 'adam-membership' ); ?></button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function render_edit_page(): void {
		$this->ensure_can_manage();
		$reward = $this->current_reward();
		$title  = null === $reward ? __( 'Nova recompensa', 'adam-membership' ) : __( 'Editar recompensa', 'adam-membership' );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form" enctype="multipart/form-data">
					<input type="hidden" name="action" value="adam_membership_save_reward">
					<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) ( null !== $reward ? $reward->id() : 0 ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_save_reward' ); ?>
					<div class="adam-admin-edit-grid">
						<label><span><?php esc_html_e( 'Nome', 'adam-membership' ); ?></span><input type="text" name="name" required value="<?php echo esc_attr( null !== $reward ? $reward->name() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span><select name="category"><?php foreach ( $this->rewards->categories() as $category ) : ?><?php $this->render_select_option( $category, $category, null !== $reward ? $reward->category() : '' ); ?><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></span><select name="type"><?php foreach ( $this->rewards->type_labels() as $type => $label ) : ?><?php $this->render_select_option( $type, $label, null !== $reward ? $reward->type() : Reward::TYPE_PERMANENT_UNLOCK ); ?><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Raridade', 'adam-membership' ); ?></span><select name="rarity"><?php foreach ( $this->rewards->rarity_labels() as $rarity => $label ) : ?><?php $this->render_select_option( $rarity, $label, null !== $reward ? $reward->rarity() : Reward::RARITY_COMMON ); ?><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'Pontos necessários', 'adam-membership' ); ?></span><input type="number" min="0" name="points_cost" value="<?php echo esc_attr( null !== $reward ? (string) $reward->points_cost() : '0' ); ?>"></label>
						<label><span><?php esc_html_e( 'Disponibilidade', 'adam-membership' ); ?></span><input type="text" name="availability_label" value="<?php echo esc_attr( null !== $reward ? $reward->availability_label() : __( 'Disponível', 'adam-membership' ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Imagem', 'adam-membership' ); ?></span><input type="file" name="reward_image" accept=".jpg,.jpeg,.png,.webp"></label>
						<label><span><?php esc_html_e( 'URL da imagem', 'adam-membership' ); ?></span><input type="url" name="image_url" value="<?php echo esc_attr( null !== $reward ? $reward->image_url() : '' ); ?>"></label>
					</div>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descrição', 'adam-membership' ); ?></span><textarea name="description" rows="4"><?php echo esc_textarea( null !== $reward ? $reward->description() : '' ); ?></textarea></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Valor/identificador interno', 'adam-membership' ); ?></span><input type="text" name="reward_value" value="<?php echo esc_attr( null !== $reward ? $reward->reward_value() : '' ); ?>"></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Revelação da recompensa mistério', 'adam-membership' ); ?></span><textarea name="mystery_reveal_text" rows="3"><?php echo esc_textarea( null !== $reward ? $reward->mystery_reveal_text() : '' ); ?></textarea></label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="active" value="1" <?php checked( null !== $reward ? $reward->active() : true ); ?>> <?php esc_html_e( 'Recompensa ativa', 'adam-membership' ); ?></label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="approval_required" value="1" <?php checked( null !== $reward ? $reward->approval_required() : true ); ?>> <?php esc_html_e( 'Exige aprovação administrativa', 'adam-membership' ); ?></label>
					<div class="adam-admin-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar recompensa', 'adam-membership' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar à lista', 'adam-membership' ); ?></a>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_save(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_reward' );
		$reward_id = isset( $_POST['reward_id'] ) ? absint( wp_unslash( $_POST['reward_id'] ) ) : 0;
		$file      = isset( $_FILES['reward_image'] ) && is_array( $_FILES['reward_image'] ) ? $_FILES['reward_image'] : array();
		$result    = $this->rewards->save_reward( $this->posted_reward_data(), $file, $reward_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), $this->edit_url( $reward_id ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Recompensa guardada com sucesso.', 'adam-membership' ), $this->edit_url( $result->id() ) );
	}

	public function handle_delete(): void {
		$this->ensure_can_manage();
		$reward_id = isset( $_POST['reward_id'] ) ? absint( wp_unslash( $_POST['reward_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_delete_reward_' . $reward_id );
		$this->rewards->delete_reward( $reward_id );
		$this->redirect_with_notice( 'adam_message', __( 'Recompensa eliminada.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	public function handle_approve_redemption(): void {
		$this->ensure_can_manage();
		$redemption_id = isset( $_POST['redemption_id'] ) ? absint( wp_unslash( $_POST['redemption_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_approve_reward_redemption_' . $redemption_id );
		$result = $this->rewards->approve_redemption( $redemption_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Pedido aprovado com sucesso.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	public function handle_reject_redemption(): void {
		$this->ensure_can_manage();
		$redemption_id = isset( $_POST['redemption_id'] ) ? absint( wp_unslash( $_POST['redemption_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_reject_reward_redemption_' . $redemption_id );
		$result = $this->rewards->reject_redemption( $redemption_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Pedido rejeitado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	public function handle_deliver_redemption(): void {
		$this->ensure_can_manage();
		$redemption_id = isset( $_POST['redemption_id'] ) ? absint( wp_unslash( $_POST['redemption_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_deliver_reward_redemption_' . $redemption_id );
		$result = $this->rewards->mark_delivered( $redemption_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Recompensa marcada como entregue.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	/**
	 * @return array{search:string,category:string,type:string}
	 */
	private function current_reward_filters(): array {
		return array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'category' => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '',
			'type'     => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
		);
	}

	/**
	 * @return array{status:string}
	 */
	private function current_redemption_filters(): array {
		return array(
			'status' => isset( $_GET['redemption_status'] ) ? sanitize_key( wp_unslash( $_GET['redemption_status'] ) ) : '',
		);
	}

	private function current_reward(): ?Reward {
		$reward_id = isset( $_GET['reward_id'] ) ? absint( wp_unslash( $_GET['reward_id'] ) ) : 0;

		return $reward_id > 0 ? $this->rewards->repository()->find_reward( $reward_id ) : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function posted_reward_data(): array {
		return array(
			'name'               => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'description'        => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'category'           => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '',
			'type'               => isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : '',
			'rarity'             => isset( $_POST['rarity'] ) ? wp_unslash( $_POST['rarity'] ) : '',
			'points_cost'        => isset( $_POST['points_cost'] ) ? wp_unslash( $_POST['points_cost'] ) : 0,
			'image_url'          => isset( $_POST['image_url'] ) ? wp_unslash( $_POST['image_url'] ) : '',
			'availability_label' => isset( $_POST['availability_label'] ) ? wp_unslash( $_POST['availability_label'] ) : '',
			'active'             => isset( $_POST['active'] ),
			'approval_required'  => isset( $_POST['approval_required'] ),
			'mystery_reveal_text' => isset( $_POST['mystery_reveal_text'] ) ? wp_unslash( $_POST['mystery_reveal_text'] ) : '',
			'reward_value'       => isset( $_POST['reward_value'] ) ? wp_unslash( $_POST['reward_value'] ) : '',
		);
	}

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

	private function ensure_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissão para gerir recompensas ADAM.', 'adam-membership' ) );
		}
	}

	private function edit_url( int $reward_id = 0 ): string {
		$args = array( 'page' => self::EDIT_PAGE_SLUG );

		if ( $reward_id > 0 ) {
			$args['reward_id'] = $reward_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function redirect_with_notice( string $key, string $message, string $redirect ): void {
		wp_safe_redirect( add_query_arg( $key, $message, $redirect ) );
		exit;
	}

	private function render_select_option( string $value, string $label, string $current ): void {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? $datetime : wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
	}
}
