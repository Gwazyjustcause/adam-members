<?php
/**
 * Member-facing announcement markup.
 *
 * @package AdamMembership\Announcement
 */

declare(strict_types=1);

namespace AdamMembership\Announcement;

use AdamMembership\Core\ManagedPages;

/**
 * Renders the notice card and detail used in the member area.
 */
final class AnnouncementView {
	/**
	 * Render a member-facing announcement card.
	 *
	 * @param Announcement $announcement Announcement.
	 * @param bool         $unread       Whether the member has read the notice.
	 */
	public static function render_card( Announcement $announcement, bool $unread ): void {
		$card_classes = array( 'adam-announcement-card' );

		if ( $unread ) {
			$card_classes[] = 'is-unread';
		}

		if ( $announcement->pinned() ) {
			$card_classes[] = 'is-pinned';
		}
		?>
		<article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
			<div class="adam-announcement-card__meta">
				<span class="adam-announcement-category"><?php echo esc_html( $announcement->category() ); ?></span>
				<span class="adam-badge adam-announcement-priority adam-announcement-priority--<?php echo esc_attr( $announcement->priority() ); ?>"><?php echo esc_html( self::priority_label( $announcement->priority() ) ); ?></span>
			</div>
			<h4><?php echo esc_html( $announcement->title() ); ?></h4>
			<p><?php echo esc_html( $announcement->summary() ); ?></p>
			<div class="adam-announcement-card__footer">
				<span><?php echo esc_html( self::format_date( $announcement->publish_date() ) ); ?></span>
				<?php if ( '' !== $announcement->expiry_date() ) : ?>
					<span><?php echo esc_html( sprintf( __( 'Expira %s', 'adam-membership' ), self::format_date( $announcement->expiry_date() ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<div class="adam-announcement-card__actions">
				<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( add_query_arg( array( 'view' => 'avisos', 'announcement_id' => (string) $announcement->id() ), ManagedPages::url( 'member_area' ) ) ); ?>">
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
	public static function render_detail( Announcement $announcement ): void {
		?>
		<section class="adam-card adam-announcement-detail" aria-label="<?php esc_attr_e( 'Detalhe do aviso', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Centro de Avisos', 'adam-membership' ); ?></p>
					<h3><?php echo esc_html( $announcement->title() ); ?></h3>
				</div>
				<span class="adam-badge adam-announcement-priority adam-announcement-priority--<?php echo esc_attr( $announcement->priority() ); ?>"><?php echo esc_html( self::priority_label( $announcement->priority() ) ); ?></span>
			</div>
			<div class="adam-announcement-detail__meta">
				<span><?php echo esc_html( $announcement->category() ); ?></span>
				<span><?php echo esc_html( self::format_date( $announcement->publish_date() ) ); ?></span>
				<?php if ( '' !== $announcement->expiry_date() ) : ?>
					<span><?php echo esc_html( sprintf( __( 'Expira %s', 'adam-membership' ), self::format_date( $announcement->expiry_date() ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<div class="adam-announcement-detail__content">
				<?php echo wp_kses_post( wpautop( $announcement->content() ) ); ?>
			</div>
			<div class="adam-announcement-card__actions">
				<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( add_query_arg( array( 'view' => 'avisos' ), ManagedPages::url( 'member_area' ) ) ); ?>"><?php esc_html_e( 'Voltar aos avisos', 'adam-membership' ); ?></a>
				<?php if ( '' !== $announcement->action_label() && '' !== $announcement->action_url() ) : ?>
					<a class="adam-action-card adam-action-card--inline adam-card" href="<?php echo esc_url( $announcement->action_url() ); ?>"><?php echo esc_html( $announcement->action_label() ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Format an ISO or legacy compact announcement date for display.
	 *
	 * @param string $date Stored date.
	 */
	private static function format_date( string $date ): string {
		$date = trim( $date );

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
	public static function priority_label( string $priority ): string {
		return match ( $priority ) {
			Announcement::PRIORITY_IMPORTANT => __( 'Importante', 'adam-membership' ),
			Announcement::PRIORITY_URGENT    => __( 'Urgente', 'adam-membership' ),
			default                          => __( 'Informação', 'adam-membership' ),
		};
	}
}
