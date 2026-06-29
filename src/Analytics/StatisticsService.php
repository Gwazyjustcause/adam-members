<?php
/**
 * Statistics and analytics service.
 *
 * @package AdamMembership\Analytics
 */

declare(strict_types=1);

namespace AdamMembership\Analytics;

use AdamMembership\Announcement\Announcement;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Event\Event;
use AdamMembership\Event\EventCheckIn;
use AdamMembership\Event\EventService;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Points\PointsEntry;
use AdamMembership\Points\PointsService;
use AdamMembership\Reward\Reward;
use AdamMembership\Reward\RewardRedemption;
use AdamMembership\Reward\RewardService;

/**
 * Builds analytics datasets from existing plugin services.
 */
final class StatisticsService {
	private MemberRepository $members;
	private RenewalRepository $renewals;
	private AnnouncementService $announcements;
	private EventService $events;
	private PointsService $points;
	private RewardService $rewards;

	public function __construct(
		MemberRepository $members,
		RenewalRepository $renewals,
		AnnouncementService $announcements,
		EventService $events,
		PointsService $points,
		RewardService $rewards
	) {
		$this->members       = $members;
		$this->renewals      = $renewals;
		$this->announcements = $announcements;
		$this->events        = $events;
		$this->points        = $points;
		$this->rewards       = $rewards;
	}

	/**
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Selected date range.
	 * @return array<string, mixed>
	 */
	public function build_report( array $range ): array {
		$members      = $this->members->all_members();
		$renewals     = $this->renewals->admin_requests();
		$events       = $this->events->admin_events();
		$checkins     = $this->events->repository()->query_checkins();
		$points       = $this->points->repository()->query();
		$rewards      = $this->rewards->admin_rewards();
		$redemptions  = $this->rewards->admin_redemptions();
		$announcements = $this->announcements->admin_list();

		$members_in_range     = array_values( array_filter( $members, fn ( Member $member ): bool => $this->timestamp_in_range( $member->registration_timestamp(), $range ) ) );
		$renewals_in_range    = array_values( array_filter( $renewals, fn ( RenewalRequest $request ): bool => $this->datetime_in_range( $request->submitted_at(), $range ) ) );
		$checkins_in_range    = array_values( array_filter( $checkins, fn ( EventCheckIn $checkin ): bool => $this->datetime_in_range( $checkin->checked_in_at(), $range ) ) );
		$points_in_range      = array_values( array_filter( $points, fn ( PointsEntry $entry ): bool => $this->datetime_in_range( $entry->created_at(), $range ) ) );
		$redemptions_in_range = array_values( array_filter( $redemptions, fn ( RewardRedemption $redemption ): bool => $this->datetime_in_range( $redemption->created_at(), $range ) ) );
		$announcements_in_range = array_values( array_filter( $announcements, fn ( Announcement $announcement ): bool => $this->datetime_in_range( $announcement->created_at(), $range ) || $this->date_in_range( $announcement->publish_date(), $range ) ) );

		$status_counts          = $this->member_status_counts( $members );
		$expiring_members       = $this->expiring_members( $members, 30 );
		$founders               = array_values( array_filter( $members, static fn ( Member $member ): bool => $member->is_founder() ) );
		$renewals_approved      = array_values( array_filter( $renewals, static fn ( RenewalRequest $request ): bool => RenewalRequest::STATUS_APPROVED === $request->status() ) );
		$renewals_pending       = array_values( array_filter( $renewals, static fn ( RenewalRequest $request ): bool => RenewalRequest::STATUS_PENDING === $request->status() ) );
		$events_upcoming        = array_values( array_filter( $events, fn ( Event $event ): bool => $event->starts_at_timestamp() >= current_time( 'timestamp' ) && Event::STATUS_PUBLISHED === $event->status() ) );
		$events_completed       = array_values( array_filter( $events, static fn ( Event $event ): bool => Event::STATUS_COMPLETED === $event->status() ) );
		$approved_redemptions   = array_values( array_filter( $redemptions, static fn ( RewardRedemption $redemption ): bool => in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) );
		$points_totals          = $this->points_totals( $points_in_range );
		$balances               = $this->member_balances( $points, $members );
		$current_points_held    = $this->current_points_held( $balances );
		$reward_lookup          = $this->reward_lookup( $rewards );
		$checkins_by_event      = $this->event_counts_from_checkins( $checkins_in_range, $events );
		$points_by_event        = $this->points_by_event( $points_in_range, $events );
		$rewards_by_category    = $this->rewards_by_category( $approved_redemptions, $reward_lookup, $range );
		$points_by_source       = $this->points_by_source( $points_in_range );
		$growth_rows            = $this->monthly_member_growth( $members_in_range );
		$new_members_rows       = $this->monthly_member_growth( $members_in_range, false );
		$renewal_rows           = $this->monthly_renewals( $renewals_in_range );
		$latest_announcements   = $this->latest_announcements( $announcements_in_range );

		return array(
			'range' => $range,
			'summary_cards' => array(
				array( 'label' => __( 'Total de sócios', 'adam-membership' ), 'value' => count( $members ) ),
				array( 'label' => __( 'Sócios ativos', 'adam-membership' ), 'value' => $status_counts[ Member::STATUS_ACTIVE ] ?? 0 ),
				array( 'label' => __( 'Sócios pendentes', 'adam-membership' ), 'value' => $status_counts[ Member::STATUS_PENDING ] ?? 0 ),
				array( 'label' => __( 'Sócios expirados', 'adam-membership' ), 'value' => $status_counts[ Member::STATUS_EXPIRED ] ?? 0 ),
				array( 'label' => __( 'Sócios rejeitados', 'adam-membership' ), 'value' => $status_counts[ Member::STATUS_REJECTED ] ?? 0 ),
				array( 'label' => __( 'Renovações pendentes', 'adam-membership' ), 'value' => count( $renewals_pending ) ),
				array( 'label' => sprintf( __( 'Novos sócios (%s)', 'adam-membership' ), $range['label'] ), 'value' => count( $members_in_range ) ),
				array( 'label' => sprintf( __( 'Renovações (%s)', 'adam-membership' ), $range['label'] ), 'value' => count( $renewals_in_range ) ),
				array( 'label' => __( 'Expiram em 30 dias', 'adam-membership' ), 'value' => count( $expiring_members ) ),
				array( 'label' => __( 'Membros fundadores', 'adam-membership' ), 'value' => count( $founders ) ),
				array( 'label' => __( 'Total de eventos', 'adam-membership' ), 'value' => count( $events ) ),
				array( 'label' => __( 'Eventos próximos', 'adam-membership' ), 'value' => count( $events_upcoming ) ),
				array( 'label' => __( 'Eventos concluídos', 'adam-membership' ), 'value' => count( $events_completed ) ),
				array( 'label' => __( 'Total de check-ins', 'adam-membership' ), 'value' => count( $checkins ) ),
				array( 'label' => __( 'Pontos atribuídos', 'adam-membership' ), 'value' => $this->total_positive_points( $points ) ),
				array( 'label' => __( 'Recompensas resgatadas', 'adam-membership' ), 'value' => count( $approved_redemptions ) ),
			),
			'membership' => array(
				'status_counts'        => $status_counts,
				'growth_rows'          => $growth_rows,
				'new_members_rows'     => $new_members_rows,
				'renewal_rate'         => $this->completion_rate( count( $renewals_approved ), count( $renewals ) ),
				'renewal_rate_period'  => $this->completion_rate(
					count( array_filter( $renewals_in_range, static fn ( RenewalRequest $request ): bool => RenewalRequest::STATUS_APPROVED === $request->status() ) ),
					count( $renewals_in_range )
				),
				'upcoming_expirations' => array_slice( $expiring_members, 0, 8 ),
				'founders_count'       => count( $founders ),
			),
			'events' => array(
				'events_created_period'   => count( array_filter( $events, fn ( Event $event ): bool => $this->datetime_in_range( $event->created_at(), $range ) ) ),
				'upcoming_events'         => count( $events_upcoming ),
				'completed_events'        => count( $events_completed ),
				'checkins_period'         => count( $checkins_in_range ),
				'average_checkins'        => $this->average_per_group( count( $checkins_in_range ), count( $checkins_by_event ) ),
				'most_attended_events'    => array_slice( $checkins_by_event, 0, 6 ),
				'points_by_event'         => array_slice( $points_by_event, 0, 6 ),
				'latest_checkins'         => array_slice( $checkins_in_range, 0, 8 ),
			),
			'points' => array(
				'total_awarded_period' => $points_totals['awarded'],
				'total_spent_period'   => $points_totals['spent'],
				'current_points_held'  => $current_points_held,
				'top_members'          => array_slice( $balances, 0, 8 ),
				'recent_activity'      => array_slice( $points_in_range, 0, 8 ),
				'by_source'            => $points_by_source,
			),
			'rewards' => array(
				'total_redemptions_period' => count( $redemptions_in_range ),
				'pending_requests'         => count( array_filter( $redemptions, static fn ( RewardRedemption $redemption ): bool => RewardRedemption::STATUS_PENDING === $redemption->status() ) ),
				'approved_period'          => count( array_filter( $redemptions_in_range, static fn ( RewardRedemption $redemption ): bool => in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) ),
				'rejected_period'          => count( array_filter( $redemptions_in_range, static fn ( RewardRedemption $redemption ): bool => RewardRedemption::STATUS_REJECTED === $redemption->status() ) ),
				'most_redeemed'            => array_slice( $this->most_redeemed_rewards( $approved_redemptions, $range ), 0, 6 ),
				'by_category'              => $rewards_by_category,
				'points_spent_period'      => $this->points_spent_on_rewards( $approved_redemptions, $range ),
				'latest_redemptions'       => array_slice( $redemptions_in_range, 0, 8 ),
			),
			'renewals' => array(
				'this_month'      => count( array_filter( $renewals, fn ( RenewalRequest $request ): bool => $this->datetime_in_range( $request->submitted_at(), $this->fixed_range( 'month' ) ) ) ),
				'this_year'       => count( array_filter( $renewals, fn ( RenewalRequest $request ): bool => $this->datetime_in_range( $request->submitted_at(), $this->fixed_range( 'year' ) ) ) ),
				'expired_members' => $status_counts[ Member::STATUS_EXPIRED ] ?? 0,
				'expiring_soon'   => count( $expiring_members ),
				'completion_rate' => $this->completion_rate(
					count( array_filter( $renewals_in_range, static fn ( RenewalRequest $request ): bool => RenewalRequest::STATUS_APPROVED === $request->status() ) ),
					count( $renewals_in_range )
				),
				'pending_count'   => count( $renewals_pending ),
				'monthly_rows'    => $renewal_rows,
			),
			'recent' => array(
				'latest_members'        => array_slice( $members_in_range, 0, 6 ),
				'latest_renewals'       => array_slice( $renewals_in_range, 0, 6 ),
				'latest_checkins'       => array_slice( $checkins_in_range, 0, 6 ),
				'latest_points'         => array_slice( $points_in_range, 0, 6 ),
				'latest_redemptions'    => array_slice( $redemptions_in_range, 0, 6 ),
				'latest_announcements'  => array_slice( $latest_announcements, 0, 6 ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $report Report payload.
	 * @return array<int, array<int, string>>
	 */
	public function export_rows( array $report ): array {
		$rows   = array();
		$rows[] = array( 'Secção', 'Métrica', 'Valor' );

		foreach ( (array) ( $report['summary_cards'] ?? array() ) as $card ) {
			$rows[] = array( 'Resumo', (string) ( $card['label'] ?? '' ), (string) ( $card['value'] ?? '' ) );
		}

		$membership = (array) ( $report['membership'] ?? array() );
		foreach ( (array) ( $membership['status_counts'] ?? array() ) as $label => $value ) {
			$rows[] = array( 'Sócios', (string) $label, (string) $value );
		}

		$events = (array) ( $report['events'] ?? array() );
		$rows[] = array( 'Eventos', 'Check-ins no período', (string) ( $events['checkins_period'] ?? 0 ) );
		$rows[] = array( 'Eventos', 'Média de check-ins por evento', (string) ( $events['average_checkins'] ?? 0 ) );

		$points = (array) ( $report['points'] ?? array() );
		$rows[] = array( 'Pontos', 'Pontos atribuídos no período', (string) ( $points['total_awarded_period'] ?? 0 ) );
		$rows[] = array( 'Pontos', 'Pontos gastos no período', (string) ( $points['total_spent_period'] ?? 0 ) );
		$rows[] = array( 'Pontos', 'Pontos atualmente detidos', (string) ( $points['current_points_held'] ?? 0 ) );

		$rewards = (array) ( $report['rewards'] ?? array() );
		$rows[]  = array( 'Recompensas', 'Resgates no período', (string) ( $rewards['total_redemptions_period'] ?? 0 ) );
		$rows[]  = array( 'Recompensas', 'Pontos gastos em recompensas', (string) ( $rewards['points_spent_period'] ?? 0 ) );

		$renewals = (array) ( $report['renewals'] ?? array() );
		$rows[]   = array( 'Renovações', 'Renovações este mês', (string) ( $renewals['this_month'] ?? 0 ) );
		$rows[]   = array( 'Renovações', 'Renovações este ano', (string) ( $renewals['this_year'] ?? 0 ) );
		$rows[]   = array( 'Renovações', 'Taxa de conclusão no período', (string) ( $renewals['completion_rate'] ?? 0 ) . '%' );

		return $rows;
	}

	/**
	 * @param array<int, Member> $members Members.
	 * @return array<string, int>
	 */
	private function member_status_counts( array $members ): array {
		$counts = array();

		foreach ( $members as $member ) {
			$status = $member->effective_status();

			if ( ! isset( $counts[ $status ] ) ) {
				$counts[ $status ] = 0;
			}

			++$counts[ $status ];
		}

		return $counts;
	}

	/**
	 * @param array<int, Member> $members Members.
	 * @return array<int, Member>
	 */
	private function expiring_members( array $members, int $days ): array {
		$today   = strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );
		$cutoff  = strtotime( '+' . $days . ' days', false === $today ? current_time( 'timestamp' ) : $today );
		$results = array();

		foreach ( $members as $member ) {
			$expiry = $member->quota_expiry_timestamp();

			if ( 0 === $expiry || false === $today || false === $cutoff ) {
				continue;
			}

			if ( $expiry >= $today && $expiry <= $cutoff ) {
				$results[] = $member;
			}
		}

		usort(
			$results,
			static fn ( Member $left, Member $right ): int => $left->quota_expiry_timestamp() <=> $right->quota_expiry_timestamp()
		);

		return $results;
	}

	/**
	 * @param array<int, Member> $members Members.
	 * @return array<int, array{label:string,value:int}>
	 */
	private function monthly_member_growth( array $members, bool $cumulative = true ): array {
		$months = array();

		foreach ( $members as $member ) {
			$timestamp = $member->registration_timestamp();

			if ( 0 === $timestamp ) {
				continue;
			}

			$key = wp_date( 'Y-m', $timestamp );

			if ( ! isset( $months[ $key ] ) ) {
				$months[ $key ] = 0;
			}

			++$months[ $key ];
		}

		ksort( $months );

		$total = 0;
		$rows  = array();

		foreach ( $months as $key => $value ) {
			$total += $value;
			$rows[] = array(
				'label' => $this->month_label( $key ),
				'value' => $cumulative ? $total : $value,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, RenewalRequest> $renewals Renewals.
	 * @return array<int, array{label:string,value:int}>
	 */
	private function monthly_renewals( array $renewals ): array {
		$months = array();

		foreach ( $renewals as $request ) {
			$timestamp = strtotime( $request->submitted_at() );

			if ( false === $timestamp ) {
				continue;
			}

			$key = wp_date( 'Y-m', $timestamp );

			if ( ! isset( $months[ $key ] ) ) {
				$months[ $key ] = 0;
			}

			++$months[ $key ];
		}

		ksort( $months );

		$rows = array();

		foreach ( $months as $key => $value ) {
			$rows[] = array(
				'label' => $this->month_label( $key ),
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, PointsEntry> $entries Entries.
	 * @return array{awarded:int,spent:int}
	 */
	private function points_totals( array $entries ): array {
		$awarded = 0;
		$spent   = 0;

		foreach ( $entries as $entry ) {
			if ( $entry->points() > 0 ) {
				$awarded += $entry->points();
			} elseif ( $entry->points() < 0 ) {
				$spent += abs( $entry->points() );
			}
		}

		return array(
			'awarded' => $awarded,
			'spent'   => $spent,
		);
	}

	/**
	 * @param array<int, array{member:Member,balance:int}> $balances Balance rows.
	 */
	private function current_points_held( array $balances ): int {
		$total = 0;

		foreach ( $balances as $row ) {
			if ( (int) $row['balance'] > 0 ) {
				$total += (int) $row['balance'];
			}
		}

		return $total;
	}

	/**
	 * @param array<int, PointsEntry> $entries Entries.
	 * @param array<int, Member>      $members Members.
	 * @return array<int, array{member:Member,balance:int}>
	 */
	private function member_balances( array $entries, array $members ): array {
		$member_index = array();
		$balances     = array();

		foreach ( $members as $member ) {
			$member_index[ $member->user_id() ] = $member;
		}

		foreach ( $entries as $entry ) {
			if ( ! isset( $balances[ $entry->member_id() ] ) ) {
				$balances[ $entry->member_id() ] = 0;
			}

			$balances[ $entry->member_id() ] += $entry->points();
		}

		arsort( $balances );

		$rows = array();

		foreach ( $balances as $member_id => $balance ) {
			if ( ! isset( $member_index[ $member_id ] ) ) {
				continue;
			}

			$rows[] = array(
				'member'  => $member_index[ $member_id ],
				'balance' => (int) $balance,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, Reward> $rewards Rewards.
	 * @return array<int, Reward>
	 */
	private function reward_lookup( array $rewards ): array {
		$lookup = array();

		foreach ( $rewards as $reward ) {
			$lookup[ $reward->id() ] = $reward;
		}

		return $lookup;
	}

	/**
	 * @param array<int, EventCheckIn> $checkins Check-ins.
	 * @param array<int, Event>        $events Events.
	 * @return array<int, array{label:string,value:int,event:?Event}>
	 */
	private function event_counts_from_checkins( array $checkins, array $events ): array {
		$event_lookup = array();
		$counts       = array();

		foreach ( $events as $event ) {
			$event_lookup[ $event->id() ] = $event;
		}

		foreach ( $checkins as $checkin ) {
			if ( ! isset( $counts[ $checkin->event_id() ] ) ) {
				$counts[ $checkin->event_id() ] = 0;
			}

			++$counts[ $checkin->event_id() ];
		}

		arsort( $counts );

		$rows = array();

		foreach ( $counts as $event_id => $count ) {
			$event = $event_lookup[ $event_id ] ?? null;
			$rows[] = array(
				'label' => null !== $event ? $event->title() : sprintf( __( 'Evento #%d', 'adam-membership' ), $event_id ),
				'value' => $count,
				'event' => $event,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, PointsEntry> $entries Entries.
	 * @param array<int, Event>       $events Events.
	 * @return array<int, array{label:string,value:int,event:?Event}>
	 */
	private function points_by_event( array $entries, array $events ): array {
		$event_lookup = array();
		$totals       = array();

		foreach ( $events as $event ) {
			$event_lookup[ $event->id() ] = $event;
		}

		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry->source_type(), array( PointsService::SOURCE_EVENT_CHECK_IN, PointsService::SOURCE_EVENT_CHECKIN_BONUS ), true ) ) {
				continue;
			}

			if ( ! isset( $totals[ $entry->source_id() ] ) ) {
				$totals[ $entry->source_id() ] = 0;
			}

			$totals[ $entry->source_id() ] += max( 0, $entry->points() );
		}

		arsort( $totals );

		$rows = array();

		foreach ( $totals as $event_id => $value ) {
			$event = $event_lookup[ $event_id ] ?? null;
			$rows[] = array(
				'label' => null !== $event ? $event->title() : sprintf( __( 'Evento #%d', 'adam-membership' ), $event_id ),
				'value' => $value,
				'event' => $event,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, RewardRedemption> $redemptions Redemptions.
	 * @param array<int, Reward>           $reward_lookup Reward lookup.
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Selected range.
	 * @return array<int, array{label:string,value:int}>
	 */
	private function rewards_by_category( array $redemptions, array $reward_lookup, array $range ): array {
		$totals = array();

		foreach ( $redemptions as $redemption ) {
			if ( ! in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) {
				continue;
			}

			if ( ! $this->datetime_in_range( $redemption->created_at(), $range ) ) {
				continue;
			}

			$reward   = $reward_lookup[ $redemption->reward_id() ] ?? null;
			$category = null !== $reward ? $reward->category() : __( 'Sem categoria', 'adam-membership' );
			$category = '' !== trim( $category ) ? $category : __( 'Sem categoria', 'adam-membership' );

			if ( ! isset( $totals[ $category ] ) ) {
				$totals[ $category ] = 0;
			}

			++$totals[ $category ];
		}

		arsort( $totals );

		$rows = array();

		foreach ( $totals as $label => $value ) {
			$rows[] = array(
				'label' => (string) $label,
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, RewardRedemption> $redemptions Redemptions.
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Selected range.
	 */
	private function points_spent_on_rewards( array $redemptions, array $range ): int {
		$total = 0;

		foreach ( $redemptions as $redemption ) {
			if ( ! in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) {
				continue;
			}

			if ( ! $this->datetime_in_range( $redemption->created_at(), $range ) ) {
				continue;
			}

			$total += $redemption->points_cost();
		}

		return $total;
	}

	/**
	 * @param array<int, RewardRedemption> $redemptions Redemptions.
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Selected range.
	 * @return array<int, array{label:string,value:int}>
	 */
	private function most_redeemed_rewards( array $redemptions, array $range ): array {
		$counts = array();

		foreach ( $redemptions as $redemption ) {
			if ( ! in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) {
				continue;
			}

			if ( ! $this->datetime_in_range( $redemption->created_at(), $range ) ) {
				continue;
			}

			$name = $redemption->reward_name();

			if ( ! isset( $counts[ $name ] ) ) {
				$counts[ $name ] = 0;
			}

			++$counts[ $name ];
		}

		arsort( $counts );

		$rows = array();

		foreach ( $counts as $label => $value ) {
			$rows[] = array(
				'label' => (string) $label,
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, PointsEntry> $entries Entries.
	 * @return array<int, array{label:string,value:int}>
	 */
	private function points_by_source( array $entries ): array {
		$totals = array(
			PointsService::SOURCE_EVENT_CHECK_IN    => 0,
			PointsService::SOURCE_EVENT_CHECKIN_BONUS => 0,
			PointsService::SOURCE_ADMIN_ADJUSTMENT  => 0,
			PointsService::SOURCE_REWARD_REDEMPTION => 0,
			PointsService::SOURCE_BONUS             => 0,
		);

		foreach ( $entries as $entry ) {
			$key = $entry->source_type();

			if ( ! isset( $totals[ $key ] ) ) {
				$totals[ $key ] = 0;
			}

			$totals[ $key ] += PointsService::SOURCE_REWARD_REDEMPTION === $key ? abs( $entry->points() ) : max( 0, $entry->points() );
		}

		$rows = array();

		foreach ( $totals as $source => $value ) {
			$rows[] = array(
				'label' => $this->points->source_label( $source ),
				'value' => $value,
			);
		}

		usort(
			$rows,
			static fn ( array $left, array $right ): int => $right['value'] <=> $left['value']
		);

		return $rows;
	}

	private function total_positive_points( array $entries ): int {
		$total = 0;

		foreach ( $entries as $entry ) {
			if ( $entry->points() > 0 ) {
				$total += $entry->points();
			}
		}

		return $total;
	}

	/**
	 * @param array<int, Announcement> $announcements Announcements.
	 * @return array<int, Announcement>
	 */
	private function latest_announcements( array $announcements ): array {
		usort(
			$announcements,
			static function ( Announcement $left, Announcement $right ): int {
				$left_time  = strtotime( '' !== $left->created_at() ? $left->created_at() : $left->publish_date() . ' 00:00:00' ) ?: 0;
				$right_time = strtotime( '' !== $right->created_at() ? $right->created_at() : $right->publish_date() . ' 00:00:00' ) ?: 0;

				return $right_time <=> $left_time;
			}
		);

		return $announcements;
	}

	private function completion_rate( int $completed, int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}

		return (int) round( ( $completed / $total ) * 100 );
	}

	private function average_per_group( int $total, int $groups ): float {
		if ( $groups <= 0 ) {
			return 0.0;
		}

		return round( $total / $groups, 1 );
	}

	private function month_label( string $key ): string {
		$timestamp = strtotime( $key . '-01 00:00:00' );

		return false === $timestamp ? $key : wp_date( 'M Y', $timestamp );
	}

	/**
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Date range.
	 */
	private function timestamp_in_range( int $timestamp, array $range ): bool {
		if ( $timestamp <= 0 ) {
			return false;
		}

		$from = strtotime( $range['date_from'] . ' 00:00:00' );
		$to   = strtotime( $range['date_to'] . ' 23:59:59' );

		if ( false === $from || false === $to ) {
			return false;
		}

		return $timestamp >= $from && $timestamp <= $to;
	}

	/**
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Date range.
	 */
	private function datetime_in_range( string $datetime, array $range ): bool {
		$timestamp = strtotime( $datetime );

		return false !== $timestamp && $this->timestamp_in_range( $timestamp, $range );
	}

	/**
	 * @param array{preset:string,date_from:string,date_to:string,label:string} $range Date range.
	 */
	private function date_in_range( string $date, array $range ): bool {
		if ( '' === trim( $date ) ) {
			return false;
		}

		$timestamp = strtotime( $date . ' 00:00:00' );

		return false !== $timestamp && $this->timestamp_in_range( $timestamp, $range );
	}

	/**
	 * @return array{preset:string,date_from:string,date_to:string,label:string}
	 */
	private function fixed_range( string $type ): array {
		$today = current_time( 'timestamp' );

		if ( 'month' === $type ) {
			return array(
				'preset'    => 'month',
				'date_from' => wp_date( 'Y-m-01', $today ),
				'date_to'   => wp_date( 'Y-m-t', $today ),
				'label'     => __( 'este mês', 'adam-membership' ),
			);
		}

		return array(
			'preset'    => 'year',
			'date_from' => wp_date( 'Y-01-01', $today ),
			'date_to'   => wp_date( 'Y-12-31', $today ),
			'label'     => __( 'este ano', 'adam-membership' ),
		);
	}
}
