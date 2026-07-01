<?php
/**
 * Rewards admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_adam_membership_save_reward', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adam_membership_delete_reward', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_adam_membership_approve_reward_redemption', array( $this, 'handle_approve_redemption' ) );
		add_action( 'admin_post_adam_membership_reject_reward_redemption', array( $this, 'handle_reject_redemption' ) );
		add_action( 'admin_post_adam_membership_deliver_reward_redemption', array( $this, 'handle_deliver_redemption' ) );
	}

	/**
	 * Enqueue reward editor assets on plugin admin pages.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::EDIT_PAGE_SLUG ) ) {
			return;
		}

		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/admin-reward-editor.js';

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script(
			'adam-membership-admin-reward-editor',
			ADAM_MEMBERSHIP_URL . 'assets/js/admin-reward-editor.js',
			array( 'jquery', 'wp-color-picker' ),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);
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
				<h2><?php esc_html_e( 'Catalogo de recompensas', 'adam-membership' ); ?></h2>
				<?php if ( array() === $rewards ) : ?>
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda nao existem recompensas.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Recompensa', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Disponibilidade', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Acoes', 'adam-membership' ); ?></th></tr></thead>
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
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda nao existem pedidos de resgate.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Socio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Recompensa', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Acoes', 'adam-membership' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $redemptions as $redemption ) : ?>
								<?php $member = $this->members->find( $redemption->member_id() ); ?>
								<tr>
									<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Socio removido', 'adam-membership' ) ); ?></td>
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
		$reward             = $this->current_reward();
		$title              = null === $reward ? __( 'Nova recompensa', 'adam-membership' ) : __( 'Editar recompensa', 'adam-membership' );
		$resolved_style     = null !== $reward ? $this->rewards->reward_visual_style( $reward ) : $this->rewards->default_reward_visual_style();
		$reward_name        = null !== $reward ? $reward->name() : '';
		$reward_description = null !== $reward ? $reward->description() : '';
		$reward_points      = null !== $reward ? $reward->points_cost() : 25;
		$reward_image       = null !== $reward ? $reward->image_url() : '';
		$reward_rarity      = null !== $reward ? $reward->rarity() : Reward::RARITY_COMMON;
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar adam-admin-titlebar--split">
				<div>
					<h1><?php echo esc_html( $title ); ?></h1>
					<p><?php esc_html_e( 'Editor visual para desenhar o cartao da recompensa sem escrever CSS.', 'adam-membership' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar a lista', 'adam-membership' ); ?></a>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-panel adam-reward-editor-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form adam-reward-editor" enctype="multipart/form-data">
					<input type="hidden" name="action" value="adam_membership_save_reward">
					<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) ( null !== $reward ? $reward->id() : 0 ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_save_reward' ); ?>

					<section class="adam-reward-editor__section">
						<div class="adam-admin-edit-grid">
							<label><span><?php esc_html_e( 'Nome', 'adam-membership' ); ?></span><input type="text" name="name" required value="<?php echo esc_attr( $reward_name ); ?>" data-adam-preview-name></label>
							<label><span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span><select name="category" data-adam-preview-category><?php foreach ( $this->rewards->categories() as $category ) : ?><?php $this->render_select_option( $category, $category, null !== $reward ? $reward->category() : '' ); ?><?php endforeach; ?></select></label>
							<label><span><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></span><select name="type"><?php foreach ( $this->rewards->type_labels() as $type => $label ) : ?><?php $this->render_select_option( $type, $label, null !== $reward ? $reward->type() : Reward::TYPE_PERMANENT_UNLOCK ); ?><?php endforeach; ?></select></label>
							<label><span><?php esc_html_e( 'Raridade', 'adam-membership' ); ?></span><select name="rarity" data-adam-preview-rarity><?php foreach ( $this->rewards->rarity_labels() as $rarity => $label ) : ?><?php $this->render_select_option( $rarity, $label, $reward_rarity ); ?><?php endforeach; ?></select></label>
							<label><span><?php esc_html_e( 'Pontos necessarios', 'adam-membership' ); ?></span><input type="number" min="0" name="points_cost" value="<?php echo esc_attr( (string) $reward_points ); ?>" data-adam-preview-points></label>
							<label><span><?php esc_html_e( 'Disponibilidade', 'adam-membership' ); ?></span><input type="text" name="availability_label" value="<?php echo esc_attr( null !== $reward ? $reward->availability_label() : __( 'Disponivel', 'adam-membership' ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Arte local', 'adam-membership' ); ?></span><input type="file" name="reward_image" accept=".jpg,.jpeg,.png,.webp" data-adam-preview-image-upload></label>
							<label class="adam-reward-editor__media-field"><span><?php esc_html_e( 'Imagem decorativa', 'adam-membership' ); ?></span><input type="url" name="image_url" value="<?php echo esc_attr( $reward_image ); ?>" data-adam-preview-image><button type="button" class="button" data-adam-media-target="input[name='image_url']"><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></button></label>
						</div>
					</section>

					<div class="adam-reward-editor__workspace">
						<div class="adam-reward-editor__sidebar">
							<section class="adam-reward-editor__section">
								<h2><?php esc_html_e( 'Fundo', 'adam-membership' ); ?></h2>
								<div class="adam-reward-editor__radio-group">
									<?php $this->render_style_mode_option( 'gradient', __( 'Gradiente', 'adam-membership' ), (string) $resolved_style['background_mode'] ); ?>
									<?php $this->render_style_mode_option( 'solid', __( 'Cor solida', 'adam-membership' ), (string) $resolved_style['background_mode'] ); ?>
									<?php $this->render_style_mode_option( 'image', __( 'Imagem', 'adam-membership' ), (string) $resolved_style['background_mode'] ); ?>
								</div>
								<div class="adam-reward-editor__field-grid adam-reward-editor__field-grid--compact">
									<label><span><?php esc_html_e( 'Cor base', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[background_color]" value="<?php echo esc_attr( (string) $resolved_style['background_color'] ); ?>" data-adam-style="background_color"></label>
									<label><span><?php esc_html_e( 'Cor secundaria', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[background_color_secondary]" value="<?php echo esc_attr( (string) $resolved_style['background_color_secondary'] ); ?>" data-adam-style="background_color_secondary"></label>
									<label><span><?php esc_html_e( 'Angulo', 'adam-membership' ); ?></span><input type="range" min="0" max="360" name="visual_style[gradient_angle]" value="<?php echo esc_attr( (string) $resolved_style['gradient_angle'] ); ?>" data-adam-style="gradient_angle"><small data-adam-value-for="gradient_angle"><?php echo esc_html( (string) $resolved_style['gradient_angle'] ); ?>deg</small></label>
									<label><span><?php esc_html_e( 'Padrao', 'adam-membership' ); ?></span><select name="visual_style[pattern]" data-adam-style="pattern"><?php $this->render_pattern_options( (string) $resolved_style['pattern'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Opacidade do padrao', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[pattern_opacity]" value="<?php echo esc_attr( (string) $resolved_style['pattern_opacity'] ); ?>" data-adam-style="pattern_opacity"><small data-adam-value-for="pattern_opacity"><?php echo esc_html( (string) $resolved_style['pattern_opacity'] ); ?>%</small></label>
								</div>
								<label class="adam-reward-editor__media-field"><span><?php esc_html_e( 'Imagem de fundo / textura', 'adam-membership' ); ?></span><input type="url" name="visual_style[background_image_url]" value="<?php echo esc_attr( (string) $resolved_style['background_image_url'] ); ?>" data-adam-style="background_image_url"><button type="button" class="button" data-adam-media-target="input[name='visual_style[background_image_url]']"><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></button></label>
								<label><span><?php esc_html_e( 'Opacidade da imagem de fundo', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[background_image_opacity]" value="<?php echo esc_attr( (string) $resolved_style['background_image_opacity'] ); ?>" data-adam-style="background_image_opacity"><small data-adam-value-for="background_image_opacity"><?php echo esc_html( (string) $resolved_style['background_image_opacity'] ); ?>%</small></label>
							</section>

							<section class="adam-reward-editor__section">
								<h2><?php esc_html_e( 'Elementos', 'adam-membership' ); ?></h2>
								<div class="adam-reward-editor__field-grid adam-reward-editor__field-grid--compact">
									<label><span><?php esc_html_e( 'Posicao da imagem', 'adam-membership' ); ?></span><select name="visual_style[card_image_position]" data-adam-style="card_image_position"><?php $this->render_image_position_options( (string) $resolved_style['card_image_position'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Opacidade da imagem', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[card_image_opacity]" value="<?php echo esc_attr( (string) $resolved_style['card_image_opacity'] ); ?>" data-adam-style="card_image_opacity"><small data-adam-value-for="card_image_opacity"><?php echo esc_html( (string) $resolved_style['card_image_opacity'] ); ?>%</small></label>
								</div>
								<div class="adam-reward-editor__shape-toolbar">
									<button type="button" class="button" data-adam-add-shape="circle"><?php esc_html_e( 'Adicionar circulo', 'adam-membership' ); ?></button>
									<button type="button" class="button" data-adam-add-shape="square"><?php esc_html_e( 'Adicionar quadrado', 'adam-membership' ); ?></button>
									<button type="button" class="button" data-adam-add-shape="line"><?php esc_html_e( 'Adicionar linha', 'adam-membership' ); ?></button>
								</div>
								<input type="hidden" name="visual_style[shapes]" value="<?php echo esc_attr( wp_json_encode( $resolved_style['shapes'] ) ); ?>" data-adam-shapes-input>
								<div class="adam-reward-editor__shapes" data-adam-shapes-list>
									<?php foreach ( $resolved_style['shapes'] as $index => $shape ) : ?>
										<?php $this->render_shape_row( (int) $index, (array) $shape ); ?>
									<?php endforeach; ?>
								</div>
							</section>
						</div>

						<div class="adam-reward-editor__preview-panel">
							<section class="adam-reward-editor__section adam-reward-editor__section--preview">
								<h2><?php esc_html_e( 'Pre-visualizacao', 'adam-membership' ); ?></h2>
								<p><?php esc_html_e( 'Visualiza o resultado final do cartao enquanto ajustas gradientes, moldura, badges e elementos decorativos.', 'adam-membership' ); ?></p>
								<div class="adam-reward-editor__preview-stage">
									<article class="adam-reward-card adam-reward-card--editor-preview adam-reward-card--<?php echo esc_attr( $reward_rarity ); ?> adam-reward-card--badge-<?php echo esc_attr( sanitize_html_class( (string) $resolved_style['badge_style'] ) ); ?> adam-reward-card--effect-<?php echo esc_attr( sanitize_html_class( 'auto' === (string) $resolved_style['rarity_effect'] ? $reward_rarity : (string) $resolved_style['rarity_effect'] ) ); ?>" style="<?php echo esc_attr( $this->preview_inline_style( $resolved_style ) ); ?>" data-adam-reward-preview>
										<div class="adam-reward-card__background"></div>
										<div class="adam-reward-card__pattern adam-reward-card__pattern--<?php echo esc_attr( sanitize_html_class( (string) $resolved_style['pattern'] ) ); ?>" data-adam-reward-preview-pattern></div>
										<div class="adam-reward-card__backdrop"<?php echo '' !== (string) $resolved_style['background_image_url'] ? ' style="background-image:url(' . esc_url( (string) $resolved_style['background_image_url'] ) . ');"' : ''; ?> data-adam-reward-preview-backdrop></div>
										<div class="adam-reward-card__art adam-reward-card__art--<?php echo esc_attr( sanitize_html_class( (string) $resolved_style['card_image_position'] ) ); ?>" data-adam-reward-preview-art-wrap>
											<img src="<?php echo esc_url( $reward_image ); ?>" alt=""<?php echo '' === $reward_image ? ' hidden' : ''; ?> data-adam-reward-preview-art>
										</div>
										<div class="adam-reward-card__shapes" data-adam-reward-preview-shapes>
											<?php foreach ( $resolved_style['shapes'] as $shape ) : ?>
												<?php $this->render_shape_preview( (array) $shape ); ?>
											<?php endforeach; ?>
										</div>
										<div class="adam-reward-card__content">
											<div class="adam-reward-card__meta">
												<span class="adam-badge adam-reward-rarity adam-reward-rarity--<?php echo esc_attr( $reward_rarity ); ?>" data-adam-reward-preview-rarity-badge><?php echo esc_html( $this->rewards->rarity_labels()[ $reward_rarity ] ?? __( 'Comum', 'adam-membership' ) ); ?></span>
												<span class="adam-announcement-category" data-adam-reward-preview-category><?php echo esc_html( null !== $reward ? $reward->category() : __( 'Cartao Digital', 'adam-membership' ) ); ?></span>
											</div>
											<div class="adam-reward-card__body">
												<h4 data-adam-reward-preview-name><?php echo esc_html( '' !== $reward_name ? $reward_name : __( 'Nome da recompensa', 'adam-membership' ) ); ?></h4>
												<p data-adam-reward-preview-description><?php echo esc_html( '' !== $reward_description ? $reward_description : __( 'Descricao curta do premio, titulo ou cosmetico apresentado no catalogo.', 'adam-membership' ) ); ?></p>
											</div>
											<div class="adam-reward-card__stats">
												<div>
													<span><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></span>
													<strong data-adam-reward-preview-points><?php echo esc_html( (string) $reward_points ); ?></strong>
												</div>
											</div>
										</div>
									</article>
								</div>
							</section>
						</div>

						<div class="adam-reward-editor__inspector">
							<section class="adam-reward-editor__section">
								<h2><?php esc_html_e( 'Estilo do cartao', 'adam-membership' ); ?></h2>
								<div class="adam-reward-editor__field-grid">
									<label><span><?php esc_html_e( 'Cor da moldura', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[border_color]" value="<?php echo esc_attr( (string) $resolved_style['border_color'] ); ?>" data-adam-style="border_color"></label>
									<label><span><?php esc_html_e( 'Espessura da moldura', 'adam-membership' ); ?></span><input type="range" min="1" max="8" name="visual_style[border_width]" value="<?php echo esc_attr( (string) $resolved_style['border_width'] ); ?>" data-adam-style="border_width"><small data-adam-value-for="border_width"><?php echo esc_html( (string) $resolved_style['border_width'] ); ?>px</small></label>
									<label><span><?php esc_html_e( 'Raio dos cantos', 'adam-membership' ); ?></span><input type="range" min="8" max="36" name="visual_style[border_radius]" value="<?php echo esc_attr( (string) $resolved_style['border_radius'] ); ?>" data-adam-style="border_radius"><small data-adam-value-for="border_radius"><?php echo esc_html( (string) $resolved_style['border_radius'] ); ?>px</small></label>
									<label><span><?php esc_html_e( 'Cor de destaque', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[accent_color]" value="<?php echo esc_attr( (string) $resolved_style['accent_color'] ); ?>" data-adam-style="accent_color"></label>
									<label><span><?php esc_html_e( 'Cor do texto', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[text_color]" value="<?php echo esc_attr( (string) $resolved_style['text_color'] ); ?>" data-adam-style="text_color"></label>
									<label><span><?php esc_html_e( 'Cor do texto secundario', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[muted_text_color]" value="<?php echo esc_attr( (string) $resolved_style['muted_text_color'] ); ?>" data-adam-style="muted_text_color"></label>
									<label><span><?php esc_html_e( 'Estilo dos badges', 'adam-membership' ); ?></span><select name="visual_style[badge_style]" data-adam-style="badge_style"><?php $this->render_badge_style_options( (string) $resolved_style['badge_style'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Efeito de raridade', 'adam-membership' ); ?></span><select name="visual_style[rarity_effect]" data-adam-style="rarity_effect"><?php $this->render_rarity_effect_options( (string) $resolved_style['rarity_effect'] ); ?></select></label>
								</div>
							</section>

							<section class="adam-reward-editor__section">
								<h2><?php esc_html_e( 'Detalhes da recompensa', 'adam-membership' ); ?></h2>
								<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descricao', 'adam-membership' ); ?></span><textarea name="description" rows="5" data-adam-preview-description><?php echo esc_textarea( $reward_description ); ?></textarea></label>
								<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Valor/identificador interno', 'adam-membership' ); ?></span><input type="text" name="reward_value" value="<?php echo esc_attr( null !== $reward ? $reward->reward_value() : '' ); ?>"></label>
								<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Revelacao da recompensa misterio', 'adam-membership' ); ?></span><textarea name="mystery_reveal_text" rows="3"><?php echo esc_textarea( null !== $reward ? $reward->mystery_reveal_text() : '' ); ?></textarea></label>
								<label class="adam-admin-checkbox-field"><input type="checkbox" name="active" value="1" <?php checked( null !== $reward ? $reward->active() : true ); ?>> <?php esc_html_e( 'Recompensa ativa', 'adam-membership' ); ?></label>
								<label class="adam-admin-checkbox-field"><input type="checkbox" name="approval_required" value="1" <?php checked( null !== $reward ? $reward->approval_required() : true ); ?>> <?php esc_html_e( 'Exige aprovacao administrativa', 'adam-membership' ); ?></label>
							</section>
						</div>
					</div>

					<div class="adam-admin-actions adam-reward-editor__actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar recompensa', 'adam-membership' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Cancelar', 'adam-membership' ); ?></a>
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
			'name'                => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'description'         => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
			'category'            => isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '',
			'type'                => isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : '',
			'rarity'              => isset( $_POST['rarity'] ) ? wp_unslash( $_POST['rarity'] ) : '',
			'points_cost'         => isset( $_POST['points_cost'] ) ? wp_unslash( $_POST['points_cost'] ) : 0,
			'image_url'           => isset( $_POST['image_url'] ) ? wp_unslash( $_POST['image_url'] ) : '',
			'availability_label'  => isset( $_POST['availability_label'] ) ? wp_unslash( $_POST['availability_label'] ) : '',
			'active'              => isset( $_POST['active'] ),
			'approval_required'   => isset( $_POST['approval_required'] ),
			'mystery_reveal_text' => isset( $_POST['mystery_reveal_text'] ) ? wp_unslash( $_POST['mystery_reveal_text'] ) : '',
			'reward_value'        => isset( $_POST['reward_value'] ) ? wp_unslash( $_POST['reward_value'] ) : '',
			'visual_style'        => isset( $_POST['visual_style'] ) ? wp_unslash( $_POST['visual_style'] ) : array(),
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
			wp_die( esc_html__( 'Nao tem permissao para gerir recompensas ADAM.', 'adam-membership' ) );
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

	private function render_pattern_options( string $current ): void {
		$options = array(
			'none'     => __( 'Sem padrao', 'adam-membership' ),
			'grid'     => __( 'Grelha', 'adam-membership' ),
			'carbon'   => __( 'Carbono', 'adam-membership' ),
			'diagonal' => __( 'Diagonal', 'adam-membership' ),
			'dots'     => __( 'Pontos', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_image_position_options( string $current ): void {
		$options = array(
			'top-left'     => __( 'Topo esquerdo', 'adam-membership' ),
			'top-right'    => __( 'Topo direito', 'adam-membership' ),
			'center'       => __( 'Centro', 'adam-membership' ),
			'bottom-right' => __( 'Inferior direito', 'adam-membership' ),
			'bottom-left'  => __( 'Inferior esquerdo', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_badge_style_options( string $current ): void {
		$options = array(
			'soft'    => __( 'Suave', 'adam-membership' ),
			'outline' => __( 'Contorno', 'adam-membership' ),
			'glow'    => __( 'Brilho', 'adam-membership' ),
			'solid'   => __( 'Solido', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_rarity_effect_options( string $current ): void {
		$options = array(
			'auto'     => __( 'Automatico pela raridade', 'adam-membership' ),
			'subtle'   => __( 'Suave', 'adam-membership' ),
			'metallic' => __( 'Metalico', 'adam-membership' ),
			'glow'     => __( 'Brilho', 'adam-membership' ),
			'none'     => __( 'Sem efeito', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_style_mode_option( string $value, string $label, string $current ): void {
		?>
		<label class="adam-reward-editor__radio-option">
			<input type="radio" name="visual_style[background_mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> data-adam-style="background_mode">
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * @param array<string, mixed> $shape Shape configuration.
	 */
	private function render_shape_row( int $index, array $shape ): void {
		?>
		<div class="adam-reward-editor__shape-row" data-adam-shape-row data-shape-index="<?php echo esc_attr( (string) $index ); ?>">
			<div class="adam-reward-editor__shape-row-head">
				<strong><?php echo esc_html( sprintf( __( 'Forma %d', 'adam-membership' ), $index + 1 ) ); ?></strong>
				<button type="button" class="button-link-delete" data-adam-remove-shape><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
			</div>
			<div class="adam-reward-editor__shape-grid">
				<label><span><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></span><select data-shape-prop="type"><?php $this->render_select_option( 'circle', __( 'Circulo', 'adam-membership' ), (string) ( $shape['type'] ?? 'circle' ) ); ?><?php $this->render_select_option( 'square', __( 'Quadrado', 'adam-membership' ), (string) ( $shape['type'] ?? 'circle' ) ); ?><?php $this->render_select_option( 'line', __( 'Linha', 'adam-membership' ), (string) ( $shape['type'] ?? 'circle' ) ); ?></select></label>
				<label><span>X</span><input type="number" min="0" max="100" value="<?php echo esc_attr( (string) ( $shape['x'] ?? 72 ) ); ?>" data-shape-prop="x"></label>
				<label><span>Y</span><input type="number" min="0" max="100" value="<?php echo esc_attr( (string) ( $shape['y'] ?? 20 ) ); ?>" data-shape-prop="y"></label>
				<label><span><?php esc_html_e( 'Largura', 'adam-membership' ); ?></span><input type="number" min="2" max="90" value="<?php echo esc_attr( (string) ( $shape['width'] ?? 18 ) ); ?>" data-shape-prop="width"></label>
				<label><span><?php esc_html_e( 'Altura', 'adam-membership' ); ?></span><input type="number" min="2" max="90" value="<?php echo esc_attr( (string) ( $shape['height'] ?? 18 ) ); ?>" data-shape-prop="height"></label>
				<label><span><?php esc_html_e( 'Rotacao', 'adam-membership' ); ?></span><input type="number" min="0" max="360" value="<?php echo esc_attr( (string) ( $shape['rotation'] ?? 0 ) ); ?>" data-shape-prop="rotation"></label>
				<label><span><?php esc_html_e( 'Opacidade', 'adam-membership' ); ?></span><input type="number" min="0" max="100" value="<?php echo esc_attr( (string) ( $shape['opacity'] ?? 28 ) ); ?>" data-shape-prop="opacity"></label>
				<label><span><?php esc_html_e( 'Cor', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" value="<?php echo esc_attr( (string) ( $shape['color'] ?? '#ffffff' ) ); ?>" data-shape-prop="color"></label>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $shape Shape configuration.
	 */
	private function render_shape_preview( array $shape ): void {
		$type     = sanitize_html_class( (string) ( $shape['type'] ?? 'circle' ) );
		$x        = max( 0, min( 100, (int) ( $shape['x'] ?? 72 ) ) );
		$y        = max( 0, min( 100, (int) ( $shape['y'] ?? 20 ) ) );
		$width    = max( 2, min( 90, (int) ( $shape['width'] ?? 18 ) ) );
		$height   = max( 2, min( 90, (int) ( $shape['height'] ?? 18 ) ) );
		$rotation = max( 0, min( 360, (int) ( $shape['rotation'] ?? 0 ) ) );
		$opacity  = max( 0, min( 100, (int) ( $shape['opacity'] ?? 28 ) ) ) / 100;
		$color    = sanitize_text_field( (string) ( $shape['color'] ?? '#ffffff' ) );
		$style    = sprintf(
			'left:%1$s%%;top:%2$s%%;width:%3$s%%;height:%4$s%%;transform:rotate(%5$sdeg);opacity:%6$s;background:%7$s;',
			(string) $x,
			(string) $y,
			(string) $width,
			(string) $height,
			(string) $rotation,
			(string) $opacity,
			$color
		);
		?>
		<span class="adam-reward-card__shape adam-reward-card__shape--<?php echo esc_attr( $type ); ?>" style="<?php echo esc_attr( $style ); ?>"></span>
		<?php
	}

	/**
	 * @param array<string, mixed> $style Style payload.
	 */
	private function preview_inline_style( array $style ): string {
		$background = 'linear-gradient(' . (int) ( $style['gradient_angle'] ?? 135 ) . 'deg, ' . (string) ( $style['background_color'] ?? '#143826' ) . ', ' . (string) ( $style['background_color_secondary'] ?? '#215b39' ) . ')';

		if ( 'solid' === (string) ( $style['background_mode'] ?? 'gradient' ) ) {
			$background = (string) ( $style['background_color'] ?? '#143826' );
		}

		$vars = array(
			'--adam-reward-card-background'         => $background,
			'--adam-reward-card-text'               => (string) ( $style['text_color'] ?? '#f8fafc' ),
			'--adam-reward-card-muted'              => (string) ( $style['muted_text_color'] ?? 'rgba(226,232,240,0.78)' ),
			'--adam-reward-card-accent'             => (string) ( $style['accent_color'] ?? '#86efac' ),
			'--adam-reward-card-border'             => (string) ( $style['border_color'] ?? '#9ca3af' ),
			'--adam-reward-card-border-width'       => (int) ( $style['border_width'] ?? 2 ) . 'px',
			'--adam-reward-card-radius'             => (int) ( $style['border_radius'] ?? 18 ) . 'px',
			'--adam-reward-card-pattern-opacity'    => (string) ( max( 0, min( 100, (int) ( $style['pattern_opacity'] ?? 18 ) ) ) / 100 ),
			'--adam-reward-card-image-opacity'      => (string) ( max( 0, min( 100, (int) ( $style['card_image_opacity'] ?? 22 ) ) ) / 100 ),
			'--adam-reward-card-background-opacity' => (string) ( max( 0, min( 100, (int) ( $style['background_image_opacity'] ?? 18 ) ) ) / 100 ),
		);

		$parts = array();

		foreach ( $vars as $property => $value ) {
			$parts[] = $property . ':' . $value;
		}

		return implode( ';', $parts ) . ';';
	}

	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? $datetime : wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
	}
}
