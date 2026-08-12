<?php
/**
 * Public ADAM membership landing page.
 *
 * @package AdamMembership\Frontend
 */

declare(strict_types=1);

namespace AdamMembership\Frontend;

use AdamMembership\Core\ManagedPages;
use AdamMembership\Core\SettingsRepository;

/** Renders the plugin-owned membership landing page. */
final class MembershipLanding {
	private SettingsRepository $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/** Register the landing-page shortcode and its scoped assets. */
	public function register(): void {
		add_shortcode( 'adam_membership_landing', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Enqueue assets only when this page's renderer is present. */
	public function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! has_shortcode( (string) $post->post_content, 'adam_membership_landing' ) ) {
			return;
		}

		$style_path = ADAM_MEMBERSHIP_PATH . 'assets/css/membership-landing.css';
		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/membership-landing.js';
		wp_enqueue_style( 'adam-membership-landing', ADAM_MEMBERSHIP_URL . 'assets/css/membership-landing.css', array(), file_exists( $style_path ) ? (string) filemtime( $style_path ) : ADAM_MEMBERSHIP_VERSION );
		wp_enqueue_script( 'adam-membership-landing', ADAM_MEMBERSHIP_URL . 'assets/js/membership-landing.js', array(), file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION, true );
	}

	/** Render the complete public landing page. */
	public function render(): string {
		$forms        = $this->settings->membership_form_settings();
		$fees         = (array) ( $forms['fees'] ?? array() );
		$secondary_fee = $this->money( $fees['secondary'] ?? '12.00' );
		$primary_fee   = $this->money( $fees['primary'] ?? '22.00' );
		$registration  = ManagedPages::url( 'registration' );
		$member_area   = ManagedPages::url( 'member_area' );
		$logo          = $this->settings->association_logo_url();

		$benefits = array(
			array( 'icon' => 'voice', 'title' => 'Ter voz na ADAM', 'text' => 'Participa nas Assembleias Gerais, apresenta ideias e ajuda a decidir o futuro da associação.' ),
			array( 'icon' => 'target', 'title' => 'Mais airsoft na região Centro', 'text' => 'A tua quota ajuda-nos a criar atividades, estabelecer parcerias e desenvolver novos projetos.' ),
			array( 'icon' => 'community', 'title' => 'Fazer parte da comunidade', 'text' => 'Conhece jogadores, equipas, campos e organizadores e aproxima o airsoft da região.' ),
			array( 'icon' => 'tag', 'title' => 'Vantagens para sócios', 'text' => 'Acesso às condições, benefícios, descontos e oportunidades disponibilizados pela ADAM.' ),
			array( 'icon' => 'medal', 'title' => 'Participar e ser reconhecido', 'text' => 'Participa nas atividades e acompanha o teu percurso enquanto membro da comunidade ADAM.' ),
			array( 'icon' => 'heart', 'title' => 'Apoiar um projeto que é nosso', 'text' => 'As quotas ajudam uma associação sem fins lucrativos a crescer e a criar oportunidades.' ),
		);

		$steps = array(
			array( 'number' => '01', 'title' => 'Preenche a inscrição', 'text' => 'Faz o pedido online.' ),
			array( 'number' => '02', 'title' => 'Envia os documentos', 'text' => 'Anexa o comprovativo necessário.' ),
			array( 'number' => '03', 'title' => 'Efetua o pagamento', 'text' => 'Segue as instruções apresentadas.' ),
			array( 'number' => '04', 'title' => 'Validação do pedido', 'text' => 'A ADAM analisa e aprova o processo.' ),
			array( 'number' => '05', 'title' => 'Bem-vindo à ADAM', 'text' => 'Recebe a confirmação e acede à tua área.' ),
		);

		$faqs = array(
			'Tenho de estar inscrito através da ANA para ser sócio ADAM?' => 'Não. O formulário permite escolher entre a inscrição ADAM com processo ANA e a adesão mantendo o enquadramento numa associação externa.',
			'Já pertenço a outra APD. Posso aderir?' => 'Sim. A inscrição suporta candidatos que já pertencem a outra APD e permite indicar essa associação no pedido.',
			'Quanto custa ser sócio?' => sprintf( 'A quota configurada atualmente é de %1$s €/ano na modalidade ADAM e %2$s €/ano na modalidade com processo ADAM + ANA.', $secondary_fee, $primary_fee ),
			'O seguro está incluído?' => 'Quando a inscrição na ANA é efetuada através da ADAM, o sistema informa que o seguro de responsabilidade civil já está incluído nesse processo.',
			'Quanto tempo demora a inscrição?' => 'O prazo depende do percurso escolhido. O sistema apresenta uma estimativa de 2–5 dias para o processo ADAM e de 2–7 dias quando depende da confirmação da ANA.',
			'Quando tenho de renovar?' => 'A quota é anual. A Área de Sócio mostra o estado e a validade da quota e disponibiliza o percurso de renovação quando aplicável.',
		);

		ob_start();
		?>
		<div class="adam-membership-landing">
			<section class="adam-landing-hero">
				<div class="adam-landing-shell adam-landing-hero__inner">
					<div class="adam-landing-hero__copy">
						<?php if ( $logo ) : ?><img class="adam-landing-hero__logo" src="<?php echo esc_url( $logo ); ?>" alt="ADAM" /><?php endif; ?>
						<p class="adam-landing-kicker">Associação Desportiva de Airsoft do Mondego</p>
						<h1>Faz parte da <span>ADAM</span></h1>
						<p class="adam-landing-lead">Mais do que uma associação. Uma comunidade criada para fazer crescer o airsoft na região Centro.</p>
						<div class="adam-landing-date"><span class="adam-landing-date__icon" aria-hidden="true">▣</span><span>Inscrições abrem<br /><strong>dia 16 de agosto</strong></span></div>
						<div class="adam-landing-actions"><a class="adam-landing-button adam-landing-button--primary" href="<?php echo esc_url( $registration ); ?>">Quero ser sócio <span aria-hidden="true">→</span></a><a class="adam-landing-button adam-landing-button--ghost" href="#vantagens">Conhecer as vantagens <span aria-hidden="true">⌄</span></a></div>
						<div class="adam-landing-proof"><span>◉ A partir de <?php echo esc_html( $secondary_fee ); ?>€/ano</span><span>▣ Inscrição 100% online</span></div>
					</div>
					<div class="adam-landing-hero__art" aria-hidden="true"><span class="adam-landing-hero__ring"></span><span class="adam-landing-hero__mark">A</span><span class="adam-landing-hero__coordinates">40°12'N<br />8°25'W</span></div>
				</div>
			</section>

			<section id="vantagens" class="adam-landing-section adam-landing-section--light">
				<div class="adam-landing-shell"><div class="adam-landing-heading"><p class="adam-landing-kicker">O que muda quando te juntas</p><h2>Ser sócio da ADAM é...</h2></div><div class="adam-landing-benefits"><?php foreach ( $benefits as $benefit ) : ?><article class="adam-landing-benefit"><span class="adam-landing-icon" aria-hidden="true"><?php echo $this->icon( $benefit['icon'] ); ?></span><div><h3><?php echo esc_html( $benefit['title'] ); ?></h3><p><?php echo esc_html( $benefit['text'] ); ?></p></div></article><?php endforeach; ?></div></div>
			</section>

			<section class="adam-landing-section adam-landing-section--dark adam-landing-ana"><div class="adam-landing-shell"><div class="adam-landing-ana__heading"><p class="adam-landing-kicker">Parceria para a modalidade</p><h2>ADAM <span>+</span> ANA</h2><h3>Precisas de enquadramento através de uma APD?</h3><p>Através da colaboração entre a ADAM e a Associação Nacional de Airsoft, podes tratar do processo através da ADAM.</p></div><div class="adam-landing-ana__features"><div><span class="adam-landing-icon" aria-hidden="true"><?php echo $this->icon( 'shield' ); ?></span><strong>Seguro de acidentes pessoais</strong><p>Informação e enquadramento apresentados no processo de inscrição.</p></div><div><span class="adam-landing-icon" aria-hidden="true"><?php echo $this->icon( 'document' ); ?></span><strong>Processo através da ADAM</strong><p>Trata dos teus dados e documentos através da plataforma.</p></div><div><span class="adam-landing-icon" aria-hidden="true"><?php echo $this->icon( 'scale' ); ?></span><strong>Enquadramento para a prática</strong><p>Uma solução para quem precisa de enquadramento associativo.</p></div></div><div class="adam-landing-note"><strong>Já estás inscrito noutra APD?</strong><span>Também podes ser sócio da ADAM. Não precisas de mudar de APD para fazer parte da nossa associação.</span></div></div></section>

			<section class="adam-landing-section adam-landing-section--light"><div class="adam-landing-shell"><div class="adam-landing-heading"><p class="adam-landing-kicker">Escolhe o teu caminho</p><h2>Tipos de sócio</h2></div><div class="adam-landing-membership-cards"><article class="adam-landing-membership-card"><p class="adam-landing-kicker">Sócio aderente</p><h3><?php echo esc_html( $secondary_fee ); ?>€<small>/ano</small></h3><p>Para quem quer fazer parte da ADAM mantendo o seu enquadramento associativo atual.</p><a href="<?php echo esc_url( $registration ); ?>">Aderir à ADAM <span aria-hidden="true">→</span></a></article><article class="adam-landing-membership-card adam-landing-membership-card--featured"><span class="adam-landing-membership-card__flag">ADAM + ANA</span><p class="adam-landing-kicker">Sócio efetivo</p><h3><?php echo esc_html( $primary_fee ); ?>€<small>/ano</small></h3><p>Para quem pretende tratar da inscrição ADAM e do processo de enquadramento através da ANA.</p><a href="<?php echo esc_url( $registration ); ?>">Quero ADAM + ANA <span aria-hidden="true">→</span></a></article></div></div></section>

			<section class="adam-landing-section adam-landing-section--process"><div class="adam-landing-shell"><div class="adam-landing-heading"><p class="adam-landing-kicker">Sem complicações</p><h2>Tornar-te sócio é simples</h2></div><div class="adam-landing-steps"><?php foreach ( $steps as $step ) : ?><div class="adam-landing-step"><span><?php echo esc_html( $step['number'] ); ?></span><h3><?php echo esc_html( $step['title'] ); ?></h3><p><?php echo esc_html( $step['text'] ); ?></p></div><?php endforeach; ?></div></div></section>

			<section class="adam-landing-section adam-landing-section--dark adam-landing-member-area"><div class="adam-landing-shell adam-landing-member-area__inner"><div><p class="adam-landing-kicker">A tua ADAM. Sempre contigo.</p><h2>Uma área de sócio feita para acompanhar o teu percurso.</h2><p>A Área de Sócio existente reúne o cartão digital, QR de validação, histórico e gestão da tua conta num só lugar.</p><a class="adam-landing-button adam-landing-button--light" href="<?php echo esc_url( $member_area ); ?>">Conhecer a Área de Sócio <span aria-hidden="true">→</span></a></div><div class="adam-landing-member-tools"><div><?php echo $this->icon( 'card' ); ?><strong>Cartão digital</strong></div><div><?php echo $this->icon( 'qr' ); ?><strong>QR de validação</strong></div><div><?php echo $this->icon( 'history' ); ?><strong>Histórico</strong></div><div><?php echo $this->icon( 'user' ); ?><strong>Área pessoal</strong></div></div></div></section>

			<section class="adam-landing-section adam-landing-section--light"><div class="adam-landing-shell adam-landing-faq"><div class="adam-landing-heading"><p class="adam-landing-kicker">Ainda tens dúvidas?</p><h2>Perguntas frequentes</h2></div><div><?php foreach ( $faqs as $question => $answer ) : ?><details><summary><?php echo esc_html( $question ); ?><span aria-hidden="true">+</span></summary><p><?php echo esc_html( $answer ); ?></p></details><?php endforeach; ?></div></div></section>

			<section class="adam-landing-final"><div class="adam-landing-shell"><p class="adam-landing-kicker">Comunidade · União · Evolução</p><h2>Pronto para fazer parte?</h2><p>As inscrições abrem domingo, 16 de agosto.</p><a class="adam-landing-button adam-landing-button--primary" href="<?php echo esc_url( $registration ); ?>">Quero ser sócio <span aria-hidden="true">→</span></a></div></section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function money( $value ): string {
		return number_format_i18n( (float) $value, 0 );
	}

	private function icon( string $name ): string {
		$paths = array(
			'voice' => '<path d="M12 3v18M5 8h14M5 16h14M8 5l-3 3 3 3M16 13l3 3-3 3"/>',
			'target' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3M22 12h-3M12 22v-3M2 12h3"/>',
			'community' => '<circle cx="12" cy="7" r="3"/><circle cx="5" cy="12" r="2.5"/><circle cx="19" cy="12" r="2.5"/><path d="M6 20c0-3 2.3-5 6-5s6 2 6 5M3 19c0-2 1-3.5 3-4M21 19c0-2-1-3.5-3-4"/>',
			'tag' => '<path d="M3 12V5h7l10 10-5 5L5 10"/><circle cx="7.5" cy="7.5" r="1"/>',
			'medal' => '<circle cx="12" cy="14" r="6"/><path d="M8 9 7 3l5 3 5-3-1 6M12 11l1 2 2 .3-1.5 1.5.4 2.2-1.9-1-1.9 1 .4-2.2L9 13.3l2-.3z"/>',
			'heart' => '<path d="M20 8c0 5-8 10-8 10S4 13 4 8a4 4 0 0 1 7-2 4 4 0 0 1 9 2Z"/><path d="M2 20h6l2-2h5l3-3 4 3"/>',
			'shield' => '<path d="M12 3 20 6v5c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/><path d="m8 12 3 3 5-6"/>',
			'document' => '<path d="M6 3h9l3 3v15H6zM15 3v4h4M9 12h6M9 16h6"/>',
			'scale' => '<path d="M12 4v16M5 20h14M7 8h10M5 8l-3 6h6zM19 8l-3 6h6z"/>',
			'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'qr' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2zM18 14h2"/>',
			'history' => '<path d="M4 12a8 8 0 1 0 2-5M4 4v5h5M12 8v5l3 2"/>',
			'user' => '<circle cx="12" cy="8" r="3"/><path d="M5 20c0-4 2.5-6 7-6s7 2 7 6"/>',
		);
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
