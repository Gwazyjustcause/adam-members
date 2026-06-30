<?php
/**
 * Member area shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Announcement\Announcement;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Core\SettingsRepository;
use AdamMembership\Document\Document;
use AdamMembership\Document\DocumentService;
use AdamMembership\Helpers\RateLimiter;
use AdamMembership\Points\PointsEntry;
use AdamMembership\Points\PointsService;
use AdamMembership\Reward\Reward;
use AdamMembership\Reward\RewardRedemption;
use AdamMembership\Reward\RewardService;

/**
 * Handles the frontend member area.
 */
final class MemberArea {

	/**
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Renewal service.
	 *
	 * @var RenewalService
	 */
	private RenewalService $renewals;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Digital card service.
	 *
	 * @var CardService
	 */
	private CardService $cards;

	/**
	 * Announcement service.
	 *
	 * @var AnnouncementService
	 */
	private AnnouncementService $announcements;

	/**
	 * Document service.
	 *
	 * @var DocumentService
	 */
	private DocumentService $documents;

	/**
	 * Points service.
	 *
	 * @var PointsService
	 */
	private PointsService $points;

	/**
	 * Reward service.
	 *
	 * @var RewardService
	 */
	private RewardService $rewards;
	private RecognitionService $recognition;

	/**
	 * Constructor.
	 *
	 * @param MemberRepository   $members  Member repository.
	 * @param RenewalService     $renewals Renewal service.
	 * @param SettingsRepository $settings Settings repository.
	 * @param CardService         $cards         Digital card service.
	 * @param AnnouncementService $announcements Announcement service.
	 * @param DocumentService     $documents     Document service.
	 * @param PointsService       $points        Points service.
	 * @param RewardService       $rewards       Reward service.
	 * @param RecognitionService  $recognition   Recognition service.
	 */
	public function __construct( MemberRepository $members, RenewalService $renewals, SettingsRepository $settings, CardService $cards, AnnouncementService $announcements, DocumentService $documents, PointsService $points, RewardService $rewards, RecognitionService $recognition ) {
		$this->members       = $members;
		$this->renewals      = $renewals;
		$this->settings      = $settings;
		$this->cards         = $cards;
		$this->announcements = $announcements;
		$this->documents     = $documents;
		$this->points        = $points;
		$this->rewards       = $rewards;
		$this->recognition   = $recognition;
	}

	/**
	 * Register shortcode and assets.
	 */
	public function register(): void {
		add_shortcode(
			'adam_member_area',
			array( $this, 'render' )
		);

		add_action(
			'wp_enqueue_scripts',
			array( $this, 'enqueue_assets' )
		);
	}

	/**
	 * Enqueue assets.
	 */
	public function enqueue_assets(): void {
		$asset_path = ADAM_MEMBERSHIP_PATH . 'assets/css/member-area.css';

		wp_enqueue_style(
			'adam-member-area',
			ADAM_MEMBERSHIP_URL . 'assets/css/member-area.css',
			array(),
			file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ADAM_MEMBERSHIP_VERSION
		);

		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/member-card.js';

		wp_enqueue_script(
			'adam-member-card',
			ADAM_MEMBERSHIP_URL . 'assets/js/member-card.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);
	}

	/**
	 * Render member area.
	 */
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			$message = $this->process_login();

			return $this->render_login( $message );
		}

		$member = $this->members->find( get_current_user_id() );

		if ( null === $member ) {
			return $this->render_not_found();
		}

		$this->handle_card_cosmetic_selection_request( $member );
		$this->handle_reward_redemption_request( $member );

		ob_start();
		?>
			<div class="adam-member-area adam-member-dashboard">
				<?php $this->renewals->maybe_send_renewal_reminder( $member ); ?>
				<?php $this->render_header( $member ); ?>
				<?php $this->render_account_notices(); ?>
				<?php $this->render_card_notices(); ?>
				<?php $this->render_reward_notices(); ?>

			<?php if ( 'recompensas' === $this->current_member_view() ) : ?>
				<?php $this->render_rewards_page( $member ); ?>
			<?php else : ?>

				<?php
				if ( $member->isPending() ) {
					$this->render_pending( $member );
				} elseif ( $member->isRenewalPending() ) {
					$this->render_renewal_pending( $member );
				} elseif ( $member->isExpired() ) {
					$this->render_expired( $member );
				} elseif ( $member->isRejected() ) {
					$this->render_rejected( $member );
				} elseif ( $member->isActive() ) {
					$this->render_active( $member );
				} else {
					$this->render_unknown_status();
				}

				$this->render_digital_card( $member );
				$this->render_personalization_section( $member );
				$this->render_points_card( $member );
				$this->render_documents( $member );
				$this->render_member_actions( $member );
				$this->render_announcements( $member );
				?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render login page.
	 *
	 * @param string $message Message to display.
	 */
	private function render_login( string $message = '' ): string {
		if ( isset( $_GET['logged_out'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['logged_out'] ) ) ) {
			$message = $this->notice_markup( 'success', __( 'Sessão terminada com sucesso.', 'adam-membership' ) );
		} elseif ( isset( $_GET['password_reset'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['password_reset'] ) ) ) {
			$message = $this->notice_markup( 'success', __( 'A sua palavra-passe foi alterada com sucesso. Pode agora iniciar sessão.', 'adam-membership' ) );
		}

		ob_start();
		?>
		<div class="adam-member-area adam-member-login">
			<section class="adam-login-panel adam-card" aria-labelledby="adam-member-login-title">
				<div class="adam-login-copy">
					<p class="adam-eyebrow"><?php esc_html_e( 'ADAM Membership', 'adam-membership' ); ?></p>
					<h2 id="adam-member-login-title"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Inicie sessão para acompanhar o estado da sua inscrição, consultar os seus dados e gerir o acesso à conta.', 'adam-membership' ); ?></p>
				</div>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post" class="adam-login-form">
					<?php wp_nonce_field( 'adam_member_login' ); ?>

					<div class="adam-form-field">
						<label for="adam_login"><?php esc_html_e( 'Email ou nome de utilizador', 'adam-membership' ); ?></label>
						<input
							type="text"
							id="adam_login"
							name="adam_login"
							required
							autocomplete="username"
						>
					</div>

					<div class="adam-form-field">
						<label for="adam_password"><?php esc_html_e( 'Palavra-passe', 'adam-membership' ); ?></label>
						<div class="adam-password-wrapper">
							<input
								type="password"
								id="adam_password"
								name="adam_password"
								required
								autocomplete="current-password"
							>
						</div>
					</div>

					<label class="adam-remember">
						<input type="checkbox" name="rememberme" value="1">
						<span><?php esc_html_e( 'Lembrar-me', 'adam-membership' ); ?></span>
					</label>

					<div class="adam-form-actions">
						<button type="submit" name="adam_login_submit" class="button button-primary adam-primary-action">
							<?php esc_html_e( 'Iniciar sessão', 'adam-membership' ); ?>
						</button>
						<a class="adam-text-link" href="<?php echo esc_url( home_url( '/recuperar-password/' ) ); ?>">
							<?php esc_html_e( 'Esqueceu-se da palavra-passe?', 'adam-membership' ); ?>
						</a>
					</div>
				</form>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Process login.
	 */
	private function process_login(): string {
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_login_submit'] )
		) {
			return '';
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'adam_member_login'
			)
		) {
			return $this->notice_markup( 'error', __( 'Pedido inválido.', 'adam-membership' ) );
		}

		$login = sanitize_text_field(
			wp_unslash( $_POST['adam_login'] ?? '' )
		);
		$identity = RateLimiter::request_identity( $login );

		if ( RateLimiter::too_many_attempts( 'member_login', $identity, 8, 15 * MINUTE_IN_SECONDS ) ) {
			return $this->notice_markup( 'error', __( 'Demasiadas tentativas. Tente novamente mais tarde.', 'adam-membership' ) );
		}

		RateLimiter::hit( 'member_login', $identity, 15 * MINUTE_IN_SECONDS );

		if ( is_email( $login ) ) {
			$user = get_user_by( 'email', $login );

			if ( $user instanceof \WP_User ) {
				$login = $user->user_login;
			}
		}

		$result = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => (string) wp_unslash( $_POST['adam_password'] ?? '' ),
				'remember'      => isset( $_POST['rememberme'] ),
			),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $this->notice_markup( 'error', __( 'Email ou palavra-passe incorretos.', 'adam-membership' ) );
		}

		wp_safe_redirect( home_url( '/socio/' ) );
		RateLimiter::clear( 'member_login', $identity );
		exit;
	}

	/**
	 * Render member not found.
	 */
	private function render_not_found(): string {
		ob_start();
		?>
		<div class="adam-member-area adam-member-dashboard">
			<section class="adam-card adam-empty-state">
				<p class="adam-eyebrow"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></p>
				<h2><?php esc_html_e( 'Informação indisponível', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'Não foi encontrada informação de associado para esta conta.', 'adam-membership' ); ?></p>
				<a class="adam-action-card" href="<?php echo esc_url( wp_logout_url( home_url( '/socio/?logged_out=1' ) ) ); ?>">
					<?php esc_html_e( 'Terminar sessão', 'adam-membership' ); ?>
				</a>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render page header.
	 *
	 * @param Member $member Member.
	 */
	private function render_header( Member $member ): void {
		$founder_number    = $member->founder_number();
		$card_presentation = $this->cards->card_presentation( $member );
		?>
		<header class="adam-member-hero">
			<div class="adam-member-hero__content">
				<p class="adam-eyebrow"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></p>
				<h2>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: member full name. */
							__( 'Bem-vindo, %s', 'adam-membership' ),
							$member->full_name()
						)
					);
					?>
				</h2>
				<p><?php esc_html_e( 'O seu painel central para acompanhar a inscrição, quota, dados de sócio e próximas funcionalidades da ADAM.', 'adam-membership' ); ?></p>
				<?php if ( $member->is_founder() ) : ?>
					<p class="adam-founder-hero-note">
						<?php echo esc_html( $founder_number > 0 ? sprintf( __( 'Membro Fundador ADAM | Fundador #%d', 'adam-membership' ), $founder_number ) : __( 'Membro Fundador ADAM', 'adam-membership' ) ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="adam-hero-status">
				<?php $this->render_status_badge( $member->effective_status() ); ?>
				<span><?php echo esc_html( (string) $member->field( 'numero_socio' ) ?: __( 'Número por atribuir', 'adam-membership' ) ); ?></span>
			</div>
		</header>
		<?php
	}

	/**
	 * Render account notices.
	 */
	private function render_account_notices(): void {
		if ( isset( $_GET['password_changed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['password_changed'] ) ) ) {
			echo wp_kses_post( $this->notice_markup( 'success', __( 'Palavra-passe alterada com sucesso.', 'adam-membership' ) ) );
			return;
		}

		if ( isset( $_GET['email_changed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['email_changed'] ) ) ) {
			echo wp_kses_post( $this->notice_markup( 'success', __( 'Endereço de email alterado com sucesso.', 'adam-membership' ) ) );
		}
	}

	/**
	 * Render card customization notices from redirects.
	 */
	private function render_card_notices(): void {
		$message = isset( $_GET['card_message'] ) ? sanitize_text_field( wp_unslash( $_GET['card_message'] ) ) : '';
		$error   = isset( $_GET['card_error'] ) ? sanitize_text_field( wp_unslash( $_GET['card_error'] ) ) : '';

		if ( '' !== $message ) {
			echo wp_kses_post( $this->notice_markup( 'success', $message ) );
		}

		if ( '' !== $error ) {
			echo wp_kses_post( $this->notice_markup( 'error', $error ) );
		}
	}

	/**
	 * Render reward notices from redirects.
	 */
	private function render_reward_notices(): void {
		$message = isset( $_GET['reward_message'] ) ? sanitize_text_field( wp_unslash( $_GET['reward_message'] ) ) : '';
		$error   = isset( $_GET['reward_error'] ) ? sanitize_text_field( wp_unslash( $_GET['reward_error'] ) ) : '';

		if ( '' !== $message ) {
			echo wp_kses_post( $this->notice_markup( 'success', $message ) );
		}

		if ( '' !== $error ) {
			echo wp_kses_post( $this->notice_markup( 'error', $error ) );
		}
	}

	/**
	 * Render pending dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_pending( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->status(),
				__( 'O seu pedido de inscrição foi recebido e encontra-se em análise pela ADAM.', 'adam-membership' )
			);

			$this->render_notifications_card(
				array(
					__( 'A equipa ADAM está a validar os seus dados e comprovativo de pagamento.', 'adam-membership' ),
					__( 'Receberá uma atualização assim que o processo for concluído.', 'adam-membership' ),
				)
			);

			$this->render_future_card();
			?>
		</div>
		<?php
	}

	/**
	 * Render renewal pending dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_renewal_pending( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->effective_status(),
				__( 'O seu pedido de renovação foi submetido e encontra-se em análise pela ADAM.', 'adam-membership' )
			);

			$this->render_membership( $member );

			$this->render_notifications_card(
				array(
					__( 'Receberá uma atualização assim que a renovação for confirmada.', 'adam-membership' ),
					__( 'Renovação em análise.', 'adam-membership' ),
				)
			);

			$this->render_profile( $member );
			?>
		</div>
		<?php
	}

	/**
	 * Render expired dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_expired( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->effective_status(),
				__( 'A sua quota expirou. Para voltar a ter a inscrição ativa, submeta a renovação.', 'adam-membership' )
			);

			$this->render_membership( $member );

			$this->render_notifications_card(
				array(
					__( 'A sua conta continua disponível para consultar dados e iniciar a renovação.', 'adam-membership' ),
				)
			);

			$this->render_profile( $member );
			?>
		</div>
		<?php
	}

	/**
	 * Render rejected dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_rejected( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->status(),
				__( 'Infelizmente a sua inscrição não foi aprovada. Caso pretenda mais informações, contacte a ADAM.', 'adam-membership' )
			);

			$this->render_notifications_card( $this->rejection_messages( $member ) );

			$this->render_profile( $member );
			?>
		</div>
		<?php
	}

	/**
	 * Render active dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_active( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->effective_status(),
				__( 'A sua inscrição encontra-se ativa. Pode consultar os seus dados e gerir o acesso à conta.', 'adam-membership' )
			);

			$this->render_membership( $member );

			$this->render_notifications_card(
				array(
					__( 'O seu cartão digital está disponível para validação através de QR code.', 'adam-membership' ),
				)
			);

			$this->render_profile( $member );
			?>
		</div>
		<?php
	}

	/**
	 * Render the digital membership card.
	 *
	 * @param Member $member Member.
	 */
	private function render_digital_card( Member $member ): void {
		if ( $member->isPending() || $member->isRejected() ) {
			return;
		}

		$photo_url          = $member->media_url( 'profile_photo' );
		$member_number      = (string) $member->field( 'numero_socio' );
		$member_number_text = '' !== $member_number ? $member_number : __( 'Por atribuir', 'adam-membership' );
		$joined_date        = $this->format_date( $member->field( 'data_adesao' ) );
		$expiry_date        = $this->format_date( $member->field( 'validade_quota' ) );
		$validation_url     = $this->cards->validation_url( $member );
		$qr_image_url       = $this->cards->qr_image_url( $member );
		$card_presentation  = $this->cards->card_presentation( $member );
		$card_classes       = implode( ' ', array_map( 'sanitize_html_class', (array) ( $card_presentation['classes'] ?? array( 'adam-digital-card' ) ) ) );
		?>
		<section class="adam-card adam-digital-card-section" aria-label="<?php esc_attr_e( 'Digital membership card', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Cartão digital', 'adam-membership' ); ?></p>
				</div>
				<div class="adam-card-actions">
					<button type="button" class="adam-card-link adam-card-print-button" data-adam-print-card><?php esc_html_e( 'Imprimir cartão', 'adam-membership' ); ?></button>
					<a class="adam-card-link" href="<?php echo esc_url( $validation_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Validar online', 'adam-membership' ); ?></a>
				</div>
			</div>
			<div class="adam-digital-card-mobile" aria-label="<?php esc_attr_e( 'Resumo do cartao digital', 'adam-membership' ); ?>">
				<div class="adam-digital-card-mobile__summary">
					<div class="adam-digital-card-mobile__identity">
						<span><?php esc_html_e( 'Cartao ADAM', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $member->full_name() ); ?></strong>
						<small><?php echo esc_html( $member_number_text ); ?></small>
					</div>
					<div class="adam-digital-card-mobile__status">
						<?php $this->render_status_badge( $member->effective_status() ); ?>
					</div>
				</div>
				<div class="adam-digital-card-mobile__meta">
					<div>
						<span><?php esc_html_e( 'Valido ate', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $expiry_date ? $expiry_date : __( 'Indisponivel', 'adam-membership' ) ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( 'Data de adesao', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $joined_date ? $joined_date : __( 'Indisponivel', 'adam-membership' ) ); ?></strong>
					</div>
				</div>
				<div class="adam-digital-card-mobile__utility">
					<div class="adam-digital-card-mobile__qr">
						<img src="<?php echo esc_url( $qr_image_url ); ?>" alt="<?php esc_attr_e( 'QR code for member validation', 'adam-membership' ); ?>">
					</div>
					<div class="adam-digital-card-mobile__actions">
						<a class="adam-card-link" href="<?php echo esc_url( $qr_image_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver QR', 'adam-membership' ); ?></a>
						<button type="button" class="adam-card-link adam-card-print-button" data-adam-print-card><?php esc_html_e( 'Imprimir / descarregar cartao', 'adam-membership' ); ?></button>
						<a class="adam-text-link" href="<?php echo esc_url( $validation_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Validar online', 'adam-membership' ); ?></a>
					</div>
				</div>
			</div>
			<article class="<?php echo esc_attr( $card_classes ); ?>" data-adam-card-preview aria-label="<?php esc_attr_e( 'ADAM digital membership card', 'adam-membership' ); ?>">
				<div class="adam-digital-card__shine" aria-hidden="true"></div>
				<header class="adam-digital-card__header">
					<img class="adam-digital-card__logo" src="<?php echo esc_url( $this->cards->association_logo_url() ); ?>" alt="<?php echo esc_attr( $this->cards->association_name() ); ?>">
					<div>
						<span><?php esc_html_e( 'Associação Desportiva', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $this->cards->association_name() ); ?></strong>
						<div class="adam-digital-card__badges">
							<?php if ( '' !== (string) ( $card_presentation['founder_badge'] ?? '' ) ) : ?>
								<small class="adam-digital-card__founder"><?php echo esc_html( (string) $card_presentation['founder_badge'] ); ?></small>
							<?php endif; ?>
							<?php if ( '' !== (string) ( $card_presentation['loyalty_badge'] ?? '' ) ) : ?>
								<small class="adam-digital-card__loyalty"><?php echo esc_html( (string) $card_presentation['loyalty_badge'] ); ?></small>
							<?php endif; ?>
						</div>
					</div>
					<?php $this->render_status_badge( $member->effective_status() ); ?>
				</header>

				<div class="adam-digital-card__body">
					<div class="adam-digital-card__photo">
						<?php if ( '' !== $photo_url ) : ?>
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $member->full_name() ); ?>">
						<?php else : ?>
							<span><?php echo esc_html( $this->member_initials( $member ) ); ?></span>
						<?php endif; ?>
					</div>

					<div class="adam-digital-card__identity">
						<span><?php esc_html_e( 'Nome do sócio', 'adam-membership' ); ?></span>
						<?php if ( is_array( $card_presentation['active_title'] ?? null ) ) : ?>
							<div class="adam-digital-card__rank">
								<small><?php esc_html_e( 'Titulo ativo', 'adam-membership' ); ?></small>
								<em class="adam-digital-card__title adam-digital-card__title--<?php echo esc_attr( sanitize_html_class( (string) $card_presentation['active_title']['rarity'] ) ); ?>">
									<span class="adam-digital-card__title-mark" aria-hidden="true"></span>
									<?php echo esc_html( (string) $card_presentation['active_title']['name'] ); ?>
								</em>
							</div>
						<?php endif; ?>
						<strong><?php echo esc_html( $member->full_name() ); ?></strong>
						<small><?php echo esc_html( '' !== $member_number ? $member_number : __( 'Número por atribuir', 'adam-membership' ) ); ?></small>
					</div>

					<div class="adam-digital-card__qr">
						<img src="<?php echo esc_url( $qr_image_url ); ?>" alt="<?php esc_attr_e( 'QR code for member validation', 'adam-membership' ); ?>">
						<span><?php esc_html_e( 'Validar cartão', 'adam-membership' ); ?></span>
					</div>
				</div>

				<div class="adam-digital-card__details" aria-label="<?php esc_attr_e( 'Membership details', 'adam-membership' ); ?>">
					<div>
						<span><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $member_number_text ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( 'Data de adesão', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $joined_date ? $joined_date : __( 'Indisponível', 'adam-membership' ) ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( 'Válido até', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $expiry_date ? $expiry_date : __( 'Indisponível', 'adam-membership' ) ); ?></strong>
					</div>
				</div>

				<footer class="adam-digital-card__footer">
					<span><?php esc_html_e( 'airsoftmondego.pt', 'adam-membership' ); ?></span>
					<span><?php esc_html_e( 'Cartão digital ADAM', 'adam-membership' ); ?></span>
				</footer>
			</article>
		</section>
		<?php
	}

	/**
	 * Render a dedicated member personalization area.
	 *
	 * @param Member $member Member.
	 */
	private function render_personalization_section( Member $member ): void {
		if ( $member->isPending() || $member->isRejected() ) {
			return;
		}

		$card_presentation = $this->cards->card_presentation( $member );
		$cosmetic_options  = $this->cards->member_cosmetic_options( $member );
		$total_options     = count( $cosmetic_options['titles'] ?? array() ) + count( $cosmetic_options['themes'] ?? array() ) + count( $cosmetic_options['frames'] ?? array() );
		?>
		<section id="adam-personalizacao" class="adam-card adam-card-personalization-section" aria-label="<?php esc_attr_e( 'Personalizacao do cartao', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Personalizacao', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Equipar titulos e cosmeticos desbloqueados', 'adam-membership' ); ?></h3>
				</div>
				<div class="adam-card-actions">
					<a class="adam-card-link" href="<?php echo esc_url( $this->member_area_url( array( 'view' => 'recompensas' ) ) ); ?>">
						<?php esc_html_e( 'Ver recompensas', 'adam-membership' ); ?>
					</a>
				</div>
			</div>

			<?php if ( 0 === $total_options ) : ?>
				<div class="adam-empty-inline">
					<?php esc_html_e( 'Ainda nao tens cosmeticos desbloqueados para equipar. Participa em eventos, acumula pontos e acompanha as recompensas ADAM.', 'adam-membership' ); ?>
				</div>
			<?php else : ?>
				<?php $this->render_card_customizer( $member, $cosmetic_options, $card_presentation ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render card customization controls.
	 *
	 * @param Member                                 $member             Member.
	 * @param array<string, array<int, array<string, mixed>>> $cosmetic_options Available options.
	 * @param array<string, mixed>                   $card_presentation Current card presentation.
	 */
	private function render_card_customizer( Member $member, array $cosmetic_options, array $card_presentation ): void {
		?>
		<form method="post" class="adam-card-customizer">
			<input type="hidden" name="adam_member_action" value="save_card_cosmetics">
			<?php wp_nonce_field( 'adam_member_save_card_cosmetics_' . $member->user_id() ); ?>

			<div class="adam-card-customizer__heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Personalizacao do cartao', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Escolher cosmeticos desbloqueados', 'adam-membership' ); ?></h3>
				</div>
				<button type="submit" class="adam-card-link"><?php esc_html_e( 'Guardar visual', 'adam-membership' ); ?></button>
			</div>

			<div class="adam-card-customizer__grid">
				<label>
					<span><?php esc_html_e( 'Titulo ativo', 'adam-membership' ); ?></span>
					<select name="active_title_reward">
						<?php $this->render_cosmetic_option( '', __( 'Predefinido ADAM', 'adam-membership' ), (string) ( $card_presentation['selected_values']['title'] ?? '' ) ); ?>
						<?php foreach ( $cosmetic_options['titles'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_cosmetic_option( (string) $cosmetic['key'], $this->cosmetic_option_label( $cosmetic ), (string) ( $card_presentation['selected_values']['title'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Fundo do cartao', 'adam-membership' ); ?></span>
					<select name="active_card_theme">
						<?php $this->render_cosmetic_option( '', __( 'Design ADAM predefinido', 'adam-membership' ), (string) ( $card_presentation['selected_values']['theme'] ?? '' ) ); ?>
						<?php foreach ( $cosmetic_options['themes'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_cosmetic_option( (string) $cosmetic['key'], $this->cosmetic_option_label( $cosmetic ), (string) ( $card_presentation['selected_values']['theme'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Moldura do cartao', 'adam-membership' ); ?></span>
					<select name="active_card_frame">
						<?php $this->render_cosmetic_option( '', __( 'Sem moldura especial', 'adam-membership' ), (string) ( $card_presentation['selected_values']['frame'] ?? '' ) ); ?>
						<?php foreach ( $cosmetic_options['frames'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_cosmetic_option( (string) $cosmetic['key'], $this->cosmetic_option_label( $cosmetic ), (string) ( $card_presentation['selected_values']['frame'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the member points card and optional history.
	 *
	 * @param Member $member Member.
	 */
	private function render_points_card( Member $member ): void {
		$balance        = $this->points->current_balance( $member );
		$total_earned   = $this->points->total_earned( $member );
		$recent_entries = $this->points->recent_activity( $member, 5 );
		$show_history   = isset( $_GET['points_history'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['points_history'] ) );
		$history_url    = add_query_arg( 'points_history', '1', home_url( '/socio/' ) );
		$back_url       = home_url( '/socio/' );
		?>
		<section class="adam-card adam-points-section" aria-label="<?php esc_attr_e( 'Pontos ADAM', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Pontos ADAM', 'adam-membership' ); ?></p>
					<h3><?php echo esc_html( sprintf( __( 'Tens %d Pontos ADAM', 'adam-membership' ), $balance ) ); ?></h3>
				</div>
				<div class="adam-card-actions">
					<a class="adam-card-link" href="<?php echo esc_url( $show_history ? $back_url : $history_url ); ?>">
						<?php echo esc_html( $show_history ? __( 'Voltar ao painel', 'adam-membership' ) : __( 'Ver histórico completo', 'adam-membership' ) ); ?>
					</a>
				</div>
			</div>

			<div class="adam-points-summary">
				<div class="adam-points-stat">
					<span><?php esc_html_e( 'Saldo atual', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong>
				</div>
				<div class="adam-points-stat">
					<span><?php esc_html_e( 'Total acumulado', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $total_earned ) ); ?></strong>
				</div>
			</div>

			<div class="adam-points-history">
				<?php
				$entries = $show_history ? $this->points->member_history( $member, array( 'limit' => 50 ) ) : $recent_entries;

				if ( array() === $entries ) :
					?>
					<div class="adam-empty-inline">
						<?php echo esc_html( $show_history ? __( 'Ainda não existem movimentos de pontos.', 'adam-membership' ) : __( 'Ainda não tens atividade de pontos registada.', 'adam-membership' ) ); ?>
					</div>
					<?php
				else :
					foreach ( $entries as $entry ) :
						$this->render_points_entry( $entry );
					endforeach;
				endif;
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render one points movement item.
	 *
	 * @param PointsEntry $entry Points entry.
	 */
	private function render_points_entry( PointsEntry $entry ): void {
		?>
		<article class="adam-points-entry">
			<div class="adam-points-entry__score<?php echo $entry->points() < 0 ? ' is-negative' : ' is-positive'; ?>">
				<?php echo esc_html( $entry->points() > 0 ? '+' . $entry->points() : (string) $entry->points() ); ?>
			</div>
			<div class="adam-points-entry__body">
				<strong><?php echo esc_html( $entry->reason() ); ?></strong>
				<span><?php echo esc_html( $this->points->source_label( $entry->source_type() ) ); ?></span>
			</div>
			<div class="adam-points-entry__date">
				<?php echo esc_html( $this->format_datetime( $entry->created_at() ) ); ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Render reward page.
	 *
	 * @param Member $member Member.
	 */
	private function render_rewards_page( Member $member ): void {
		$balance          = $this->points->current_balance( $member );
		$total_earned     = $this->points->total_earned( $member );
		$catalogue        = $this->rewards->member_catalogue( $member );
		$recent_rewards   = $this->rewards->recent_redeemed_rewards( $member, 3 );
		$reward_history   = $this->rewards->member_redemptions( $member, 20 );
		$back_url         = $this->member_area_url();
		$loyalty_progress = $this->recognition->loyalty_progress( $member );
		$card_selection   = $this->cards->card_presentation( $member );
		$equipped_keys    = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					(array) ( $card_selection['selected_values'] ?? array() )
				)
			)
		);
		?>
		<section class="adam-card adam-rewards-page" aria-label="<?php esc_attr_e( 'Recompensas ADAM', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Recompensas ADAM', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Desbloqueios, titulos e surpresas', 'adam-membership' ); ?></h3>
				</div>
				<div class="adam-card-actions">
					<a class="adam-card-link" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Voltar ao painel', 'adam-membership' ); ?></a>
				</div>
			</div>

			<div class="adam-rewards-summary">
				<div class="adam-points-stat">
					<span><?php esc_html_e( 'Pontos atuais', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong>
				</div>
				<div class="adam-points-stat">
					<span><?php esc_html_e( 'Total acumulado', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $total_earned ) ); ?></strong>
				</div>
				<div class="adam-reward-recent">
					<span><?php esc_html_e( 'Fidelidade ADAM', 'adam-membership' ); ?></span>
					<?php if ( null !== $loyalty_progress['next_tier'] ) : ?>
						<strong><?php echo esc_html( $loyalty_progress['next_tier']['elapsed_label'] ); ?></strong>
					<?php else : ?>
						<strong><?php esc_html_e( 'Todos os marcos de fidelidade desbloqueados.', 'adam-membership' ); ?></strong>
					<?php endif; ?>
				</div>
				<div class="adam-reward-recent">
					<span><?php esc_html_e( 'Resgates recentes', 'adam-membership' ); ?></span>
					<?php if ( array() === $recent_rewards ) : ?>
						<strong><?php esc_html_e( 'Ainda sem resgates aprovados.', 'adam-membership' ); ?></strong>
					<?php else : ?>
						<div class="adam-reward-recent__list">
							<?php foreach ( $recent_rewards as $redemption ) : ?>
								<span class="adam-reward-chip"><?php echo esc_html( $redemption->reward_name() ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="adam-reward-types">
				<span class="adam-reward-chip"><?php esc_html_e( 'Pontos ADAM', 'adam-membership' ); ?></span>
				<span class="adam-reward-chip"><?php esc_html_e( 'Fidelidade ADAM', 'adam-membership' ); ?></span>
				<span class="adam-reward-chip"><?php esc_html_e( 'Fundadores', 'adam-membership' ); ?></span>
				<span class="adam-reward-chip"><?php esc_html_e( 'Eventos Especiais', 'adam-membership' ); ?></span>
				<span class="adam-reward-chip"><?php esc_html_e( 'Exclusivos', 'adam-membership' ); ?></span>
			</div>

			<?php if ( $member->is_founder() ) : ?>
				<div class="adam-founder-panel">
					<strong><?php esc_html_e( 'Membro Fundador', 'adam-membership' ); ?></strong>
					<span><?php echo esc_html( $member->founder_number() > 0 ? sprintf( __( 'Um dos primeiros 50 sócios da ADAM. Fundador #%d.', 'adam-membership' ), $member->founder_number() ) : __( 'Um dos primeiros 50 sócios da ADAM.', 'adam-membership' ) ); ?></span>
				</div>
			<?php endif; ?>

			<div class="adam-reward-grid">
				<?php if ( array() === $catalogue ) : ?>
					<div class="adam-empty-inline adam-reward-grid__empty">
						<?php esc_html_e( 'Ainda nao existem recompensas ativas para resgate.', 'adam-membership' ); ?>
					</div>
				<?php else : ?>
					<?php foreach ( $catalogue as $reward ) : ?>
						<?php $this->render_reward_card( $member, $reward, $balance, $equipped_keys, $loyalty_progress ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>

		<section class="adam-card adam-reward-history-section" aria-label="<?php esc_attr_e( 'Historico de recompensas', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Historico', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Os teus resgates ADAM', 'adam-membership' ); ?></h3>
				</div>
			</div>

			<?php if ( array() === $reward_history ) : ?>
				<div class="adam-empty-inline">
					<?php esc_html_e( 'Ainda nao existem pedidos de recompensa registados.', 'adam-membership' ); ?>
				</div>
			<?php else : ?>
				<div class="adam-reward-history">
					<?php foreach ( $reward_history as $redemption ) : ?>
						<?php $this->render_reward_history_item( $redemption ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render one reward card.
	 *
	 * @param Member $member Member.
	 * @param Reward $reward Reward.
	 * @param int $balance Current points balance.
	 * @param array<int, string> $equipped_keys Equipped cosmetic reward keys.
	 * @param array<string, mixed> $loyalty_progress Loyalty progress.
	 */
	private function render_reward_card( Member $member, Reward $reward, int $balance, array $equipped_keys, array $loyalty_progress ): void {
		$owned            = $this->rewards->member_owns_reward( $member, $reward );
		$pending          = $this->rewards->member_has_pending_request( $member, $reward );
		$can_redeem       = $this->rewards->member_can_redeem( $member, $reward );
		$redeemable       = $reward->redeemable();
		$shortfall        = max( 0, $reward->points_cost() - $balance );
		$progress         = $reward->points_cost() > 0 ? min( 100, (int) floor( ( $balance / $reward->points_cost() ) * 100 ) ) : 100;
		$known_redemption = $owned ? $this->first_owned_reward_redemption( $member, $reward ) : null;
		$reward_key       = sanitize_key( $reward->reward_value() );
		$is_equipped      = in_array( $reward_key, $equipped_keys, true );
		$is_founder       = $this->rewards->is_founder_reward( $reward );
		$is_loyalty       = $this->rewards->is_loyalty_reward( $reward );
		$progress_label   = $this->reward_progress_label( $member, $reward, $owned, $pending, $can_redeem, $shortfall, $loyalty_progress );
		$cost_label       = $this->reward_cost_label( $reward );
		?>
		<article class="adam-reward-card adam-reward-card--<?php echo esc_attr( $reward->rarity() ); ?>">
			<div class="adam-reward-card__meta">
				<span class="adam-badge adam-reward-rarity adam-reward-rarity--<?php echo esc_attr( $reward->rarity() ); ?>"><?php echo esc_html( $this->reward_rarity_label( $reward ) ); ?></span>
				<span class="adam-announcement-category"><?php echo esc_html( $reward->category() ); ?></span>
				<?php if ( $owned && $is_equipped ) : ?>
					<span class="adam-badge active"><?php esc_html_e( 'Em uso', 'adam-membership' ); ?></span>
				<?php elseif ( $owned ) : ?>
					<span class="adam-badge active"><?php esc_html_e( 'Desbloqueada', 'adam-membership' ); ?></span>
				<?php elseif ( $pending ) : ?>
					<span class="adam-badge pending"><?php esc_html_e( 'Pendente', 'adam-membership' ); ?></span>
				<?php elseif ( $is_founder ) : ?>
					<span class="adam-badge warning"><?php esc_html_e( 'Fundadores', 'adam-membership' ); ?></span>
				<?php elseif ( $is_loyalty ) : ?>
					<span class="adam-badge warning"><?php esc_html_e( 'Fidelidade', 'adam-membership' ); ?></span>
				<?php elseif ( ! $redeemable ) : ?>
					<span class="adam-badge warning"><?php echo esc_html( $cost_label ); ?></span>
				<?php elseif ( $can_redeem ) : ?>
					<span class="adam-badge active"><?php esc_html_e( 'Disponivel', 'adam-membership' ); ?></span>
				<?php else : ?>
					<span class="adam-badge unknown"><?php esc_html_e( 'Bloqueada', 'adam-membership' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="adam-reward-card__body">
				<h4><?php echo esc_html( $reward->name() ); ?></h4>
			</div>

			<div class="adam-reward-card__stats">
				<div>
					<span><?php echo esc_html( $reward->points_cost() > 0 && $redeemable ? __( 'Pontos', 'adam-membership' ) : __( 'Desbloqueio', 'adam-membership' ) ); ?></span>
					<strong><?php echo esc_html( $cost_label ); ?></strong>
				</div>
			</div>

			<div class="adam-reward-progress" aria-hidden="true">
				<div class="adam-reward-progress__track">
					<span style="width: <?php echo esc_attr( (string) $progress ); ?>%;"></span>
				</div>
				<small><?php echo esc_html( $progress_label ); ?></small>
			</div>

			<div class="adam-reward-card__actions">
				<?php if ( $owned && $is_equipped ) : ?>
					<span class="adam-text-link"><?php esc_html_e( 'Atualmente equipada na tua conta', 'adam-membership' ); ?></span>
				<?php elseif ( $owned && $reward->is_single_claim() ) : ?>
					<a class="adam-text-link" href="#adam-personalizacao"><?php esc_html_e( 'Gerir na personalizacao', 'adam-membership' ); ?></a>
				<?php elseif ( $pending ) : ?>
					<span class="adam-text-link"><?php esc_html_e( 'Pedido em analise pela ADAM', 'adam-membership' ); ?></span>
				<?php elseif ( ! $redeemable ) : ?>
					<span class="adam-text-link"><?php echo esc_html( $progress_label ); ?></span>
				<?php elseif ( $can_redeem ) : ?>
					<form method="post" class="adam-reward-redeem-form">
						<input type="hidden" name="adam_member_action" value="redeem_reward">
						<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) $reward->id() ); ?>">
						<?php wp_nonce_field( 'adam_member_redeem_reward_' . $reward->id() ); ?>
						<button type="submit" class="adam-card-link"><?php esc_html_e( 'Resgatar', 'adam-membership' ); ?></button>
					</form>
				<?php else : ?>
					<button type="button" class="adam-card-link" disabled><?php esc_html_e( 'Pontos insuficientes', 'adam-membership' ); ?></button>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Render reward history item.
	 *
	 * @param RewardRedemption $redemption Redemption.
	 */
	private function render_reward_history_item( RewardRedemption $redemption ): void {
		?>
		<article class="adam-reward-history__item">
			<div class="adam-reward-history__main">
				<strong><?php echo esc_html( $redemption->reward_name() ); ?></strong>
				<span><?php echo esc_html( $this->reward_redemption_status_label( $redemption ) ); ?></span>
			</div>
			<div class="adam-reward-history__meta">
				<span><?php echo esc_html( sprintf( __( '%d pontos', 'adam-membership' ), $redemption->points_cost() ) ); ?></span>
				<span><?php echo esc_html( $this->format_datetime( $redemption->created_at() ) ); ?></span>
			</div>
		</article>
		<?php
	}

	/**
	 * Get initials for a member photo fallback.
	 *
	 * @param Member $member Member.
	 */
	private function member_initials( Member $member ): string {
		$parts    = preg_split( '/\s+/', trim( $member->full_name() ) );
		$initials = '';

		if ( is_array( $parts ) ) {
			foreach ( array_slice( $parts, 0, 2 ) as $part ) {
				$initials .= strtoupper( substr( $part, 0, 1 ) );
			}
		}

		return '' !== $initials ? $initials : 'AD';
	}

	/**
	 * Render unknown status state.
	 */
	private function render_unknown_status(): void {
		$this->render_status_card(
			__( 'Estado desconhecido', 'adam-membership' ),
			__( 'Não foi possível determinar o estado atual da sua inscrição.', 'adam-membership' )
		);
	}

	/**
	 * Render Communication Centre.
	 *
	 * @param Member $member Member.
	 */
	private function render_announcements( Member $member ): void {
		$selected_id   = isset( $_GET['announcement_id'] ) ? absint( wp_unslash( $_GET['announcement_id'] ) ) : 0;
		$announcements = $this->announcements->visible_for_member( $member );

		if ( array() === $announcements ) {
			return;
		}

		if ( $selected_id > 0 ) {
			$selected = $this->announcements->visible_announcement( $member, $selected_id );

			if ( null !== $selected ) {
				$this->announcements->mark_read( $member, $selected );
				$this->render_announcement_detail( $selected );
			}
		}
		?>
		<section class="adam-card adam-announcements-section" aria-label="<?php esc_attr_e( 'Centro de Avisos', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Centro de Avisos', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Comunicacoes oficiais da ADAM', 'adam-membership' ); ?></h3>
				</div>
			</div>

			<div class="adam-announcement-grid">
				<?php foreach ( $announcements as $announcement ) : ?>
					<?php $this->render_announcement_card( $member, $announcement ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a member-facing announcement card.
	 *
	 * @param Member       $member       Member.
	 * @param Announcement $announcement Announcement.
	 */
	private function render_announcement_card( Member $member, Announcement $announcement ): void {
		$card_classes = array( 'adam-announcement-card' );

		if ( $this->announcements->is_unread( $member, $announcement ) ) {
			$card_classes[] = 'is-unread';
		}

		if ( $announcement->pinned() ) {
			$card_classes[] = 'is-pinned';
		}
		?>
		<article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
			<div class="adam-announcement-card__meta">
				<span class="adam-announcement-category"><?php echo esc_html( $announcement->category() ); ?></span>
				<span class="adam-badge adam-announcement-priority adam-announcement-priority--<?php echo esc_attr( $announcement->priority() ); ?>"><?php echo esc_html( $this->announcement_priority_label( $announcement->priority() ) ); ?></span>
			</div>
			<h4><?php echo esc_html( $announcement->title() ); ?></h4>
			<p><?php echo esc_html( $announcement->summary() ); ?></p>
			<div class="adam-announcement-card__footer">
				<span><?php echo esc_html( $this->format_date( $announcement->publish_date() ) ); ?></span>
				<?php if ( '' !== $announcement->expiry_date() ) : ?>
					<span><?php echo esc_html( sprintf( __( 'Expira %s', 'adam-membership' ), $this->format_date( $announcement->expiry_date() ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<div class="adam-announcement-card__actions">
				<a class="adam-action-card adam-action-card--inline" href="<?php echo esc_url( add_query_arg( 'announcement_id', $announcement->id(), home_url( '/socio/' ) ) ); ?>">
					<?php esc_html_e( 'Ler mais', 'adam-membership' ); ?>
				</a>
				<?php if ( '' !== $announcement->action_label() && '' !== $announcement->action_url() ) : ?>
					<a class="adam-action-card adam-action-card--inline" href="<?php echo esc_url( $announcement->action_url() ); ?>">
						<?php echo esc_html( $announcement->action_label() ); ?>
					</a>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Render a selected announcement detail view.
	 *
	 * @param Announcement $announcement Announcement.
	 */
	private function render_announcement_detail( Announcement $announcement ): void {
		?>
		<section class="adam-card adam-announcement-detail" aria-label="<?php esc_attr_e( 'Detalhe do aviso', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Centro de Avisos', 'adam-membership' ); ?></p>
					<h3><?php echo esc_html( $announcement->title() ); ?></h3>
				</div>
				<span class="adam-badge adam-announcement-priority adam-announcement-priority--<?php echo esc_attr( $announcement->priority() ); ?>"><?php echo esc_html( $this->announcement_priority_label( $announcement->priority() ) ); ?></span>
			</div>
			<div class="adam-announcement-detail__meta">
				<span><?php echo esc_html( $announcement->category() ); ?></span>
				<span><?php echo esc_html( $this->format_date( $announcement->publish_date() ) ); ?></span>
				<?php if ( '' !== $announcement->expiry_date() ) : ?>
					<span><?php echo esc_html( sprintf( __( 'Expira %s', 'adam-membership' ), $this->format_date( $announcement->expiry_date() ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<div class="adam-announcement-detail__content">
				<?php echo wp_kses_post( wpautop( $announcement->content() ) ); ?>
			</div>
			<div class="adam-announcement-card__actions">
				<a class="adam-action-card adam-action-card--inline" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>"><?php esc_html_e( 'Voltar ao painel', 'adam-membership' ); ?></a>
				<?php if ( '' !== $announcement->action_label() && '' !== $announcement->action_url() ) : ?>
					<a class="adam-action-card adam-action-card--inline" href="<?php echo esc_url( $announcement->action_url() ); ?>"><?php echo esc_html( $announcement->action_label() ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render Document Centre.
	 *
	 * @param Member $member Member.
	 */
	private function render_documents( Member $member ): void {
		$filters   = $this->current_document_filters();
		$documents = $this->documents->visible_for_member( $member, $filters );

		if ( array() === $documents ) {
			return;
		}
		?>
		<section class="adam-card adam-documents-section" aria-label="<?php esc_attr_e( 'Documentos', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Documentos', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Documentos oficiais da ADAM', 'adam-membership' ); ?></h3>
				</div>
			</div>

			<form method="get" class="adam-document-filters">
				<label>
					<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
					<input type="search" name="document_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Pesquisar documentos', 'adam-membership' ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Categoria', 'adam-membership' ); ?></span>
					<select name="document_category">
						<?php $this->render_document_select_option( '', __( 'Todas', 'adam-membership' ), $filters['category'] ); ?>
						<?php foreach ( $this->documents->categories() as $category ) : ?>
							<?php $this->render_document_select_option( $category, $category, $filters['category'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="adam-card-link"><?php esc_html_e( 'Filtrar', 'adam-membership' ); ?></button>
				<a class="adam-text-link" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>"><?php esc_html_e( 'Limpar', 'adam-membership' ); ?></a>
			</form>

			<div class="adam-document-grid">
				<?php foreach ( $documents as $document ) : ?>
					<?php $this->render_document_card( $document ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a document card.
	 *
	 * @param Document $document Document.
	 */
	private function render_document_card( Document $document ): void {
		?>
		<article class="adam-document-card">
			<div class="adam-document-card__icon" aria-hidden="true"><?php echo esc_html( $this->document_file_icon( $document ) ); ?></div>
			<div class="adam-document-card__body">
				<div class="adam-document-card__meta">
					<span class="adam-announcement-category"><?php echo esc_html( $document->category() ); ?></span>
					<?php if ( $document->important() ) : ?>
						<span class="adam-badge adam-document-important"><?php esc_html_e( 'Importante', 'adam-membership' ); ?></span>
					<?php endif; ?>
				</div>
				<h4><?php echo esc_html( $document->title() ); ?></h4>
				<?php if ( '' !== $document->description() ) : ?>
					<p><?php echo esc_html( $document->description() ); ?></p>
				<?php endif; ?>
				<div class="adam-document-card__details">
					<span><?php echo esc_html( sprintf( __( 'Versao %s', 'adam-membership' ), $document->version() ) ); ?></span>
					<span><?php echo esc_html( sprintf( __( 'Enviado %s', 'adam-membership' ), $this->format_date( $document->upload_date() ) ) ); ?></span>
					<span><?php echo esc_html( sprintf( __( 'Atualizado %s', 'adam-membership' ), $this->format_datetime( $document->updated_at() ) ) ); ?></span>
					<span><?php echo esc_html( $this->format_file_size( $document->file_size() ) ); ?></span>
				</div>
				<a class="adam-action-card adam-action-card--inline" href="<?php echo esc_url( $this->documents->download_url( $document ) ); ?>">
					<?php esc_html_e( 'Download', 'adam-membership' ); ?>
				</a>
			</div>
		</article>
		<?php
	}

	/**
	 * Render status card.
	 *
	 * @param string $status  Status.
	 * @param string $message Message.
	 */
	private function render_status_card( string $status, string $message ): void {
		?>
		<section class="adam-card adam-status-card" aria-label="<?php esc_attr_e( 'Estado da inscrição', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Estado da inscrição', 'adam-membership' ); ?></p>
				<?php $this->render_status_badge( $status ); ?>
			</div>
			<p><?php echo esc_html( $message ); ?></p>
		</section>
		<?php
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Member status.
	 */
	private function render_status_badge( string $status ): void {
		printf(
			'<span class="adam-badge %1$s">%2$s</span>',
			esc_attr( $this->status_class( $status ) ),
			esc_html( $status )
		);
	}

	/**
	 * Render membership information.
	 *
	 * @param Member $member Member.
	 */
	private function render_membership( Member $member ): void {
		?>
		<section class="adam-card adam-membership-card" aria-label="<?php esc_attr_e( 'Quota e identificação', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Quota e identificação', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-data-list">
				<?php $this->render_data_item( __( 'N.º de sócio', 'adam-membership' ), (string) $member->field( 'numero_socio' ) ); ?>
				<?php $this->render_data_item( __( 'Data de adesão', 'adam-membership' ), $this->format_date( $member->field( 'data_adesao' ) ) ); ?>
				<?php $this->render_data_item( __( 'Validade da quota', 'adam-membership' ), $this->format_date( $member->field( 'validade_quota' ) ) ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render member profile.
	 *
	 * @param Member $member Member.
	 */
	private function render_profile( Member $member ): void {
		?>
		<section class="adam-card adam-profile-card" aria-label="<?php esc_attr_e( 'Dados do sócio', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Dados do sócio', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-data-list">
				<?php $this->render_data_item( __( 'Nome', 'adam-membership' ), $member->full_name() ); ?>
				<?php $this->render_data_item( __( 'Email', 'adam-membership' ), $member->email() ); ?>
				<?php $this->render_data_item( __( 'Telefone', 'adam-membership' ), (string) $member->field( 'telefone' ) ); ?>
				<?php $this->render_data_item( __( 'Equipa', 'adam-membership' ), (string) $member->field( 'equipa' ) ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render notifications card.
	 *
	 * @param array<int, string> $messages Notification messages.
	 */
	private function render_notifications_card( array $messages ): void {
		?>
		<section class="adam-card adam-notifications-card" aria-label="<?php esc_attr_e( 'Notificações', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Notificações', 'adam-membership' ); ?></p>
			</div>

			<ul class="adam-notification-list">
				<?php foreach ( $messages as $message ) : ?>
					<li><?php echo esc_html( $message ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Render future-ready member tools card.
	 */
	private function render_future_card(): void {
		?>
		<section class="adam-card adam-future-card" aria-label="<?php esc_attr_e( 'Funcionalidades em preparação', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Em preparação', 'adam-membership' ); ?></p>
				<h3><?php esc_html_e( 'Cartão, QR code e renovações', 'adam-membership' ); ?></h3>
			</div>
			<p><?php esc_html_e( 'Esta área está preparada para receber o cartão digital de sócio, QR code e gestão de renovações.', 'adam-membership' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Render standard account management actions.
	 */
	private function render_standard_account_actions(): void {
		$this->render_actions( $this->standard_account_actions() );
	}

	/**
	 * Handle reward redemption submissions.
	 *
	 * @param Member $member Member.
	 */
	private function handle_reward_redemption_request( Member $member ): void {
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_member_action'] ) ||
			'redeem_reward' !== sanitize_key( (string) wp_unslash( $_POST['adam_member_action'] ) )
		) {
			return;
		}

		$reward_id = isset( $_POST['reward_id'] ) ? absint( wp_unslash( $_POST['reward_id'] ) ) : 0;

		if ( $reward_id <= 0 ) {
			$this->redirect_member_notice( 'reward_error', __( 'A recompensa selecionada e invalida.', 'adam-membership' ), array( 'view' => 'recompensas' ) );
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_member_redeem_reward_' . $reward_id )
		) {
			$this->redirect_member_notice( 'reward_error', __( 'Nao foi possivel validar o pedido de recompensa.', 'adam-membership' ), array( 'view' => 'recompensas' ) );
		}

		if ( $member->isPending() || $member->isRejected() ) {
			$this->redirect_member_notice( 'reward_error', __( 'A tua conta nao pode resgatar recompensas neste estado.', 'adam-membership' ), array( 'view' => 'recompensas' ) );
		}

		$result = $this->rewards->redeem( $member, $reward_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_member_notice( 'reward_error', $result->get_error_message(), array( 'view' => 'recompensas' ) );
		}

		$message = RewardRedemption::STATUS_PENDING === $result->status()
			? __( 'Pedido de recompensa registado. Ficou pendente para validacao da ADAM.', 'adam-membership' )
			: __( 'Recompensa resgatada com sucesso.', 'adam-membership' );

		$this->redirect_member_notice( 'reward_message', $message, array( 'view' => 'recompensas' ) );
	}

	/**
	 * Handle card cosmetic selection submissions.
	 *
	 * @param Member $member Member.
	 */
	private function handle_card_cosmetic_selection_request( Member $member ): void {
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_member_action'] ) ||
			'save_card_cosmetics' !== sanitize_key( (string) wp_unslash( $_POST['adam_member_action'] ) )
		) {
			return;
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_member_save_card_cosmetics_' . $member->user_id() )
		) {
			$this->redirect_member_notice( 'card_error', __( 'Nao foi possivel validar a personalizacao do cartao.', 'adam-membership' ) );
		}

		$result = $this->cards->save_member_cosmetic_selection( $member, $_POST );

		if ( is_wp_error( $result ) ) {
			$this->redirect_member_notice( 'card_error', $result->get_error_message() );
		}

		$this->redirect_member_notice( 'card_message', __( 'Visual do cartao atualizado com sucesso.', 'adam-membership' ) );
	}

	/**
	 * Render member actions after feature sections.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_actions( Member $member ): void {
		if ( $member->isPending() || $member->isRejected() ) {
			$this->render_actions( $this->logout_actions() );
			return;
		}

		if ( $member->isActive() ) {
			$this->render_actions( $this->active_actions( $member ) );
			return;
		}

		if ( $member->isExpired() ) {
			$this->render_actions(
				array_merge(
					$this->renewal_actions( $member ),
					$this->standard_account_actions()
				)
			);
			return;
		}

		$this->render_standard_account_actions();
	}

	/**
	 * Build renewal actions for eligible members.
	 *
	 * @param Member $member Member.
	 * @return array<int,array{label:string,description:string,url:string}>
	 */
	private function renewal_actions( Member $member ): array {
		if ( ! $member->can_renew() ) {
			return array();
		}

		return array(
			array(
				'label'       => __( 'Renovar quota', 'adam-membership' ),
				'description' => '',
				'url'         => $this->settings->renewal_page_url(),
			),
		);
	}

	/**
	 * Build standard account management actions.
	 *
	 * @return array<int,array{label:string,description:string,url:string}>
	 */
	private function standard_account_actions(): array {
		return array(
			array(
				'label'       => __( 'Alterar palavra-passe', 'adam-membership' ),
				'description' => '',
				'url'         => home_url( '/socio-password/' ),
			),
			array(
				'label'       => __( 'Alterar email', 'adam-membership' ),
				'description' => '',
				'url'         => home_url( '/socio-email/' ),
			),
			array(
				'label'       => __( 'Terminar sessão', 'adam-membership' ),
				'description' => '',
				'url'         => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
			),
		);
	}

	/**
	 * Build logout-only actions.
	 *
	 * @return array<int,array{label:string,description:string,url:string}>
	 */
	private function logout_actions(): array {
		return array(
			array(
				'label'       => __( 'Terminar sessão', 'adam-membership' ),
				'description' => '',
				'url'         => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
			),
		);
	}

	/**
	 * Build the combined active member actions.
	 *
	 * @param Member $member Member.
	 * @return array<int,array{label:string,description:string,url:string}>
	 */
	private function active_actions( Member $member ): array {
		return array_merge(
			$this->renewal_actions( $member ),
			$this->standard_account_actions()
		);
	}

	/**
	 * Render a select option for a cosmetic choice.
	 *
	 * @param string $value   Option value.
	 * @param string $label   Option label.
	 * @param string $current Current selected value.
	 */
	private function render_cosmetic_option( string $value, string $label, string $current ): void {
		?>
		<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $current ); ?>>
			<?php echo esc_html( $label ); ?>
		</option>
		<?php
	}

	/**
	 * Build the select label for one cosmetic.
	 *
	 * @param array<string, mixed> $cosmetic Cosmetic metadata.
	 */
	private function cosmetic_option_label( array $cosmetic ): string {
		$name         = (string) ( $cosmetic['name'] ?? '' );
		$rarity_label = (string) ( $cosmetic['rarity_label'] ?? '' );

		if ( '' === $rarity_label ) {
			return $name;
		}

		return sprintf(
			/* translators: 1: cosmetic name, 2: cosmetic rarity */
			__( '%1$s — %2$s', 'adam-membership' ),
			$name,
			$rarity_label
		);
	}

	/**
	 * Get reward cost/unlock label for member-facing cards.
	 *
	 * @param Reward $reward Reward.
	 */
	private function reward_cost_label( Reward $reward ): string {
		if ( $reward->points_cost() > 0 && $reward->redeemable() ) {
			return (string) $reward->points_cost();
		}

		if ( $this->rewards->is_founder_reward( $reward ) ) {
			return __( 'Fundadores', 'adam-membership' );
		}

		if ( $this->rewards->is_loyalty_reward( $reward ) ) {
			return __( 'Requer renovacao', 'adam-membership' );
		}

		if ( $this->rewards->is_seasonal_reward( $reward ) ) {
			return __( 'Sazonal', 'adam-membership' );
		}

		if ( ! $reward->redeemable() ) {
			return __( 'Desbloqueio automatico', 'adam-membership' );
		}

		return __( 'Exclusivo', 'adam-membership' );
	}

	/**
	 * Build member-facing reward progress text.
	 *
	 * @param Member $member Member.
	 * @param Reward $reward Reward.
	 * @param bool $owned Whether the reward is already unlocked.
	 * @param bool $pending Whether the reward has a pending request.
	 * @param bool $can_redeem Whether the reward can be redeemed now.
	 * @param int $shortfall Missing points.
	 * @param array<string, mixed> $loyalty_progress Loyalty progress payload.
	 */
	private function reward_progress_label( Member $member, Reward $reward, bool $owned, bool $pending, bool $can_redeem, int $shortfall, array $loyalty_progress ): string {
		if ( $owned ) {
			return __( 'Desbloqueada e pronta a usar na tua personalizacao.', 'adam-membership' );
		}

		if ( $pending ) {
			return __( 'Pedido em analise pela ADAM.', 'adam-membership' );
		}

		if ( $this->rewards->is_founder_reward( $reward ) ) {
			return $member->is_founder()
				? __( 'Exclusivo de fundador. Sera desbloqueada automaticamente na tua conta.', 'adam-membership' )
				: __( 'Exclusivo para um dos primeiros 50 socios da ADAM.', 'adam-membership' );
		}

		if ( $this->rewards->is_loyalty_reward( $reward ) ) {
			$tier = $this->loyalty_tier_for_reward( $reward );

			if ( ! $member->isActive() ) {
				return __( 'Requer associacao ativa e renovacoes confirmadas.', 'adam-membership' );
			}

			if ( null === $tier ) {
				return __( 'Desbloqueio automatico por fidelidade ADAM.', 'adam-membership' );
			}

			if ( (int) ( $loyalty_progress['completed_years'] ?? 0 ) >= $tier['years'] ) {
				return __( 'Elegivel para desbloqueio automatico pela fidelidade ADAM.', 'adam-membership' );
			}

			return $this->loyalty_elapsed_label( (int) ( $loyalty_progress['completed_months'] ?? 0 ), $tier['years'] );
		}

		if ( ! $reward->redeemable() ) {
			return $this->reward_cost_label( $reward );
		}

		if ( $can_redeem ) {
			return __( 'Disponivel para resgate', 'adam-membership' );
		}

		return sprintf(
			/* translators: %d: missing points. */
			__( 'Faltam %d pontos', 'adam-membership' ),
			$shortfall
		);
	}

	/**
	 * Resolve the loyalty tier attached to a reward.
	 *
	 * @param Reward $reward Reward.
	 * @return array{years:int,label:string,rewards:array<int,string>}|null
	 */
	private function loyalty_tier_for_reward( Reward $reward ): ?array {
		$reward_key = sanitize_key( $reward->reward_value() );

		foreach ( $this->recognition->loyalty_tiers() as $tier ) {
			if ( in_array( $reward_key, $tier['rewards'], true ) ) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Format progress against a loyalty milestone.
	 */
	private function loyalty_elapsed_label( int $completed_months, int $target_years ): string {
		$months_total = max( 0, $completed_months );
		$years        = intdiv( $months_total, 12 );
		$months       = $months_total % 12;

		if ( $months > 0 ) {
			return sprintf(
				/* translators: 1: completed years, 2: plural suffix, 3: completed months, 4: plural suffix, 5: target years */
				__( '%1$d ano%2$s e %3$d mes%4$s / %5$d anos', 'adam-membership' ),
				$years,
				1 === $years ? '' : 's',
				$months,
				1 === $months ? '' : 'es',
				$target_years
			);
		}

		return sprintf(
			/* translators: 1: completed years, 2: plural suffix, 3: target years */
			__( '%1$d ano%2$s / %3$d anos', 'adam-membership' ),
			$years,
			1 === $years ? '' : 's',
			$target_years
		);
	}

	/**
	 * Get current member subview.
	 */
	private function current_member_view(): string {
		return isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
	}

	/**
	 * Build member area URL with query args.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	private function member_area_url( array $args = array() ): string {
		$url = home_url( '/socio/' );

		if ( array() === $args ) {
			return $url;
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * Redirect member area with a notice message.
	 *
	 * @param string               $key   Query-string key.
	 * @param string               $text  Notice text.
	 * @param array<string,string> $args  Extra query args.
	 */
	private function redirect_member_notice( string $key, string $text, array $args = array() ): void {
		$args[ $key ] = $text;
		wp_safe_redirect( $this->member_area_url( $args ) );
		exit;
	}

	/**
	 * Get the first owned redemption entry for a reward.
	 *
	 * @param Member $member Member.
	 * @param Reward $reward Reward.
	 */
	private function first_owned_reward_redemption( Member $member, Reward $reward ): ?RewardRedemption {
		foreach ( $this->rewards->member_redemptions( $member ) as $redemption ) {
			if (
				$redemption->reward_id() === $reward->id() &&
				in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true )
			) {
				return $redemption;
			}
		}

		return null;
	}

	/**
	 * Get translated reward rarity label.
	 *
	 * @param Reward $reward Reward.
	 */
	private function reward_rarity_label( Reward $reward ): string {
		$labels = $this->rewards->rarity_labels();

		return $labels[ $reward->rarity() ] ?? $reward->rarity();
	}

	/**
	 * Get translated reward redemption status label.
	 *
	 * @param RewardRedemption $redemption Redemption.
	 */
	private function reward_redemption_status_label( RewardRedemption $redemption ): string {
		$labels = $this->rewards->redemption_status_labels();

		return $labels[ $redemption->status() ] ?? $redemption->status();
	}

	/**
	 * Get safe rejection messages for the member.
	 *
	 * @param Member $member Member.
	 * @return array<int, string>
	 */
	private function rejection_messages( Member $member ): array {
		$messages = array(
			__( 'A sua inscrição foi analisada e não foi aprovada pela ADAM.', 'adam-membership' ),
			__( 'Caso pretenda mais informações, contacte a Direção da ADAM.', 'adam-membership' ),
		);

		$reason = $member->field( 'motivo_rejeicao' );

		if ( is_scalar( $reason ) && '' !== trim( (string) $reason ) ) {
			$messages[] = sprintf(
				/* translators: %s: rejection reason. */
				__( 'Motivo indicado: %s', 'adam-membership' ),
				trim( (string) $reason )
			);
		}

		return $messages;
	}

	/**
	 * Render action links.
	 *
	 * @param array<int,array{label:string,description:string,url:string}> $actions Actions.
	 */
	private function render_actions( array $actions ): void {
		?>
		<section class="adam-card adam-actions-card" aria-label="<?php esc_attr_e( 'Ações da conta', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Ações', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-action-grid">
				<?php foreach ( $actions as $action ) : ?>
					<a class="adam-action-card" href="<?php echo esc_url( $action['url'] ); ?>">
						<strong><?php echo esc_html( $action['label'] ); ?></strong>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a data item.
	 *
	 * @param string $label Data label.
	 * @param string $value Data value.
	 */
	private function render_data_item( string $label, string $value ): void {
		?>
		<div class="adam-data-item">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( '' !== $value ? $value : __( 'Por preencher', 'adam-membership' ) ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Build notice markup.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 */
	private function notice_markup( string $type, string $message ): string {
		$role = 'error' === $type ? 'alert' : 'status';

		return sprintf(
			'<div class="notice notice-%1$s adam-member-notice" role="%2$s"><p>%3$s</p></div>',
			esc_attr( $type ),
			esc_attr( $role ),
			esc_html( $message )
		);
	}

	/**
	 * Convert a member status into a badge class.
	 *
	 * @param string $status Member status.
	 */
	private function status_class( string $status ): string {
		if ( Member::STATUS_ACTIVE === $status ) {
			return 'active';
		}

		if ( Member::STATUS_REJECTED === $status ) {
			return 'rejected expired';
		}

		if ( Member::STATUS_EXPIRED === $status ) {
			return 'expired';
		}

		if ( Member::STATUS_RENEWAL_PENDING === $status ) {
			return 'pending warning renewal-pending';
		}

		if ( Member::STATUS_PENDING === $status ) {
			return 'pending warning';
		}

		return 'unknown';
	}

	/**
	 * Format stored dates for display.
	 *
	 * @param mixed $date Raw date value.
	 */
	private function format_date( mixed $date ): string {
		if ( ! is_scalar( $date ) ) {
			return '';
		}

		$date = trim( (string) $date );

		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 6, 2 ) . '/' . substr( $date, 4, 2 ) . '/' . substr( $date, 0, 4 );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $date, 0, 4 );
		}

		return $date;
	}

	/**
	 * Get a translated announcement priority label.
	 *
	 * @param string $priority Priority.
	 */
	private function announcement_priority_label( string $priority ): string {
		return match ( $priority ) {
			Announcement::PRIORITY_IMPORTANT => __( 'Importante', 'adam-membership' ),
			Announcement::PRIORITY_URGENT    => __( 'Urgente', 'adam-membership' ),
			default                          => __( 'Informacao', 'adam-membership' ),
		};
	}

	/**
	 * Get current document filters.
	 *
	 * @return array{search:string,category:string}
	 */
	private function current_document_filters(): array {
		return array(
			'search'   => isset( $_GET['document_search'] ) ? sanitize_text_field( wp_unslash( $_GET['document_search'] ) ) : '',
			'category' => isset( $_GET['document_category'] ) ? sanitize_text_field( wp_unslash( $_GET['document_category'] ) ) : '',
		);
	}

	/**
	 * Render document select option.
	 *
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param string $current Current value.
	 */
	private function render_document_select_option( string $value, string $label, string $current ): void {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * Get compact file icon label.
	 *
	 * @param Document $document Document.
	 */
	private function document_file_icon( Document $document ): string {
		$extension = strtolower( pathinfo( $document->file_name(), PATHINFO_EXTENSION ) );

		return match ( $extension ) {
			'pdf'                 => 'PDF',
			'doc', 'docx'         => 'DOC',
			'xls', 'xlsx'         => 'XLS',
			'ppt', 'pptx'         => 'PPT',
			'jpg', 'jpeg', 'png'  => 'IMG',
			default               => 'FILE',
		};
	}

	/**
	 * Format file size.
	 *
	 * @param int $bytes File size in bytes.
	 */
	private function format_file_size( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return __( 'Tamanho indisponivel', 'adam-membership' );
		}

		$units = array( 'B', 'KB', 'MB', 'GB' );
		$size  = (float) $bytes;
		$unit  = 0;

		while ( $size >= 1024 && $unit < count( $units ) - 1 ) {
			$size /= 1024;
			++$unit;
		}

		return sprintf( '%1$s %2$s', number_format_i18n( $size, $unit > 0 ? 1 : 0 ), $units[ $unit ] );
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

		return wp_date( 'd/m/Y', $timestamp );
	}
}
