<?php
/**
 * Account management.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Handles account management.
 */
final class Account {

	/**
	 * Register shortcodes.
	 */
	public function register(): void {

		add_shortcode(
			'adam_change_password',
			array( $this, 'render_password_form' )
		);

		add_shortcode(
			'adam_change_email',
			array( $this, 'render_email_form' )
		);

		add_action(
			'wp_enqueue_scripts',
			array( $this, 'enqueue_assets' )
		);
	}

		/**
	 * Enqueue password strength assets.
	 */
	public function enqueue_assets(): void {

		if (
			! is_page(
				array(
					'socio-password',
					'redefinir-password',
				)
			)
		) {
			return;
		}

		wp_enqueue_script(
			'password-strength-meter'
		);

		wp_enqueue_script(
			'zxcvbn-async'
		);

		wp_enqueue_script(
			'adam-password-strength',
			ADAM_MEMBERSHIP_URL . 'assets/js/password-strength.js',
			array(
				'jquery',
				'password-strength-meter',
			),
			ADAM_MEMBERSHIP_VERSION,
			true
		);

		wp_enqueue_style(
			'dashicons'
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
	 * Render password form.
	 */
	public function render_password_form(): string {

		if ( ! is_user_logged_in() ) {

	return '
	<div class="adam-member-area">

		<div class="adam-card adam-login-required">

			<h2>🔒 Alterar Palavra-passe</h2>

			<p>
				É necessário iniciar sessão para alterar a sua palavra-passe.
			</p>

			<p>
				Esta página destina-se apenas a associados que já possuem
				uma conta na ADAM.
			</p>

			<p>

				<a
					class="button button-primary"
					href="' . esc_url( home_url( '/socio/' ) ) . '"
				>
					Iniciar Sessão
				</a>

			</p>

			<p>

				Esqueceu-se da palavra-passe?

			</p>

			<p>

				<a
					class="button"
					href="' . esc_url( home_url( '/recuperar-password/' ) ) . '"
				>
					Recuperar Palavra-passe
				</a>

			</p>

		</div>

	</div>';
}

		$message = '';

		if (
			'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST['adam_change_password'] )
		) {
			$message = $this->process_password_change();
		}

		ob_start();
		?>

		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Alterar Palavra-passe</h2>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post">

					<?php wp_nonce_field( 'adam_change_password' ); ?>

					<p>

						<label for="current_password">
							Palavra-passe atual
						</label><br>

						<input
							type="password"
							id="current_password"
							name="current_password"
							required
						>

					</p>

<p>

	<label for="new_password">
		Nova palavra-passe
	</label><br>

	<input
		type="password"
		id="new_password"
		name="new_password"
		required
		autocomplete="new-password"
	>

</p>

<div
	id="adam-password-strength"
	class="adam-password-strength"
>

	<p class="adam-strength-title">
		Força da palavra-passe
	</p>

	<div
		id="adam-strength-bar"
		class="adam-strength-bar"
	>

		<span></span>
		<span></span>
		<span></span>
		<span></span>
		<span></span>

	</div>

	<p
		id="password-strength-text"
		class="adam-strength-text"
	>
		Muito fraca
	</p>

	<div class="adam-password-rules">

		<p>
			A sua palavra-passe deve conter:
		</p>

		<ul>

			<li id="rule-length">
				✗ Pelo menos 8 caracteres
			</li>

			<li id="rule-lower">
				✗ Uma letra minúscula
			</li>

			<li id="rule-upper">
				✗ Uma letra maiúscula
			</li>

			<li id="rule-number">
				✗ Um número
			</li>

			<li id="rule-symbol">
				✗ Um símbolo
			</li>

		</ul>

	</div>

</div>

<p>

	<label for="confirm_password">
		Confirmar nova palavra-passe
	</label><br>

	<input
		type="password"
		id="confirm_password"
		name="confirm_password"
		required
	>

</p>

<p>

	<button
		type="submit"
		name="adam_change_password"
		class="button button-primary"
	>
		Alterar Palavra-passe
	</button>

</p>

				</form>

			</div>

		</div>

		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render email form.
	 */
	public function render_email_form(): string {

		if ( ! is_user_logged_in() ) {

	return '
	<div class="adam-member-area">

		<div class="adam-card adam-login-required">

			<h2>🔒 Alterar Email</h2>

			<p>
				É necessário iniciar sessão para alterar o seu endereço de email.
			</p>

			<p>
				Esta página destina-se apenas a associados que já possuem
				uma conta na ADAM.
			</p>

			<p>

				<a
					class="button button-primary"
					href="' . esc_url( home_url( '/socio/' ) ) . '"
				>
					Iniciar Sessão
				</a>

			</p>

			<p>

				Esqueceu-se da palavra-passe?

			</p>

			<p>

				<a
					class="button"
					href="' . esc_url( home_url( '/recuperar-password/' ) ) . '"
				>
					Recuperar Palavra-passe
				</a>

			</p>

		</div>

	</div>';
}

		$message = '';

		if (
			'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST['adam_change_email'] )
		) {
			$message = $this->process_email_change();
		}

		$current_user = wp_get_current_user();

		ob_start();
		?>

		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Alterar Email</h2>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post">

					<?php wp_nonce_field( 'adam_change_email' ); ?>

					<p>

						<label>Email atual</label><br>

						<input
							type="email"
							value="<?php echo esc_attr( $current_user->user_email ); ?>"
							readonly
						>

					</p>

					<p>

						<label for="new_email">
							Novo Email
						</label><br>

						<input
							type="email"
							id="new_email"
							name="new_email"
							required
						>

					</p>

					<p>

						<label for="confirm_email">
							Confirmar Email
						</label><br>

						<input
							type="email"
							id="confirm_email"
							name="confirm_email"
							required
						>

					</p>

					<p>

						<label for="email_password">
							Palavra-passe atual
						</label><br>

						<input
							type="password"
							id="email_password"
							name="email_password"
							required
						>

					</p>
                    					<p>

						<button
							type="submit"
							name="adam_change_email"
							class="button button-primary"
						>
							Alterar Email
						</button>

					</p>

				</form>

			</div>

		</div>

		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Process password change.
	 */
	private function process_password_change(): string {

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field(
					wp_unslash( $_POST['_wpnonce'] )
				),
				'adam_change_password'
			)
		) {
			return '<div class="notice notice-error"><p>Pedido inválido.</p></div>';
		}

		$user = wp_get_current_user();

		$current = (string) wp_unslash(
			$_POST['current_password'] ?? ''
		);

		$new = (string) wp_unslash(
			$_POST['new_password'] ?? ''
		);

		$confirm = (string) wp_unslash(
			$_POST['confirm_password'] ?? ''
		);

		if (
			! wp_check_password(
				$current,
				$user->user_pass,
				$user->ID
			)
		) {
			return '<div class="notice notice-error"><p>A palavra-passe atual está incorreta.</p></div>';
		}

		if ( $new !== $confirm ) {
			return '<div class="notice notice-error"><p>As palavras-passe não coincidem.</p></div>';
		}

		if ( strlen( $new ) < 8 ) {
			return '<div class="notice notice-error"><p>A palavra-passe deve ter pelo menos 8 caracteres.</p></div>';
		}

		if (
			wp_check_password(
				$new,
				$user->user_pass,
				$user->ID
			)
		) {
			return '<div class="notice notice-error"><p>A nova palavra-passe deve ser diferente da palavra-passe atual.</p></div>';
		}

		wp_set_password(
			$new,
			$user->ID
		);

		wp_set_auth_cookie(
			$user->ID
		);

		return '<div class="notice notice-success"><p>Palavra-passe alterada com sucesso.</p></div>';
	}

	/**
	 * Process email change.
	 */
	private function process_email_change(): string {

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_change_email' ) ) {
			return '<div class="notice notice-error"><p>Pedido inválido.</p></div>';
		}

		$user = wp_get_current_user();

		$new      = sanitize_email( wp_unslash( $_POST['new_email'] ?? '' ) );
		$confirm  = sanitize_email( wp_unslash( $_POST['confirm_email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['email_password'] ?? '' );
        		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return '<div class="notice notice-error"><p>A palavra-passe atual está incorreta.</p></div>';
		}

		if ( $new !== $confirm ) {
			return '<div class="notice notice-error"><p>Os emails não coincidem.</p></div>';
		}

		if ( ! is_email( $new ) ) {
			return '<div class="notice notice-error"><p>O endereço de email é inválido.</p></div>';
		}

		if ( email_exists( $new ) ) {
			return '<div class="notice notice-error"><p>Este endereço de email já está a ser utilizado.</p></div>';
		}

		wp_update_user(
			array(
				'ID'         => $user->ID,
				'user_email' => $new,
			)
		);

		return '<div class="notice notice-success"><p>Email alterado com sucesso.</p></div>';
	}
}