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
	private const VERSION              = '1.1.0';
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
		add_filter( 'adam_membership_member_area_url', array( $this, 'member_area_url' ) );

		if ( function_exists( 'adam_ui_register_system_pages' ) ) {
			adam_ui_register_system_pages( 'adam-membership', array( $this, 'protection_definitions' ) );
		}
	}

	/** @return array<string,array{label:string,title:string,slug:string,content:string}> */
	public static function definitions(): array {
		return array(
			'landing'            => array(
				'label'   => __( 'Associa-te', 'adam-membership' ),
				'title'   => __( 'Associa-te', 'adam-membership' ),
				'slug'    => 'associa-te',
				'content' => '[adam_membership_landing]',
			),
			'registration'       => array(
				'label'   => __( 'Inscrição', 'adam-membership' ),
				'title'   => __( 'Inscrição', 'adam-membership' ),
				'slug'    => 'inscricao',
				'content' => '[adam_registration_form]',
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
		$page_id = self::id( 'landing' );
		$page    = $page_id ? get_post( $page_id ) : null;
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return;
		}

		if ( has_shortcode( (string) $page->post_content, 'adam_membership_landing' ) ) {
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
				'post_content' => '[adam_membership_landing]',
			)
		);
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
