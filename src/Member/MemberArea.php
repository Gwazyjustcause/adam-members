<?php
/**
 * Member area shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

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
	 * Constructor.
	 *
	 * @param MemberRepository $members Member repository.
	 */
	public function __construct( MemberRepository $members ) {
		$this->members = $members;
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

		wp_enqueue_style(
			'adam-member-area',
			ADAM_MEMBERSHIP_URL . 'assets/css/member-area.css',
			array(),
			ADAM_MEMBERSHIP_VERSION
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

		ob_start();

		?>

		<div class="adam-member-area">

			<?php $this->render_header( $member ); ?>

			<?php

			if ( $member->isPending() ) {

				$this->render_pending( $member );

			} elseif ( $member->isRejected() ) {

				$this->render_rejected( $member );

			} elseif ( $member->isActive() ) {

				$this->render_active( $member );

			} else {

				echo '<p>Estado desconhecido.</p>';

			}

			?>

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

	if ( isset( $_GET['logged_out'] ) ) {
		$message = '<div class="notice notice-success"><p>Sessão terminada com sucesso.</p></div>';
	}

	ob_start();

	?>

		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Área do Sócio</h2>

				<p>Inicie sessão para aceder à Área do Sócio.</p>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post">

					<?php wp_nonce_field( 'adam_member_login' ); ?>

					<p>

						<label for="adam_login">
							Email ou Nome de Utilizador
						</label>

						<input
							type="text"
							id="adam_login"
							name="adam_login"
							required
							autocomplete="username"
						>

					</p>

					<p>

						<label for="adam_password">
							Palavra-passe
						</label>

						<input
							type="password"
							id="adam_password"
							name="adam_password"
							required
							autocomplete="current-password"
						>

					</p>

					<p>

						<label>

							<input
								type="checkbox"
								name="rememberme"
								value="1"
							>

							Lembrar-me

						</label>

					</p>

					<p>

						<button
							type="submit"
							name="adam_login_submit"
							class="button button-primary"
						>
							Iniciar Sessão
						</button>

					</p>

					<p>

						<a href="<?php echo esc_url( home_url( '/recuperar-password/' ) ); ?>">
							Esqueceu-se da palavra-passe?
						</a>

					</p>

				</form>

			</div>

		</div>

		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Process login.
	 */
	private function process_login(): string {

		if (
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
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
			return '<div class="notice notice-error"><p>Pedido inválido.</p></div>';
		}

		$login = sanitize_text_field(
			wp_unslash( $_POST['adam_login'] ?? '' )
		);

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
			return '<div class="notice notice-error"><p>Email ou palavra-passe incorretos.</p></div>';
		}

		wp_safe_redirect( home_url( '/socio/' ) );
		exit;
	}
    	/**
	 * Render member not found.
	 */
	private function render_not_found(): string {

		return '
		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Área do Sócio</h2>

				<p>Não foi encontrada informação de associado.</p>

			</div>

		</div>';
	}

	/**
	 * Render page header.
	 *
	 * @param Member $member Member.
	 */
	private function render_header( Member $member ): void {

		?>

		<h2>Área do Sócio</h2>

		<p>

			Bem-vindo,

			<strong>

				<?php echo esc_html( $member->full_name() ); ?>

			</strong>.

		</p>

		<?php
	}

	/**
	 * Render pending dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_pending( Member $member ): void {

		$this->render_status_card(
			'Pendente',
			'O seu pedido de inscrição foi recebido e encontra-se em análise pela ADAM.'
		);

		$this->render_actions(
			array(
				array(
					'label' => 'Terminar sessão',
					'url'   => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
				),
			)
		);
	}

	/**
	 * Render rejected dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_rejected( Member $member ): void {

		$this->render_status_card(
			'Rejeitado',
			'Infelizmente a sua inscrição não foi aprovada. Caso pretenda mais informações, contacte a ADAM.'
		);

		$this->render_actions(
			array(
				array(
					'label' => 'Terminar sessão',
					'url'   => wp_logout_url( home_url( '/socio/?logged_out=1' ) )
				),
			)
		);
	}
    	/**
	 * Render active dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_active( Member $member ): void {

		$this->render_status_card(
			$member->status(),
			'A sua quota encontra-se ativa.'
		);

		$this->render_membership( $member );

		$this->render_profile( $member );

		$this->render_actions(
			array(
				array(
					'label' => 'Alterar Palavra-passe',
					'url'   => home_url( '/socio-password/' ),
				),
				array(
					'label' => 'Alterar Email',
					'url'   => home_url( '/socio-email/' ),
				),
				array(
					'label' => 'Terminar sessão',
					'url'   => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
				),
			)
		);
	}

	/**
	 * Render status card.
	 *
	 * @param string $status Status.
	 * @param string $message Message.
	 */
	private function render_status_card(
		string $status,
		string $message
	): void {

		?>

		<section class="adam-card">

			<h3>Estado</h3>

			<p><strong><?php echo esc_html( $status ); ?></strong></p>

			<p><?php echo esc_html( $message ); ?></p>

		</section>

		<?php
	}

	/**
	 * Render membership information.
	 *
	 * @param Member $member Member.
	 */
	private function render_membership( Member $member ): void {

		?>

		<section class="adam-card">

			<h3>Quota</h3>

			<p>
				<strong>N.º de Sócio:</strong><br>
				<?php echo esc_html( (string) $member->field( 'numero_socio' ) ); ?>
			</p>

			<p>
				<strong>Data de Adesão:</strong><br>
				<?php echo esc_html( (string) $member->field( 'data_adesao' ) ); ?>
			</p>

			<p>
				<strong>Validade:</strong><br>
				<?php echo esc_html( (string) $member->field( 'validade_quota' ) ); ?>
			</p>

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

		<section class="adam-card">

			<h3>Dados do Sócio</h3>

			<p>
				<strong>Nome:</strong><br>
				<?php echo esc_html( $member->full_name() ); ?>
			</p>

			<p>
				<strong>Email:</strong><br>
				<?php echo esc_html( $member->email() ); ?>
			</p>

			<p>
				<strong>Telefone:</strong><br>
				<?php echo esc_html( (string) $member->field( 'telefone' ) ); ?>
			</p>

			<p>
				<strong>Equipa:</strong><br>
				<?php echo esc_html( (string) $member->field( 'equipa' ) ); ?>
			</p>

		</section>

		<?php
	}

	/**
	 * Render action links.
	 *
	 * @param array<int,array{label:string,url:string}> $actions Actions.
	 */
	private function render_actions( array $actions ): void {

		?>

		<section class="adam-card">

			<h3>Ações</h3>

			<ul>

				<?php foreach ( $actions as $action ) : ?>

					<li>

						<a href="<?php echo esc_url( $action['url'] ); ?>">

							<?php echo esc_html( $action['label'] ); ?>

						</a>

					</li>

				<?php endforeach; ?>

			</ul>

		</section>

		<?php
	}
}