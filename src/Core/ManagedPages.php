<?php
/**
 * Managed WordPress pages used by ADAM Sócios.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Member\AdminPreview;
use WP_Post;

/**
 * Creates, resolves and administers all plugin-owned public pages.
 */
final class ManagedPages {
	private const VERSION              = '1.2.0';
	private const OPTION_IDS           = 'adam_membership_managed_page_ids';
	private const OPTION_VER           = 'adam_membership_managed_pages_version';
	private const META_KEY             = '_adam_membership_managed_page';
	private const MENU_SLUG            = 'adam-membership-addresses';
	private const CAPABILITY           = 'manage_options';
	private static bool $synchronizing = false;

	/** Registers lifecycle, shared protection and administration hooks. */
	public function register(): void {
		$this->maybe_install();
		add_action( 'post_updated', array( $this, 'page_updated' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_adam_membership_save_addresses', array( $this, 'save' ) );
		add_action( 'admin_post_adam_membership_recover_page', array( $this, 'recover' ) );
		add_action( 'admin_post_adam_membership_restore_landing_content', array( $this, 'restore_landing_content' ) );
		add_action( 'admin_post_adam_membership_migrate_landing_content', array( $this, 'migrate_landing_content' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_landing_styles' ) );
		// enqueue_block_assets is loaded into the editor iframe as well as the
		// frontend. This keeps the shared landing stylesheet in the same CSS
		// context Gutenberg actually uses to render block content.
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_landing_editor_styles' ) );
		add_filter( 'adam_membership_member_area_url', array( $this, 'member_area_url' ) );

		if ( function_exists( 'adam_ui_register_system_pages' ) ) {
			adam_ui_register_system_pages( 'adam-membership', array( $this, 'protection_definitions' ) );
		}
	}

	/** @return array<string,array{label:string,title:string,slug:string,content:string}> */
	public static function definitions(): array {
		return array(
			'registration'       => array(
				'label'   => __( 'Inscrição', 'adam-membership' ),
				'title'   => __( 'Inscrição', 'adam-membership' ),
				'slug'    => 'inscricao',
				'content' => '[adam_registration_form]',
			),
			'landing'            => array(
				'label'   => __( 'Associa-te', 'adam-membership' ),
				'title'   => __( 'Associa-te', 'adam-membership' ),
				'slug'    => 'associa-te',
				'content' => self::landing_block_content(),
			),
			'renewal'            => array(
				'label'   => __( 'Renovar Quota', 'adam-membership' ),
				'title'   => __( 'Renovar Quota', 'adam-membership' ),
				'slug'    => 'renovar-quota',
				'content' => '[adam_renewal_form]',
			),
			'member_area'        => array(
				'label'   => __( 'Área de Sócio', 'adam-membership' ),
				'title'   => __( 'Área de Sócio', 'adam-membership' ),
				'slug'    => 'socio',
				'content' => '[adam_member_area]',
			),
			'points_history'    => array(
				'label'   => __( 'Histórico de Pontos', 'adam-membership' ),
				'title'   => __( 'Histórico de Pontos', 'adam-membership' ),
				'slug'    => 'socio-pontos',
				'content' => '[adam_member_area]',
			),
			'account_setup'      => array(
				'label'   => __( 'Definir Password', 'adam-membership' ),
				'title'   => __( 'Definir Password', 'adam-membership' ),
				'slug'    => 'definir-user',
				'content' => '[adam_account_setup]',
			),
			'password_recovery'  => array(
				'label'   => __( 'Recuperar Password', 'adam-membership' ),
				'title'   => __( 'Recuperar Password', 'adam-membership' ),
				'slug'    => 'recuperar-password',
				'content' => '[adam_recuperar_password]',
			),
			'password_reset'     => array(
				'label'   => __( 'Redefinir Password', 'adam-membership' ),
				'title'   => __( 'Redefinir Password', 'adam-membership' ),
				'slug'    => 'redefinir-password',
				'content' => '[adam_reset_password]',
			),
			'change_email'       => array(
				'label'   => __( 'Alterar Email', 'adam-membership' ),
				'title'   => __( 'Alterar Email', 'adam-membership' ),
				'slug'    => 'socio-email',
				'content' => '[adam_change_email]',
			),
			'change_password'    => array(
				'label'   => __( 'Alterar Palavra-passe', 'adam-membership' ),
				'title'   => __( 'Alterar Palavra-passe', 'adam-membership' ),
				'slug'    => 'socio-password',
				'content' => '[adam_change_password]',
			),
			'email_confirmation' => array(
				'label'   => __( 'Confirmar Alteração de Email', 'adam-membership' ),
				'title'   => __( 'Confirmar Alteração de Email', 'adam-membership' ),
				'slug'    => 'confirmar-email',
				'content' => '[adam_confirm_email_change]',
			),
		);
	}

	/** Creates all required pages during plugin activation. */
	public static function activate(): void {
		self::$synchronizing = true;
		foreach ( array_keys( self::definitions() ) as $key ) {
			self::ensure( $key );
		}
		self::migrate_landing_page();
		update_option( self::OPTION_VER, self::VERSION, false );
		self::$synchronizing = false;
	}

	/** Installs newly introduced pages once during an upgrade. */
	public function maybe_install(): void {
		if ( self::VERSION !== (string) get_option( self::OPTION_VER, '' ) ) {
			self::activate();
		}

		// Repair an interrupted migration even when the version option was
		// already marked current before the repair code was deployed.
		self::migrate_landing_page();
	}

	/** Returns a valid managed page ID. */
	public static function id( string $key, bool $include_trash = false ): int {
		$ids     = get_option( self::OPTION_IDS, array() );
		$page_id = absint( is_array( $ids ) ? ( $ids[ sanitize_key( $key ) ] ?? 0 ) : 0 );
		$page    = $page_id ? get_post( $page_id ) : null;
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || ( ! $include_trash && 'trash' === $page->post_status ) ) {
			return 0;
		}
		return $page_id;
	}

	/** Returns a managed page URL, falling back to its default route. */
	public static function url( string $key ): string {
		$page_id    = self::id( $key );
		$url        = $page_id ? get_permalink( $page_id ) : false;
		$definition = self::definitions()[ sanitize_key( $key ) ] ?? null;
		return $url ? (string) $url : home_url( '/' . ( $definition['slug'] ?? '' ) . '/' );
	}

	/** Supplies the member-area URL to existing consumers. */
	public function member_area_url( string $url = '' ): string {
		unset( $url );
		return self::url( 'member_area' );
	}

	/** Registers the Endereços submenu. */
	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			__( 'Endereços', 'adam-membership' ),
			__( 'Endereços', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/** Renders the managed page table. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissão para aceder a esta página.', 'adam-membership' ) );
		}

		$rows            = array();
		$available_pages = get_pages(
			array(
				'post_status' => array( 'publish', 'draft', 'private' ),
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
			)
		);
		foreach ( self::definitions() as $key => $definition ) {
			$page_id      = self::id( $key, true );
			$page         = $page_id ? get_post( $page_id ) : null;
			$rows[ $key ] = array(
				'definition' => $definition,
				'id'         => $page_id,
				'page'       => $page,
				'exists'     => $page instanceof WP_Post && 'trash' !== $page->post_status,
				'protected'  => $page_id && function_exists( 'adam_ui_is_system_page_protected' ) ? adam_ui_is_system_page_protected( $page_id ) : false,
				'has_backup' => 'landing' === $key && '' !== (string) get_post_meta( $page_id, '_adam_membership_landing_previous_content', true ),
				'legacy'     => 'landing' === $key && self::is_legacy_landing_content( $page ),
			);
		}

		require ADAM_MEMBERSHIP_PATH . 'templates/admin-addresses.php';
	}

	/** Saves page assignments, slugs and protection flags. */
	public function save(): never {
		$this->authorize( 'adam_membership_save_addresses' );
		$page_ids  = isset( $_POST['page_ids'] ) && is_array( $_POST['page_ids'] ) ? wp_unslash( $_POST['page_ids'] ) : array();
		$slugs     = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? wp_unslash( $_POST['slugs'] ) : array();
		$protected = isset( $_POST['protected'] ) && is_array( $_POST['protected'] ) ? wp_unslash( $_POST['protected'] ) : array();
		$requested = array_filter( array_map( 'absint', $page_ids ) );
		if ( count( $requested ) !== count( array_unique( $requested ) ) ) {
			$this->redirect_with_notice( 'duplicate' );
		}

		self::$synchronizing = true;
		foreach ( self::definitions() as $key => $definition ) {
			$page_id = absint( $page_ids[ $key ] ?? self::id( $key, true ) );
			$page    = $page_id ? get_post( $page_id ) : null;
			if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
				continue;
			}

			self::store_id( $key, $page_id );
			$slug = sanitize_title( (string) ( $slugs[ $key ] ?? '' ) );
			if ( '' !== $slug && $slug !== $page->post_name ) {
				wp_update_post(
					array(
						'ID'        => $page_id,
						'post_name' => $slug,
					)
				);
				self::store_id( $key, $page_id );
			}
			if ( function_exists( 'adam_ui_set_system_page_protected' ) ) {
				adam_ui_set_system_page_protected( $page_id, ! empty( $protected[ $key ] ) );
			}
		}
		self::$synchronizing = false;
		flush_rewrite_rules( false );
		$this->redirect_with_notice( 'saved' );
	}

	/** Restores or recreates one missing managed page. */
	public function recover(): never {
		$key = sanitize_key( $_GET['system_page'] ?? '' );
		$this->authorize( 'adam_membership_recover_page_' . $key );
		if ( isset( self::definitions()[ $key ] ) ) {
			self::$synchronizing = true;
			self::ensure( $key, true );
			self::$synchronizing = false;
			flush_rewrite_rules( false );
		}
		$this->redirect_with_notice( 'recovered' );
	}

	/** Restore the content captured before the landing-page migration. */
	public function restore_landing_content(): never {
		$this->authorize( 'adam_membership_restore_landing_content' );
		$page_id = self::id( 'landing' );
		$backup  = $page_id ? get_post_meta( $page_id, '_adam_membership_landing_previous_content', true ) : '';
		if ( $page_id && is_string( $backup ) && '' !== $backup ) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $backup,
				)
			);
		}
		$this->redirect_with_notice( 'restored' );
	}

	/** Explicitly migrate the legacy landing shortcode from the admin screen. */
	public function migrate_landing_content(): never {
		$this->authorize( 'adam_membership_migrate_landing_content' );
		self::migrate_landing_page();
		$this->redirect_with_notice( 'migrated' );
	}

	/** Enqueue the landing stylesheet on the public landing page only. */
	public function enqueue_landing_styles(): void {
		if ( ! is_page( self::id( 'landing' ) ) ) {
			return;
		}
		$style_path = ADAM_MEMBERSHIP_PATH . 'assets/css/membership-landing.css';
		wp_enqueue_style( 'adam-membership-landing', ADAM_MEMBERSHIP_URL . 'assets/css/membership-landing.css', array(), file_exists( $style_path ) ? (string) filemtime( $style_path ) : ADAM_MEMBERSHIP_VERSION );
	}

	/** Enqueue the shared landing stylesheet for block content and the editor iframe. */
	public function enqueue_landing_editor_styles(): void {
		if ( ! is_admin() && ! is_page( self::id( 'landing' ) ) ) {
			return;
		}
		$style_path = ADAM_MEMBERSHIP_PATH . 'assets/css/membership-landing.css';
		wp_enqueue_style( 'adam-membership-landing', ADAM_MEMBERSHIP_URL . 'assets/css/membership-landing.css', array(), file_exists( $style_path ) ? (string) filemtime( $style_path ) : ADAM_MEMBERSHIP_VERSION );
	}

	/** Registers IDs and token entry points with the shared protection engine. */
	public function protection_definitions(): array {
		$token_pages = array(
			'registration'       => static fn (): bool => AdminPreview::is_available(),
			'account_setup'      => static fn (): bool => ! empty( $_GET['user'] ) && ! empty( $_GET['token'] ),
			'password_reset'     => static fn (): bool => ! empty( $_GET['login'] ) && ! empty( $_GET['key'] ),
			'email_confirmation' => static fn (): bool => ! empty( $_GET['user'] ) && ! empty( $_GET['token'] ),
		);
		$pages       = array();
		foreach ( array_keys( self::definitions() ) as $key ) {
			$page_id = self::id( $key );
			if ( $page_id ) {
				$pages[] = array(
					'id'           => $page_id,
					'allow_access' => $token_pages[ $key ] ?? null,
				);
			}
		}
		return $pages;
	}

	/** Flushes rewrites when a managed page route changes in the editor. */
	public function page_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		$key = $this->key_for_id( $post_id );
		if ( ! self::$synchronizing && $key && ( $after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent ) ) {
			self::store_id( $key, $post_id );
			flush_rewrite_rules( false );
		}
	}

	/** Creates or restores one page and preserves existing installations. */
	private static function ensure( string $key, bool $restore = false ): int {
		$definition = self::definitions()[ $key ] ?? null;
		if ( null === $definition ) {
			return 0;
		}
		$page_id = self::id( $key, true );
		$page    = $page_id ? get_post( $page_id ) : null;
		if ( ! $page instanceof WP_Post ) {
			$configured_url = self::configured_url( $key );
			$page_id        = '' !== $configured_url ? url_to_postid( $configured_url ) : 0;
			$page           = $page_id ? get_post( $page_id ) : null;
			if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
				$page    = null;
				$page_id = 0;
			}
		}
		if ( ! $page instanceof WP_Post ) {
			$page    = get_page_by_path( $definition['slug'], OBJECT, 'page' );
			$page_id = $page instanceof WP_Post ? $page->ID : 0;
		}
		if ( $page instanceof WP_Post ) {
			if ( 'trash' === $page->post_status && $restore ) {
				wp_untrash_post( $page_id );
			}
			self::store_id( $key, $page_id );
			return $page_id;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $definition['title'],
				'post_name'    => $definition['slug'],
				'post_content' => $definition['content'],
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return 0;
		}
		update_post_meta( $page_id, self::META_KEY, $key );
		self::store_id( $key, $page_id );
		return $page_id;
	}

	/**
	 * Adopt the existing landing page and replace its content with the plugin
	 * renderer while keeping the page ID, slug and permalink intact.
	 *
	 * The original content is stored as post meta as an additional recovery
	 * copy. wp_update_post() also creates a normal WordPress revision when
	 * revisions are enabled for pages.
	 */
	private static function migrate_landing_page(): void {
		$page_id = self::id( 'landing', true );
		$page    = $page_id ? get_post( $page_id ) : null;
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
			$page = get_page_by_path( 'associa-te', OBJECT, 'page' );
			if ( $page instanceof WP_Post ) {
				$page_id = (int) $page->ID;
				self::store_id( 'landing', $page_id );
			}
		}
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return;
		}

		if ( ! self::is_legacy_landing_content( $page ) ) {
			return;
		}

		$backup_key = '_adam_membership_landing_previous_content';
		if ( '' === (string) get_post_meta( $page_id, $backup_key, true ) ) {
			update_post_meta( $page_id, $backup_key, (string) $page->post_content );
			update_post_meta( $page_id, '_adam_membership_landing_previous_content_saved_at', current_time( 'mysql' ) );
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => self::landing_block_content(),
			)
		);
	}

	/** Identify only the old shortcode-only page content for safe repair. */
	private static function is_legacy_landing_content( ?WP_Post $page ): bool {
		return $page instanceof WP_Post && 1 === preg_match( '/^\s*\[adam_membership_landing\]\s*$/', (string) $page->post_content );
	}

	/** Build the editable Gutenberg document used for the one-time migration. */
	private static function landing_block_content(): string {
		$settings = new SettingsRepository();
		$forms    = $settings->membership_form_settings();
		$fees     = (array) ( $forms['fees'] ?? array() );
		$secondary = number_format_i18n( (float) ( $fees['secondary'] ?? '12.00' ), 0 );
		$primary   = number_format_i18n( (float) ( $fees['primary'] ?? '22.00' ), 0 );
		$registration = $settings->registration_page_url();
		$logo = esc_url( $settings->association_logo_url() );

		$benefits = array(
			array( 'title' => 'Ter voz na ADAM', 'text' => 'Participa nas Assembleias Gerais, apresenta ideias e ajuda a decidir o futuro da associação.' ),
			array( 'title' => 'Mais airsoft na região Centro', 'text' => 'A tua quota ajuda-nos a criar atividades, estabelecer parcerias e desenvolver novos projetos.' ),
			array( 'title' => 'Fazer parte da comunidade', 'text' => 'Conhece jogadores, equipas, campos e organizadores e aproxima o airsoft da região.' ),
			array( 'title' => 'Vantagens para sócios', 'text' => 'Acesso às condições, benefícios, descontos e oportunidades disponibilizados pela ADAM.' ),
			array( 'title' => 'Participar e ser reconhecido', 'text' => 'Participa nas atividades e acompanha o teu percurso enquanto membro da comunidade ADAM.' ),
			array( 'title' => 'Apoiar um projeto que é nosso', 'text' => 'As quotas ajudam uma associação sem fins lucrativos a crescer e a criar oportunidades.' ),
		);
		$benefit_blocks = '';
		foreach ( $benefits as $benefit ) {
			$benefit_blocks .= '<!-- wp:group {"className":"adam-landing-benefit","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-benefit"><!-- wp:paragraph {"className":"adam-landing-icon"} --><p class="adam-landing-icon">✦</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $benefit['title'] ) . '</h3><!-- /wp:heading --><!-- wp:paragraph --><p>' . esc_html( $benefit['text'] ) . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		}

		$faq_items = array(
			array( 'question' => 'Tenho de estar inscrito através da ANA para ser sócio ADAM?', 'answer' => 'Não. O formulário permite escolher entre a inscrição ADAM com processo ANA e a adesão mantendo o enquadramento numa associação externa.' ),
			array( 'question' => 'Já pertenço a outra APD. Posso aderir?', 'answer' => 'Sim. A inscrição suporta candidatos que já pertencem a outra APD e permite indicar essa associação no pedido.' ),
			array( 'question' => 'Quanto custa ser sócio?', 'answer' => 'A quota configurada atualmente é de ' . $secondary . ' €/ano na modalidade ADAM e ' . $primary . ' €/ano na modalidade com processo ADAM + ANA.' ),
			array( 'question' => 'O seguro está incluído?', 'answer' => 'Quando a inscrição na ANA é efetuada através da ADAM, o sistema informa que o seguro de responsabilidade civil já está incluído nesse processo.' ),
			array( 'question' => 'Quanto tempo demora a inscrição?', 'answer' => 'O prazo depende do percurso escolhido: o sistema apresenta uma estimativa de 2–5 dias para o processo ADAM e de 2–7 dias quando depende da confirmação da ANA.' ),
			array( 'question' => 'Quando tenho de renovar?', 'answer' => 'A quota é anual. A Área de Sócio mostra o estado e a validade da quota e disponibiliza o percurso de renovação quando aplicável.' ),
		);
		$faq_blocks = '';
		foreach ( $faq_items as $faq ) {
			$faq_blocks .= '<!-- wp:details {"className":"adam-landing-faq-item"} --><details class="wp-block-details adam-landing-faq-item"><summary>' . esc_html( $faq['question'] ) . '</summary><!-- wp:paragraph --><p>' . esc_html( $faq['answer'] ) . '</p><!-- /wp:paragraph --></details><!-- /wp:details -->';
		}

		return '<!-- wp:group {"metadata":{"name":"ADAM Membership Landing"},"className":"adam-membership-landing","layout":{"type":"constrained"}} --><div class="wp-block-group adam-membership-landing">'
			. '<!-- wp:cover {"className":"adam-landing-hero","minHeight":640,"minHeightUnit":"px","isDark":true,"layout":{"type":"constrained"}} --><div class="wp-block-cover is-dark adam-landing-hero" style="min-height:640px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-60 has-background-dim"></span><div class="wp-block-cover__inner-container">'
			. '<!-- wp:image {"className":"adam-landing-hero__logo","sizeSlug":"medium","linkDestination":"none"} --><figure class="wp-block-image size-medium adam-landing-hero__logo"><img src="' . $logo . '" alt="ADAM" /></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">Associação Desportiva de Airsoft do Mondego</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Faz parte da <mark style="background-color:transparent" class="has-inline-color has-adam-lime-color">ADAM</mark></h1><!-- /wp:heading -->'
			. '<!-- wp:paragraph {"className":"adam-landing-lead"} --><p class="adam-landing-lead">Mais do que uma associação. Uma comunidade criada para fazer crescer o airsoft na região Centro.</p><!-- /wp:paragraph -->'
			. '<!-- wp:group {"className":"adam-landing-date","layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group adam-landing-date"><!-- wp:paragraph --><p>▣ &nbsp; Inscrições abrem<br><strong>dia 16 de agosto</strong></p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. '<!-- wp:buttons {"className":"adam-landing-actions"} --><div class="wp-block-buttons adam-landing-actions"><!-- wp:button {"className":"adam-landing-button--primary"} --><div class="wp-block-button adam-landing-button--primary"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $registration ) . '">Quero ser sócio →</a></div><!-- /wp:button --><!-- wp:button {"className":"adam-landing-button--ghost","style":{"outline":{"width":"1px","offset":"0px","color":"#ffffff"}}} --><div class="wp-block-button adam-landing-button--ghost"><a class="wp-block-button__link wp-element-button" href="#vantagens">Conhecer as vantagens ↓</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
			. '<!-- wp:paragraph {"className":"adam-landing-proof"} --><p class="adam-landing-proof">◉ A partir de ' . esc_html( $secondary ) . '€/ano &nbsp;&nbsp; · &nbsp;&nbsp; ▣ Inscrição 100% online</p><!-- /wp:paragraph -->'
			. '</div></div><!-- /wp:cover -->'
			. '<!-- wp:group {"metadata":{"name":"Vantagens"},"anchor":"vantagens","className":"adam-landing-section adam-landing-section--light","layout":{"type":"constrained"}} --><div id="vantagens" class="wp-block-group adam-landing-section adam-landing-section--light"><!-- wp:group {"className":"adam-landing-heading","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-heading"><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">O que muda quando te juntas</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Ser sócio da ADAM é...</h2><!-- /wp:heading --></div><!-- /wp:group --><!-- wp:group {"className":"adam-landing-benefits","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-benefits">' . $benefit_blocks . '</div><!-- /wp:group --></div><!-- /wp:group -->'
			. '<!-- wp:group {"metadata":{"name":"ADAM + ANA"},"className":"adam-landing-section adam-landing-section--dark adam-landing-ana","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-section adam-landing-section--dark adam-landing-ana"><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">Parceria para a modalidade</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">ADAM <mark style="background-color:transparent" class="has-inline-color has-adam-lime-color">+</mark> ANA</h2><!-- /wp:heading --><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Precisas de enquadramento através de uma APD?</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Através da colaboração entre a ADAM e a Associação Nacional de Airsoft, podes tratar do processo através da ADAM.</p><!-- /wp:paragraph --><!-- wp:columns {"className":"adam-landing-ana__features"} --><div class="wp-block-columns adam-landing-ana__features"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Seguro de acidentes pessoais</h4><!-- /wp:heading --><!-- wp:paragraph --><p>Informação e enquadramento apresentados no processo de inscrição.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Processo através da ADAM</h4><!-- /wp:heading --><!-- wp:paragraph --><p>Trata dos teus dados e documentos através da plataforma.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Enquadramento para a prática</h4><!-- /wp:heading --><!-- wp:paragraph --><p>Uma solução para quem precisa de enquadramento associativo.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:group {"className":"adam-landing-note","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-note"><!-- wp:paragraph --><p><strong>Já estás inscrito noutra APD?</strong><br>Também podes ser sócio da ADAM. Não precisas de mudar de APD para fazer parte da nossa associação.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->'
			. '<!-- wp:group {"metadata":{"name":"Tipos de Sócio"},"className":"adam-landing-section adam-landing-section--light","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-section adam-landing-section--light"><div class="adam-landing-heading"><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">Escolhe o teu caminho</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Tipos de sócio</h2><!-- /wp:heading --></div><!-- wp:columns {"className":"adam-landing-membership-cards"} --><div class="wp-block-columns adam-landing-membership-cards"><!-- wp:column --><div class="wp-block-column adam-landing-membership-card"><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">Sócio aderente</p><!-- /wp:paragraph --><!-- wp:heading --><h3 class="wp-block-heading">' . esc_html( $secondary ) . '€<small>/ano</small></h3><!-- /wp:heading --><!-- wp:paragraph --><p>Para quem quer fazer parte da ADAM mantendo o seu enquadramento associativo atual.</p><!-- /wp:paragraph --><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $registration ) . '">Aderir à ADAM →</a></div><!-- /wp:button --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column adam-landing-membership-card adam-landing-membership-card--featured"><!-- wp:paragraph {"className":"adam-landing-membership-card__flag"} --><p class="adam-landing-membership-card__flag">ADAM + ANA</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">Sócio efetivo</p><!-- /wp:paragraph --><!-- wp:heading --><h3 class="wp-block-heading">' . esc_html( $primary ) . '€<small>/ano</small></h3><!-- /wp:heading --><!-- wp:paragraph --><p>Para quem pretende tratar da inscrição ADAM e do processo de enquadramento através da ANA.</p><!-- /wp:paragraph --><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $registration ) . '">Quero ADAM + ANA →</a></div><!-- /wp:button --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->'
			. '<!-- wp:group {"metadata":{"name":"Como Funciona"},"className":"adam-landing-section adam-landing-section--process","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-section adam-landing-section--process"><div class="adam-landing-heading"><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">Sem complicações</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Tornar-te sócio é simples</h2><!-- /wp:heading --></div><!-- wp:list {"className":"adam-landing-steps"} --><ol class="wp-block-list adam-landing-steps"><li><strong>01 · Preenche a inscrição</strong><br>Faz o pedido online.</li><li><strong>02 · Envia os documentos</strong><br>Anexa o comprovativo necessário.</li><li><strong>03 · Efetua o pagamento</strong><br>Segue as instruções apresentadas.</li><li><strong>04 · Validação do pedido</strong><br>A ADAM analisa e aprova o processo.</li><li><strong>05 · Bem-vindo à ADAM</strong><br>Recebe a confirmação e acede à tua área.</li></ol><!-- /wp:list --></div><!-- /wp:group -->'
			. '<!-- wp:group {"metadata":{"name":"Área de Sócio"},"className":"adam-landing-section adam-landing-section--dark adam-landing-member-area","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-section adam-landing-section--dark adam-landing-member-area"><!-- wp:paragraph {"className":"adam-landing-kicker"} --><p class="adam-landing-kicker">A tua ADAM. Sempre contigo.</p><!-- /wp:paragraph --><!-- wp:heading --><h2 class="wp-block-heading">Uma área de sócio feita para acompanhar o teu percurso.</h2><!-- /wp:heading --><!-- wp:paragraph --><p>A Área de Sócio existente reúne o cartão digital, QR de validação, histórico e gestão da tua conta num só lugar.</p><!-- /wp:paragraph --><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( home_url( '/socio/' ) ) . '">Conhecer a Área de Sócio →</a></div><!-- /wp:button --></div><!-- /wp:group -->'
			. '<!-- wp:group {"metadata":{"name":"FAQ"},"className":"adam-landing-section adam-landing-section--light adam-landing-faq","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-section adam-landing-section--light adam-landing-faq"><!-- wp:heading --><h2 class="wp-block-heading">Perguntas frequentes</h2><!-- /wp:heading -->' . $faq_blocks . '</div><!-- /wp:group -->'
			. '<!-- wp:group {"metadata":{"name":"CTA Final"},"className":"adam-landing-final","layout":{"type":"constrained"}} --><div class="wp-block-group adam-landing-final"><!-- wp:heading --><h2 class="wp-block-heading">Pronto para fazer parte?</h2><!-- /wp:heading --><!-- wp:paragraph --><p>As inscrições abrem domingo, 16 de agosto.</p><!-- /wp:paragraph --><!-- wp:button {"className":"adam-landing-button--primary"} --><div class="wp-block-button adam-landing-button--primary"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $registration ) . '">Quero ser sócio →</a></div><!-- /wp:button --></div><!-- /wp:group -->'
			. '</div><!-- /wp:group -->';
	}

	/** Returns a legacy configured URL when one exists for the page. */
	private static function configured_url( string $key ): string {
		$settings = new SettingsRepository();
		if ( 'registration' === $key ) {
			return $settings->registration_page_url();
		}
		if ( 'renewal' === $key ) {
			return $settings->renewal_page_url();
		}
		if ( 'account_setup' === $key ) {
			return $settings->account_setup_page_url();
		}
		return '';
	}

	/** Persists one page ID and synchronizes legacy URL options. */
	private static function store_id( string $key, int $page_id ): void {
		$ids         = get_option( self::OPTION_IDS, array() );
		$ids         = is_array( $ids ) ? $ids : array();
		$ids[ $key ] = $page_id;
		update_option( self::OPTION_IDS, $ids, false );
		update_post_meta( $page_id, self::META_KEY, $key );

		$settings = new SettingsRepository();
		$url      = get_permalink( $page_id );
		if ( $url && 'registration' === $key ) {
			$settings->save_registration_page_url( (string) $url );
		} elseif ( $url && 'renewal' === $key ) {
			$settings->save_renewal_page_url( (string) $url );
		} elseif ( $url && 'account_setup' === $key ) {
			$settings->save_account_setup_page_url( (string) $url );
		}
	}

	/** Returns the module key for a managed post ID. */
	private function key_for_id( int $page_id ): string {
		foreach ( array_keys( self::definitions() ) as $key ) {
			if ( self::id( $key, true ) === $page_id ) {
				return $key;
			}
		}
		return '';
	}

	/** Checks capability and nonce for an admin action. */
	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissão para executar esta ação.', 'adam-membership' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/** Redirects to the table with a compact result code. */
	private function redirect_with_notice( string $notice ): never {
		wp_safe_redirect( add_query_arg( 'adam_notice', sanitize_key( $notice ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}
}
