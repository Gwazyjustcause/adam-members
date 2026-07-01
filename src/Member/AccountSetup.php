<?php
/**
 * Initial account setup page.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\RateLimiter;
use WP_User;

/**
 * Handles the branded initial username/password setup flow.
 */
final class AccountSetup {
	private const TOKEN_META = 'adam_account_setup_token';
	private const TOKEN_EXPIRES_META = 'adam_account_setup_expires';
	private const USERNAME_META = 'adam_account_username';
	private const COMPLETED_AT_META = 'adam_account_setup_completed_at';
	private const TOKEN_LIFETIME = 3 * DAY_IN_SECONDS;

	private SettingsRepository $settings;
	private MemberRepository $members;
	private HistoryService $history;

	public function __construct( SettingsRepository $settings, MemberRepository $members, HistoryService $history ) {
		$this->settings = $settings;
		$this->members  = $members;
		$this->history  = $history;
	}

	/**
	 * Register shortcode, asset loading and page injection.
	 */
	public function register(): void {
		add_shortcode( 'adam_account_setup', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'the_content', array( $this, 'inject_into_configured_page' ), 8 );
	}

	/**
	 * Enqueue shared account setup assets.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_setup_page_request() ) {
			return;
		}

		wp_enqueue_script( 'password-strength-meter' );
		wp_enqueue_script( 'zxcvbn-async' );
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_script(
			'adam-password-strength',
			ADAM_MEMBERSHIP_URL . 'assets/js/password-strength.js',
			array( 'jquery', 'password-strength-meter' ),
			ADAM_MEMBERSHIP_VERSION,
			true
		);

		wp_enqueue_script(
			'adam-password-toggle',
			ADAM_MEMBERSHIP_URL . 'assets/js/password-toggle.js',
			array(),
			ADAM_MEMBERSHIP_VERSION,
			true
		);
	}

	/**
	 * Issue a new one-time setup link for a user.
	 *
	 * @param WP_User $user User.
	 */
	public function issue_setup_link( WP_User $user ): string {
		$token = wp_generate_password( 48, false, false );

		update_user_meta( $user->ID, self::TOKEN_META, wp_hash( $token ) );
		update_user_meta( $user->ID, self::TOKEN_EXPIRES_META, time() + self::TOKEN_LIFETIME );

		return add_query_arg(
			array(
				'user'  => (int) $user->ID,
				'token' => rawurlencode( $token ),
			),
			$this->settings->account_setup_page_url()
		);
	}

	/**
	 * Resolve a frontend login identifier into the internal WordPress login.
	 *
	 * @param string $identifier Submitted identifier.
	 */
	public function resolve_login_identifier( string $identifier ): string {
		$identifier = sanitize_text_field( $identifier );

		if ( '' === $identifier || is_email( $identifier ) || username_exists( $identifier ) ) {
			return $identifier;
		}

		$users = get_users(
			array(
				'meta_key'     => self::USERNAME_META,
				'meta_value'   => sanitize_user( strtolower( $identifier ), true ),
				'number'       => 1,
				'count_total'  => false,
				'fields'       => 'all',
			)
		);

		if ( isset( $users[0] ) && $users[0] instanceof WP_User ) {
			return (string) $users[0]->user_login;
		}

		return $identifier;
	}

	/**
	 * Render the branded setup page.
	 */
	public function render(): string {
		$user_id = absint( $_REQUEST['user'] ?? 0 );
		$token   = sanitize_text_field( wp_unslash( $_REQUEST['token'] ?? '' ) );

		if ( 0 === $user_id || '' === $token ) {
			return $this->render_error_state( __( 'O link de definição de acesso é inválido.', 'adam-membership' ) );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user instanceof WP_User ) {
			return $this->render_error_state( __( 'Não foi possível localizar a conta associada a este link.', 'adam-membership' ) );
		}

		$validation_error = $this->validate_token( $user, $token );

		if ( '' !== $validation_error ) {
			return $this->render_error_state( $validation_error );
		}

		$message = $this->process_submission( $user, $token );

		if ( 'success' === ( $message['state'] ?? '' ) ) {
			return $this->render_success_state();
		}

		$username = isset( $message['username'] ) ? (string) $message['username'] : '';

		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-member-hero adam-account-hero">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Ativação da conta', 'adam-membership' ); ?></p>
					<h2><?php esc_html_e( 'Definir utilizador e palavra-passe', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Conclua o acesso à sua conta ADAM escolhendo as credenciais que vai utilizar na Área de Sócio.', 'adam-membership' ); ?></p>
				</div>
			</section>

			<section class="adam-card adam-form-card" aria-labelledby="adam-account-setup-title">
				<h3 id="adam-account-setup-title"><?php esc_html_e( 'Criar acesso ADAM', 'adam-membership' ); ?></h3>

				<?php echo wp_kses_post( (string) ( $message['notice'] ?? '' ) ); ?>

				<form method="post" class="adam-account-form">
					<?php wp_nonce_field( 'adam_account_setup' ); ?>
					<input type="hidden" name="user" value="<?php echo esc_attr( (string) $user->ID ); ?>">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

					<div class="adam-form-field">
						<label for="adam_setup_email"><?php esc_html_e( 'Email', 'adam-membership' ); ?></label>
						<input type="email" id="adam_setup_email" value="<?php echo esc_attr( (string) $user->user_email ); ?>" readonly>
					</div>

					<div class="adam-form-field">
						<label for="adam_setup_username"><?php esc_html_e( 'Nome de utilizador', 'adam-membership' ); ?></label>
						<input
							type="text"
							id="adam_setup_username"
							name="adam_setup_username"
							value="<?php echo esc_attr( $username ); ?>"
							required
							autocomplete="username"
						>
					</div>

					<div class="adam-form-field">
						<label for="adam_setup_password"><?php esc_html_e( 'Palavra-passe', 'adam-membership' ); ?></label>
						<input
							type="password"
							id="adam_setup_password"
							name="adam_setup_password"
							required
							autocomplete="new-password"
							aria-describedby="adam-password-requirements password-strength-text"
						>
					</div>

					<?php $this->render_password_strength(); ?>

					<div class="adam-form-field">
						<label for="adam_setup_password_confirm"><?php esc_html_e( 'Confirmar palavra-passe', 'adam-membership' ); ?></label>
						<input
							type="password"
							id="adam_setup_password_confirm"
							name="adam_setup_password_confirm"
							required
							autocomplete="new-password"
						>
					</div>

					<div class="adam-form-actions">
						<button type="submit" name="adam_account_setup_submit" class="button button-primary adam-primary-action">
							<?php esc_html_e( 'Concluir acesso', 'adam-membership' ); ?>
						</button>
					</div>
				</form>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Inject the shortcode automatically into the configured page.
	 *
	 * @param string $content Page content.
	 */
	public function inject_into_configured_page( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$permalink = get_permalink();

		if ( ! is_string( $permalink ) || ! $this->same_url( $permalink, $this->settings->account_setup_page_url() ) ) {
			return $content;
		}

		if ( str_contains( $content, '[adam_account_setup' ) ) {
			return $content;
		}

		return $content . "\n\n[adam_account_setup]";
	}

	/**
	 * Validate a token for the supplied user.
	 *
	 * @param WP_User $user  User.
	 * @param string  $token Raw token.
	 */
	private function validate_token( WP_User $user, string $token ): string {
		$stored_hash = (string) get_user_meta( $user->ID, self::TOKEN_META, true );
		$expires     = absint( get_user_meta( $user->ID, self::TOKEN_EXPIRES_META, true ) );

		if ( '' === $stored_hash || 0 === $expires ) {
			return __( 'Este link já foi utilizado ou expirou.', 'adam-membership' );
		}

		if ( time() > $expires ) {
			$this->delete_setup_token( (int) $user->ID );

			return __( 'Este link expirou. Solicite um novo pedido de inscrição à ADAM se necessário.', 'adam-membership' );
		}

		if ( ! hash_equals( $stored_hash, wp_hash( $token ) ) ) {
			return __( 'O código de acesso deste link é inválido.', 'adam-membership' );
		}

		return '';
	}

	/**
	 * Process a valid setup submission.
	 *
	 * @param WP_User $user  User.
	 * @param string  $token Token.
	 * @return array{state:string,notice:string,username:string}
	 */
	private function process_submission( WP_User $user, string $token ): array {
		$username = sanitize_text_field( wp_unslash( $_POST['adam_setup_username'] ?? '' ) );

		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_account_setup_submit'] )
		) {
			return array(
				'state'    => 'idle',
				'notice'   => '',
				'username' => $username,
			);
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_account_setup' )
		) {
			return array(
				'state'    => 'error',
				'notice'   => $this->notice_markup( 'error', __( 'Não foi possível validar o pedido.', 'adam-membership' ) ),
				'username' => $username,
			);
		}

		if ( '' !== $this->validate_token( $user, $token ) ) {
			return array(
				'state'    => 'error',
				'notice'   => $this->notice_markup( 'error', __( 'Este link já não está disponível.', 'adam-membership' ) ),
				'username' => $username,
			);
		}

		$identity = RateLimiter::request_identity( (string) $user->user_email );

		if ( RateLimiter::too_many_attempts( 'account_setup', $identity, 5, 15 * MINUTE_IN_SECONDS ) ) {
			return array(
				'state'    => 'error',
				'notice'   => $this->notice_markup( 'error', __( 'Demasiadas tentativas. Tente novamente mais tarde.', 'adam-membership' ) ),
				'username' => $username,
			);
		}

		RateLimiter::hit( 'account_setup', $identity, 15 * MINUTE_IN_SECONDS );

		$normalized_username = sanitize_user( strtolower( $username ), true );
		$password            = (string) wp_unslash( $_POST['adam_setup_password'] ?? '' );
		$confirm             = (string) wp_unslash( $_POST['adam_setup_password_confirm'] ?? '' );

		$error_message = $this->validate_submission_fields( $user, $normalized_username, $password, $confirm );

		if ( '' !== $error_message ) {
			return array(
				'state'    => 'error',
				'notice'   => $this->notice_markup( 'error', $error_message ),
				'username' => $normalized_username,
			);
		}

		update_user_meta( $user->ID, self::USERNAME_META, $normalized_username );
		update_user_meta( $user->ID, self::COMPLETED_AT_META, wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );
		wp_set_password( $password, $user->ID );
		$this->delete_setup_token( (int) $user->ID );
		RateLimiter::clear( 'account_setup', $identity );

		$member = $this->members->find( (int) $user->ID );

		if ( null !== $member ) {
			$this->history->account_setup_completed( $member, $normalized_username );
		}

		return array(
			'state'    => 'success',
			'notice'   => '',
			'username' => $normalized_username,
		);
	}

	/**
	 * Validate submitted username/password fields.
	 *
	 * @param WP_User $user     User.
	 * @param string  $username Normalized username.
	 * @param string  $password Password.
	 * @param string  $confirm  Confirmation.
	 */
	private function validate_submission_fields( WP_User $user, string $username, string $password, string $confirm ): string {
		if ( '' === $username ) {
			return __( 'Introduza um nome de utilizador válido.', 'adam-membership' );
		}

		if ( strlen( $username ) < 4 ) {
			return __( 'O nome de utilizador deve ter pelo menos 4 caracteres.', 'adam-membership' );
		}

		if ( username_exists( $username ) && $username !== (string) $user->user_login ) {
			return __( 'Este nome de utilizador já está a ser utilizado.', 'adam-membership' );
		}

		$owner = $this->username_owner_id( $username );

		if ( 0 !== $owner && $owner !== (int) $user->ID ) {
			return __( 'Este nome de utilizador já está reservado.', 'adam-membership' );
		}

		if ( $password !== $confirm ) {
			return __( 'As palavras-passe não coincidem.', 'adam-membership' );
		}

		if ( strlen( $password ) < 8 ) {
			return __( 'A palavra-passe deve ter pelo menos 8 caracteres.', 'adam-membership' );
		}

		if ( wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return __( 'A nova palavra-passe deve ser diferente da palavra-passe atual.', 'adam-membership' );
		}

		return '';
	}

	/**
	 * Resolve the owner of a username alias.
	 *
	 * @param string $username Username alias.
	 */
	private function username_owner_id( string $username ): int {
		$users = get_users(
			array(
				'meta_key'     => self::USERNAME_META,
				'meta_value'   => $username,
				'number'       => 1,
				'count_total'  => false,
				'fields'       => 'ids',
			)
		);

		return isset( $users[0] ) ? absint( $users[0] ) : 0;
	}

	/**
	 * Delete the active setup token metadata.
	 *
	 * @param int $user_id User ID.
	 */
	private function delete_setup_token( int $user_id ): void {
		delete_user_meta( $user_id, self::TOKEN_META );
		delete_user_meta( $user_id, self::TOKEN_EXPIRES_META );
	}

	/**
	 * Render the successful setup state.
	 */
	private function render_success_state(): string {
		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-card adam-login-required" aria-labelledby="adam-setup-success-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Conta pronta', 'adam-membership' ); ?></p>
				<h2 id="adam-setup-success-title"><?php esc_html_e( 'Acesso configurado com sucesso', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'O seu utilizador e a sua palavra-passe ficaram definidos com sucesso.', 'adam-membership' ); ?></p>
				<p><?php esc_html_e( 'Já pode iniciar sessão na Área de Sócio com o email ou com o nome de utilizador escolhido.', 'adam-membership' ); ?></p>

				<div class="adam-form-actions adam-form-actions-center">
					<a class="button button-primary adam-primary-action" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
						<?php esc_html_e( 'Ir para a Área de Sócio', 'adam-membership' ); ?>
					</a>
				</div>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render an error state for invalid or expired links.
	 *
	 * @param string $message Error message.
	 */
	private function render_error_state( string $message ): string {
		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-card adam-login-required" aria-labelledby="adam-setup-error-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Link indisponível', 'adam-membership' ); ?></p>
				<h2 id="adam-setup-error-title"><?php esc_html_e( 'Definir acesso ADAM', 'adam-membership' ); ?></h2>
				<p><?php echo esc_html( $message ); ?></p>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render password strength guidance.
	 */
	private function render_password_strength(): void {
		?>
		<div id="adam-password-strength" class="adam-password-strength">
			<p class="adam-strength-title"><?php esc_html_e( 'Força da palavra-passe', 'adam-membership' ); ?></p>

			<div id="adam-strength-bar" class="adam-strength-bar" aria-hidden="true">
				<span></span>
				<span></span>
				<span></span>
				<span></span>
				<span></span>
			</div>

			<p id="password-strength-text" class="adam-strength-text" aria-live="polite">
				<?php esc_html_e( 'Muito fraca', 'adam-membership' ); ?>
			</p>

			<div id="adam-password-requirements" class="adam-password-rules">
				<p><?php esc_html_e( 'A sua palavra-passe deve conter:', 'adam-membership' ); ?></p>
				<ul>
					<li id="rule-length"><?php esc_html_e( 'Pelo menos 8 caracteres', 'adam-membership' ); ?></li>
					<li id="rule-lower"><?php esc_html_e( 'Uma letra minúscula', 'adam-membership' ); ?></li>
					<li id="rule-upper"><?php esc_html_e( 'Uma letra maiúscula', 'adam-membership' ); ?></li>
					<li id="rule-number"><?php esc_html_e( 'Um número', 'adam-membership' ); ?></li>
					<li id="rule-symbol"><?php esc_html_e( 'Um símbolo', 'adam-membership' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Build a notice box.
	 *
	 * @param string $type Notice type.
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
	 * Determine whether the current request is the setup page.
	 */
	private function is_setup_page_request(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$permalink = get_permalink();

		return is_string( $permalink ) && $this->same_url( $permalink, $this->settings->account_setup_page_url() );
	}

	/**
	 * Compare two URLs by normalized path.
	 *
	 * @param string $left  Left URL.
	 * @param string $right Right URL.
	 */
	private function same_url( string $left, string $right ): bool {
		$left_path  = wp_parse_url( $left, PHP_URL_PATH );
		$right_path = wp_parse_url( $right, PHP_URL_PATH );

		if ( ! is_string( $left_path ) || ! is_string( $right_path ) ) {
			return false;
		}

		return trailingslashit( $left_path ) === trailingslashit( $right_path );
	}
}
