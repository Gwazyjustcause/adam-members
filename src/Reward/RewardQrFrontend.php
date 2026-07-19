<?php
/**
 * Frontend reward QR controller.
 *
 * @package AdamMembership\Reward
 */

declare(strict_types=1);

namespace AdamMembership\Reward;

use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;

/**
 * Handles temporary reward QR claims.
 */
final class RewardQrFrontend {
	private RewardService $rewards;
	private MemberRepository $members;

	public function __construct( RewardService $rewards, MemberRepository $members ) {
		$this->rewards = $rewards;
		$this->members = $members;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'render_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! $this->is_qr_request() ) {
			return;
		}

		$asset_path = ADAM_MEMBERSHIP_PATH . 'assets/css/reward-claim.css';

		wp_enqueue_style(
			'adam-reward-claim',
			ADAM_MEMBERSHIP_URL . 'assets/css/reward-claim.css',
			array(),
			file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ADAM_MEMBERSHIP_VERSION
		);
	}

	public function render_route(): void {
		$token = $this->current_token();

		if ( '' === $token ) {
			return;
		}

		$reward         = $this->rewards->find_reward_by_qr_token( $token );
		$notice_type    = 'info';
		$notice_lines   = array();
		$registration   = home_url( '/inscricao/' );
		$member         = $this->current_member();

		if ( null === $reward ) {
			$notice_type  = 'error';
			$notice_lines[] = __( 'Este QR Code nao e valido.', 'adam-membership' );
		} elseif ( ! is_user_logged_in() ) {
			$notice_type    = 'warning';
			$notice_lines[] = __( 'Esta recompensa e exclusiva para socios da ADAM.', 'adam-membership' );
			$notice_lines[] = __( 'Inicia sessao para resgatar esta recompensa.', 'adam-membership' );
		} else {
			if ( ! $member instanceof Member || ! $member->isActive() ) {
				$notice_type    = 'warning';
				$notice_lines[] = __( 'Esta recompensa esta disponivel apenas para socios ativos da ADAM.', 'adam-membership' );
			} else {
				$result = $this->rewards->claim_reward_via_qr( $member, $token );

				if ( is_wp_error( $result ) ) {
					$notice_type    = 'warning';
					$notice_lines[] = $result->get_error_message();
				} else {
					$notice_type    = 'success';
					$notice_lines[] = __( 'Recompensa atribuida com sucesso!', 'adam-membership' );
					$notice_lines[] = sprintf(
						/* translators: %s: reward name */
						__( 'A recompensa %s foi adicionada a tua colecao.', 'adam-membership' ),
						$result->name()
					);
				}
			}
		}

		status_header( 200 );
		nocache_headers();
		get_header();
		?>
		<main class="adam-reward-claim-page">
			<section class="adam-reward-claim-card">
				<p class="adam-reward-claim-card__eyebrow"><?php esc_html_e( 'ADAM Rewards', 'adam-membership' ); ?></p>
				<h1><?php esc_html_e( 'Resgate por QR Code', 'adam-membership' ); ?></h1>

				<?php if ( $reward instanceof Reward ) : ?>
					<div class="adam-reward-claim-card__reward">
						<strong><?php echo esc_html( $reward->name() ); ?></strong>
						<?php if ( '' !== trim( $reward->description() ) ) : ?>
							<p><?php echo esc_html( $reward->description() ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="adam-reward-claim-card__notice adam-reward-claim-card__notice--<?php echo esc_attr( $notice_type ); ?>">
					<?php foreach ( $notice_lines as $line ) : ?>
						<p><?php echo esc_html( $line ); ?></p>
					<?php endforeach; ?>
				</div>

				<?php if ( $reward instanceof Reward && ! is_user_logged_in() ) : ?>
					<div class="adam-reward-claim-card__login">
						<?php
						wp_login_form(
							array(
								'echo'           => true,
								'remember'       => true,
								'redirect'       => $this->rewards->reward_qr_claim_url( $reward ),
								'label_username' => __( 'Email ou utilizador', 'adam-membership' ),
								'label_password' => __( 'Palavra-passe', 'adam-membership' ),
								'label_log_in'   => __( 'Iniciar sessao e resgatar', 'adam-membership' ),
							)
						);
						?>
					</div>
				<?php elseif ( is_user_logged_in() && ( ! $member instanceof Member || ! $member->isActive() ) ) : ?>
					<div class="adam-reward-claim-card__actions">
						<a class="adam-reward-claim-card__button" href="<?php echo esc_url( $registration ); ?>">
							<?php esc_html_e( 'Tornar-me socio', 'adam-membership' ); ?>
						</a>
					</div>
				<?php else : ?>
					<div class="adam-reward-claim-card__actions">
						<a class="adam-reward-claim-card__button" href="<?php echo esc_url( home_url( '/socio/?view=recompensas' ) ); ?>">
							<?php esc_html_e( 'Ver recompensas', 'adam-membership' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</section>
		</main>
		<?php
		get_footer();
		exit;
	}

	private function current_token(): string {
		return isset( $_GET['adam_reward_qr'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_reward_qr'] ) ) : '';
	}

	private function is_qr_request(): bool {
		return '' !== $this->current_token();
	}

	private function current_member(): ?Member {
		return is_user_logged_in() ? $this->members->find( get_current_user_id() ) : null;
	}
}
