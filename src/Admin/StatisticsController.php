<?php
/**
 * Statistics admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Analytics\StatisticsService;
use AdamMembership\Announcement\Announcement;
use AdamMembership\Event\Event;
use AdamMembership\Event\EventCheckIn;
use AdamMembership\Event\EventService;
use AdamMembership\Member\Member;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Points\PointsEntry;
use AdamMembership\Points\PointsService;
use AdamMembership\Reward\RewardRedemption;

/**
 * Manages the admin-side statistics area.
 */
final class StatisticsController {
	private const CAPABILITY = 'manage_options';
	private const MENU_SLUG  = 'adam-membership-statistics';

	private StatisticsService $statistics;
	private EventService $events;
	private PointsService $points;

	public function __construct( StatisticsService $statistics, EventService $events, PointsService $points ) {
		$this->statistics = $statistics;
		$this->events     = $events;
		$this->points     = $points;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_export_statistics_csv', array( $this, 'handle_export_csv' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			esc_html__( 'Estatísticas', 'adam-membership' ),
			esc_html__( 'Estatísticas', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		$this->ensure_can_manage();

		$range  = $this->current_range();
		$report = $this->statistics->build_report( $range );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar adam-admin-stats-titlebar">
				<div>
					<h1><?php esc_html_e( 'Estatísticas', 'adam-membership' ); ?></h1>
					<p><?php esc_html_e( 'Visão consolidada da evolução da ADAM, atividade dos sócios, eventos, pontos, recompensas e renovações.', 'adam-membership' ); ?></p>
				</div>
				<div class="adam-admin-actions">
					<a class="button button-primary" href="<?php echo esc_url( $this->export_url( $range ) ); ?>"><?php esc_html_e( 'Exportar resumo CSV', 'adam-membership' ); ?></a>
					<button type="button" class="button" disabled><?php esc_html_e( 'Relatório anual PDF (em breve)', 'adam-membership' ); ?></button>
				</div>
			</div>

			<?php $this->render_filters( $range ); ?>
			<?php $this->render_summary_cards( (array) ( $report['summary_cards'] ?? array() ) ); ?>

			<section class="adam-admin-panel">
				<div class="adam-admin-dashboard-heading">
					<div>
						<h2><?php esc_html_e( 'Sócios', 'adam-membership' ); ?></h2>
						<p class="adam-admin-section-intro"><?php esc_html_e( 'Crescimento, distribuição de estados, renovações e expirações próximas.', 'adam-membership' ); ?></p>
					</div>
				</div>
				<div class="adam-admin-stats-grid">
					<?php
					$this->render_line_chart_panel(
						__( 'Crescimento de sócios no período', 'adam-membership' ),
						(array) ( $report['membership']['growth_rows'] ?? array() ),
						__( 'Acumulado por mês dentro do intervalo selecionado.', 'adam-membership' )
					);
					$this->render_bar_chart_panel(
						__( 'Novos sócios por mês', 'adam-membership' ),
						(array) ( $report['membership']['new_members_rows'] ?? array() ),
						__( 'Entradas mensais registadas no intervalo selecionado.', 'adam-membership' )
					);
					$this->render_donut_panel(
						__( 'Sócios por estado', 'adam-membership' ),
						$this->status_rows( (array) ( $report['membership']['status_counts'] ?? array() ) ),
						__( 'Distribuição atual do ciclo de vida dos sócios.', 'adam-membership' )
					);
					$this->render_kpi_list_panel(
						__( 'Indicadores de sócios', 'adam-membership' ),
						array(
							array( 'label' => __( 'Taxa global de renovação', 'adam-membership' ), 'value' => (string) ( $report['membership']['renewal_rate'] ?? 0 ) . '%' ),
							array( 'label' => __( 'Taxa de renovação no período', 'adam-membership' ), 'value' => (string) ( $report['membership']['renewal_rate_period'] ?? 0 ) . '%' ),
							array( 'label' => __( 'Membros fundadores', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['membership']['founders_count'] ?? 0 ) ) ),
							array( 'label' => __( 'Expirações próximas', 'adam-membership' ), 'value' => number_format_i18n( count( (array) ( $report['membership']['upcoming_expirations'] ?? array() ) ) ) ),
						)
					);
					?>
				</div>
				<?php $this->render_upcoming_expirations( (array) ( $report['membership']['upcoming_expirations'] ?? array() ) ); ?>
			</section>

			<section class="adam-admin-panel">
				<div class="adam-admin-dashboard-heading">
					<div>
						<h2><?php esc_html_e( 'Eventos', 'adam-membership' ); ?></h2>
						<p class="adam-admin-section-intro"><?php esc_html_e( 'Atividade dos eventos, participação e impacto em pontos.', 'adam-membership' ); ?></p>
					</div>
				</div>
				<div class="adam-admin-stats-grid">
					<?php
					$this->render_kpi_list_panel(
						__( 'Indicadores de eventos', 'adam-membership' ),
						array(
							array( 'label' => __( 'Eventos criados no período', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['events']['events_created_period'] ?? 0 ) ) ),
							array( 'label' => __( 'Eventos próximos', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['events']['upcoming_events'] ?? 0 ) ) ),
							array( 'label' => __( 'Eventos concluídos', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['events']['completed_events'] ?? 0 ) ) ),
							array( 'label' => __( 'Média de check-ins por evento', 'adam-membership' ), 'value' => (string) ( $report['events']['average_checkins'] ?? 0 ) ),
						)
					);
					$this->render_bar_chart_panel(
						__( 'Eventos mais participados', 'adam-membership' ),
						(array) ( $report['events']['most_attended_events'] ?? array() ),
						__( 'Check-ins registados por evento dentro do período.', 'adam-membership' )
					);
					$this->render_bar_chart_panel(
						__( 'Pontos atribuídos por evento', 'adam-membership' ),
						(array) ( $report['events']['points_by_event'] ?? array() ),
						__( 'Pontos distribuídos através de check-ins e bónus.', 'adam-membership' )
					);
					?>
				</div>
				<?php $this->render_latest_checkins( (array) ( $report['events']['latest_checkins'] ?? array() ) ); ?>
			</section>

			<section class="adam-admin-panel">
				<div class="adam-admin-dashboard-heading">
					<div>
						<h2><?php esc_html_e( 'Pontos ADAM', 'adam-membership' ); ?></h2>
						<p class="adam-admin-section-intro"><?php esc_html_e( 'Fluxo de atribuição, consumo e concentração de pontos na comunidade.', 'adam-membership' ); ?></p>
					</div>
				</div>
				<div class="adam-admin-stats-grid">
					<?php
					$this->render_kpi_list_panel(
						__( 'Indicadores de pontos', 'adam-membership' ),
						array(
							array( 'label' => __( 'Pontos atribuídos no período', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['points']['total_awarded_period'] ?? 0 ) ) ),
							array( 'label' => __( 'Pontos gastos no período', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['points']['total_spent_period'] ?? 0 ) ) ),
							array( 'label' => __( 'Pontos atualmente detidos', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['points']['current_points_held'] ?? 0 ) ) ),
							array( 'label' => __( 'Movimentos recentes', 'adam-membership' ), 'value' => number_format_i18n( count( (array) ( $report['points']['recent_activity'] ?? array() ) ) ) ),
						)
					);
					$this->render_bar_chart_panel(
						__( 'Pontos por origem', 'adam-membership' ),
						(array) ( $report['points']['by_source'] ?? array() ),
						__( 'Distribuição por check-in, bónus, ajustes e resgates.', 'adam-membership' )
					);
					$this->render_balance_panel( (array) ( $report['points']['top_members'] ?? array() ) );
					?>
				</div>
				<?php $this->render_points_activity( (array) ( $report['points']['recent_activity'] ?? array() ) ); ?>
			</section>

			<section class="adam-admin-panel">
				<div class="adam-admin-dashboard-heading">
					<div>
						<h2><?php esc_html_e( 'Recompensas', 'adam-membership' ); ?></h2>
						<p class="adam-admin-section-intro"><?php esc_html_e( 'Procura do catálogo, aprovações e categorias com mais rotação.', 'adam-membership' ); ?></p>
					</div>
				</div>
				<div class="adam-admin-stats-grid">
					<?php
					$this->render_kpi_list_panel(
						__( 'Indicadores de recompensas', 'adam-membership' ),
						array(
							array( 'label' => __( 'Resgates no período', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['rewards']['total_redemptions_period'] ?? 0 ) ) ),
							array( 'label' => __( 'Pedidos pendentes', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['rewards']['pending_requests'] ?? 0 ) ) ),
							array( 'label' => __( 'Aprovadas no período', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['rewards']['approved_period'] ?? 0 ) ) ),
							array( 'label' => __( 'Pontos gastos em recompensas', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['rewards']['points_spent_period'] ?? 0 ) ) ),
						)
					);
					$this->render_bar_chart_panel(
						__( 'Recompensas mais resgatadas', 'adam-membership' ),
						(array) ( $report['rewards']['most_redeemed'] ?? array() ),
						__( 'Itens com mais aprovações ou entregas no período.', 'adam-membership' )
					);
					$this->render_bar_chart_panel(
						__( 'Resgates por categoria', 'adam-membership' ),
						(array) ( $report['rewards']['by_category'] ?? array() ),
						__( 'Categorias com maior utilização no período.', 'adam-membership' )
					);
					?>
				</div>
				<?php $this->render_latest_redemptions( (array) ( $report['rewards']['latest_redemptions'] ?? array() ) ); ?>
			</section>

			<section class="adam-admin-panel">
				<div class="adam-admin-dashboard-heading">
					<div>
						<h2><?php esc_html_e( 'Renovações', 'adam-membership' ); ?></h2>
						<p class="adam-admin-section-intro"><?php esc_html_e( 'Ritmo de renovação, pendências e risco de expiração.', 'adam-membership' ); ?></p>
					</div>
				</div>
				<div class="adam-admin-stats-grid">
					<?php
					$this->render_kpi_list_panel(
						__( 'Indicadores de renovação', 'adam-membership' ),
						array(
							array( 'label' => __( 'Renovações este mês', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['renewals']['this_month'] ?? 0 ) ) ),
							array( 'label' => __( 'Renovações este ano', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['renewals']['this_year'] ?? 0 ) ) ),
							array( 'label' => __( 'Sócios expirados', 'adam-membership' ), 'value' => number_format_i18n( (int) ( $report['renewals']['expired_members'] ?? 0 ) ) ),
							array( 'label' => __( 'Taxa de conclusão no período', 'adam-membership' ), 'value' => (string) ( $report['renewals']['completion_rate'] ?? 0 ) . '%' ),
						)
					);
					$this->render_bar_chart_panel(
						__( 'Renovações por mês', 'adam-membership' ),
						(array) ( $report['renewals']['monthly_rows'] ?? array() ),
						__( 'Pedidos de renovação submetidos no período selecionado.', 'adam-membership' )
					);
					?>
				</div>
			</section>

			<section class="adam-admin-panel">
				<div class="adam-admin-dashboard-heading">
					<div>
						<h2><?php esc_html_e( 'Atividade recente', 'adam-membership' ); ?></h2>
						<p class="adam-admin-section-intro"><?php esc_html_e( 'Últimos registos relevantes para acompanhamento operacional e relatórios.', 'adam-membership' ); ?></p>
					</div>
				</div>
				<div class="adam-admin-stats-recent-grid">
					<?php
					$this->render_recent_members_panel( (array) ( $report['recent']['latest_members'] ?? array() ) );
					$this->render_recent_renewals_panel( (array) ( $report['recent']['latest_renewals'] ?? array() ) );
					$this->render_recent_checkins_panel( (array) ( $report['recent']['latest_checkins'] ?? array() ) );
					$this->render_recent_points_panel( (array) ( $report['recent']['latest_points'] ?? array() ) );
					$this->render_recent_redemptions_panel( (array) ( $report['recent']['latest_redemptions'] ?? array() ) );
					$this->render_recent_announcements_panel( (array) ( $report['recent']['latest_announcements'] ?? array() ) );
					?>
				</div>
			</section>
		</div>
		<?php
	}

	public function handle_export_csv(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_export_statistics_csv' );

		$range  = $this->current_range_from_request( $_GET );
		$report = $this->statistics->build_report( $range );
		$rows   = $this->statistics->export_rows( $report );
		$handle = fopen( 'php://temp', 'w+' );

		if ( false === $handle ) {
			wp_die( esc_html__( 'Não foi possível gerar o ficheiro CSV.', 'adam-membership' ) );
		}

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ';' );
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		fclose( $handle );

		if ( false === $content ) {
			wp_die( esc_html__( 'Não foi possível gerar o ficheiro CSV.', 'adam-membership' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=adam-estatisticas-' . wp_date( 'Ymd-His' ) . '.csv' );

		echo "\xEF\xBB\xBF";
		echo $content;
		exit;
	}

	/**
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Range.
	 */
	private function render_filters( array $range ): void {
		?>
		<form method="get" class="adam-admin-filters adam-admin-stats-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Período', 'adam-membership' ); ?></span>
				<select name="range">
					<?php foreach ( $this->range_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $range['preset'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Data inicial', 'adam-membership' ); ?></span>
				<input type="date" name="date_from" value="<?php echo esc_attr( $range['date_from'] ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Data final', 'adam-membership' ); ?></span>
				<input type="date" name="date_to" value="<?php echo esc_attr( $range['date_to'] ); ?>">
			</label>
			<div class="adam-admin-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Atualizar estatísticas', 'adam-membership' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Limpar filtros', 'adam-membership' ); ?></a>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $cards Summary cards.
	 */
	private function render_summary_cards( array $cards ): void {
		?>
		<div class="adam-admin-cards adam-admin-stats-cards">
			<?php foreach ( $cards as $card ) : ?>
				<div class="adam-admin-card">
					<span><?php echo esc_html( (string) ( $card['label'] ?? '' ) ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $card['value'] ?? 0 ) ) ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Chart rows.
	 */
	private function render_bar_chart_panel( string $title, array $rows, string $description = '' ): void {
		?>
		<div class="adam-admin-stats-panel">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( '' !== $description ) : ?><p class="adam-admin-panel-copy"><?php echo esc_html( $description ); ?></p><?php endif; ?>
			<?php if ( array() === $rows ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem dados para este período.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<?php $max = max( array_map( static fn ( array $row ): int => (int) ( $row['value'] ?? 0 ), $rows ) ); ?>
				<div class="adam-admin-bar-chart">
					<?php foreach ( $rows as $row ) : ?>
						<?php $value = (int) ( $row['value'] ?? 0 ); ?>
						<div class="adam-admin-bar-chart__row">
							<div class="adam-admin-bar-chart__label"><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></div>
							<div class="adam-admin-bar-chart__track">
								<div class="adam-admin-bar-chart__fill" style="width: <?php echo esc_attr( (string) $this->percentage( $value, $max ) ); ?>%;"></div>
							</div>
							<div class="adam-admin-bar-chart__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Chart rows.
	 */
	private function render_line_chart_panel( string $title, array $rows, string $description = '' ): void {
		?>
		<div class="adam-admin-stats-panel">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( '' !== $description ) : ?><p class="adam-admin-panel-copy"><?php echo esc_html( $description ); ?></p><?php endif; ?>
			<?php if ( array() === $rows ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem dados para este período.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<div class="adam-admin-line-chart">
					<?php echo $this->line_chart_svg( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="adam-admin-line-chart__legend">
					<?php foreach ( $rows as $row ) : ?>
						<span><?php echo esc_html( (string) ( $row['label'] ?? '' ) . ': ' . number_format_i18n( (int) ( $row['value'] ?? 0 ) ) ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array{label:string,value:int,class?:string}> $rows Donut rows.
	 */
	private function render_donut_panel( string $title, array $rows, string $description = '' ): void {
		$total      = array_sum( array_map( static fn ( array $row ): int => (int) ( $row['value'] ?? 0 ), $rows ) );
		$segments   = array();
		$legend_map = array();
		$offset     = 0.0;
		$colors     = array( '#2f6b3b', '#f59e0b', '#ef4444', '#64748b', '#2563eb', '#8b5cf6' );

		foreach ( array_values( $rows ) as $index => $row ) {
			$value = (int) ( $row['value'] ?? 0 );

			if ( $total <= 0 || $value <= 0 ) {
				continue;
			}

			$size         = round( ( $value / $total ) * 100, 2 );
			$color        = $colors[ $index % count( $colors ) ];
			$segments[]   = $color . ' ' . $offset . '% ' . ( $offset + $size ) . '%';
			$legend_map[] = array(
				'label' => (string) ( $row['label'] ?? '' ),
				'value' => $value,
				'color' => $color,
			);
			$offset += $size;
		}
		?>
		<div class="adam-admin-stats-panel">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( '' !== $description ) : ?><p class="adam-admin-panel-copy"><?php echo esc_html( $description ); ?></p><?php endif; ?>
			<?php if ( 0 === $total ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem dados para este período.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<div class="adam-admin-donut">
					<div class="adam-admin-donut__chart" style="background: conic-gradient(<?php echo esc_attr( implode( ', ', $segments ) ); ?>);">
						<div class="adam-admin-donut__center">
							<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
							<span><?php esc_html_e( 'Total', 'adam-membership' ); ?></span>
						</div>
					</div>
					<div class="adam-admin-donut__legend">
						<?php foreach ( $legend_map as $item ) : ?>
							<div class="adam-admin-donut__legend-item">
								<span class="adam-admin-donut__swatch" style="background-color: <?php echo esc_attr( $item['color'] ); ?>;"></span>
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<small><?php echo esc_html( number_format_i18n( $item['value'] ) ); ?></small>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array{label:string,value:string}> $items KPI items.
	 */
	private function render_kpi_list_panel( string $title, array $items ): void {
		?>
		<div class="adam-admin-stats-panel">
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="adam-admin-stat-list">
				<?php foreach ( $items as $item ) : ?>
					<div class="adam-admin-stat-list__item">
						<span><?php echo esc_html( $item['label'] ); ?></span>
						<strong><?php echo esc_html( $item['value'] ); ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int, array{member:Member,balance:int}> $rows Balance rows.
	 */
	private function render_balance_panel( array $rows ): void {
		?>
		<div class="adam-admin-stats-panel">
			<h3><?php esc_html_e( 'Top de sócios por saldo', 'adam-membership' ); ?></h3>
			<?php if ( array() === $rows ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem pontos atribuídos até ao momento.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<div class="adam-admin-compact-list">
					<?php foreach ( $rows as $row ) : ?>
						<div class="adam-admin-compact-list__item">
							<div>
								<strong><?php echo esc_html( $row['member']->full_name() ); ?></strong>
								<small><?php echo esc_html( (string) $row['member']->field( 'numero_socio' ) ); ?></small>
							</div>
							<span><?php echo esc_html( $this->format_points( (int) $row['balance'] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, Member> $members Expiring members.
	 */
	private function render_upcoming_expirations( array $members ): void {
		?>
		<div class="adam-admin-stats-table-panel">
			<h3><?php esc_html_e( 'Sócios a expirar nos próximos 30 dias', 'adam-membership' ); ?></h3>
			<?php if ( array() === $members ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Não existem quotas a expirar dentro da janela definida.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Validade da quota', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $members as $member ) : ?>
							<tr>
								<td><?php echo esc_html( $member->full_name() ); ?></td>
								<td><?php echo esc_html( (string) $member->field( 'numero_socio' ) ); ?></td>
								<td><?php echo esc_html( $this->format_date( (string) $member->field( 'validade_quota' ) ) ); ?></td>
								<td><?php $this->render_badge( $member->effective_status(), 'status-' . sanitize_title( $member->effective_status() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, EventCheckIn> $checkins Check-ins.
	 */
	private function render_latest_checkins( array $checkins ): void {
		?>
		<div class="adam-admin-stats-table-panel">
			<h3><?php esc_html_e( 'Últimos check-ins', 'adam-membership' ); ?></h3>
			<?php if ( array() === $checkins ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem check-ins dentro deste período.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Evento', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $checkins as $checkin ) : ?>
							<?php $event = $this->find_event( $checkin->event_id() ); ?>
							<?php $member = Member::load( $checkin->member_id() ); ?>
							<tr>
								<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></td>
								<td><?php echo esc_html( null !== $event ? $event->title() : sprintf( __( 'Evento #%d', 'adam-membership' ), $checkin->event_id() ) ); ?></td>
								<td><?php echo esc_html( $this->format_datetime( $checkin->checked_in_at() ) ); ?></td>
								<td><?php echo esc_html( '+' . $checkin->points_awarded() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, PointsEntry> $entries Point entries.
	 */
	private function render_points_activity( array $entries ): void {
		?>
		<div class="adam-admin-stats-table-panel">
			<h3><?php esc_html_e( 'Atividade recente de pontos', 'adam-membership' ); ?></h3>
			<?php if ( array() === $entries ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem atividade de pontos para este período.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Origem', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Motivo', 'adam-membership' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $member = Member::load( $entry->member_id() ); ?>
							<tr>
								<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></td>
								<td><?php echo esc_html( $this->format_datetime( $entry->created_at() ) ); ?></td>
								<td><?php echo esc_html( $this->source_label( $entry->source_type() ) ); ?></td>
								<td><?php echo esc_html( $this->format_points( $entry->points() ) ); ?></td>
								<td><?php echo esc_html( $entry->reason() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, RewardRedemption> $redemptions Reward redemptions.
	 */
	private function render_latest_redemptions( array $redemptions ): void {
		?>
		<div class="adam-admin-stats-table-panel">
			<h3><?php esc_html_e( 'Últimos resgates de recompensas', 'adam-membership' ); ?></h3>
			<?php if ( array() === $redemptions ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem resgates de recompensas no período selecionado.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Recompensa', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $redemptions as $redemption ) : ?>
							<?php $member = Member::load( $redemption->member_id() ); ?>
							<tr>
								<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></td>
								<td><?php echo esc_html( $redemption->reward_name() ); ?></td>
								<td><?php $this->render_badge( $this->redemption_status_label( $redemption->status() ), 'reward-status-' . $redemption->status() ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $redemption->points_cost() ) ); ?></td>
								<td><?php echo esc_html( $this->format_datetime( $redemption->created_at() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_recent_members_panel( array $members ): void {
		$this->render_recent_panel(
			__( 'Últimos sócios registados', 'adam-membership' ),
			$members,
			function ( Member $member ): string {
				return $member->full_name();
			},
			function ( Member $member ): string {
				return sprintf( '%s · %s', (string) $member->field( 'numero_socio' ), $this->format_date( wp_date( 'Y-m-d', $member->registration_timestamp() ) ) );
			}
		);
	}

	private function render_recent_renewals_panel( array $renewals ): void {
		$this->render_recent_panel(
			__( 'Últimas renovações', 'adam-membership' ),
			$renewals,
			function ( RenewalRequest $request ): string {
				$member = Member::load( $request->user_id() );
				return null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' );
			},
			fn ( RenewalRequest $request ): string => $this->renewal_status_label( $request->status() ) . ' · ' . $this->format_datetime( $request->submitted_at() )
		);
	}

	private function render_recent_checkins_panel( array $checkins ): void {
		$this->render_recent_panel(
			__( 'Últimos check-ins', 'adam-membership' ),
			$checkins,
			function ( EventCheckIn $checkin ): string {
				$event = $this->find_event( $checkin->event_id() );
				return null !== $event ? $event->title() : sprintf( __( 'Evento #%d', 'adam-membership' ), $checkin->event_id() );
			},
			function ( EventCheckIn $checkin ): string {
				$member = Member::load( $checkin->member_id() );
				$name   = null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' );
				return $name . ' · ' . $this->format_datetime( $checkin->checked_in_at() );
			}
		);
	}

	private function render_recent_points_panel( array $entries ): void {
		$this->render_recent_panel(
			__( 'Últimos movimentos de pontos', 'adam-membership' ),
			$entries,
			fn ( PointsEntry $entry ): string => $this->source_label( $entry->source_type() ) . ' · ' . $this->format_points( $entry->points() ),
			function ( PointsEntry $entry ): string {
				$member = Member::load( $entry->member_id() );
				$name   = null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' );
				return $name . ' · ' . $this->format_datetime( $entry->created_at() );
			}
		);
	}

	private function render_recent_redemptions_panel( array $redemptions ): void {
		$this->render_recent_panel(
			__( 'Últimos resgates', 'adam-membership' ),
			$redemptions,
			fn ( RewardRedemption $redemption ): string => $redemption->reward_name(),
			function ( RewardRedemption $redemption ): string {
				$member = Member::load( $redemption->member_id() );
				$name   = null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' );
				return $name . ' · ' . $this->redemption_status_label( $redemption->status() );
			}
		);
	}

	private function render_recent_announcements_panel( array $announcements ): void {
		$this->render_recent_panel(
			__( 'Últimos avisos', 'adam-membership' ),
			$announcements,
			fn ( Announcement $announcement ): string => $announcement->title(),
			fn ( Announcement $announcement ): string => $announcement->category() . ' · ' . $this->format_date( '' !== $announcement->publish_date() ? $announcement->publish_date() : substr( $announcement->created_at(), 0, 10 ) )
		);
	}

	/**
	 * @param array<int, mixed>                 $items Items.
	 * @param callable(mixed):string $title_renderer Title callback.
	 * @param callable(mixed):string $meta_renderer Meta callback.
	 */
	private function render_recent_panel( string $title, array $items, callable $title_renderer, callable $meta_renderer ): void {
		?>
		<div class="adam-admin-stats-panel adam-admin-stats-panel--compact">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( array() === $items ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Sem dados para apresentar.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<div class="adam-admin-compact-list">
					<?php foreach ( $items as $item ) : ?>
						<div class="adam-admin-compact-list__item">
							<div>
								<strong><?php echo esc_html( $title_renderer( $item ) ); ?></strong>
								<small><?php echo esc_html( $meta_renderer( $item ) ); ?></small>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function line_chart_svg( array $rows ): string {
		$count = count( $rows );

		if ( $count <= 0 ) {
			return '';
		}

		$width  = 520;
		$height = 200;
		$max    = max( 1, max( array_map( static fn ( array $row ): int => (int) ( $row['value'] ?? 0 ), $rows ) ) );
		$points = array();

		foreach ( array_values( $rows ) as $index => $row ) {
			$x = 18 + ( $index * ( ( $width - 36 ) / max( 1, $count - 1 ) ) );
			$y = $height - 24 - ( ( (int) ( $row['value'] ?? 0 ) / $max ) * ( $height - 52 ) );
			$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}

		$polyline = implode( ' ', $points );

		return sprintf(
			'<svg viewBox="0 0 %1$d %2$d" role="img" aria-hidden="true"><defs><linearGradient id="adamStatsStroke" x1="0" y1="0" x2="1" y2="1"><stop offset="0%%" stop-color="#2f6b3b"></stop><stop offset="100%%" stop-color="#7cc08a"></stop></linearGradient></defs><rect x="0" y="0" width="%1$d" height="%2$d" rx="18" fill="#f8fbf9"></rect><line x1="18" y1="%3$d" x2="%4$d" y2="%3$d" stroke="#d9e4dc" stroke-width="1"></line><polyline fill="none" stroke="url(#adamStatsStroke)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points="%5$s"></polyline></svg>',
			$width,
			$height,
			$height - 24,
			$width - 18,
			esc_attr( $polyline )
		);
	}

	/**
	 * @param array<string, int> $counts Status counts.
	 * @return array<int, array{label:string,value:int}>
	 */
	private function status_rows( array $counts ): array {
		$rows = array();

		foreach ( $counts as $label => $value ) {
			$rows[] = array(
				'label' => (string) $label,
				'value' => (int) $value,
			);
		}

		usort(
			$rows,
			static fn ( array $left, array $right ): int => $right['value'] <=> $left['value']
		);

		return $rows;
	}

	private function percentage( int $value, int $max ): int {
		if ( $max <= 0 || $value <= 0 ) {
			return 0;
		}

		return max( 4, (int) round( ( $value / $max ) * 100 ) );
	}

	private function find_event( int $event_id ): ?Event {
		return $this->events->repository()->find_event( $event_id );
	}

	private function source_label( string $source_type ): string {
		return $this->points->source_label( $source_type );
	}

	private function ensure_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissão para consultar as estatísticas da ADAM.', 'adam-membership' ) );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function range_options(): array {
		return array(
			'30_days'      => __( 'Últimos 30 dias', 'adam-membership' ),
			'90_days'      => __( 'Últimos 90 dias', 'adam-membership' ),
			'this_year'    => __( 'Este ano', 'adam-membership' ),
			'previous_year' => __( 'Ano anterior', 'adam-membership' ),
			'custom'       => __( 'Intervalo personalizado', 'adam-membership' ),
		);
	}

	/**
	 * @return array{preset:string,date_from:string,date_to:string,label:string}
	 */
	private function current_range(): array {
		return $this->current_range_from_request( $_GET );
	}

	/**
	 * @param array<string, mixed> $request Request data.
	 * @return array{preset:string,date_from:string,date_to:string,label:string}
	 */
	private function current_range_from_request( array $request ): array {
		$preset = isset( $request['range'] ) ? sanitize_key( (string) $request['range'] ) : '30_days';
		$today  = current_time( 'timestamp' );

		switch ( $preset ) {
			case '90_days':
				$from  = wp_date( 'Y-m-d', strtotime( '-89 days', $today ) ?: $today );
				$to    = wp_date( 'Y-m-d', $today );
				$label = __( 'últimos 90 dias', 'adam-membership' );
				break;
			case 'this_year':
				$from  = wp_date( 'Y-01-01', $today );
				$to    = wp_date( 'Y-12-31', $today );
				$label = __( 'este ano', 'adam-membership' );
				break;
			case 'previous_year':
				$year  = (int) wp_date( 'Y', $today ) - 1;
				$from  = sprintf( '%d-01-01', $year );
				$to    = sprintf( '%d-12-31', $year );
				$label = __( 'ano anterior', 'adam-membership' );
				break;
			case 'custom':
				$from = isset( $request['date_from'] ) ? sanitize_text_field( (string) $request['date_from'] ) : '';
				$to   = isset( $request['date_to'] ) ? sanitize_text_field( (string) $request['date_to'] ) : '';

				if ( ! $this->is_valid_date( $from ) || ! $this->is_valid_date( $to ) || $from > $to ) {
					$from = wp_date( 'Y-m-d', strtotime( '-29 days', $today ) ?: $today );
					$to   = wp_date( 'Y-m-d', $today );
				}

				$label = sprintf( __( '%1$s a %2$s', 'adam-membership' ), $from, $to );
				break;
			case '30_days':
			default:
				$preset = '30_days';
				$from   = wp_date( 'Y-m-d', strtotime( '-29 days', $today ) ?: $today );
				$to     = wp_date( 'Y-m-d', $today );
				$label  = __( 'últimos 30 dias', 'adam-membership' );
				break;
		}

		return array(
			'preset'    => $preset,
			'date_from' => $from,
			'date_to'   => $to,
			'label'     => $label,
		);
	}

	/**
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Range.
	 */
	private function export_url( array $range ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'adam_membership_export_statistics_csv',
					'range'     => $range['preset'],
					'date_from' => $range['date_from'],
					'date_to'   => $range['date_to'],
				),
				admin_url( 'admin-post.php' )
			),
			'adam_membership_export_statistics_csv'
		);
	}

	private function is_valid_date( string $date ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}

	private function format_date( string $date ): string {
		$timestamp = strtotime( $date );

		return false === $timestamp ? $date : wp_date( get_option( 'date_format' ), $timestamp );
	}

	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? $datetime : wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
	}

	private function format_points( int $points ): string {
		return $points > 0 ? '+' . number_format_i18n( $points ) : (string) $points;
	}

	private function render_badge( string $label, string $class ): void {
		printf(
			'<span class="adam-admin-badge %1$s">%2$s</span>',
			esc_attr( sanitize_html_class( $class ) ),
			esc_html( $label )
		);
	}

	private function renewal_status_label( string $status ): string {
		return match ( $status ) {
			RenewalRequest::STATUS_APPROVED => __( 'Aprovada', 'adam-membership' ),
			RenewalRequest::STATUS_REJECTED => __( 'Rejeitada', 'adam-membership' ),
			default                         => __( 'Pendente', 'adam-membership' ),
		};
	}

	private function redemption_status_label( string $status ): string {
		return match ( $status ) {
			RewardRedemption::STATUS_APPROVED  => __( 'Aprovada', 'adam-membership' ),
			RewardRedemption::STATUS_DELIVERED => __( 'Entregue', 'adam-membership' ),
			RewardRedemption::STATUS_REJECTED  => __( 'Rejeitada', 'adam-membership' ),
			default                            => __( 'Pendente', 'adam-membership' ),
		};
	}
}
