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
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode(
			'adam_member_area',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the member area.
	 */
	public function render(): string {

		if ( ! is_user_logged_in() ) {
			return $this->render_login();
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
				?>
				<p>Estado desconhecido.</p>
				<?php
			}
			?>

		</div>

		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render login page.
	 */
	private function render_login(): string {

		ob_start();

		?>

		<div class="adam-member-area">

			<h2>Área do Sócio</h2>

			<p>Esta área é exclusiva para associados.</p>

			<?php
			wp_login_form(
				array(
					'remember' => true,
				)
			);
			?>

		</div>

		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render member not found.
	 */
	private function render_not_found(): string {

		return '
		<div class="adam-member-area">
			<h2>Área do Sócio</h2>
			<p>Não foi encontrada informação de associado.</p>
		</div>';
	}

	/**
	 * Render common page header.
	 */
	private function render_header( Member $member ): void {

		?>

		<h2>Área do Sócio</h2>

		<p>
			Bem-vindo,
			<strong><?php echo esc_html( $member->full_name() ); ?></strong>.
		</p>

		<?php
	}
    	/**
	 * Render pending dashboard.
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
					'url'   => wp_logout_url( home_url() ),
				),
			)
		);
	}

	/**
	 * Render rejected dashboard.
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
					'url'   => wp_logout_url( home_url() ),
				),
			)
		);
	}

	/**
	 * Render active dashboard.
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
					'url'   => wp_lostpassword_url(),
				),
				array(
					'label' => 'Terminar sessão',
					'url'   => wp_logout_url( home_url() ),
				),
			)
		);
	}

	/**
	 * Render status card.
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
	 * @param array<int, array{label:string,url:string}> $actions Actions.
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