<?php
/**
 * Rewards admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Member\CardService;
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
	private CardService $cards;

	public function __construct( RewardService $rewards, MemberRepository $members, CardService $cards ) {
		$this->rewards = $rewards;
		$this->members = $members;
		$this->cards   = $cards;
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
		$member_style_path = ADAM_MEMBERSHIP_PATH . 'assets/css/member-area.css';

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style(
			'adam-membership-member-card-preview',
			ADAM_MEMBERSHIP_URL . 'assets/css/member-area.css',
			array(),
			file_exists( $member_style_path ) ? (string) filemtime( $member_style_path ) : ADAM_MEMBERSHIP_VERSION
		);
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
		$reward_category    = null !== $reward ? $reward->category() : 'Cartao Digital';
		$reward_type        = null !== $reward ? $reward->type() : Reward::TYPE_DIGITAL_COSMETIC;
		$is_digital_reward  = Reward::TYPE_DIGITAL_COSMETIC === $reward_type || str_contains( strtolower( $reward_category ), 'cartao' );
		$card_preview       = null !== $reward
			? $this->cards->render_card(
				$this->cards->preview_card_data(),
				$this->cards->reward_preview_presentation( $reward, array_merge( $resolved_style, array( 'image_url' => $reward_image ) ) )
			)
			: '';
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar adam-admin-titlebar--split">
				<div>
					<h1><?php echo esc_html( $title ); ?></h1>
					<p><?php esc_html_e( 'Organiza a recompensa e, quando aplicavel, desenha visualmente o cartao digital sem escrever CSS.', 'adam-membership' ); ?></p>
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
							<label><span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span><select name="category" data-adam-preview-category data-adam-reward-category><?php foreach ( $this->rewards->categories() as $category ) : ?><?php $this->render_select_option( $category, $category, $reward_category ); ?><?php endforeach; ?></select></label>
							<label><span><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></span><select name="type" data-adam-reward-type><?php foreach ( $this->rewards->type_labels() as $type => $label ) : ?><?php $this->render_select_option( $type, $label, $reward_type ); ?><?php endforeach; ?></select></label>
							<label><span><?php esc_html_e( 'Raridade', 'adam-membership' ); ?></span><select name="rarity" data-adam-preview-rarity><?php foreach ( $this->rewards->rarity_labels() as $rarity => $label ) : ?><?php $this->render_select_option( $rarity, $label, $reward_rarity ); ?><?php endforeach; ?></select></label>
							<label><span><?php esc_html_e( 'Pontos necessarios', 'adam-membership' ); ?></span><input type="number" min="0" name="points_cost" value="<?php echo esc_attr( (string) $reward_points ); ?>" data-adam-preview-points></label>
							<label><span><?php esc_html_e( 'Disponibilidade', 'adam-membership' ); ?></span><input type="text" name="availability_label" value="<?php echo esc_attr( null !== $reward ? $reward->availability_label() : __( 'Disponivel', 'adam-membership' ) ); ?>"></label>
							<label class="adam-reward-editor__conditional-field<?php echo $is_digital_reward ? '' : ' is-hidden'; ?>" data-adam-card-subtype-field><span><?php esc_html_e( 'Subtipo do cartao digital', 'adam-membership' ); ?></span><select name="visual_style[card_subtype]" data-adam-style="card_subtype"><?php $this->render_card_subtype_options( (string) $resolved_style['card_subtype'] ); ?></select></label>
						</div>
					</section>

					<div class="adam-admin-notice info adam-reward-editor__conditional-field<?php echo $is_digital_reward ? ' is-hidden' : ''; ?>" data-adam-non-digital-notice>
						<p><?php esc_html_e( 'Os controlos visuais do cartao digital aparecem apenas em recompensas ligadas ao cartao de socio. Para outras recompensas, guarda apenas os metadados gerais.', 'adam-membership' ); ?></p>
					</div>

					<div class="adam-reward-editor__workspace adam-reward-editor__conditional-field<?php echo $is_digital_reward ? '' : ' is-hidden'; ?>" data-adam-digital-workspace>
						<div class="adam-reward-editor__controls">
							<section class="adam-reward-editor__section adam-reward-editor__section--summary">
								<div class="adam-reward-editor__summary">
									<div>
										<p class="adam-reward-editor__eyebrow"><?php esc_html_e( 'Controlos', 'adam-membership' ); ?></p>
										<h2><?php esc_html_e( 'Editar recompensa digital', 'adam-membership' ); ?></h2>
										<p><?php esc_html_e( 'Cada controlo altera diretamente o cartao real da ADAM. O editor mostra apenas os grupos relevantes para o subtipo e modo atualmente selecionados.', 'adam-membership' ); ?></p>
									</div>
								</div>
							</section>
							<section class="adam-reward-editor__section adam-reward-editor__section--accordion is-open" data-adam-background-controls>
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="true"><?php esc_html_e( 'Fundo do cartao', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Escolhe como o fundo base do cartao deve ser construido: cor solida, gradiente ou imagem com sobreposicao.', 'adam-membership' ); ?></p>
								<div class="adam-reward-editor__radio-group">
									<?php $this->render_style_mode_option( 'gradient', __( 'Gradiente', 'adam-membership' ), (string) $resolved_style['background_mode'] ); ?>
									<?php $this->render_style_mode_option( 'solid', __( 'Cor solida', 'adam-membership' ), (string) $resolved_style['background_mode'] ); ?>
									<?php $this->render_style_mode_option( 'image', __( 'Imagem + gradiente', 'adam-membership' ), (string) $resolved_style['background_mode'] ); ?>
								</div>
								<div class="adam-reward-editor__field-grid adam-reward-editor__field-grid--compact">
									<label data-adam-background-mode-group="solid gradient image"><span><?php esc_html_e( 'Cor principal', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[background_color]" value="<?php echo esc_attr( (string) $resolved_style['background_color'] ); ?>" data-adam-style="background_color"></label>
									<label data-adam-background-mode-group="gradient image"><span><?php esc_html_e( 'Cor intermedia', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[background_color_secondary]" value="<?php echo esc_attr( (string) $resolved_style['background_color_secondary'] ); ?>" data-adam-style="background_color_secondary"></label>
									<label data-adam-background-mode-group="gradient image"><span><?php esc_html_e( 'Cor final', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[background_color_tertiary]" value="<?php echo esc_attr( (string) $resolved_style['background_color_tertiary'] ); ?>" data-adam-style="background_color_tertiary"></label>
									<label data-adam-background-mode-group="gradient image"><span><?php esc_html_e( 'Angulo do gradiente', 'adam-membership' ); ?></span><input type="range" min="0" max="360" name="visual_style[gradient_angle]" value="<?php echo esc_attr( (string) $resolved_style['gradient_angle'] ); ?>" data-adam-style="gradient_angle"><small data-adam-value-for="gradient_angle"><?php echo esc_html( (string) $resolved_style['gradient_angle'] ); ?>deg</small></label>
									<label data-adam-background-mode-group="gradient image"><span><?php esc_html_e( 'Ponto intermedio', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[gradient_stop_secondary]" value="<?php echo esc_attr( (string) $resolved_style['gradient_stop_secondary'] ); ?>" data-adam-style="gradient_stop_secondary"><small data-adam-value-for="gradient_stop_secondary"><?php echo esc_html( (string) $resolved_style['gradient_stop_secondary'] ); ?>%</small></label>
									<label data-adam-background-mode-group="gradient image"><span><?php esc_html_e( 'Fim do gradiente', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[gradient_stop_tertiary]" value="<?php echo esc_attr( (string) $resolved_style['gradient_stop_tertiary'] ); ?>" data-adam-style="gradient_stop_tertiary"><small data-adam-value-for="gradient_stop_tertiary"><?php echo esc_html( (string) $resolved_style['gradient_stop_tertiary'] ); ?>%</small></label>
									<label data-adam-background-mode-group="gradient image"><span><?php esc_html_e( 'Opacidade do gradiente', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[gradient_opacity]" value="<?php echo esc_attr( (string) $resolved_style['gradient_opacity'] ); ?>" data-adam-style="gradient_opacity"><small data-adam-value-for="gradient_opacity"><?php echo esc_html( (string) $resolved_style['gradient_opacity'] ); ?>%</small></label>
								</div>
								</div>
							</section>

							<section class="adam-reward-editor__section adam-reward-editor__section--accordion" data-adam-background-controls>
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="false"><?php esc_html_e( 'Padrao', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Adiciona uma camada de padrao por cima do fundo para dar textura sem comprometer a legibilidade do cartao.', 'adam-membership' ); ?></p>
								<div class="adam-reward-editor__field-grid adam-reward-editor__field-grid--compact">
									<label><span><?php esc_html_e( 'Tipo de padrao', 'adam-membership' ); ?></span><select name="visual_style[pattern]" data-adam-style="pattern"><?php $this->render_pattern_options( (string) $resolved_style['pattern'] ); ?></select></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Cor do padrao', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[pattern_color]" value="<?php echo esc_attr( (string) $resolved_style['pattern_color'] ); ?>" data-adam-style="pattern_color"></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Cor de base do padrao', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[pattern_background_color]" value="<?php echo esc_attr( (string) $resolved_style['pattern_background_color'] ); ?>" data-adam-style="pattern_background_color"></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Escala', 'adam-membership' ); ?></span><input type="range" min="6" max="120" name="visual_style[pattern_scale]" value="<?php echo esc_attr( (string) $resolved_style['pattern_scale'] ); ?>" data-adam-style="pattern_scale"><small data-adam-value-for="pattern_scale"><?php echo esc_html( (string) $resolved_style['pattern_scale'] ); ?>px</small></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Densidade', 'adam-membership' ); ?></span><input type="range" min="1" max="12" name="visual_style[pattern_density]" value="<?php echo esc_attr( (string) $resolved_style['pattern_density'] ); ?>" data-adam-style="pattern_density"><small data-adam-value-for="pattern_density"><?php echo esc_html( (string) $resolved_style['pattern_density'] ); ?></small></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Rotacao', 'adam-membership' ); ?></span><input type="range" min="0" max="360" name="visual_style[pattern_rotation]" value="<?php echo esc_attr( (string) $resolved_style['pattern_rotation'] ); ?>" data-adam-style="pattern_rotation"><small data-adam-value-for="pattern_rotation"><?php echo esc_html( (string) $resolved_style['pattern_rotation'] ); ?>deg</small></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Espacamento', 'adam-membership' ); ?></span><input type="range" min="6" max="120" name="visual_style[pattern_spacing]" value="<?php echo esc_attr( (string) $resolved_style['pattern_spacing'] ); ?>" data-adam-style="pattern_spacing"><small data-adam-value-for="pattern_spacing"><?php echo esc_html( (string) $resolved_style['pattern_spacing'] ); ?>px</small></label>
									<label data-adam-pattern-detail><span><?php esc_html_e( 'Opacidade do padrao', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[pattern_opacity]" value="<?php echo esc_attr( (string) $resolved_style['pattern_opacity'] ); ?>" data-adam-style="pattern_opacity"><small data-adam-value-for="pattern_opacity"><?php echo esc_html( (string) $resolved_style['pattern_opacity'] ); ?>%</small></label>
								</div>
								</div>
							</section>

							<section class="adam-reward-editor__section adam-reward-editor__section--accordion" data-adam-background-controls data-adam-image-controls data-adam-background-mode-group="image">
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="false"><?php esc_html_e( 'Imagem e textura', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Usa uma imagem de fundo ou textura adicional. Esta camada so aparece quando o modo ativo e Imagem + gradiente.', 'adam-membership' ); ?></p>
								<label class="adam-reward-editor__media-field"><span><?php esc_html_e( 'Imagem de fundo / textura', 'adam-membership' ); ?></span><input type="url" name="visual_style[background_image_url]" value="<?php echo esc_attr( (string) $resolved_style['background_image_url'] ); ?>" data-adam-style="background_image_url"><button type="button" class="button" data-adam-media-target="input[name='visual_style[background_image_url]']"><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></button></label>
								<div class="adam-reward-editor__field-grid adam-reward-editor__field-grid--compact">
									<label><span><?php esc_html_e( 'Posicao da imagem de fundo', 'adam-membership' ); ?></span><select name="visual_style[background_image_position]" data-adam-style="background_image_position"><?php $this->render_gradient_origin_options( (string) $resolved_style['background_image_position'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Tamanho da imagem de fundo', 'adam-membership' ); ?></span><input type="range" min="20" max="200" name="visual_style[background_image_size]" value="<?php echo esc_attr( (string) $resolved_style['background_image_size'] ); ?>" data-adam-style="background_image_size"><small data-adam-value-for="background_image_size"><?php echo esc_html( (string) $resolved_style['background_image_size'] ); ?>%</small></label>
									<label><span><?php esc_html_e( 'Opacidade da imagem de fundo', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[background_image_opacity]" value="<?php echo esc_attr( (string) $resolved_style['background_image_opacity'] ); ?>" data-adam-style="background_image_opacity"><small data-adam-value-for="background_image_opacity"><?php echo esc_html( (string) $resolved_style['background_image_opacity'] ); ?>%</small></label>
									<label><span><?php esc_html_e( 'Modo de mistura', 'adam-membership' ); ?></span><select name="visual_style[background_image_blend_mode]" data-adam-style="background_image_blend_mode"><?php $this->render_background_blend_options( (string) $resolved_style['background_image_blend_mode'] ); ?></select></label>
								</div>
								</div>
							</section>

							<section class="adam-reward-editor__section adam-reward-editor__section--accordion" data-adam-style-controls>
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="false"><?php esc_html_e( 'Elementos decorativos', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Aplica imagem decorativa, formas e sobreposicoes ao layout do cartao para criar um estilo mais distintivo.', 'adam-membership' ); ?></p>
								<label><span><?php esc_html_e( 'Carregar imagem decorativa', 'adam-membership' ); ?></span><input type="file" name="reward_image" accept=".jpg,.jpeg,.png,.webp" data-adam-preview-image-upload></label>
								<label class="adam-reward-editor__media-field"><span><?php esc_html_e( 'Imagem decorativa', 'adam-membership' ); ?></span><input type="url" name="image_url" value="<?php echo esc_attr( $reward_image ); ?>" data-adam-preview-image><button type="button" class="button" data-adam-media-target="input[name='image_url']"><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></button></label>
								<div class="adam-reward-editor__field-grid adam-reward-editor__field-grid--compact">
									<label><span><?php esc_html_e( 'Posicao da imagem', 'adam-membership' ); ?></span><select name="visual_style[card_image_position]" data-adam-style="card_image_position"><?php $this->render_image_position_options( (string) $resolved_style['card_image_position'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Opacidade da imagem', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[card_image_opacity]" value="<?php echo esc_attr( (string) $resolved_style['card_image_opacity'] ); ?>" data-adam-style="card_image_opacity"><small data-adam-value-for="card_image_opacity"><?php echo esc_html( (string) $resolved_style['card_image_opacity'] ); ?>%</small></label>
									<label><span><?php esc_html_e( 'Tamanho da imagem', 'adam-membership' ); ?></span><input type="range" min="10" max="80" name="visual_style[card_image_size]" value="<?php echo esc_attr( (string) $resolved_style['card_image_size'] ); ?>" data-adam-style="card_image_size"><small data-adam-value-for="card_image_size"><?php echo esc_html( (string) $resolved_style['card_image_size'] ); ?>%</small></label>
									<label><span><?php esc_html_e( 'Camada da imagem', 'adam-membership' ); ?></span><select name="visual_style[card_image_layer]" data-adam-style="card_image_layer"><?php $this->render_card_image_layer_options( (string) $resolved_style['card_image_layer'] ); ?></select></label>
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
								</div>
							</section>

							<section class="adam-reward-editor__section adam-reward-editor__section--accordion" data-adam-style-controls>
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="false"><?php esc_html_e( 'Tipografia e badges', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Ajusta a hierarquia visual do titulo, texto e badges do cartao para combinar com o estilo desbloqueado.', 'adam-membership' ); ?></p>
								<div class="adam-reward-editor__field-grid">
									<label><span><?php esc_html_e( 'Cor do texto', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[text_color]" value="<?php echo esc_attr( (string) $resolved_style['text_color'] ); ?>" data-adam-style="text_color"></label>
									<label><span><?php esc_html_e( 'Cor do texto secundario', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[muted_text_color]" value="<?php echo esc_attr( (string) $resolved_style['muted_text_color'] ); ?>" data-adam-style="muted_text_color"></label>
									<label><span><?php esc_html_e( 'Cor do titulo', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[title_color]" value="<?php echo esc_attr( (string) $resolved_style['title_color'] ); ?>" data-adam-style="title_color"></label>
									<label><span><?php esc_html_e( 'Tamanho do titulo', 'adam-membership' ); ?></span><input type="range" min="14" max="28" name="visual_style[title_size]" value="<?php echo esc_attr( (string) $resolved_style['title_size'] ); ?>" data-adam-style="title_size"><small data-adam-value-for="title_size"><?php echo esc_html( (string) $resolved_style['title_size'] ); ?>px</small></label>
									<label><span><?php esc_html_e( 'Peso do titulo', 'adam-membership' ); ?></span><input type="range" min="400" max="900" step="100" name="visual_style[title_weight]" value="<?php echo esc_attr( (string) $resolved_style['title_weight'] ); ?>" data-adam-style="title_weight"><small data-adam-value-for="title_weight"><?php echo esc_html( (string) $resolved_style['title_weight'] ); ?></small></label>
									<label><span><?php esc_html_e( 'Alinhamento do titulo', 'adam-membership' ); ?></span><select name="visual_style[title_align]" data-adam-style="title_align"><?php $this->render_text_align_options( (string) $resolved_style['title_align'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Sombra do titulo', 'adam-membership' ); ?></span><input type="range" min="0" max="40" name="visual_style[title_shadow]" value="<?php echo esc_attr( (string) $resolved_style['title_shadow'] ); ?>" data-adam-style="title_shadow"><small data-adam-value-for="title_shadow"><?php echo esc_html( (string) $resolved_style['title_shadow'] ); ?>px</small></label>
									<label><span><?php esc_html_e( 'Cor de destaque', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[accent_color]" value="<?php echo esc_attr( (string) $resolved_style['accent_color'] ); ?>" data-adam-style="accent_color"></label>
									<label><span><?php esc_html_e( 'Estilo dos badges', 'adam-membership' ); ?></span><select name="visual_style[badge_style]" data-adam-style="badge_style"><?php $this->render_badge_style_options( (string) $resolved_style['badge_style'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Efeito de raridade', 'adam-membership' ); ?></span><select name="visual_style[rarity_effect]" data-adam-style="rarity_effect"><?php $this->render_rarity_effect_options( (string) $resolved_style['rarity_effect'] ); ?></select></label>
								</div>
								</div>
							</section>

							<section class="adam-reward-editor__section adam-reward-editor__section--accordion is-open" data-adam-style-controls>
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="true"><?php esc_html_e( 'Estilo do cartao', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Escolhe um preset de moldura e ajusta apenas as cores e a espessura. A geometria do cartao permanece fixa em toda a ADAM.', 'adam-membership' ); ?></p>
								<div class="adam-reward-editor__field-grid">
									<label><span><?php esc_html_e( 'Preset da moldura', 'adam-membership' ); ?></span><select name="visual_style[frame_style]" data-adam-style="frame_style"><?php $this->render_frame_style_options( (string) $resolved_style['frame_style'] ); ?></select></label>
									<label><span><?php esc_html_e( 'Cor 1', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[border_color]" value="<?php echo esc_attr( (string) $resolved_style['border_color'] ); ?>" data-adam-style="border_color"></label>
									<label data-adam-frame-secondary-field><span><?php esc_html_e( 'Cor 2', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[frame_inner_color]" value="<?php echo esc_attr( (string) $resolved_style['frame_inner_color'] ); ?>" data-adam-style="frame_inner_color"></label>
									<label data-adam-frame-gradient-field><span><?php esc_html_e( 'Cor 3', 'adam-membership' ); ?></span><input class="adam-color-picker" type="text" name="visual_style[frame_gradient_color]" value="<?php echo esc_attr( (string) $resolved_style['frame_gradient_color'] ); ?>" data-adam-style="frame_gradient_color"></label>
									<label class="adam-reward-editor__slider-field"><span><?php esc_html_e( 'Espessura da moldura', 'adam-membership' ); ?></span><input type="range" min="2" max="16" name="visual_style[border_width]" value="<?php echo esc_attr( (string) $resolved_style['border_width'] ); ?>" data-adam-style="border_width"><small data-adam-value-for="border_width"><?php echo esc_html( (string) $resolved_style['border_width'] ); ?>px</small></label>
									<label class="adam-reward-editor__slider-field" data-adam-frame-metallic-field><span><?php esc_html_e( 'Intensidade do brilho', 'adam-membership' ); ?></span><input type="range" min="0" max="100" name="visual_style[frame_shine_intensity]" value="<?php echo esc_attr( (string) $resolved_style['frame_shine_intensity'] ); ?>" data-adam-style="frame_shine_intensity"><small data-adam-value-for="frame_shine_intensity"><?php echo esc_html( (string) $resolved_style['frame_shine_intensity'] ); ?>%</small></label>
									<label class="adam-reward-editor__slider-field" data-adam-frame-gradient-field><span><?php esc_html_e( 'Angulo do gradiente', 'adam-membership' ); ?></span><input type="range" min="0" max="360" name="visual_style[frame_gradient_angle]" value="<?php echo esc_attr( (string) $resolved_style['frame_gradient_angle'] ); ?>" data-adam-style="frame_gradient_angle"><small data-adam-value-for="frame_gradient_angle"><?php echo esc_html( (string) $resolved_style['frame_gradient_angle'] ); ?>deg</small></label>
								</div>
								</div>
							</section>

							<section class="adam-reward-editor__section adam-reward-editor__section--accordion" data-adam-details-controls>
								<button type="button" class="adam-reward-editor__accordion-toggle" data-adam-accordion-toggle aria-expanded="false"><?php esc_html_e( 'Detalhes da recompensa', 'adam-membership' ); ?></button>
								<div class="adam-reward-editor__accordion-body">
								<p class="adam-reward-editor__section-copy"><?php esc_html_e( 'Guarda os metadados gerais da recompensa sem sair do fluxo do editor visual.', 'adam-membership' ); ?></p>
								<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descricao', 'adam-membership' ); ?></span><textarea name="description" rows="5" data-adam-preview-description><?php echo esc_textarea( $reward_description ); ?></textarea></label>
								<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Valor/identificador interno', 'adam-membership' ); ?></span><input type="text" name="reward_value" value="<?php echo esc_attr( null !== $reward ? $reward->reward_value() : '' ); ?>"></label>
								<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Revelacao da recompensa misterio', 'adam-membership' ); ?></span><textarea name="mystery_reveal_text" rows="3"><?php echo esc_textarea( null !== $reward ? $reward->mystery_reveal_text() : '' ); ?></textarea></label>
								<label class="adam-admin-checkbox-field"><input type="checkbox" name="active" value="1" <?php checked( null !== $reward ? $reward->active() : true ); ?>> <?php esc_html_e( 'Recompensa ativa', 'adam-membership' ); ?></label>
								<label class="adam-admin-checkbox-field"><input type="checkbox" name="approval_required" value="1" <?php checked( null !== $reward ? $reward->approval_required() : true ); ?>> <?php esc_html_e( 'Exige aprovacao administrativa', 'adam-membership' ); ?></label>
								</div>
							</section>
						</div>

						<div class="adam-reward-editor__preview-panel">
							<section class="adam-reward-editor__section adam-reward-editor__section--preview">
								<p class="adam-reward-editor__eyebrow"><?php esc_html_e( 'Pre-visualizacao em tempo real', 'adam-membership' ); ?></p>
								<h2><?php esc_html_e( 'Cartao ADAM real', 'adam-membership' ); ?></h2>
								<p><?php esc_html_e( 'A pre-visualizacao usa a mesma estrutura do cartao do socio, incluindo logo, fotografia, QR code, titulos e area de estado.', 'adam-membership' ); ?></p>
								<div class="adam-reward-editor__preview-stage">
									<?php echo $card_preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
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

	private function render_card_subtype_options( string $current ): void {
		$options = array(
			'background' => __( 'Cor do cartao', 'adam-membership' ),
			'card_style' => __( 'Estilo do cartao', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_gradient_origin_options( string $current ): void {
		$options = array(
			'top-left'     => __( 'Canto superior esquerdo', 'adam-membership' ),
			'top'          => __( 'Topo', 'adam-membership' ),
			'top-right'    => __( 'Canto superior direito', 'adam-membership' ),
			'left'         => __( 'Esquerda', 'adam-membership' ),
			'center'       => __( 'Centro', 'adam-membership' ),
			'right'        => __( 'Direita', 'adam-membership' ),
			'bottom-left'  => __( 'Canto inferior esquerdo', 'adam-membership' ),
			'bottom'       => __( 'Inferior', 'adam-membership' ),
			'bottom-right' => __( 'Canto inferior direito', 'adam-membership' ),
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

	private function render_card_image_layer_options( string $current ): void {
		$options = array(
			'overlay'  => __( 'Sobre o cartao', 'adam-membership' ),
			'underlay' => __( 'Por baixo do conteudo', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_background_blend_options( string $current ): void {
		$options = array(
			'screen'     => __( 'Clarear', 'adam-membership' ),
			'overlay'    => __( 'Sobrepor', 'adam-membership' ),
			'soft-light' => __( 'Luz suave', 'adam-membership' ),
			'multiply'   => __( 'Multiplicar', 'adam-membership' ),
			'normal'     => __( 'Normal', 'adam-membership' ),
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

	private function render_frame_style_options( string $current ): void {
		$options = array(
			'none'      => __( 'Sem moldura', 'adam-membership' ),
			'simple'    => __( 'Simples', 'adam-membership' ),
			'metallic'  => __( 'Metalica', 'adam-membership' ),
			'gradient'  => __( 'Gradiente', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_text_align_options( string $current ): void {
		$options = array(
			'left'   => __( 'Esquerda', 'adam-membership' ),
			'center' => __( 'Centro', 'adam-membership' ),
			'right'  => __( 'Direita', 'adam-membership' ),
		);

		foreach ( $options as $value => $label ) {
			$this->render_select_option( $value, $label, $current );
		}
	}

	private function render_meta_align_options( string $current ): void {
		$options = array(
			'left'          => __( 'Esquerda', 'adam-membership' ),
			'center'        => __( 'Centro', 'adam-membership' ),
			'right'         => __( 'Direita', 'adam-membership' ),
			'space-between' => __( 'Extremos', 'adam-membership' ),
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

	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? $datetime : wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
	}
}
