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
use AdamMembership\Communication\CommunicationPreferences;
use AdamMembership\Communication\CommunicationPreferencesController;
use AdamMembership\Core\SettingsRepository;
use AdamMembership\Core\ManagedPages;
use AdamMembership\Core\DisplayLabels;
use AdamMembership\Form\SharedFieldValidator;
use AdamMembership\Form\IdentificationValidator;
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
	private AccountSetup $account_setup;
	private RecognitionService $recognition;

	/**
	 * Communication preferences.
	 *
	 * @var CommunicationPreferences
	 */
	private CommunicationPreferences $communication_preferences;
	private ApdAssociationService $apd_association;
	private MemberChangeService $member_changes;

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
	 * @param AccountSetup        $account_setup Account setup service.
	 * @param RecognitionService       $recognition               Recognition service.
	 * @param CommunicationPreferences $communication_preferences Communication preferences.
	 */
	public function __construct( MemberRepository $members, RenewalService $renewals, SettingsRepository $settings, CardService $cards, AnnouncementService $announcements, DocumentService $documents, PointsService $points, RewardService $rewards, AccountSetup $account_setup, RecognitionService $recognition, CommunicationPreferences $communication_preferences, ApdAssociationService $apd_association, MemberChangeService $member_changes ) {
		$this->members       = $members;
		$this->renewals      = $renewals;
		$this->settings      = $settings;
		$this->cards         = $cards;
		$this->announcements = $announcements;
		$this->documents     = $documents;
		$this->points        = $points;
		$this->rewards       = $rewards;
		$this->account_setup = $account_setup;
		$this->recognition   = $recognition;
		$this->communication_preferences = $communication_preferences;
		$this->apd_association = $apd_association;
		$this->member_changes = $member_changes;
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
		$form_asset_path = ADAM_MEMBERSHIP_PATH . 'assets/css/membership-forms.css';
		$rewards_script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/member-rewards.js';
		$preferences_script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/member-communication-preferences.js';

		wp_enqueue_style(
			'adam-member-area',
			ADAM_MEMBERSHIP_URL . 'assets/css/member-area.css',
			array(),
			file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ADAM_MEMBERSHIP_VERSION
		);

		if ( ! $this->should_enqueue_download_assets() ) {
			return;
		}

		wp_enqueue_script(
			'adam-member-communication-preferences',
			ADAM_MEMBERSHIP_URL . 'assets/js/member-communication-preferences.js',
			array(),
			file_exists( $preferences_script_path ) ? (string) filemtime( $preferences_script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);
		wp_enqueue_style( 'adam-membership-forms', ADAM_MEMBERSHIP_URL . 'assets/css/membership-forms.css', array( 'adam-member-area' ), file_exists( $form_asset_path ) ? (string) filemtime( $form_asset_path ) : ADAM_MEMBERSHIP_VERSION );

		wp_add_inline_script(
			'adam-member-communication-preferences',
			'window.adamCommunicationPreferences = ' . wp_json_encode( CommunicationPreferencesController::script_config() ) . ';',
			'before'
		);

		wp_enqueue_script(
			'adam-member-rewards',
			ADAM_MEMBERSHIP_URL . 'assets/js/member-rewards.js',
			array(),
			file_exists( $rewards_script_path ) ? (string) filemtime( $rewards_script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);

		$html2canvas_path = ADAM_MEMBERSHIP_PATH . 'assets/vendor/html2canvas/html2canvas.min.js';
		$download_path    = ADAM_MEMBERSHIP_PATH . 'assets/js/member-card-download.js';

		wp_enqueue_script(
			'html2canvas',
			ADAM_MEMBERSHIP_URL . 'assets/vendor/html2canvas/html2canvas.min.js',
			array(),
			file_exists( $html2canvas_path ) ? (string) filemtime( $html2canvas_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);

		wp_enqueue_script(
			'adam-member-card-download',
			ADAM_MEMBERSHIP_URL . 'assets/js/member-card-download.js',
			array( 'html2canvas' ),
			file_exists( $download_path ) ? (string) filemtime( $download_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);

		wp_add_inline_script(
			'adam-member-card-download',
			'window.adamMemberCardDownload = ' . wp_json_encode(
				array(
					'messages' => array(
						'preparing'        => __( 'A preparar PNG...', 'adam-membership' ),
						'defaultLabel'     => __( 'Descarregar cartão PNG', 'adam-membership' ),
						'errorNoLibrary'   => __( 'A biblioteca de captura não está disponível.', 'adam-membership' ),
						'errorNoCard'      => __( 'Não foi possível encontrar o cartão digital visível.', 'adam-membership' ),
						'errorHiddenCard'  => __( 'O cartão digital tem de estar visível para descarregar o PNG.', 'adam-membership' ),
						'errorCrossOrigin' => __( 'Existe uma imagem externa no cartão que impede a captura do PNG.', 'adam-membership' ),
						'errorCapture'     => __( 'Não foi possível gerar o PNG do cartão.', 'adam-membership' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Determine whether the member-card download assets should be enqueued.
	 */
	private function should_enqueue_download_assets(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( (string) $post->post_content, 'adam_member_area' );
	}

	/**
	 * Render member area.
	 */
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			$message = $this->process_login();

			return $this->render_login( $message );
		}
		if ( 'correction' === $this->current_member_view() && '1' === (string) ( $_GET['correction_complete'] ?? '' ) ) {
			return '<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow">PEDIDO RECEBIDO</p><h2>Correção submetida</h2><p>Recebemos as correções ao seu pedido. A informação corrigida foi enviada para nova análise pela ADAM.</p><a class="button button-primary" href="' . esc_url( $this->member_area_url() ) . '">Voltar à Área de Sócio</a></div></section></div>';
		}

		$member = $this->members->find( get_current_user_id() );

		if ( null === $member ) {
			return $this->render_not_found();
		}

		$this->handle_card_cosmetic_selection_request( $member );
		$this->handle_reward_redemption_request( $member );
		if ( 'apd-association' === $this->current_member_view() ) {
			if ( '1' === (string) ( $_GET['correction'] ?? '' ) ) { return $this->render_apd_correction_page( $member, absint( $_GET['request_id'] ?? $_POST['request_id'] ?? 0 ) ); }
			if ( '1' === (string) ( $_GET['apd_confirmation'] ?? '' ) ) { return $this->render_apd_confirmation_page( $member, absint( $_GET['request_id'] ?? 0 ) ); }
			return $this->render_apd_association_page( $member );
		}
		if ( 'member-update' === $this->current_member_view() ) {
			if ( '1' === (string) ( $_GET['member_update_confirmation'] ?? '' ) ) { return $this->render_member_update_confirmation_page( $member, absint( $_GET['request_id'] ?? 0 ) ); }
			return $this->render_member_update_page( $member );
		}
		if ( 'correction' === $this->current_member_view() ) {
			$correction_user = absint( $_GET['correction_user'] ?? 0 );
			$correction_token = sanitize_text_field( wp_unslash( $_GET['correction_token'] ?? '' ) );
			if ( $correction_user > 0 && ( $correction_user !== $member->user_id() || ! hash_equals( hash_hmac( 'sha256', (string) $member->user_id(), wp_salt( 'auth' ) ), $correction_token ) ) ) { return $this->render_not_found(); }
			if ( 'correction_requested' === (string) $member->field( 'adam_correction_status' ) ) { return $this->render_registration_correction_v2( $member ); }
			return $this->render_member_correction_page( $member );
		}
		$this->recognition->grant_eligible_loyalty_rewards( $member );

		ob_start();
		?>
			<div class="adam-member-area adam-member-dashboard">
				<?php $this->renewals->maybe_send_renewal_reminder( $member ); ?>
				<?php $this->render_header( $member ); ?>
				<?php $this->render_account_notices(); ?>
				<?php $this->render_card_notices(); ?>
				<?php $this->render_reward_notices(); ?>

			<?php if ( 'recompensas' === $this->current_member_view() ) : ?>
			<?php $this->render_rewards_catalogue_page( $member ); ?>
			<?php elseif ( 'avisos' === $this->current_member_view() ) : ?>
			<?php $this->render_announcements( $member, false, true ); ?>
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
				$this->render_announcements( $member, true );
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
						<button type="submit" name="adam_login_submit" class="button button-primary adam-primary-action adam-button">
							<?php esc_html_e( 'Iniciar sessão', 'adam-membership' ); ?>
						</button>
						<a class="adam-text-link" href="<?php echo esc_url( ManagedPages::url( 'password_recovery' ) ); ?>">
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
		} else {
			$login = $this->account_setup->resolve_login_identifier( $login );
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

		wp_safe_redirect( ManagedPages::url( 'member_area' ) );
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
				<a class="adam-action-card adam-card" href="<?php echo esc_url( wp_logout_url( add_query_arg( 'logged_out', '1', ManagedPages::url( 'member_area' ) ) ) ); ?>">
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
		$correction = 'correction_requested' === (string) $member->field( 'adam_correction_status' );
		if ( 'correction_submitted' === (string) $member->field( 'adam_correction_status' ) ) {
			$this->render_status_card( 'Correção submetida — A aguardar nova análise', 'A ADAM irá analisar novamente a informação enviada.' );
			$this->render_profile( $member );
			return;
		}
		if ( $correction ) {
			$this->render_status_card( 'Necessita de correção', 'Motivo: ' . (string) $member->field( 'adam_correction_reason' ) . ( $member->field( 'adam_correction_note' ) ? ' — ' . (string) $member->field( 'adam_correction_note' ) : '' ) );
			$this->render_notifications_card( array( 'A sua inscrição necessita de correções.', 'O que precisa de corrigir: ' . (string) $member->field( 'adam_correction_note' ) ) );
			$this->render_actions( array( array( 'label' => 'Corrigir pedido', 'description' => '', 'url' => $this->member_area_url( array( 'view' => 'correction' ) ) ) ) );
			$this->render_profile( $member );
			return;
		}
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->status(),
				sprintf( __( 'Infelizmente a sua inscrição não foi aprovada. Caso necessite de ajuda ou esclarecimentos, contacte-nos através de %s.', 'adam-membership' ), $this->settings->support_email() )
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

		$card_data          = $this->cards->card_data( $member );
		$member_number_text = (string) $card_data['member_number_ui'];
		$joined_date        = (string) $card_data['joined_date'];
		$expiry_date        = (string) $card_data['expiry_date'];
		$validation_url     = (string) $card_data['validation_url'];
		$qr_image_url       = (string) $card_data['qr_image_url'];
		$card_presentation  = $this->cards->card_presentation( $member );
		$download_filename  = 'cartao-adam-' . sanitize_file_name( '' !== $member_number_text ? $member_number_text : (string) $member->user_id() ) . '.png';
		?>
		<section class="adam-card adam-digital-card-section" data-adam-ui-ignore aria-label="<?php esc_attr_e( 'Digital membership card', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Cartão digital', 'adam-membership' ); ?></p>
				</div>
				<div class="adam-card-actions">
					<a class="adam-card-link adam-card-download-button" href="#" data-adam-card-download="png" data-adam-card-filename="<?php echo esc_attr( $download_filename ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'Descarregar cartão PNG', 'adam-membership' ); ?></a>
					<a class="adam-card-link" href="<?php echo esc_url( $validation_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Validar online', 'adam-membership' ); ?></a>
				</div>
			</div>
			<div class="adam-digital-card-mobile" aria-label="<?php esc_attr_e( 'Resumo do cartao digital', 'adam-membership' ); ?>">
				<div class="adam-digital-card-mobile__summary">
					<div class="adam-digital-card-mobile__identity">
						<span><?php esc_html_e( 'Cartao ADAM', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( (string) $card_data['member_name'] ); ?></strong>
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
						<a class="adam-card-link adam-card-download-button" href="#" data-adam-card-download="png" data-adam-card-filename="<?php echo esc_attr( $download_filename ); ?>" rel="noopener noreferrer"><?php esc_html_e( 'Descarregar cartão PNG', 'adam-membership' ); ?></a>
						<a class="adam-text-link" href="<?php echo esc_url( $validation_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Validar online', 'adam-membership' ); ?></a>
					</div>
				</div>
			</div>
			<?php echo $this->cards->render_card( $card_data, $card_presentation ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
					<span><?php esc_html_e( 'Acabamento do cartao', 'adam-membership' ); ?></span>
					<select name="active_card_frame">
						<?php $this->render_cosmetic_option( '', __( 'Sem acabamento especial', 'adam-membership' ), (string) ( $card_presentation['selected_values']['frame'] ?? '' ) ); ?>
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
		$history_url    = add_query_arg( 'points_history', '1', ManagedPages::url( 'member_area' ) );
		$back_url       = ManagedPages::url( 'member_area' );
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
				<div class="adam-founder-panel adam-card">
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
		$presentation     = $this->rewards->reward_card_presentation( $reward );
		$style            = (array) ( $presentation['style'] ?? array() );
		?>
		<article class="adam-reward-card adam-reward-card--<?php echo esc_attr( $reward->rarity() ); ?> <?php echo esc_attr( (string) ( $presentation['badge_style_class'] ?? '' ) ); ?> <?php echo esc_attr( (string) ( $presentation['effect_class'] ?? '' ) ); ?> <?php echo esc_attr( (string) ( $presentation['frame_style_class'] ?? '' ) ); ?> <?php echo esc_attr( (string) ( $presentation['corner_style_class'] ?? '' ) ); ?>" style="<?php echo esc_attr( (string) ( $presentation['inline_style'] ?? '' ) ); ?>">
			<div class="adam-reward-card__background"></div>
			<div class="adam-reward-card__pattern <?php echo esc_attr( (string) ( $presentation['pattern_class'] ?? 'adam-reward-card__pattern--grid' ) ); ?>"></div>
			<?php if ( '' !== (string) ( $style['background_image_url'] ?? '' ) ) : ?>
				<div class="adam-reward-card__backdrop" style="background-image:url('<?php echo esc_url( (string) $style['background_image_url'] ); ?>');"></div>
			<?php endif; ?>
			<?php if ( '' !== $reward->image_url() ) : ?>
				<div class="adam-reward-card__art <?php echo esc_attr( (string) ( $presentation['image_position_class'] ?? 'adam-reward-card__art--top-right' ) ); ?> <?php echo esc_attr( (string) ( $presentation['image_layer_class'] ?? 'adam-reward-card__art--layer-overlay' ) ); ?>">
					<img src="<?php echo esc_url( $reward->image_url() ); ?>" alt="">
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $style['shapes'] ) && is_array( $style['shapes'] ) ) : ?>
				<div class="adam-reward-card__shapes">
					<?php foreach ( $style['shapes'] as $shape ) : ?>
						<?php $this->render_reward_shape_preview( (array) $shape ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="adam-reward-card__content">
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
					<span class="adam-badge warning adam-notice--warning"><?php esc_html_e( 'Fundadores', 'adam-membership' ); ?></span>
				<?php elseif ( $is_loyalty ) : ?>
					<span class="adam-badge warning adam-notice--warning"><?php esc_html_e( 'Fidelidade', 'adam-membership' ); ?></span>
				<?php elseif ( ! $redeemable ) : ?>
					<span class="adam-badge warning adam-notice--warning"><?php echo esc_html( $cost_label ); ?></span>
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
				<?php elseif ( $owned || $is_loyalty ) : ?>
					<span class="adam-text-link"><?php echo esc_html( $progress_label ); ?></span>
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
			</div>
		</article>
		<?php
	}

	/**
	 * Render one decorative reward card shape.
	 *
	 * @param array<string, mixed> $shape Shape payload.
	 */
	private function render_reward_shape_preview( array $shape ): void {
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
	private function render_rewards_catalogue_page( Member $member ): void {
		$balance            = $this->points->current_balance( $member );
		$total_earned       = $this->points->total_earned( $member );
		$catalogue          = $this->rewards->member_catalogue( $member );
		$reward_redemptions = $this->rewards->member_redemptions( $member, 50 );
		$back_url           = $this->member_area_url();
		$loyalty_progress   = $this->recognition->loyalty_progress( $member );
		$card_selection     = $this->cards->card_presentation( $member );
		$equipped_keys      = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					(array) ( $card_selection['selected_values'] ?? array() )
				)
			)
		);
		$points_rewards    = array();
		$loyalty_rewards   = array();
		$automatic_rewards = array();
		$claimed_rewards   = array();
		$item_index        = 0;

		foreach ( $catalogue as $reward ) {
			$unlock_method = $this->reward_unlock_method( $reward );
			$owned         = $this->rewards->member_owns_reward( $member, $reward );

			if ( 'loyalty' === $unlock_method ) {
				$loyalty_rewards[] = $this->build_reward_catalogue_item(
					$member,
					$reward,
					$balance,
					$equipped_keys,
					$loyalty_progress,
					$item_index
				);
				++$item_index;
				continue;
			}

			if ( $owned ) {
				continue;
			}

			$item = $this->build_reward_catalogue_item(
				$member,
				$reward,
				$balance,
				$equipped_keys,
				$loyalty_progress,
				$item_index
			);

			if ( 'points' === $unlock_method ) {
				$points_rewards[] = $item;
			} else {
				$automatic_rewards[] = $item;
			}

			++$item_index;
		}

		foreach ( $reward_redemptions as $redemption ) {
			if ( ! $this->is_claimed_reward_redemption( $redemption ) ) {
				continue;
			}

			$reward = $this->rewards->find_reward( $redemption->reward_id() );

			if ( ! $reward instanceof Reward || ! $reward->catalog_visible() || 'points' !== $this->reward_unlock_method( $reward ) ) {
				continue;
			}

			$claimed_rewards[] = $this->build_reward_catalogue_item(
				$member,
				$reward,
				$balance,
				$equipped_keys,
				$loyalty_progress,
				$item_index,
				$redemption
			);
			++$item_index;
		}

		usort(
			$loyalty_rewards,
			fn ( array $left, array $right ): int => ( (int) $left['progress_rank'] <=> (int) $right['progress_rank'] ) ?: ( (int) $left['index'] <=> (int) $right['index'] )
		);
		?>
		<section class="adam-card adam-rewards-catalogue-page" aria-label="<?php esc_attr_e( 'Recompensas ADAM', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Recompensas ADAM', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Desbloqueios, titulos e surpresas', 'adam-membership' ); ?></h3>
					<p class="adam-rewards-catalogue-page__intro"><?php esc_html_e( 'Acumula pontos, acompanha a tua fidelidade e resgata recompensas sem sair da area de socio.', 'adam-membership' ); ?></p>
				</div>
				<div class="adam-card-actions">
					<a class="adam-card-link" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Voltar ao painel', 'adam-membership' ); ?></a>
				</div>
			</div>

			<div class="adam-rewards-catalogue-summary">
				<div class="adam-points-stat">
					<span><?php esc_html_e( 'Pontos atuais', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong>
				</div>
				<div class="adam-points-stat">
					<span><?php esc_html_e( 'Total acumulado', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $total_earned ) ); ?></strong>
				</div>
				<div class="adam-rewards-catalogue-summary__card">
					<span><?php esc_html_e( 'Fidelidade ADAM', 'adam-membership' ); ?></span>
					<?php if ( null !== $loyalty_progress['next_tier'] ) : ?>
						<strong><?php echo esc_html( $loyalty_progress['next_tier']['elapsed_label'] ); ?></strong>
					<?php else : ?>
						<strong><?php esc_html_e( 'Todos os marcos de fidelidade desbloqueados.', 'adam-membership' ); ?></strong>
					<?php endif; ?>
				</div>
			</div>

			<div class="adam-rewards-catalogue-toolbar">
				<div class="adam-rewards-catalogue-toolbar__field">
					<label for="adam-rewards-sort"><?php esc_html_e( 'Ordenar por', 'adam-membership' ); ?></label>
					<select id="adam-rewards-sort" class="adam-form-select" data-adam-rewards-sort>
						<?php foreach ( $this->reward_sort_options() as $sort_key => $sort_label ) : ?>
							<option value="<?php echo esc_attr( $sort_key ); ?>" <?php selected( 'points_asc', $sort_key ); ?>>
								<?php echo esc_html( $sort_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="adam-rewards-catalogue-section">
				<div class="adam-rewards-catalogue-section__header">
					<div>
						<p class="adam-eyebrow"><?php esc_html_e( 'Pontos ADAM', 'adam-membership' ); ?></p>
						<h4><?php esc_html_e( 'Recompensas por pontos', 'adam-membership' ); ?></h4>
					</div>
				</div>

				<?php if ( array() === $points_rewards ) : ?>
					<div class="adam-empty-inline adam-rewards-catalogue-empty">
						<?php esc_html_e( 'Nao existem recompensas por pontos disponiveis neste momento.', 'adam-membership' ); ?>
					</div>
				<?php else : ?>
					<div class="adam-rewards-catalogue-list" data-adam-reward-list="points">
						<?php foreach ( $points_rewards as $reward_item ) : ?>
							<?php $this->render_rewards_catalogue_card( $reward_item ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="adam-rewards-catalogue-section">
				<div class="adam-rewards-catalogue-section__header">
					<div>
						<p class="adam-eyebrow"><?php esc_html_e( 'Fidelidade ADAM', 'adam-membership' ); ?></p>
						<h4><?php esc_html_e( 'Recompensas de fidelidade', 'adam-membership' ); ?></h4>
					</div>
				</div>

				<?php if ( array() === $loyalty_rewards ) : ?>
					<div class="adam-empty-inline adam-rewards-catalogue-empty">
						<?php esc_html_e( 'Ainda nao existem marcos de fidelidade configurados.', 'adam-membership' ); ?>
					</div>
				<?php else : ?>
					<div class="adam-rewards-catalogue-list">
						<?php foreach ( $loyalty_rewards as $reward_item ) : ?>
							<?php $this->render_rewards_catalogue_card( $reward_item ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( array() !== $automatic_rewards ) : ?>
				<div class="adam-rewards-catalogue-section">
					<div class="adam-rewards-catalogue-section__header">
						<div>
							<p class="adam-eyebrow"><?php esc_html_e( 'Outros desbloqueios', 'adam-membership' ); ?></p>
							<h4><?php esc_html_e( 'Recompensas automaticas', 'adam-membership' ); ?></h4>
						</div>
					</div>
					<div class="adam-rewards-catalogue-list">
						<?php foreach ( $automatic_rewards as $reward_item ) : ?>
							<?php $this->render_rewards_catalogue_card( $reward_item ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="adam-rewards-catalogue-section">
				<div class="adam-rewards-catalogue-section__header">
					<div>
						<p class="adam-eyebrow"><?php esc_html_e( 'Historico', 'adam-membership' ); ?></p>
						<h4><?php esc_html_e( 'Recompensas obtidas', 'adam-membership' ); ?></h4>
					</div>
				</div>

				<?php if ( array() === $claimed_rewards ) : ?>
					<div class="adam-empty-inline adam-rewards-catalogue-empty">
						<?php esc_html_e( 'Ainda nao resgataste nenhuma recompensa por pontos.', 'adam-membership' ); ?>
					</div>
				<?php else : ?>
					<div class="adam-rewards-catalogue-list">
						<?php foreach ( $claimed_rewards as $reward_item ) : ?>
							<?php $this->render_rewards_catalogue_card( $reward_item ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<int, string> $equipped_keys Equipped cosmetic reward keys.
	 * @param array<string, mixed> $loyalty_progress Loyalty progress.
	 * @return array<string, mixed>
	 */
	private function build_reward_catalogue_item( Member $member, ?Reward $reward, int $balance, array $equipped_keys, array $loyalty_progress, int $index, ?RewardRedemption $redemption = null ): array {
		$claimed      = $redemption instanceof RewardRedemption && $this->is_claimed_reward_redemption( $redemption );
		$owned        = $claimed || ( $reward instanceof Reward && $this->rewards->member_owns_reward( $member, $reward ) );
		$pending      = $reward instanceof Reward && ! $claimed ? $this->rewards->member_has_pending_request( $member, $reward ) : false;
		$can_redeem   = $reward instanceof Reward && ! $claimed ? $this->rewards->member_can_redeem( $member, $reward ) : false;
		$redeemable   = $reward instanceof Reward ? $reward->redeemable() : false;
		$points_cost  = $reward instanceof Reward ? $reward->points_cost() : ( $redemption instanceof RewardRedemption ? $redemption->points_cost() : 0 );
		$shortfall    = $reward instanceof Reward ? max( 0, $points_cost - $balance ) : 0;
		$reward_key   = $reward instanceof Reward ? sanitize_key( $reward->reward_value() ) : '';
		$is_equipped  = '' !== $reward_key && in_array( $reward_key, $equipped_keys, true );
		$rarity       = $reward instanceof Reward ? $reward->rarity() : Reward::RARITY_COMMON;
		$claim_date   = $redemption instanceof RewardRedemption ? $this->reward_claim_date( $redemption ) : '';
		$type_label   = $this->reward_type_label( $reward, $redemption );
		$name         = $reward instanceof Reward ? $reward->name() : ( $redemption instanceof RewardRedemption ? $redemption->reward_name() : '' );
		$description  = $reward instanceof Reward ? $this->rewards->public_reward_description( $reward, $redemption ) : ( $redemption instanceof RewardRedemption ? $redemption->revealed_reward() : '' );
		$unlock_method = $reward instanceof Reward ? $this->reward_unlock_method( $reward ) : 'points';
		$meta          = $reward instanceof Reward ? $this->reward_catalogue_meta( $reward ) : array(
			'label' => __( 'Pontos', 'adam-membership' ),
			'value' => sprintf( __( '%d pontos', 'adam-membership' ), $points_cost ),
		);
		$status_label = $claimed
			? ( $is_equipped ? __( 'Em utilizacao', 'adam-membership' ) : __( 'Resgatada', 'adam-membership' ) )
			: $this->reward_catalogue_status_label( $reward, $unlock_method, $owned, $pending, $can_redeem );
		$detail_label = $claimed
			? ( '' !== $claim_date ? sprintf( __( 'Resgatada em %s', 'adam-membership' ), $this->format_datetime( $claim_date ) ) : __( 'Recompensa resgatada com sucesso.', 'adam-membership' ) )
			: ( $reward instanceof Reward ? $this->reward_catalogue_detail_label( $member, $reward, $unlock_method, $owned, $pending, $can_redeem, $shortfall, $loyalty_progress ) : '' );
		$progress_rank = $reward instanceof Reward && 'loyalty' === $unlock_method
			? $this->reward_loyalty_years( $reward )
			: $points_cost;

		return array(
			'id'            => $reward instanceof Reward ? $reward->id() : ( $redemption instanceof RewardRedemption ? $redemption->reward_id() : 0 ),
			'index'         => $index,
			'name'          => $name,
			'description'   => $description,
			'type_label'    => $type_label,
			'rarity'        => $rarity,
			'rarity_label'  => $this->reward_rarity_label_from_slug( $rarity ),
			'rarity_rank'   => $this->reward_rarity_rank( $rarity ),
			'points'        => $points_cost,
			'meta_label'    => $meta['label'],
			'meta_value'    => $meta['value'],
			'claimed'       => $claimed,
			'pending'       => $pending,
			'can_redeem'    => $can_redeem,
			'is_equipped'   => $is_equipped,
			'is_equippable' => $reward instanceof Reward ? $this->reward_is_equippable( $reward ) : false,
			'status_label'  => $status_label,
			'detail_label'  => $detail_label,
			'claim_date'    => $claim_date,
			'reward'        => $reward,
			'unlock_method' => $unlock_method,
			'progress_rank' => $progress_rank,
			'type_sort'     => remove_accents( strtolower( $type_label ) ),
			'name_sort'     => remove_accents( strtolower( $name ) ),
			'equip_url'     => '#adam-personalizacao',
			'insufficient'  => ! $claimed && $reward instanceof Reward && $redeemable && ! $can_redeem && ! $pending && $shortfall > 0,
		);
	}

	/**
	 * @param array<string, mixed> $item Reward payload.
	 */
	private function render_rewards_catalogue_card( array $item ): void {
		$reward = $item['reward'] instanceof Reward ? $item['reward'] : null;
		?>
		<article
			class="adam-rewards-catalogue-card adam-rewards-catalogue-card--<?php echo esc_attr( (string) $item['rarity'] ); ?><?php echo ! empty( $item['claimed'] ) ? ' is-claimed' : ''; ?><?php echo ! empty( $item['is_equipped'] ) ? ' is-equipped' : ''; ?>"
			data-reward-points="<?php echo esc_attr( (string) $item['points'] ); ?>"
			data-reward-rarity="<?php echo esc_attr( (string) $item['rarity_rank'] ); ?>"
			data-reward-type="<?php echo esc_attr( (string) $item['type_sort'] ); ?>"
			data-reward-name="<?php echo esc_attr( (string) $item['name_sort'] ); ?>"
			data-reward-index="<?php echo esc_attr( (string) $item['index'] ); ?>"
		>
			<div class="adam-rewards-catalogue-card__header">
				<div class="adam-rewards-catalogue-card__heading">
					<h5><?php echo esc_html( (string) $item['name'] ); ?></h5>
					<?php if ( '' !== trim( (string) $item['description'] ) ) : ?>
						<p><?php echo esc_html( (string) $item['description'] ); ?></p>
					<?php endif; ?>
				</div>
				<div class="adam-rewards-catalogue-card__badges">
					<span class="adam-badge adam-rewards-catalogue-card__type"><?php echo esc_html( (string) $item['type_label'] ); ?></span>
					<span class="adam-badge adam-reward-rarity adam-reward-rarity--<?php echo esc_attr( (string) $item['rarity'] ); ?>"><?php echo esc_html( (string) $item['rarity_label'] ); ?></span>
					<span class="adam-badge adam-rewards-catalogue-card__status<?php echo ! empty( $item['can_redeem'] ) ? ' active' : ( ! empty( $item['claimed'] ) ? ' warning' : ( ! empty( $item['pending'] ) ? ' pending' : ' unknown' ) ); ?>">
						<?php echo esc_html( (string) $item['status_label'] ); ?>
					</span>
				</div>
			</div>

			<div class="adam-rewards-catalogue-card__meta-grid">
				<div class="adam-rewards-catalogue-card__meta-item">
					<span><?php echo esc_html( (string) $item['meta_label'] ); ?></span>
					<strong><?php echo esc_html( (string) $item['meta_value'] ); ?></strong>
				</div>
				<?php if ( ! empty( $item['claim_date'] ) ) : ?>
					<div class="adam-rewards-catalogue-card__meta-item">
						<span><?php esc_html_e( 'Data do resgate', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $this->format_datetime( (string) $item['claim_date'] ) ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<div class="adam-rewards-catalogue-card__footer">
				<div class="adam-rewards-catalogue-card__detail">
					<?php echo esc_html( (string) $item['detail_label'] ); ?>
				</div>
				<div class="adam-rewards-catalogue-card__actions">
					<?php if ( ! empty( $item['claimed'] ) || 'loyalty' === ( $item['unlock_method'] ?? '' ) ) : ?>
						<span class="adam-text-link"><?php echo esc_html( (string) $item['status_label'] ); ?></span>
					<?php elseif ( ! empty( $item['is_equipped'] ) ) : ?>
						<span class="adam-text-link"><?php esc_html_e( 'Equipada na tua conta', 'adam-membership' ); ?></span>
					<?php elseif ( ! empty( $item['pending'] ) ) : ?>
						<span class="adam-text-link"><?php esc_html_e( 'Pedido em analise pela ADAM', 'adam-membership' ); ?></span>
					<?php elseif ( $reward instanceof Reward && ! empty( $item['can_redeem'] ) ) : ?>
						<form method="post" class="adam-reward-redeem-form">
							<input type="hidden" name="adam_member_action" value="redeem_reward">
							<input type="hidden" name="reward_id" value="<?php echo esc_attr( (string) $reward->id() ); ?>">
							<?php wp_nonce_field( 'adam_member_redeem_reward_' . $reward->id() ); ?>
							<button type="submit" class="adam-card-link"><?php esc_html_e( 'Resgatar', 'adam-membership' ); ?></button>
						</form>
					<?php elseif ( ! empty( $item['insufficient'] ) ) : ?>
						<button type="button" class="adam-card-link" disabled><?php echo esc_html( (string) $item['detail_label'] ); ?></button>
					<?php else : ?>
						<span class="adam-text-link"><?php echo esc_html( (string) $item['detail_label'] ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
	}

	/**
	 * @return array<string, string>
	 */
	private function reward_sort_options(): array {
		return array(
			'points_asc'  => __( 'Pontos - Menor para maior', 'adam-membership' ),
			'points_desc' => __( 'Pontos - Maior para menor', 'adam-membership' ),
			'rarity_desc' => __( 'Raridade - Maior para menor', 'adam-membership' ),
			'rarity_asc'  => __( 'Raridade - Menor para maior', 'adam-membership' ),
			'type'        => __( 'Tipo de recompensa', 'adam-membership' ),
			'name'        => __( 'Nome - A a Z', 'adam-membership' ),
		);
	}

	private function is_claimed_reward_redemption( RewardRedemption $redemption ): bool {
		return in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true );
	}

	private function reward_claim_date( RewardRedemption $redemption ): string {
		if ( '' !== $redemption->delivered_at() ) {
			return $redemption->delivered_at();
		}

		if ( '' !== $redemption->approved_at() ) {
			return $redemption->approved_at();
		}

		return $redemption->created_at();
	}

	private function reward_type_label( ?Reward $reward, ?RewardRedemption $redemption = null ): string {
		if ( $reward instanceof Reward ) {
			$reward_value = sanitize_key( $reward->reward_value() );
			$visual_style = $this->rewards->reward_visual_style( $reward );
			$subtype      = sanitize_key( (string) ( $visual_style['card_subtype'] ?? '' ) );

			if ( str_starts_with( $reward_value, 'title_' ) ) {
				return __( 'Titulo', 'adam-membership' );
			}

			if ( str_contains( $reward_value, 'pattern' ) || 'pattern' === $subtype ) {
				return __( 'Padrao do cartao', 'adam-membership' );
			}

			if ( str_starts_with( $reward_value, 'card_frame_' ) || 'card_style' === $subtype ) {
				return __( 'Acabamento do cartao', 'adam-membership' );
			}

			if ( str_starts_with( $reward_value, 'card_theme_' ) || 'background' === $subtype ) {
				return __( 'Fundo do cartao', 'adam-membership' );
			}

			if ( in_array( $reward->type(), array( Reward::TYPE_PERMANENT_UNLOCK, Reward::TYPE_CONSUMABLE, Reward::TYPE_PHYSICAL_REWARD, Reward::TYPE_MANUAL_REWARD, Reward::TYPE_MYSTERY_REWARD, Reward::TYPE_RAFFLE_TICKET ), true ) ) {
				return __( 'Beneficio', 'adam-membership' );
			}
		}

		if ( $redemption instanceof RewardRedemption ) {
			$labels = $this->rewards->type_labels();

			return $labels[ $redemption->reward_type() ] ?? __( 'Outro', 'adam-membership' );
		}

		return __( 'Outro', 'adam-membership' );
	}

	private function reward_rarity_label_from_slug( string $rarity ): string {
		$labels = $this->rewards->rarity_labels();

		return $labels[ $rarity ] ?? $rarity;
	}

	private function reward_rarity_rank( string $rarity ): int {
		return match ( $rarity ) {
			Reward::RARITY_COMMON => 10,
			Reward::RARITY_UNCOMMON => 20,
			Reward::RARITY_RARE => 30,
			Reward::RARITY_EPIC => 40,
			Reward::RARITY_LEGENDARY => 50,
			Reward::RARITY_LIMITED_EDITION => 60,
			default => 0,
		};
	}

	private function reward_display_cost_label( Reward $reward ): string {
		if ( $reward->points_cost() > 0 && $reward->redeemable() ) {
			return sprintf(
				/* translators: %d: reward points cost. */
				__( '%d pontos', 'adam-membership' ),
				$reward->points_cost()
			);
		}

		return $this->reward_cost_label( $reward );
	}

	private function reward_unlock_method( Reward $reward ): string {
		if ( $this->rewards->is_loyalty_reward( $reward ) ) {
			return 'loyalty';
		}

		if ( $reward->redeemable() && $reward->points_cost() > 0 ) {
			return 'points';
		}

		if ( Reward::TYPE_MANUAL_REWARD === $reward->type() ) {
			return 'manual';
		}

		return 'automatic';
	}

	/**
	 * @return array{label:string,value:string}
	 */
	private function reward_catalogue_meta( Reward $reward ): array {
		$unlock_method = $this->reward_unlock_method( $reward );

		if ( 'loyalty' === $unlock_method ) {
			$years = $this->reward_loyalty_years( $reward );

			return array(
				'label' => __( 'Fidelidade', 'adam-membership' ),
				'value' => $years > 0 ? sprintf( __( '%d anos', 'adam-membership' ), $years ) : __( 'Associacao ativa', 'adam-membership' ),
			);
		}

		if ( 'points' === $unlock_method ) {
			return array(
				'label' => __( 'Pontos', 'adam-membership' ),
				'value' => sprintf( __( '%d pontos', 'adam-membership' ), $reward->points_cost() ),
			);
		}

		return array(
			'label' => __( 'Desbloqueio', 'adam-membership' ),
			'value' => $this->reward_cost_label( $reward ),
		);
	}

	private function reward_catalogue_status_label( ?Reward $reward, string $unlock_method, bool $owned, bool $pending, bool $can_redeem ): string {
		if ( $owned ) {
			return 'loyalty' === $unlock_method ? __( 'Obtida', 'adam-membership' ) : __( 'Resgatada', 'adam-membership' );
		}

		if ( $pending ) {
			return __( 'Pendente', 'adam-membership' );
		}

		if ( ! $reward instanceof Reward ) {
			return __( 'Indisponivel', 'adam-membership' );
		}

		if ( $can_redeem ) {
			return 'loyalty' === $unlock_method ? __( 'Elegivel', 'adam-membership' ) : __( 'Disponivel', 'adam-membership' );
		}

		return 'loyalty' === $unlock_method ? __( 'Bloqueada', 'adam-membership' ) : __( 'Indisponivel', 'adam-membership' );
	}

	private function reward_catalogue_detail_label( Member $member, Reward $reward, string $unlock_method, bool $owned, bool $pending, bool $can_redeem, int $shortfall, array $loyalty_progress ): string {
		if ( $owned ) {
			return 'loyalty' === $unlock_method
				? __( 'Obtida pela tua fidelidade ADAM.', 'adam-membership' )
				: __( 'Desbloqueada e pronta a usar na tua personalizacao.', 'adam-membership' );
		}

		if ( $pending ) {
			return __( 'Pedido em analise pela ADAM.', 'adam-membership' );
		}

		if ( 'loyalty' === $unlock_method ) {
			$tier = $this->loyalty_tier_for_reward( $reward );

			if ( ! $member->isActive() ) {
				return __( 'Requer associacao ativa e renovacoes confirmadas.', 'adam-membership' );
			}

			if ( null === $tier ) {
				return __( 'Desbloqueio automatico por fidelidade ADAM.', 'adam-membership' );
			}

			if ( (int) ( $loyalty_progress['completed_years'] ?? 0 ) >= $tier['years'] ) {
				return __( 'Marcos de fidelidade cumpridos.', 'adam-membership' );
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

	private function reward_loyalty_years( Reward $reward ): int {
		$tier = $this->loyalty_tier_for_reward( $reward );

		return null !== $tier ? (int) $tier['years'] : 0;
	}

	private function reward_status_label( ?Reward $reward, bool $pending, bool $can_redeem, bool $owned ): string {
		if ( $owned ) {
			return __( 'Resgatada', 'adam-membership' );
		}

		if ( $pending ) {
			return __( 'Pendente', 'adam-membership' );
		}

		if ( ! $reward instanceof Reward ) {
			return __( 'Indisponivel', 'adam-membership' );
		}

		if ( $can_redeem ) {
			return __( 'Disponivel', 'adam-membership' );
		}

		return __( 'Indisponivel', 'adam-membership' );
	}

	private function reward_is_equippable( Reward $reward ): bool {
		$reward_value = sanitize_key( $reward->reward_value() );

		return str_starts_with( $reward_value, 'title_' ) || str_starts_with( $reward_value, 'card_theme_' ) || str_starts_with( $reward_value, 'card_frame_' );
	}

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
	 * @param Member $member        Member.
	 * @param bool   $homepage_only Only show homepage placements.
	 * @param bool   $standalone    Whether this is the dedicated notices view.
	 */
	private function render_announcements( Member $member, bool $homepage_only = true, bool $standalone = false ): void {
		$selected_id   = isset( $_GET['announcement_id'] ) ? absint( wp_unslash( $_GET['announcement_id'] ) ) : 0;
		$announcements = $this->announcements->visible_for_member( $member, $homepage_only );

		if ( array() === $announcements ) {
			if ( $standalone ) {
				?>
				<section class="adam-card adam-announcements-section adam-empty-state" aria-label="<?php esc_attr_e( 'Centro de Avisos', 'adam-membership' ); ?>">
					<p class="adam-eyebrow"><?php esc_html_e( 'Centro de Avisos', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Sem avisos disponíveis', 'adam-membership' ); ?></h3>
					<p><?php esc_html_e( 'Não existem comunicações disponíveis para a sua conta neste momento.', 'adam-membership' ); ?></p>
					<a class="adam-card-link" href="<?php echo esc_url( $this->member_area_url() ); ?>"><?php esc_html_e( 'Voltar ao painel', 'adam-membership' ); ?></a>
				</section>
				<?php
			}

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
					<h3><?php esc_html_e( 'Comunicações oficiais da ADAM', 'adam-membership' ); ?></h3>
				</div>
				<div class="adam-card-actions">
					<?php if ( $standalone ) : ?>
						<a class="adam-card-link" href="<?php echo esc_url( $this->member_area_url() ); ?>"><?php esc_html_e( 'Voltar ao painel', 'adam-membership' ); ?></a>
					<?php else : ?>
						<a class="adam-card-link" href="<?php echo esc_url( $this->member_area_url( array( 'view' => 'avisos' ) ) ); ?>"><?php esc_html_e( 'Ver todos', 'adam-membership' ); ?></a>
					<?php endif; ?>
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
				<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( $this->member_area_url( array( 'view' => 'avisos', 'announcement_id' => (string) $announcement->id() ) ) ); ?>">
					<?php esc_html_e( 'Ler mais', 'adam-membership' ); ?>
				</a>
				<?php if ( '' !== $announcement->action_label() && '' !== $announcement->action_url() ) : ?>
					<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( $announcement->action_url() ); ?>">
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
				<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( $this->member_area_url( array( 'view' => 'avisos' ) ) ); ?>"><?php esc_html_e( 'Voltar aos avisos', 'adam-membership' ); ?></a>
				<?php if ( '' !== $announcement->action_label() && '' !== $announcement->action_url() ) : ?>
					<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( $announcement->action_url() ); ?>"><?php echo esc_html( $announcement->action_label() ); ?></a>
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
				<a class="adam-text-link" href="<?php echo esc_url( ManagedPages::url( 'member_area' ) ); ?>"><?php esc_html_e( 'Limpar', 'adam-membership' ); ?></a>
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
		<article class="adam-document-card adam-card">
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
				<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( $this->documents->download_url( $document ) ); ?>">
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
			esc_html( DisplayLabels::status( $status ) )
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
				<?php $this->render_data_item( __( 'APD / Associação', 'adam-membership' ), (string) $member->field( 'adam_external_association_name' ) ); ?>
				<?php $this->render_data_item( __( 'N.º de sócio APD', 'adam-membership' ), (string) $member->field( 'adam_external_member_number' ) ); ?>
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
		$user_id       = get_current_user_id();
		$optional      = $this->communication_preferences->categories()->optional();
		$mandatory     = $this->communication_preferences->categories()->mandatory();
		$subscriptions = $this->communication_preferences->subscriptions( $user_id, CommunicationPreferences::CHANNEL_EMAIL );
		?>
		<section class="adam-card adam-notifications-card" aria-label="<?php esc_attr_e( 'Centro de Avisos', 'adam-membership' ); ?>" data-adam-communication-preferences>
			<div class="adam-card-heading adam-notifications-card__header">
				<a class="adam-card-link adam-notifications-card__notices-link" href="<?php echo esc_url( $this->member_area_url( array( 'view' => 'avisos' ) ) ); ?>"><?php esc_html_e( 'Centro de Avisos', 'adam-membership' ); ?></a>
				<button type="button" class="adam-communication-settings-button" data-adam-communication-preferences-open aria-controls="adam-communication-preferences-dialog" aria-haspopup="dialog" title="<?php esc_attr_e( 'Preferências de comunicação', 'adam-membership' ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( 'Abrir preferências de comunicação', 'adam-membership' ); ?></span>
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.07-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.61-.22l-2.39.96a7.2 7.2 0 0 0-1.63-.95L14.37 2.8a.5.5 0 0 0-.5-.4h-3.84a.5.5 0 0 0-.49.4l-.36 2.51c-.59.24-1.13.56-1.64.95L5.16 5.3a.5.5 0 0 0-.61.22L2.63 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.08.65-.08.94s.03.63.08.94l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32a.5.5 0 0 0 .61.22l2.39-.96c.5.39 1.05.71 1.63.95l.36 2.51a.5.5 0 0 0 .49.4h3.84a.5.5 0 0 0 .5-.4l.36-2.51a7.2 7.2 0 0 0 1.63-.95l2.39.96a.5.5 0 0 0 .61-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.02-1.58ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z" fill="currentColor"/></svg>
				</button>
			</div>

			<ul class="adam-notification-list">
				<?php foreach ( $messages as $message ) : ?>
					<li><?php echo esc_html( $message ); ?></li>
				<?php endforeach; ?>
			</ul>

			<dialog id="adam-communication-preferences-dialog" class="adam-communication-preferences-dialog" data-adam-communication-preferences-dialog aria-labelledby="adam-communication-preferences-title">
				<form method="post" class="adam-communication-preferences-form" data-adam-communication-preferences-form>
					<div class="adam-communication-preferences-dialog__header">
						<div>
							<p class="adam-eyebrow"><?php esc_html_e( 'Email', 'adam-membership' ); ?></p>
							<h2 id="adam-communication-preferences-title"><?php esc_html_e( 'Preferências de Comunicação', 'adam-membership' ); ?></h2>
						</div>
						<button type="button" class="adam-communication-preferences-dialog__close" data-adam-communication-preferences-cancel aria-label="<?php esc_attr_e( 'Fechar', 'adam-membership' ); ?>">&times;</button>
					</div>

					<p class="adam-communication-preferences-dialog__description"><?php esc_html_e( 'Escolha que tipos de comunicações pretende receber por email. As comunicações essenciais relacionadas com a sua inscrição, quota, regulamentos e obrigações legais continuarão sempre a ser enviadas.', 'adam-membership' ); ?></p>

					<fieldset class="adam-communication-preferences-list">
						<legend><?php esc_html_e( 'Comunicações opcionais por email', 'adam-membership' ); ?></legend>
						<?php foreach ( $optional as $category_id => $category ) : ?>
							<label>
								<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $category_id ); ?>" <?php checked( ! empty( $subscriptions[ $category_id ] ) ); ?>>
								<span><?php echo esc_html( $category['label'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<div class="adam-communication-mandatory-categories">
						<h3><?php esc_html_e( 'Comunicações sempre enviadas', 'adam-membership' ); ?></h3>
						<p><?php esc_html_e( 'Estas comunicações são essenciais e não podem ser desativadas.', 'adam-membership' ); ?></p>
						<ul>
							<?php foreach ( $mandatory as $category ) : ?>
								<li><?php echo esc_html( $category['label'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>

					<p class="adam-communication-preferences-status" data-adam-communication-preferences-status role="status" aria-live="polite"></p>

					<div class="adam-communication-preferences-dialog__actions">
						<button type="submit" class="adam-button adam-communication-preferences-save"><?php esc_html_e( 'Guardar Preferências', 'adam-membership' ); ?></button>
						<button type="button" class="adam-button adam-button--secondary" data-adam-communication-preferences-cancel><?php esc_html_e( 'Cancelar', 'adam-membership' ); ?></button>
					</div>
				</form>
			</dialog>
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
					$this->member_update_actions(),
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
				'url'         => ManagedPages::url( 'change_password' ),
			),
			array(
				'label'       => __( 'Alterar email', 'adam-membership' ),
				'description' => '',
				'url'         => ManagedPages::url( 'change_email' ),
			),
			array(
				'label'       => __( 'Terminar sessão', 'adam-membership' ),
				'description' => '',
				'url'         => wp_logout_url( add_query_arg( 'logged_out', '1', ManagedPages::url( 'member_area' ) ) ),
			),
		);
	}

	private function render_apd_association_page( Member $member ): string {
		if ( empty( $this->settings->membership_form_settings()['forms']['apd']['enabled'] ) || ! $this->apd_association->eligible( $member ) ) { return $this->render_not_found(); }
		$message = 'submitted' === (string) ( $_GET['apd_message'] ?? '' ) ? $this->notice_markup( 'success', __( 'Pedido de associação APD submetido com sucesso.', 'adam-membership' ) ) : '';
		$edit_mode = 'edit' === (string) ( $_POST['apd_information_mode'] ?? '' );
		$legacy_fields = array(
			'full_name' => array( 'label' => 'Nome completo', 'value' => $member->full_name(), 'editable' => false ),
			'birth_date' => array( 'label' => 'Data de nascimento', 'value' => (string) $member->field( 'data_nascimento' ), 'editable' => true ),
			'gender' => array( 'label' => 'Género', 'value' => (string) $member->field( 'genero' ), 'editable' => true ),
			'marital_status' => array( 'label' => 'Estado civil', 'value' => (string) $member->field( 'estado_civil' ), 'editable' => true ),
			'nationality' => array( 'label' => 'Nacionalidade', 'value' => (string) $member->field( 'nacionalidade' ), 'editable' => true ),
			'birthplace' => array( 'label' => 'Naturalidade', 'value' => (string) $member->field( 'naturalidade' ), 'editable' => true ),
			'profession' => array( 'label' => 'Profissão', 'value' => (string) $member->field( 'profissao' ), 'editable' => true ),
			'email' => array( 'label' => 'Email', 'value' => $member->email(), 'editable' => false ),
			'phone' => array( 'label' => 'Telemóvel', 'value' => (string) $member->field( 'telefone' ), 'editable' => true ),
			'address' => array( 'label' => 'Morada', 'value' => (string) $member->field( 'morada' ), 'editable' => true ),
			'postcode' => array( 'label' => 'Código postal', 'value' => (string) $member->field( 'codigo_postal' ), 'editable' => true ),
			'city' => array( 'label' => 'Localidade', 'value' => (string) $member->field( 'cidade' ), 'editable' => true ),
			'nif' => array( 'label' => 'NIF', 'value' => (string) $member->field( 'nif' ), 'editable' => false ),
			'citizen_card' => array( 'label' => 'BI / Cartão de Cidadão', 'value' => (string) $member->field( 'cartao_cidadao' ), 'editable' => false ),
			'document_expiry' => array( 'label' => 'Data de validade', 'value' => (string) $member->field( 'documento_validade' ), 'editable' => false ),
			'document_place' => array( 'label' => 'Local de emissão', 'value' => (string) $member->field( 'documento_local_emissao' ), 'editable' => false ),
		);
		$legacy_fields['gender']['label'] = "G\u{00E9}nero";
		$fields = $this->apd_registration_fields( $member );
		if ( 'submitted' === (string) ( $_GET['apd_message'] ?? '' ) ) { $message = $this->notice_markup( 'success', __( "Pedido de associa\u{00E7}\u{00E3}o APD submetido com sucesso.", 'adam-membership' ) ); }
		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['adam_apd_association_submit'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'adam_apd_association' ) ) { $message = $this->notice_markup( 'error', __( 'Não foi possível validar o pedido.', 'adam-membership' ) ); }
			elseif ( ! in_array( (string) ( $_POST['apd_information_mode'] ?? '' ), array( 'confirm', 'edit' ), true ) ) { $message = $this->notice_markup( 'error', __( 'Selecione se os seus dados estão corretos ou se precisam de alterações.', 'adam-membership' ) ); }
			else {
				$payload = array(); $validation_error = null;
				foreach ( $fields as $key => $field ) {
					$payload[ $key ] = 'edit' === (string) $_POST['apd_information_mode'] ? sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $field['value'] ) ) : $field['value'];
					if ( 'citizen_card' === (string) $key ) { $payload[ $key ] = IdentificationValidator::normalize( $payload[ $key ] ); }
					$check = SharedFieldValidator::validate( (string) $key, $payload[ $key ], $field, false );
					if ( is_wp_error( $check ) ) { $validation_error = $check; break; }
				}
				if ( $validation_error instanceof \WP_Error ) { $message = $this->notice_markup( 'error', $validation_error->get_error_message() ); }
				else {
				$payload['remove_profile_photo'] = ! empty( $_POST['remove_profile_photo'] );
				$photo_mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
				$receipt_mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' );
				$upload_error = SharedFieldValidator::validate_upload( $_FILES['profile_photo'] ?? null, $photo_mimes, false );
				if ( ! is_wp_error( $upload_error ) ) { $upload_error = SharedFieldValidator::validate_upload( $_FILES['payment_receipt'] ?? null, $receipt_mimes, true ); }
				if ( is_wp_error( $upload_error ) ) { $message = $this->notice_markup( 'error', $upload_error->get_error_message() ); }
				else {
					if ( ! empty( $_FILES['profile_photo']['name'] ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; $photo = media_handle_upload( 'profile_photo', 0, array(), array( 'test_form' => false, 'mimes' => $photo_mimes ) ); if ( ! is_wp_error( $photo ) ) { $payload['profile_photo'] = $photo; } }
					$receipt = ''; if ( ! empty( $_FILES['payment_receipt']['name'] ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; $upload = wp_handle_upload( $_FILES['payment_receipt'], array( 'test_form' => false, 'mimes' => $receipt_mimes ) ); $receipt = is_array( $upload ) ? (string) ( $upload['url'] ?? '' ) : ''; }
					$result = $this->apd_association->submit( $member, $payload, $receipt ); if ( is_wp_error( $result ) ) { $message = $this->notice_markup( 'error', $result->get_error_message() ); } else { wp_safe_redirect( $this->member_area_url( array( 'view' => 'apd-association', 'apd_confirmation' => '1', 'request_id' => $result->id() ) ) ); exit; }
				}
				}
			}
		}
		return $this->render_apd_registration_form( $member, $fields, $message );
		$price = $this->apd_association->price_for( $member );
		ob_start();
		?><section class="adam-public-form adam-card" data-adam-membership-form="apd-association"><div class="adam-card-heading"><p class="adam-eyebrow">ADAM / ANA</p><h2><?php esc_html_e( "Associar APD atrav\u{00E9}s da ADAM", 'adam-membership' ); ?></h2></div><?php echo wp_kses_post( $message ); ?><p><?php esc_html_e( "Reveja os dados que a ADAM ir\u{00E1} utilizar no seu registo ANA.", 'adam-membership' ); ?></p><p><strong><?php esc_html_e( 'Valor aplicável:', 'adam-membership' ); ?> <?php echo esc_html( number_format_i18n( (float) $price, 2 ) . ' ' . html_entity_decode( '&#8364;', ENT_QUOTES, 'UTF-8' ) ); ?></strong></p><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_apd_association' ); ?><div class="adam-choice-grid"><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="confirm" required> <?php esc_html_e( "Confirmo que os meus dados est\u{00E3}o corretos", 'adam-membership' ); ?></label><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="edit" required> <?php esc_html_e( 'Preciso de alterar os meus dados', 'adam-membership' ); ?></label></div><div class="adam-form-section"><h3><?php esc_html_e( 'Informação pessoal e de registo', 'adam-membership' ); ?></h3><div class="adam-form-grid"><?php foreach ( $fields as $key => $field ) : ?><label class="adam-form-field"><?php echo esc_html( $field['label'] ); ?><input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>" <?php disabled( ! $field['editable'] ); ?>></label><?php endforeach; ?></div></div><div class="adam-form-section"><h3><?php esc_html_e( 'APD atual', 'adam-membership' ); ?></h3><div class="adam-form-grid"><label class="adam-form-field"><?php esc_html_e( 'Associação/APD', 'adam-membership' ); ?><input type="text" name="association_name" value="<?php echo esc_attr( (string) $member->field( 'adam_external_association_name' ) ); ?>" required></label><label class="adam-form-field"><?php esc_html_e( "N.\u{00BA} de sócio APD", 'adam-membership' ); ?><input type="text" name="external_member_number" value="<?php echo esc_attr( (string) $member->field( 'adam_external_member_number' ) ); ?>"></label></div></div><div class="adam-form-section"><label class="adam-form-field"><?php esc_html_e( 'Comprovativo de pagamento', 'adam-membership' ); ?><input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png"></label></div><button class="button button-primary" name="adam_apd_association_submit" value="1"><?php esc_html_e( 'Submeter pedido', 'adam-membership' ); ?></button></form></section><?php
		$html = (string) ob_get_clean();
		$html .= '<script>(function(){var f=document.querySelector("[data-adam-membership-form=apd-association]");if(!f)return;var r=f.querySelectorAll("input[name=apd_information_mode]"),e=f.querySelectorAll("input[name=birth_date],input[name=gender],input[name=marital_status],input[name=nationality],input[name=birthplace],input[name=profession],input[name=phone],input[name=address],input[name=postcode],input[name=city]");function s(){var x=f.querySelector("input[name=apd_information_mode]:checked");e.forEach(function(i){i.readOnly=!x||x.value!=="edit";});}r.forEach(function(i){i.addEventListener("change",s);});s();}());</script>';
		return $html;
		/* Legacy rendering retained below for compatibility with old templates. */
		?><section class="adam-public-form adam-card" data-adam-membership-form="apd-association"><div class="adam-card-heading"><p class="adam-eyebrow">ADAM / ANA</p><h2><?php esc_html_e( 'Associar APD através da ADAM', 'adam-membership' ); ?></h2></div><?php echo wp_kses_post( $message ); ?><p><?php esc_html_e( 'Reveja os dados que a ADAM irá utilizar no seu registo ANA.', 'adam-membership' ); ?></p><p><strong><?php esc_html_e( 'Valor aplicável:', 'adam-membership' ); ?> <?php echo esc_html( number_format_i18n( (float) $price, 2 ) . ' €' ); ?></strong></p><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_apd_association' ); ?><div class="adam-choice-grid"><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="confirm" required> <?php esc_html_e( 'Confirmo que os meus dados estão corretos', 'adam-membership' ); ?></label><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="edit" required> <?php esc_html_e( 'Preciso de alterar os meus dados', 'adam-membership' ); ?></label></div><div class="adam-form-section"><h3><?php esc_html_e( 'Informação pessoal e de registo', 'adam-membership' ); ?></h3><div class="adam-form-grid"><?php foreach ( $fields as $key => $field ) : ?><label class="adam-form-field"><?php echo esc_html( $field['label'] ); ?><input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>" <?php disabled( ! $field['editable'] ); ?>></label><?php endforeach; ?></div></div><div class="adam-form-section"><h3><?php esc_html_e( 'APD atual', 'adam-membership' ); ?></h3><div class="adam-form-grid"><label class="adam-form-field">Associação/APD<input type="text" name="association_name" value="<?php echo esc_attr( (string) $member->field( 'adam_external_association_name' ) ); ?>" required></label><label class="adam-form-field">N.º de sócio APD<input type="text" name="external_member_number" value="<?php echo esc_attr( (string) $member->field( 'adam_external_member_number' ) ); ?>"></label></div></div><div class="adam-form-section"><label class="adam-form-field"><?php esc_html_e( 'Comprovativo de pagamento', 'adam-membership' ); ?><input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png"></label></div><button class="button button-primary" name="adam_apd_association_submit" value="1"><?php esc_html_e( 'Submeter pedido', 'adam-membership' ); ?></button></form></section><?php
		$html = (string) ob_get_clean();
		$html .= '<script>(function(){var f=document.querySelector("[data-adam-membership-form=apd-association]");if(!f)return;var radios=f.querySelectorAll("input[name=apd_information_mode]");var editable=f.querySelectorAll("input[name=birth_date],input[name=gender],input[name=marital_status],input[name=nationality],input[name=birthplace],input[name=profession],input[name=phone],input[name=address],input[name=postcode],input[name=city]");function sync(){var edit=f.querySelector("input[name=apd_information_mode]:checked");editable.forEach(function(i){i.readOnly=!edit||edit.value!=="edit";});}radios.forEach(function(r){r.addEventListener("change",sync);});sync();}());</script>';
		return $html;
	}

	private function render_apd_clean_form( Member $member, array $fields, string $message ): string {
		$price = $this->apd_association->price_for( $member );
		foreach ( $fields as $field_key => $field_definition ) {
			$fields[ $field_key ] = is_array( $field_definition ) ? $field_definition : array();
			$fields[ $field_key ]['type'] = isset( $fields[ $field_key ]['type'] ) && is_string( $fields[ $field_key ]['type'] ) && '' !== $fields[ $field_key ]['type'] ? $fields[ $field_key ]['type'] : 'text';
			$fields[ $field_key ]['label'] = (string) ( $fields[ $field_key ]['label'] ?? $field_key );
			$fields[ $field_key ]['key'] = (string) ( $fields[ $field_key ]['key'] ?? $field_key );
		}
		ob_start(); ?>
		<section class="adam-public-form adam-card" data-adam-membership-form="apd-association">
			<div class="adam-card-heading"><p class="adam-eyebrow">ADAM / ANA</p><h2><?php esc_html_e( 'Associar APD através da ADAM', 'adam-membership' ); ?></h2></div>
			<?php echo wp_kses_post( $message ); ?><p><?php esc_html_e( "Reveja os dados que a ADAM ir\u{00E1} utilizar no seu registo ANA.", 'adam-membership' ); ?></p>
			<p><strong><?php esc_html_e( "Valor aplic\u{00E1}vel:", 'adam-membership' ); ?> <?php echo esc_html( number_format_i18n( (float) $price, 2 ) . ' ' . html_entity_decode( '&#8364;', ENT_QUOTES, 'UTF-8' ) ); ?></strong></p>
			<form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_apd_association' ); ?>
				<div class="adam-choice-grid"><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="confirm" required><strong><?php esc_html_e( "Confirmo que os meus dados est\u{00E3}o corretos", 'adam-membership' ); ?></strong><small><?php esc_html_e( 'Os dados abaixo serão utilizados no pedido à ANA.', 'adam-membership' ); ?></small></label><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="edit" required><strong><?php esc_html_e( 'Preciso de alterar os meus dados', 'adam-membership' ); ?></strong><small><?php esc_html_e( 'Quero corrigir ou atualizar os meus dados antes de enviar o pedido.', 'adam-membership' ); ?></small></label></div>
				<div class="adam-form-section"><h3><?php esc_html_e( "Informa\u{00E7}\u{00E3}o pessoal e de registo", 'adam-membership' ); ?></h3><div class="adam-form-grid"><?php foreach ( $fields as $key => $field ) : ?><label class="adam-form-field"><?php echo esc_html( $field['label'] ); ?><input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>" <?php disabled( ! $field['editable'] ); ?>></label><?php endforeach; ?></div></div>
				<div class="adam-form-section"><h3><?php esc_html_e( 'APD atual', 'adam-membership' ); ?></h3><div class="adam-form-grid"><label class="adam-form-field"><?php esc_html_e( "Associa\u{00E7}\u{00E3}o/APD", 'adam-membership' ); ?><input type="text" name="association_name" value="<?php echo esc_attr( (string) $member->field( 'adam_external_association_name' ) ); ?>" required></label><label class="adam-form-field"><?php esc_html_e( "N.\u{00BA} de s\u{00F3}cio APD", 'adam-membership' ); ?><input type="text" name="external_member_number" value="<?php echo esc_attr( (string) $member->field( 'adam_external_member_number' ) ); ?>"></label></div></div>
				<label class="adam-form-field"><?php esc_html_e( 'Comprovativo de pagamento', 'adam-membership' ); ?><input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png"></label><button class="button button-primary" name="adam_apd_association_submit" value="1"><?php esc_html_e( 'Submeter pedido', 'adam-membership' ); ?></button>
			</form>
		</section>
		<?php $html = (string) ob_get_clean(); $html .= '<script>(function(){var f=document.querySelector("[data-adam-membership-form=apd-association]"),r=f&&f.querySelectorAll("input[name=apd_information_mode]"),e=f&&f.querySelectorAll("input[name=birth_date],input[name=gender],input[name=marital_status],input[name=nationality],input[name=birthplace],input[name=profession],input[name=phone],input[name=address],input[name=postcode],input[name=city]");if(!f)return;var d=f.querySelector(".adam-choice-card small");if(d)d.textContent="Os dados abaixo ser\\u00e3o utilizados no pedido \\u00e0 ANA.";function s(){var x=f.querySelector("input[name=apd_information_mode]:checked"),locked=!x||x.value!=="edit";e.forEach(function(i){i.readOnly=locked;i.classList.toggle("adam-apd-field--locked",locked);});}r.forEach(function(i){i.addEventListener("change",s);});s();}());</script>'; return $html;
	}

	/** Build the APD form from the same registration field configuration. */
	private function apd_registration_fields( Member $member ): array {
		$settings = $this->settings->membership_form_settings();
		$configs  = is_array( $settings['registration_fields'] ?? null ) ? $settings['registration_fields'] : array();
		$allowed  = (array) ( $settings['forms']['apd']['fields'] ?? array_keys( $configs ) );
		$configs  = array_intersect_key( $configs, array_flip( $allowed ) );
		$map = array( 'full_name' => $member->full_name(), 'birth_date' => $member->field( 'data_nascimento' ), 'marital_status' => $member->field( 'estado_civil' ), 'gender' => $member->field( 'genero' ), 'profession' => $member->field( 'profissao' ), 'birthplace' => $member->field( 'naturalidade' ), 'nationality' => $member->field( 'nacionalidade' ), 'email' => $member->email(), 'phone' => $member->field( 'telefone' ), 'telephone' => $member->field( 'telefone_fixo' ), 'address_line_1' => $member->field( 'morada' ), 'address_line_2' => $member->field( 'morada_linha_2' ), 'postcode' => $member->field( 'codigo_postal' ), 'city' => $member->field( 'cidade' ), 'municipality' => $member->field( 'municipio' ), 'country' => $member->field( 'pais' ), 'citizen_card' => $member->field( 'cartao_cidadao' ), 'document_expiry_date' => $member->field( 'documento_validade' ), 'document_issuing_place' => $member->field( 'documento_local_emissao' ), 'nif' => $member->field( 'nif' ), 'team' => $member->field( 'equipa' ) );
		$fields = array();
		foreach ( $configs as $key => $config ) {
			if ( ! is_array( $config ) || empty( $config['enabled'] ) || in_array( $key, array( 'payment_receipt', 'privacy_acceptance', 'external_association_name', 'external_member_number', 'external_association_proof' ), true ) ) { continue; }
			$fields[ $key ] = array( 'label' => (string) ( $config['label'] ?? $key ), 'type' => (string) ( $config['type'] ?? 'text' ), 'options' => (string) ( $config['options'] ?? '' ), 'help' => (string) ( $config['help'] ?? '' ), 'value' => (string) ( $map[ $key ] ?? '' ), 'editable' => true );
		}
		return $fields;
	}

	private function render_apd_registration_form( Member $member, array $fields, string $message ): string {
		$price = $this->apd_association->price_for( $member );
		$mode  = (string) ( $_POST['apd_information_mode'] ?? '' );
		$parse_options = static function ( string $raw ): array { $out = array(); foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) { $parts = array_map( 'trim', explode( '|', (string) $line, 2 ) ); if ( '' !== $parts[0] ) { $out[ $parts[0] ] = $parts[1] ?? $parts[0]; } } return $out; };
		$photo_id = absint( $member->field( 'profile_photo' ) );
		$photo_url = $photo_id ? (string) wp_get_attachment_url( $photo_id ) : '';
		ob_start(); ?>
		<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow">ADAM / ANA</p><h2><?php esc_html_e( 'Associar APD através da ADAM', 'adam-membership' ); ?></h2><p><?php esc_html_e( 'Confirme os seus dados para que a ADAM possa tratar da sua inscrição na ANA.', 'adam-membership' ); ?></p></div></section><section class="adam-card adam-form-card adam-public-form" data-adam-membership-form="apd-association"><?php echo wp_kses_post( $message ); ?><p><strong><?php esc_html_e( 'Valor aplicável:', 'adam-membership' ); ?> <?php echo esc_html( number_format_i18n( (float) $price, 2 ) . ' €' ); ?></strong></p>
		<form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_apd_association' ); ?><div class="adam-choice-grid"><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="confirm" required <?php checked( 'confirm', $mode ); ?>><strong><?php esc_html_e( 'Confirmo que os meus dados estão corretos', 'adam-membership' ); ?></strong><small><?php esc_html_e( 'Os dados abaixo serão utilizados no pedido à ANA.', 'adam-membership' ); ?></small></label><label class="adam-choice-card adam-card"><input type="radio" name="apd_information_mode" value="edit" required <?php checked( 'edit', $mode ); ?>><strong><?php esc_html_e( 'Preciso de alterar os meus dados', 'adam-membership' ); ?></strong><small><?php esc_html_e( 'Quero corrigir ou atualizar os meus dados antes de enviar o pedido.', 'adam-membership' ); ?></small></label></div>
		<div class="adam-form-section"><h3><?php esc_html_e( 'Informação pessoal e de registo', 'adam-membership' ); ?></h3><div class="adam-form-grid"><?php foreach ( $fields as $key => $field ) : $locked = 'edit' !== $mode; $type = in_array( $field['type'], array( 'date', 'email', 'phone', 'number' ), true ) ? ( 'phone' === $field['type'] ? 'tel' : $field['type'] ) : 'text'; ?><label class="adam-form-field"><span><?php echo esc_html( $field['label'] ); ?></span><?php if ( 'select' === $field['type'] ) : ?><select name="<?php echo esc_attr( $key ); ?>" <?php disabled( $locked ); ?>><option value=""><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></option><?php foreach ( $parse_options( $field['options'] ) as $option => $label ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $field['value'], $option ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><?php else : ?><input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>" <?php echo $locked ? 'readonly class="adam-apd-field--locked"' : ''; ?>><?php endif; ?><?php if ( '' !== $field['help'] ) : ?><small><?php echo esc_html( $field['help'] ); ?></small><?php endif; ?></label><?php endforeach; ?></div></div>
		<div class="adam-form-section"><h3><?php esc_html_e( 'Fotografia', 'adam-membership' ); ?></h3><div class="adam-apd-photo-editor"><div class="adam-apd-photo-preview" tabindex="0" data-adam-photo-preview><?php if ( '' !== $photo_url ) : ?><img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php esc_attr_e( 'Fotografia atual', 'adam-membership' ); ?>" data-adam-photo-image><?php else : ?><div class="adam-apd-photo-preview__empty" data-adam-photo-empty><?php esc_html_e( 'Sem fotografia', 'adam-membership' ); ?></div><?php endif; ?><div class="adam-apd-photo-preview__overlay"><button type="button" class="adam-apd-photo-preview__action" data-adam-photo-replace><?php esc_html_e( 'Substituir fotografia', 'adam-membership' ); ?></button><button type="button" class="adam-apd-photo-preview__action adam-apd-photo-preview__action--remove" data-adam-photo-remove><?php esc_html_e( 'Remover fotografia', 'adam-membership' ); ?></button></div></div><input class="adam-apd-photo-input" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" data-adam-photo-input><input type="hidden" name="remove_profile_photo" value="0" data-adam-photo-removed></div></div>
		<?php $payment = $this->settings->membership_form_settings()['payment'] ?? array(); ?><section class="adam-payment-panel adam-card" data-adam-payment-panel><div class="adam-payment-panel__header"><div><p class="adam-eyebrow">PAGAMENTO</p><h4><?php esc_html_e( 'Instruções de pagamento', 'adam-membership' ); ?></h4></div><div class="adam-payment-panel__fee"><span><?php esc_html_e( 'Valor a pagar', 'adam-membership' ); ?></span><strong data-adam-fee-value><?php echo esc_html( number_format_i18n( (float) $price, 2 ) . ' €' ); ?></strong></div></div><div class="adam-payment-panel__grid"><?php if ( ! empty( $payment['mbway'] ) ) : ?><div><span>MB Way</span><strong><?php echo esc_html( (string) $payment['mbway'] ); ?></strong></div><?php endif; ?><?php if ( ! empty( $payment['iban'] ) ) : ?><div><span>IBAN</span><strong><?php echo esc_html( (string) $payment['iban'] ); ?></strong></div><?php endif; ?></div><?php if ( ! empty( $payment['instructions'] ) ) : ?><p class="adam-payment-panel__notes"><?php echo esc_html( (string) $payment['instructions'] ); ?></p><?php endif; ?><p class="adam-payment-panel__notes"><?php esc_html_e( 'Efetue o pagamento do valor indicado e envie o comprovativo juntamente com este pedido.', 'adam-membership' ); ?></p></section><div class="adam-form-section"><h3><?php esc_html_e( 'Comprovativo de pagamento', 'adam-membership' ); ?></h3><label class="adam-form-field"><input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png" required></label></div><button class="button button-primary" name="adam_apd_association_submit" value="1"><?php esc_html_e( 'Submeter pedido', 'adam-membership' ); ?></button></form></section>
		<?php $html = (string) ob_get_clean(); $html .= '<script>(function(){var f=document.querySelector("[data-adam-membership-form=apd-association]");if(!f)return;var r=f.querySelectorAll("input[name=apd_information_mode]"),e=f.querySelectorAll("input[name],select[name]"),p=f.querySelector("[data-adam-photo-preview]"),i=f.querySelector("[data-adam-photo-input]"),rm=f.querySelector("[data-adam-photo-removed]"),rep=f.querySelector("[data-adam-photo-replace]"),rem=f.querySelector("[data-adam-photo-remove]");function s(){var x=f.querySelector("input[name=apd_information_mode]:checked"),l=!x||x.value!=="edit";e.forEach(function(n){if(n.name!=="apd_information_mode"&&n.name!=="payment_receipt"&&n!==i&&n!==rm){n.readOnly=l;n.disabled=l;n.classList.toggle("adam-apd-field--locked",l);}});if(i)i.disabled=l;if(rm)rm.disabled=l;}r.forEach(function(n){n.addEventListener("change",s);});if(p)p.addEventListener("click",function(){p.classList.toggle("is-open");});if(rep)rep.addEventListener("click",function(e){e.stopPropagation();if(i&&!i.disabled)i.click();});if(rem)rem.addEventListener("click",function(e){e.stopPropagation();if(!rm||rm.disabled)return;rm.value="1";p.classList.add("is-removed");});if(i)i.addEventListener("change",function(){var file=i.files&&i.files[0];if(!file)return;var u=URL.createObjectURL(file),img=p.querySelector("[data-adam-photo-image]");if(!img){img=document.createElement("img");img.setAttribute("data-adam-photo-image","");p.insertBefore(img,p.firstChild);}img.src=u;p.classList.remove("is-removed");if(rm)rm.value="0";});s();}());</script>'; return $html . '</section></div>';
	}

	private function apd_association_actions( Member $member ): array {
		$form_settings = $this->settings->membership_form_settings();
		if ( empty( $form_settings['forms']['apd']['enabled'] ) || ! $this->apd_association->eligible( $member ) ) { return array(); }
		return array( array( 'label' => __( "Associar APD atrav\u{00E9}s da ADAM", 'adam-membership' ), 'description' => '', 'url' => $this->member_area_url( array( 'view' => 'apd-association' ) ) ) );
		return array( array( 'label' => __( 'Associar APD através da ADAM', 'adam-membership' ), 'description' => '', 'url' => $this->member_area_url( array( 'view' => 'apd-association' ) ) ) );
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
				'url'         => wp_logout_url( add_query_arg( 'logged_out', '1', ManagedPages::url( 'member_area' ) ) ),
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
			$this->apd_association_actions( $member ),
			$this->correction_actions( $member ),
			$this->member_update_actions(),
			$this->standard_account_actions()
		);
	}

	/** @return array<int,array{label:string,description:string,url:string}> */
	private function correction_actions( Member $member ): array {
		if ( empty( $this->settings->membership_form_settings()['forms']['correction']['enabled'] ) ) { return array(); }
		$request = $this->member_changes->repository()->latest_correction_for_user( $member->user_id() );
		if ( null !== $request ) { return array( array( 'label' => 'Corrigir pedido', 'description' => $request->correction_reason(), 'url' => $this->member_area_url( array( 'view' => 'correction', 'request_id' => $request->id() ) ) ) ); }
		foreach ( $this->apd_association->repository()->for_user( $member->user_id() ) as $apd_request ) {
			if ( ApdAssociationRequest::STATUS_CORRECTION_REQUESTED === $apd_request->status() ) { return array( array( 'label' => 'Corrigir pedido', 'description' => (string) ( $apd_request->data()['correction_reason'] ?? '' ), 'url' => $this->member_area_url( array( 'view' => 'apd-association', 'correction' => '1', 'request_id' => $apd_request->id() ) ) ) ); }
		}
		return array();
	}

	/** @return array<int,array{label:string,description:string,url:string}> */
	private function member_update_actions(): array {
		if ( empty( $this->settings->membership_form_settings()['forms']['update']['enabled'] ) ) { return array(); }
		return array( array( 'label' => __( 'Atualizar dados', 'adam-membership' ), 'description' => __( 'Solicitar alterações aos seus dados pessoais.', 'adam-membership' ), 'url' => $this->member_area_url( array( 'view' => 'member-update' ) ) ) );
	}

	private function render_member_correction_page( Member $member ): string {
		$request_id = absint( $_GET['request_id'] ?? $_POST['request_id'] ?? 0 );
		if ( 0 === $request_id && 'correction_requested' === (string) $member->field( 'adam_correction_status' ) ) { return $this->render_registration_correction_page( $member ); }
		$request = $this->member_changes->repository()->find( $request_id );
		if ( null === $request || $request->user_id() !== $member->user_id() || MemberChangeRequest::STATUS_CORRECTION_REQUESTED !== $request->status() ) {
			return $this->render_not_found();
		}
		$message = '';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['adam_member_correction_submit'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'adam_member_correction_' . $request_id ) ) {
				$message = $this->notice_markup( 'error', 'Não foi possível validar o pedido.' );
			} else {
				$changes = array();
				foreach ( $request->changes() as $field => $change ) {
					$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
					$old = $change['old'] ?? '';
					if ( (string) $value !== (string) ( $change['new'] ?? '' ) ) { $changes[ $field ] = array( 'old' => $old, 'new' => $value ); }
				}
				$result = $this->member_changes->submit_correction( $request_id, $member->user_id(), $changes );
				if ( is_wp_error( $result ) ) { $message = $this->notice_markup( 'error', $result->get_error_message() ); } else { wp_safe_redirect( $this->member_area_url( array( 'view' => 'member-update', 'member_update_confirmation' => '1', 'request_id' => $request_id ) ) ); exit; }
			}
		}
		ob_start(); ?>
		<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow">CORRIGIR PEDIDO</p><h2>Corrigir pedido</h2><p>Atualize a informação indicada pela ADAM e volte a enviar o pedido para análise.</p></div></section><section class="adam-card adam-form-card adam-public-form"><?php echo wp_kses_post( $message ); ?><div class="adam-notice adam-notice--warning"><strong>Correção solicitada</strong><p><?php echo esc_html( $request->correction_reason() ); ?><?php if ( $request->correction_note() ) : ?> — <?php echo esc_html( $request->correction_note() ); ?><?php endif; ?></p></div><form method="post"><?php wp_nonce_field( 'adam_member_correction_' . $request_id ); ?><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request_id ); ?>"><div class="adam-form-grid">
		<?php foreach ( $request->changes() as $field => $change ) : ?><label class="adam-form-field"><?php echo esc_html( DisplayLabels::field( (string) $field ) ); ?><input type="text" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) ( $change['new'] ?? '' ) ); ?>"><small>Valor anteriormente enviado: <?php echo esc_html( DisplayLabels::value( (string) $field, $change['new'] ?? '' ) ); ?></small></label><?php endforeach; ?></div><button class="button button-primary" name="adam_member_correction_submit" value="1">Enviar correção</button></form></section></div>
		<?php return (string) ob_get_clean();
	}

	private function render_registration_correction_page( Member $member ): string {
		$settings = $this->settings->membership_form_settings();
		$fields = (array) ( $settings['registration_fields'] ?? array() );
		$stored_fields = $member->field( 'adam_correction_fields' );
		$history = is_array( $member->field( 'adam_correction_history' ) ) ? $member->field( 'adam_correction_history' ) : array();
		$active_round = absint( $member->field( 'adam_correction_active_round' ) );
		foreach ( array_reverse( $history ) as $round ) {
			if ( is_array( $round ) && ( 0 === $active_round || absint( $round['id'] ?? 0 ) === $active_round ) && 'correction_requested' === (string) ( $round['status'] ?? '' ) ) { $stored_fields = $round['fields'] ?? array(); break; }
		}
		$allowed = $this->normalize_correction_fields( $stored_fields, $fields );
		$map = array( 'full_name' => 'nome', 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'phone' => 'telefone', 'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa', 'external_association_proof' => 'adam_external_association_proof' );
		if ( '1' === (string) ( $_GET['correction_complete'] ?? '' ) ) { return '<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><h2>Correção submetida</h2><p>As alterações ao seu pedido foram enviadas com sucesso. A ADAM irá agora rever novamente a informação submetida.</p></div></section></div>'; }
		$message = '';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['adam_registration_correction_submit'] ) ) {
			foreach ( $allowed as $required_key ) { $definition = is_array( $fields[ $required_key ] ?? null ) ? $fields[ $required_key ] : array( 'label' => DisplayLabels::field( $required_key ), 'type' => 'text' ); $raw_value = sanitize_text_field( wp_unslash( $_POST[ $required_key ] ?? '' ) ); $field_error = 'profile_photo' === $required_key ? ( empty( $_FILES['profile_photo']['name'] ) ? new \WP_Error( 'adam_photo_required', 'É necessário enviar a fotografia.' ) : null ) : SharedFieldValidator::validate( $required_key, $raw_value, $definition, true ); if ( $field_error instanceof \WP_Error ) { $message = $this->notice_markup( 'error', 'Preencha corretamente todos os campos solicitados antes de enviar a correção. ' . $field_error->get_error_message() ); unset( $_POST['adam_registration_correction_submit'] ); break; } }
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'adam_registration_correction' ) ) { $message = $this->notice_markup( 'error', 'Não foi possível validar o pedido.' ); }
			else { $updates = array(); foreach ( $allowed as $key ) { if ( array_key_exists( $key, $_POST ) && isset( $map[ $key ] ) ) { $updates[ $map[ $key ] ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); } } if ( isset( $_FILES['profile_photo'] ) && ! empty( $_FILES['profile_photo']['name'] ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; $upload = media_handle_upload( 'profile_photo', 0, array(), array( 'test_form' => false ) ); if ( ! is_wp_error( $upload ) ) { $updates['profile_photo'] = $upload; } } if ( array() !== $updates ) { $history = is_array( $member->field( 'adam_correction_history' ) ) ? $member->field( 'adam_correction_history' ) : array(); foreach ( $history as &$round ) { if ( is_array( $round ) && absint( $round['id'] ?? 0 ) === absint( $member->field( 'adam_correction_active_round' ) ) ) { $round['status'] = 'correction_submitted'; $round['submitted_at'] = current_time( 'mysql' ); $round['values'] = $updates; } } unset( $round ); $member->save( array_merge( $updates, array( 'adam_correction_status' => 'correction_submitted', 'adam_correction_history' => $history ) ) ); wp_safe_redirect( $this->member_area_url( array( 'view' => 'correction', 'correction_complete' => '1' ) ) ); exit; } $message = $this->notice_markup( 'error', 'Corrija pelo menos um campo antes de enviar.' ); }
		}
		ob_start(); ?><div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><h2>Corrigir pedido</h2><p><?php echo esc_html( (string) $member->field( 'adam_correction_note' ) ); ?></p></div></section><section class="adam-card adam-form-card adam-public-form"><?php echo wp_kses_post( $message ); ?><div class="adam-notice adam-notice--warning"><strong>Necessita de correção</strong><p>Motivo: <?php echo esc_html( (string) $member->field( 'adam_correction_reason' ) ); ?></p></div><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_registration_correction' ); ?><div class="adam-form-grid"><?php foreach ( $allowed as $key ) : $field = $fields[ $key ] ?? array( 'label' => DisplayLabels::field( $key ) ); $storage = $map[ $key ] ?? $key; $value = (string) $member->field( $storage ); ?><label class="adam-form-field"><span><?php echo esc_html( $field['label'] ?? $key ); ?></span><input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>"></label><?php endforeach; ?><?php if ( in_array( 'profile_photo', $allowed, true ) ) : ?><label class="adam-form-field">Fotografia<input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp"></label><?php endif; ?></div><button class="button button-primary" name="adam_registration_correction_submit" value="1">Enviar correção</button></form></section></div><?php return (string) ob_get_clean();
	}

	/**
	 * Render and process a registration correction using the canonical registration
	 * field definitions.  This path is deliberately separate from member-change
	 * corrections: an unapproved registration must never be sent to the normal
	 * member dashboard after submission.
	 */
	private function render_registration_correction_v2( Member $member ): string {
		$settings = $this->settings->membership_form_settings();
		$fields   = (array) ( $settings['registration_fields'] ?? array() );
		$stored  = $member->field( 'adam_correction_fields' );
		$history = is_array( $member->field( 'adam_correction_history' ) ) ? $member->field( 'adam_correction_history' ) : array();
		$active  = absint( $member->field( 'adam_correction_active_round' ) );
		foreach ( array_reverse( $history ) as $round ) {
			if ( is_array( $round ) && ( 0 === $active || absint( $round['id'] ?? 0 ) === $active ) && 'correction_requested' === (string) ( $round['status'] ?? '' ) ) {
				$stored = $round['fields'] ?? array();
				break;
			}
		}
		$allowed = $this->normalize_correction_fields( $stored, $fields );
		$map = array(
			'full_name' => 'nome', 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil',
			'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade',
			'nationality' => 'nacionalidade', 'email' => 'email', 'phone' => 'telefone',
			'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2',
			'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais',
			'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade',
			'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa',
			'external_association_proof' => 'adam_external_association_proof', 'profile_photo' => 'profile_photo',
		);
		$file_fields = array( 'profile_photo', 'external_association_proof', 'payment_receipt' );
		$definitions = array();
		foreach ( $allowed as $key ) {
			$definition = is_array( $fields[ $key ] ?? null ) ? $fields[ $key ] : array();
			$type = (string) ( $definition['type'] ?? '' );
			if ( 'upload' === $type ) { $type = 'file'; }
			if ( '' === $type ) {
				$type = in_array( $key, $file_fields, true ) ? 'file' : ( in_array( $key, array( 'birth_date', 'document_expiry_date' ), true ) ? 'date' : ( 'email' === $key ? 'email' : 'text' ) );
			}
			$definitions[ $key ] = array(
				'label'   => (string) ( $definition['label'] ?? DisplayLabels::field( $key ) ),
				'help'    => (string) ( $definition['help'] ?? '' ),
				'type'    => $type,
				'options' => (string) ( $definition['options'] ?? '' ),
			);
		}
		$message = '';
		if ( empty( $allowed ) ) {
			$message = $this->notice_markup( 'error', 'Não foi possível identificar os campos solicitados. Contacte-nos através de apoio@airsoftmondego.pt.' );
		}
		if ( '1' === (string) ( $_GET['correction_complete'] ?? '' ) ) {
			return '<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><h2>Correção submetida</h2><p>Recebemos as correções ao seu pedido. A informação corrigida foi enviada para nova análise pela ADAM.</p><a class="button button-primary" href="' . esc_url( $this->member_area_url() ) . '">Voltar à Área de Sócio</a></div></section></div>';
		}
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['adam_registration_correction_submit'] ) && ! empty( $allowed ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'adam_registration_correction' ) ) {
				$message = $this->notice_markup( 'error', 'Não foi possível validar o pedido. Tente novamente.' );
			} else {
				$updates = array();
				$email_update = '';
				$upload_ids = array();
				foreach ( $allowed as $key ) {
					$config = $definitions[ $key ];
					if ( 'file' === $config['type'] ) {
						$file_key = 'profile_photo' === $key ? 'profile_photo' : $key;
						$mimes = 'profile_photo' === $key ? array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) : array( 'pdf' => 'application/pdf', 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
						$check = SharedFieldValidator::validate_upload( $_FILES[ $file_key ] ?? array(), $mimes, true );
						if ( is_wp_error( $check ) ) { $message = $this->notice_markup( 'error', $check->get_error_message() ); break; }
						continue;
					}
					$raw = array_key_exists( $key, $_POST ) ? wp_unslash( $_POST[ $key ] ) : '';
					if ( 'citizen_card' === $key ) { $raw = IdentificationValidator::normalize( is_scalar( $raw ) ? (string) $raw : '' ); }
					$check = SharedFieldValidator::validate( $key, $raw, $config, true );
					if ( is_wp_error( $check ) ) { $message = $this->notice_markup( 'error', $check->get_error_message() ); break; }
					$clean_value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
					if ( 'email' === $key ) { $email_update = $clean_value; } else { $updates[ $map[ $key ] ?? $key ] = $clean_value; }
				}
				if ( '' === $message ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
					foreach ( $allowed as $key ) {
						if ( 'file' !== $definitions[ $key ]['type'] ) { continue; }
						$file_key = 'profile_photo' === $key ? 'profile_photo' : $key;
						$upload = media_handle_upload( $file_key, 0, array(), array( 'test_form' => false ) );
						if ( is_wp_error( $upload ) ) { $message = $this->notice_markup( 'error', $upload->get_error_message() ); break; }
						$upload_ids[ $map[ $key ] ?? $key ] = absint( $upload );
					}
				}
				if ( '' === $message && $email_update !== '' ) {
					$email_result = wp_update_user( array( 'ID' => $member->user_id(), 'user_email' => $email_update ) );
					if ( is_wp_error( $email_result ) ) { $message = $this->notice_markup( 'error', $email_result->get_error_message() ); }
				}
				if ( '' === $message && ( $updates || $upload_ids || $email_update !== '' ) ) {
					$history = is_array( $member->field( 'adam_correction_history' ) ) ? $member->field( 'adam_correction_history' ) : array();
					foreach ( $history as &$round ) {
						if ( is_array( $round ) && absint( $round['id'] ?? 0 ) === $active ) { $round['status'] = 'correction_submitted'; $round['submitted_at'] = current_time( 'mysql' ); $round['values'] = array_merge( $updates, $upload_ids, $email_update !== '' ? array( 'email' => $email_update ) : array() ); }
					}
					unset( $round );
					$member->save( array_merge( $updates, $upload_ids, array( 'adam_correction_status' => 'correction_submitted', 'adam_correction_history' => $history ) ) );
					if ( 'correction_submitted' === (string) $member->field( 'adam_correction_status' ) && in_array( $member->status(), array( Member::STATUS_PENDING, Member::STATUS_REJECTED ), true ) ) {
						wp_safe_redirect( $this->member_area_url( array( 'view' => 'correction', 'correction_complete' => '1' ) ) );
						exit;
					}
					$message = $this->notice_markup( 'error', 'Não foi possível guardar a correção. Tente novamente.' );
				}
				if ( '' === $message ) { $message = $this->notice_markup( 'error', 'Corrija pelo menos um campo antes de enviar.' ); }
			}
		}
		ob_start();
		?>
		<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><h2>Corrigir pedido</h2><p>Corrija os dados indicados pela ADAM e volte a submeter o seu pedido.</p></div></section><section class="adam-card adam-form-card adam-public-form"><?php echo wp_kses_post( $message ); ?><div class="adam-notice adam-notice--warning"><strong>Necessita de correção</strong><p>Motivo: <?php echo esc_html( (string) $member->field( 'adam_correction_reason' ) ); ?></p><?php if ( $member->field( 'adam_correction_note' ) ) : ?><p>O que precisa de corrigir: <?php echo esc_html( (string) $member->field( 'adam_correction_note' ) ); ?></p><?php endif; ?></div><?php if ( ! empty( $allowed ) ) : ?><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_registration_correction' ); ?><div class="adam-form-grid">
		<?php foreach ( $allowed as $key ) : $config = $definitions[ $key ]; $storage = $map[ $key ] ?? $key; $value = $member->field( $storage ); if ( 'file' === $config['type'] ) : ?><label class="adam-form-field"><span><?php echo esc_html( $config['label'] ); ?></span><?php if ( $member->media_url( $storage ) ) : ?><a href="<?php echo esc_url( $member->media_url( $storage ) ); ?>" target="_blank" rel="noopener">Ver documento atual</a><?php endif; ?><input type="file" name="<?php echo esc_attr( $key ); ?>" accept="profile_photo" === $key ? ".jpg,.jpeg,.png,.webp" : ".pdf,.jpg,.jpeg,.png,.webp" required></label><?php elseif ( in_array( $config['type'], array( 'select', 'radio' ), true ) ) : ?><label class="adam-form-field"><span><?php echo esc_html( $config['label'] ); ?></span><select name="<?php echo esc_attr( $key ); ?>" required><option value="">Selecionar</option><?php foreach ( SharedFieldValidator::parse_options( $config['options'] ) as $option_key => $option_label ) : ?><option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( (string) $value, (string) $option_key ); ?>><?php echo esc_html( $option_label ); ?></option><?php endforeach; ?></select></label><?php else : ?><label class="adam-form-field"><span><?php echo esc_html( $config['label'] ); ?></span><input type="<?php echo esc_attr( in_array( $config['type'], array( 'date', 'email', 'number', 'tel' ), true ) ? $config['type'] : 'text' ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>" required></label><?php endif; endforeach; ?></div><button class="button button-primary" name="adam_registration_correction_submit" value="1">Enviar correção</button></form><?php endif; ?></section></div>
		<?php
		return (string) ob_get_clean();
	}

	/** @param mixed $stored @param array<string,mixed> $definitions @return array<int,string> */
	private function normalize_correction_fields( mixed $stored, array $definitions ): array {
		$out = array();
		$aliases = array( 'civil_status' => 'marital_status', 'estado_civil' => 'marital_status', 'telemovel' => 'phone', 'fotografia' => 'profile_photo', 'comprovativo_de_associacao' => 'external_association_proof', 'comprovativo_de_associacao_apd' => 'external_association_proof', 'adam_external_association_proof' => 'external_association_proof', 'cartao_cidadao' => 'citizen_card', 'bi_cartao_de_cidadao' => 'citizen_card' );
		$items = (array) $stored;
		if ( is_array( $stored ) && array_keys( $stored ) !== range( 0, count( $stored ) - 1 ) ) { $items = array_keys( $stored ); }
		foreach ( $items as $raw ) {
			$key = is_array( $raw ) ? ( $raw['field_key'] ?? $raw['key'] ?? $raw['name'] ?? '' ) : $raw;
			$key = sanitize_key( (string) $key );
			$key = $aliases[ $key ] ?? $key;
			if ( isset( $definitions[ $key ] ) ) { $out[] = $key; continue; }
			foreach ( $definitions as $definition_key => $definition ) {
				if ( is_array( $definition ) && $key === sanitize_key( (string) ( $definition['label'] ?? '' ) ) ) { $out[] = (string) $definition_key; break; }
			}
			if ( ! in_array( $key, $out, true ) && 'profile_photo' === $key ) { $out[] = $key; }
			if ( ! in_array( $key, $out, true ) && function_exists( 'error_log' ) ) { error_log( 'ADAM correction field could not be resolved: ' . $key ); }
		}
		return array_values( array_unique( $out ) );
	}

	private function render_apd_correction_page( Member $member, int $request_id ): string {
		$request = $this->apd_association->repository()->find( $request_id );
		if ( null === $request || $request->user_id() !== $member->user_id() || ApdAssociationRequest::STATUS_CORRECTION_REQUESTED !== $request->status() ) { return $this->render_not_found(); }
		$message = '';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['adam_apd_correction_submit'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'adam_apd_correction_' . $request_id ) ) { $message = $this->notice_markup( 'error', 'Não foi possível validar o pedido.' ); }
			else {
				$data = (array) ( $request->data()['submitted_data'] ?? array() );
				foreach ( $data as $field => $value ) { if ( array_key_exists( $field, $_POST ) ) { $data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); } }
				$this->apd_association->repository()->update( $request, array( 'status' => ApdAssociationRequest::STATUS_AWAITING_ADAM, 'submitted_data' => $data, 'correction_resubmitted_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) ) );
				wp_safe_redirect( $this->member_area_url( array( 'view' => 'member-update', 'member_update_confirmation' => '1', 'request_id' => $request_id ) ) ); exit;
			}
		}
		$data = (array) ( $request->data()['submitted_data'] ?? array() ); ob_start(); ?>
		<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow">CORRIGIR PEDIDO</p><h2>Corrigir pedido APD / ANA</h2><p><?php echo esc_html( (string) ( $request->data()['correction_note'] ?? $request->data()['correction_reason'] ?? '' ) ); ?></p></div></section><section class="adam-card adam-form-card adam-public-form"><form method="post"><?php wp_nonce_field( 'adam_apd_correction_' . $request_id ); ?><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request_id ); ?>"><div class="adam-form-grid"><?php foreach ( $data as $field => $value ) : if ( ! is_scalar( $value ) ) { continue; } ?><label class="adam-form-field"><?php echo esc_html( DisplayLabels::field( (string) $field ) ); ?><input type="text" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"></label><?php endforeach; ?></div><button class="button button-primary" name="adam_apd_correction_submit" value="1">Enviar correção</button></form></section></div><?php return (string) ob_get_clean();
	}

	private function render_member_update_confirmation_page( Member $member, int $request_id ): string {
		$request = $request_id > 0 ? $this->member_changes->repository()->find( $request_id ) : null;
		if ( null === $request || $request->user_id() !== $member->user_id() ) { return $this->render_not_found(); }
		$labels = array();
		$pretty_fields = array( 'estado_civil' => 'Estado civil', 'genero' => 'Género', 'profissao' => 'Profissão', 'data_nascimento' => 'Data de nascimento', 'nacionalidade' => 'Nacionalidade', 'naturalidade' => 'Naturalidade', 'telefone' => 'Telemóvel', 'telefone_fixo' => 'Telefone', 'morada' => 'Morada', 'codigo_postal' => 'Código postal', 'cidade' => 'Localidade', 'municipio' => 'Município', 'pais' => 'País', 'nif' => 'NIF', 'cartao_cidadao' => 'Documento de identificação', 'documento_validade' => 'Data de validade', 'documento_local_emissao' => 'Local de emissão', 'equipa' => 'Equipa', 'profile_photo' => 'Fotografia', 'email' => 'Email' );
		foreach ( $request->changes() as $field => $change ) { $labels[] = $pretty_fields[ (string) $field ] ?? DisplayLabels::field( (string) $field ); }
		ob_start(); ?>
		<div class="adam-member-area adam-account-page adam-confirmation-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow">PEDIDO RECEBIDO</p><h2>Alterações enviadas</h2><p>Recebemos o seu pedido de atualização de dados.</p></div></section><section class="adam-card adam-form-card"><div class="adam-confirmation-icon" aria-hidden="true">✓</div><p>As alterações serão verificadas pela ADAM antes de serem aplicadas à sua conta.</p><p>Até serem aprovadas, a informação atualmente aprovada mantém-se inalterada.</p><dl class="adam-confirmation-summary"><div><dt>Pedido</dt><dd>Atualização de dados</dd></div><div><dt>Estado</dt><dd>A aguardar aprovação</dd></div><div><dt>Data do pedido</dt><dd><?php echo esc_html( $this->format_datetime( $request->submitted_at() ) ); ?></dd></div></dl><?php if ( $labels ) : ?><h3>Alterações submetidas</h3><ul><?php foreach ( $labels as $label ) : ?><li><?php echo esc_html( $label ); ?></li><?php endforeach; ?></ul><?php endif; ?><p><a class="button button-primary" href="<?php echo esc_url( $this->member_area_url() ); ?>">Voltar à Área de Sócio</a></p></section></div>
		<?php return (string) ob_get_clean();
	}

	private function render_apd_confirmation_page( Member $member, int $request_id ): string {
		$request = $request_id > 0 ? $this->apd_association->repository()->find( $request_id ) : null;
		if ( null === $request || $request->user_id() !== $member->user_id() ) { return $this->render_not_found(); }
		ob_start(); ?>
		<div class="adam-member-area adam-account-page adam-confirmation-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow">PEDIDO RECEBIDO</p><h2>Pedido de associação à ANA enviado</h2><p>Recebemos o seu pedido para associar a sua APD através da ADAM.</p></div></section><section class="adam-card adam-form-card"><div class="adam-confirmation-icon" aria-hidden="true">✓</div><div class="adam-notice adam-notice--warning"><strong>A sua inscrição ainda não está confirmada.</strong><p>A ADAM irá verificar o pedido e proceder ao registo junto da ANA. A inscrição só será aprovada após confirmação por parte da ANA.</p><p>O processo poderá demorar entre 2 e 7 dias.</p></div><dl class="adam-confirmation-summary"><div><dt>Pedido</dt><dd>Associação APD através da ADAM</dd></div><div><dt>Valor pago</dt><dd><?php echo esc_html( number_format_i18n( (float) $request->amount(), 2 ) . ' €' ); ?></dd></div><div><dt>Estado</dt><dd>Pedido recebido / A aguardar processamento</dd></div><div><dt>Data do pedido</dt><dd><?php echo esc_html( $this->format_datetime( $request->requested_at() ) ); ?></dd></div></dl><p><a class="button button-primary" href="<?php echo esc_url( $this->member_area_url() ); ?>">Voltar à Área de Sócio</a></p></section></div>
		<?php return (string) ob_get_clean();
	}

	private function member_update_field_definitions( Member $member ): array {
		$form_settings = $this->settings->membership_form_settings();
		$configs = (array) ( $form_settings['registration_fields'] ?? array() );
		$allowed = (array) ( $form_settings['forms']['update']['fields'] ?? array_keys( $configs ) );
		$configs = array_intersect_key( $configs, array_flip( $allowed ) );
		$map = array( 'full_name' => $member->full_name(), 'birth_date' => $member->field( 'data_nascimento' ), 'marital_status' => $member->field( 'estado_civil' ), 'gender' => $member->field( 'genero' ), 'profession' => $member->field( 'profissao' ), 'birthplace' => $member->field( 'naturalidade' ), 'nationality' => $member->field( 'nacionalidade' ), 'email' => $member->email(), 'phone' => $member->field( 'telefone' ), 'telephone' => $member->field( 'telefone_fixo' ), 'address_line_1' => $member->field( 'morada' ), 'address_line_2' => $member->field( 'morada_linha_2' ), 'postcode' => $member->field( 'codigo_postal' ), 'city' => $member->field( 'cidade' ), 'municipality' => $member->field( 'municipio' ), 'country' => $member->field( 'pais' ), 'citizen_card' => $member->field( 'cartao_cidadao' ), 'document_expiry_date' => $member->field( 'documento_validade' ), 'document_issuing_place' => $member->field( 'documento_local_emissao' ), 'nif' => $member->field( 'nif' ), 'team' => $member->field( 'equipa' ) );
		$meta_map = array( 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'phone' => 'telefone', 'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa' );
		$out = array();
		foreach ( $configs as $key => $config ) { if ( ! is_array( $config ) || empty( $config['enabled'] ) || in_array( $key, array( 'payment_receipt', 'privacy_acceptance', 'profile_photo', 'external_association_name', 'external_member_number', 'external_association_proof' ), true ) ) { continue; } $meta = $meta_map[ $key ] ?? ''; $out[ $key ] = array( 'label' => (string) ( $config['label'] ?? $key ), 'value' => (string) ( $map[ $key ] ?? ( '' !== $meta ? $member->field( $meta ) : $member->field( 'adam_custom_' . sanitize_key( (string) $key ) ) ) ), 'key' => $key, 'type' => (string) ( $config['type'] ?? 'text' ), 'options' => (string) ( $config['options'] ?? '' ), 'readonly' => 'full_name' === $key ); }
		foreach ( $out as $field_key => $definition ) {
			$out[ $field_key ]['type'] = isset( $definition['type'] ) && is_string( $definition['type'] ) && '' !== $definition['type'] ? $definition['type'] : 'text';
			$out[ $field_key ]['label'] = (string) ( $definition['label'] ?? $field_key );
			$out[ $field_key ]['key'] = (string) ( $definition['key'] ?? $field_key );
			$out[ $field_key ]['options'] = (string) ( $definition['options'] ?? '' );
		}
		return $out;
	}

	private function render_member_update_page( Member $member ): string {
		if ( empty( $this->settings->membership_form_settings()['forms']['update']['enabled'] ) ) {
			return $this->render_not_found();
		}
		if ( ! $member->isActive() && ! $member->isExpired() ) {
			return $this->render_not_found();
		}
		$fields = array(
			'nome_completo' => array( 'label' => __( 'Nome completo', 'adam-membership' ), 'value' => $member->full_name(), 'key' => 'full_name', 'readonly' => true ),
			'email' => array( 'label' => __( 'Email', 'adam-membership' ), 'value' => $member->email(), 'key' => 'email', 'readonly' => false ),
			'data_nascimento' => array( 'label' => __( 'Data de nascimento', 'adam-membership' ), 'value' => $member->field( 'data_nascimento' ), 'key' => 'data_nascimento' ),
			'genero' => array( 'label' => __( 'Género', 'adam-membership' ), 'value' => $member->field( 'genero' ), 'key' => 'genero' ),
			'estado_civil' => array( 'label' => __( 'Estado civil', 'adam-membership' ), 'value' => $member->field( 'estado_civil' ), 'key' => 'estado_civil' ),
			'nacionalidade' => array( 'label' => __( 'Nacionalidade', 'adam-membership' ), 'value' => $member->field( 'nacionalidade' ), 'key' => 'nacionalidade' ),
			'naturalidade' => array( 'label' => __( 'Naturalidade', 'adam-membership' ), 'value' => $member->field( 'naturalidade' ), 'key' => 'naturalidade' ),
			'profissao' => array( 'label' => __( 'Profissão', 'adam-membership' ), 'value' => $member->field( 'profissao' ), 'key' => 'profissao' ),
			'telefone' => array( 'label' => __( 'Telemóvel', 'adam-membership' ), 'value' => $member->field( 'telefone' ), 'key' => 'telefone' ),
			'telefone_fixo' => array( 'label' => __( 'Telefone', 'adam-membership' ), 'value' => $member->field( 'telefone_fixo' ), 'key' => 'telefone_fixo' ),
			'morada' => array( 'label' => __( 'Morada', 'adam-membership' ), 'value' => $member->field( 'morada' ), 'key' => 'morada' ),
			'morada_linha_2' => array( 'label' => __( 'Morada (linha 2)', 'adam-membership' ), 'value' => $member->field( 'morada_linha_2' ), 'key' => 'morada_linha_2' ),
			'codigo_postal' => array( 'label' => __( 'Código postal', 'adam-membership' ), 'value' => $member->field( 'codigo_postal' ), 'key' => 'codigo_postal' ),
			'cidade' => array( 'label' => __( 'Localidade', 'adam-membership' ), 'value' => $member->field( 'cidade' ), 'key' => 'cidade' ),
			'municipio' => array( 'label' => __( 'Município', 'adam-membership' ), 'value' => $member->field( 'municipio' ), 'key' => 'municipio' ),
			'pais' => array( 'label' => __( 'País', 'adam-membership' ), 'value' => $member->field( 'pais' ), 'key' => 'pais' ),
			'nif' => array( 'label' => 'NIF', 'value' => $member->field( 'nif' ), 'key' => 'nif' ),
			'cartao_cidadao' => array( 'label' => __( 'BI / Cartão de Cidadão', 'adam-membership' ), 'value' => $member->field( 'cartao_cidadao' ), 'key' => 'cartao_cidadao' ),
			'documento_validade' => array( 'label' => __( 'Data de validade', 'adam-membership' ), 'value' => $member->field( 'documento_validade' ), 'key' => 'documento_validade' ),
			'documento_local_emissao' => array( 'label' => __( 'Local de emissão', 'adam-membership' ), 'value' => $member->field( 'documento_local_emissao' ), 'key' => 'documento_local_emissao' ),
		);
		$fields = $this->member_update_field_definitions( $member );
		// External APD identity is managed outside this form; it must not be editable here.
		$external = false;
		if ( $external ) {
			$fields['adam_external_association_name'] = array( 'label' => __( 'APD / Associação', 'adam-membership' ), 'value' => $member->field( 'adam_external_association_name' ), 'key' => 'adam_external_association_name' );
			$fields['adam_external_member_number'] = array( 'label' => __( 'N.º de sócio APD', 'adam-membership' ), 'value' => $member->field( 'adam_external_member_number' ), 'key' => 'adam_external_member_number' );
		}
		$message = 'submitted' === (string) ( $_GET['member_update'] ?? '' ) ? $this->notice_markup( 'success', __( 'Pedido de alteração submetido para análise.', 'adam-membership' ) ) : '';
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['adam_member_update_submit'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'adam_member_update' ) ) {
				$message = $this->notice_markup( 'error', __( 'Não foi possível validar o pedido.', 'adam-membership' ) );
			} else {
				$submitted = array();
				foreach ( $fields as $field ) {
					if ( ! empty( $field['readonly'] ) || ! isset( $_POST[ $field['key'] ] ) ) { continue; }
					$patch_keys = array( 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'phone' => 'telefone', 'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa' );
					$patch_key = $patch_keys[ $field['key'] ] ?? $field['key'];
					$submitted[ $patch_key ] = sanitize_text_field( wp_unslash( $_POST[ $field['key'] ] ) );
					if ( 'citizen_card' === (string) $field['key'] ) { $submitted[ $patch_key ] = IdentificationValidator::normalize( $submitted[ $patch_key ] ); }
				}
				$validation_error = null;
				foreach ( array( 'profile_photo' ) as $upload_field ) {
					if ( empty( $_FILES[ $upload_field ]['name'] ) ) { continue; }
					$upload_check = SharedFieldValidator::validate_upload( $_FILES[ $upload_field ], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ), false );
					if ( is_wp_error( $upload_check ) ) { $validation_error = $upload_check; continue; }
					require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
					$attachment = media_handle_upload( $upload_field, 0, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
					if ( ! is_wp_error( $attachment ) ) { $submitted[ $upload_field ] = $attachment; }
				}
				foreach ( $fields as $field ) {
					$key = (string) ( $field['key'] ?? '' );
					if ( '' === $key || ! array_key_exists( $key, $_POST ) || ! empty( $field['readonly'] ) ) { continue; }
					$check = SharedFieldValidator::validate( $key, $submitted[ $this->member_update_patch_key( $key ) ] ?? '', $field, false );
					if ( is_wp_error( $check ) ) { $validation_error = $check; break; }
				}
				if ( $validation_error instanceof \WP_Error ) {
					$message = $this->notice_markup( 'error', $validation_error->get_error_message() );
				} else {
					$result = $this->member_changes->submit( $member, $submitted );
					if ( is_wp_error( $result ) ) { $message = $this->notice_markup( 'error', $result->get_error_message() ); } else { if ( 'correction_requested' === (string) $member->field( 'adam_correction_status' ) ) { $member->save( array( 'adam_correction_status' => 'correction_submitted' ) ); } wp_safe_redirect( $this->member_area_url( array( 'view' => 'member-update', 'member_update_confirmation' => '1', 'request_id' => $result->id() ) ) ); exit; }
				}
			}
		}
		ob_start(); ?>
		<div class="adam-member-area adam-account-page"><section class="adam-member-hero adam-account-hero"><div><p class="adam-eyebrow"><?php esc_html_e( 'DADOS DO SÓCIO', 'adam-membership' ); ?></p><h2><?php esc_html_e( 'Atualizar dados', 'adam-membership' ); ?></h2><p><?php esc_html_e( 'Consulte e atualize os seus dados pessoais. As alterações serão enviadas para aprovação da ADAM.', 'adam-membership' ); ?></p></div></section><section class="adam-card adam-form-card adam-public-form" data-adam-membership-form="member-update">
			<?php echo wp_kses_post( $message ); ?><p><?php esc_html_e( 'As alterações serão revistas por um administrador antes de serem aplicadas.', 'adam-membership' ); ?></p>
			<form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'adam_member_update' ); ?>
				<div class="adam-form-section"><h3><?php esc_html_e( 'Informação pessoal', 'adam-membership' ); ?></h3><div class="adam-form-grid">
				<?php foreach ( $fields as $name => $field ) : ?><label class="adam-form-field"><?php echo esc_html( $field['label'] ); ?><?php if ( 'select' === $field['type'] ) : ?><select name="<?php echo esc_attr( $field['key'] ); ?>"><option value="">Selecionar</option><?php foreach ( preg_split( '/\r\n|\r|\n/', $field['options'] ) ?: array() as $option ) : $option = trim( (string) $option ); if ( '' === $option ) { continue; } $parts = explode( '|', $option, 2 ); ?><option value="<?php echo esc_attr( $parts[0] ); ?>" <?php selected( (string) $field['value'], (string) $parts[0] ); ?>><?php echo esc_html( $parts[1] ?? $parts[0] ); ?></option><?php endforeach; ?></select><?php else : ?><input type="<?php echo esc_attr( 'date' === $field['type'] ? 'date' : ( 'email' === $field['type'] ? 'email' : ( 'phone' === $field['type'] ? 'tel' : 'text' ) ) ); ?>" name="<?php echo esc_attr( $field['key'] ); ?>" value="<?php echo esc_attr( (string) $field['value'] ); ?>" <?php echo ! empty( $field['readonly'] ) ? 'readonly' : ''; ?>><?php endif; ?></label><?php endforeach; ?>
				</div></div><div class="adam-form-section"><h3><?php esc_html_e( 'Documentos', 'adam-membership' ); ?></h3><div class="adam-form-grid"><label class="adam-form-field"><?php esc_html_e( 'Fotografia', 'adam-membership' ); ?><input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp"></label><?php if ( $external ) : ?><label class="adam-form-field"><?php esc_html_e( 'Comprovativo de Associação/APD', 'adam-membership' ); ?><input type="file" name="adam_external_association_proof" accept=".pdf,.jpg,.jpeg,.png"></label><?php endif; ?></div></div>
				<button class="button button-primary" name="adam_member_update_submit" value="1"><?php esc_html_e( 'Enviar para validação', 'adam-membership' ); ?></button>
			</form>
		</section></div>
		<?php return (string) ob_get_clean();
	}

	private function member_update_patch_key( string $key ): string {
		return (array( 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'phone' => 'telefone', 'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa' )[ $key ] ?? $key);
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
		$url = ManagedPages::url( 'member_area' );

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
			sprintf( __( 'Se tiver alguma dúvida sobre o motivo indicado, contacte-nos através de %s.', 'adam-membership' ), $this->settings->support_email() ),
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
					<a class="adam-action-card adam-card" href="<?php echo esc_url( $action['url'] ); ?>">
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
			'<div class="notice notice-%1$s adam-member-notice adam-notice" role="%2$s"><p>%3$s</p></div>',
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
