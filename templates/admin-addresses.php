<?php
/** @var array<string,array<string,mixed>> $rows */
/** @var WP_Post[] $available_pages */
defined( 'ABSPATH' ) || exit;
$notice = sanitize_key( $_GET['adam_notice'] ?? '' );
?>
<div class="wrap adam-membership-admin adam-admin-page">
	<header class="adam-page-header"><div><h1><?php esc_html_e( 'Endereços de ADAM Sócios', 'adam-membership' ); ?></h1><p><?php esc_html_e( 'Estas páginas WordPress suportam os formulários e percursos públicos do plugin.', 'adam-membership' ); ?></p></div></header>
	<?php
	if ( 'saved' === $notice ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Os endereços foram guardados.', 'adam-membership' ); ?></p></div><?php endif; ?>
	<?php
	if ( 'recovered' === $notice ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A página foi recuperada com sucesso.', 'adam-membership' ); ?></p></div><?php endif; ?>
	<?php
	if ( 'duplicate' === $notice ) :
		?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Cada função deve utilizar uma página WordPress diferente.', 'adam-membership' ); ?></p></div><?php endif; ?>
	<?php if ( 'restored' === $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'O conteúdo anterior da página Associa-te foi restaurado. A revisão do renderer ADAM continua disponível no histórico da página.', 'adam-membership' ); ?></p></div><?php endif; ?>
	<form class="adam-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<input type="hidden" name="action" value="adam_membership_save_addresses">
		<?php wp_nonce_field( 'adam_membership_save_addresses' ); ?>
		<div class="adam-table-responsive"><table class="widefat striped adam-table"><thead><tr>
			<th><?php esc_html_e( 'Página do sistema', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Página WordPress', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Slug atual', 'adam-membership' ); ?></th><th><?php esc_html_e( 'ID', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Página Protegida', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
		</tr></thead><tbody>
		<?php
		foreach ( $rows as $key => $row ) :
			$page = $row['page'];
			?>
			<tr><td><strong><?php echo esc_html( $row['definition']['label'] ); ?></strong></td>
			<td><select name="page_ids[<?php echo esc_attr( $key ); ?>]"><option value="0"><?php esc_html_e( 'Selecionar página', 'adam-membership' ); ?></option>
			<?php
			foreach ( $available_pages as $candidate ) :
				?>
				<option value="<?php echo esc_attr( $candidate->ID ); ?>" <?php selected( $row['id'], $candidate->ID ); ?>><?php echo esc_html( $candidate->post_title ?: sprintf( __( 'Página #%d', 'adam-membership' ), $candidate->ID ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="text" name="slugs[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $page instanceof \WP_Post ? $page->post_name : '' ); ?>" <?php disabled( ! $row['exists'] ); ?>></td>
			<td><?php echo $row['id'] ? esc_html( (string) $row['id'] ) : '—'; ?></td>
			<td>
			<?php
			if ( $row['exists'] ) :
				?>
				<span class="adam-badge adam-badge-success"><?php esc_html_e( 'Existe', 'adam-membership' ); ?></span>
				<?php
else :
	?>
				<span class="adam-badge adam-badge-warning"><?php esc_html_e( 'Em falta', 'adam-membership' ); ?></span><?php endif; ?></td>
			<td><label><input type="checkbox" name="protected[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $row['protected'] ); ?> <?php disabled( ! $row['exists'] ); ?>> <?php esc_html_e( 'Ativa', 'adam-membership' ); ?></label></td>
			<td>
			<?php
			if ( $row['exists'] ) :
				?>
				<a class="button" href="<?php echo esc_url( get_edit_post_link( $row['id'], 'raw' ) ); ?>"><?php esc_html_e( 'Editar página', 'adam-membership' ); ?></a> <a class="button" href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir', 'adam-membership' ); ?></a><?php if ( ! empty( $row['has_backup'] ) ) : ?> <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'adam_membership_restore_landing_content', admin_url( 'admin-post.php' ) ), 'adam_membership_restore_landing_content' ) ); ?>"><?php esc_html_e( 'Restaurar conteúdo anterior', 'adam-membership' ); ?></a><?php endif; ?>
				<?php
else :
				$recover = wp_nonce_url(
					add_query_arg(
						array(
							'action'      => 'adam_membership_recover_page',
							'system_page' => $key,
						),
						admin_url( 'admin-post.php' )
					),
					'adam_membership_recover_page_' . $key
				);
	?>
	<a class="button button-primary" href="<?php echo esc_url( $recover ); ?>"><?php esc_html_e( 'Recriar página', 'adam-membership' ); ?></a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php submit_button( __( 'Guardar endereços', 'adam-membership' ) ); ?>
	</form>
</div>
