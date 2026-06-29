<?php
/**
 * Points service.
 *
 * @package AdamMembership\Points
 */

declare(strict_types=1);

namespace AdamMembership\Points;

use AdamMembership\Event\Event;
use AdamMembership\Event\EventCheckIn;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use WP_Error;
use WP_User;

/**
 * Coordinates the ADAM points ledger.
 *
 * The points ledger is the single source of truth for balances, totals,
 * event-awarded points, and future redemptions or achievements.
 */
final class PointsService {
	public const SOURCE_EVENT_CHECK_IN    = 'event_check_in';
	public const SOURCE_EVENT_CHECKIN_BONUS = 'event_checkin_bonus';
	public const SOURCE_ADMIN_ADJUSTMENT  = 'admin_adjustment';
	public const SOURCE_REWARD_REDEMPTION = 'reward_redemption';
	public const SOURCE_BONUS             = 'bonus';

	private PointsRepository $repository;
	private MemberRepository $members;
	private HistoryRepository $history;
	private Logger $logger;

	public function __construct( PointsRepository $repository, MemberRepository $members, HistoryRepository $history, Logger $logger ) {
		$this->repository = $repository;
		$this->members    = $members;
		$this->history    = $history;
		$this->logger     = $logger;
	}

	public function repository(): PointsRepository {
		return $this->repository;
	}

	public function current_balance( Member|int $member ): int {
		$member_id = $member instanceof Member ? $member->user_id() : absint( $member );
		$total     = 0;

		foreach ( $this->repository->query( array( 'member_id' => $member_id ) ) as $entry ) {
			$total += $entry->points();
		}

		return $total;
	}

	public function total_earned( Member|int $member ): int {
		$member_id = $member instanceof Member ? $member->user_id() : absint( $member );
		$total     = 0;

		foreach ( $this->repository->query( array( 'member_id' => $member_id ) ) as $entry ) {
			if ( $entry->points() > 0 ) {
				$total += $entry->points();
			}
		}

		return $total;
	}

	/**
	 * @return array<int, PointsEntry>
	 */
	public function recent_activity( Member|int $member, int $limit = 5 ): array {
		$member_id = $member instanceof Member ? $member->user_id() : absint( $member );

		return $this->repository->query(
			array(
				'member_id' => $member_id,
				'limit'     => max( 1, $limit ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $filters Optional filters.
	 * @return array<int, PointsEntry>
	 */
	public function member_history( Member|int $member, array $filters = array() ): array {
		$member_id = $member instanceof Member ? $member->user_id() : absint( $member );
		$filters['member_id'] = $member_id;

		return $this->repository->query( $filters );
	}

	public function award_event_checkin_points( Member $member, Event $event, EventCheckIn $checkin ): ?PointsEntry {
		$points = $event->checkin_points();

		if ( $points <= 0 ) {
			return null;
		}

		$existing = $this->repository->find_by_source( $member->user_id(), self::SOURCE_EVENT_CHECK_IN, $event->id() );

		if ( null !== $existing ) {
			return $existing;
		}

		return $this->create_entry(
			$member,
			$points,
			sprintf(
				/* translators: %s: event title */
				__( 'Participação no evento %s', 'adam-membership' ),
				$event->title()
			),
			self::SOURCE_EVENT_CHECK_IN,
			$event->id(),
			$member->user_id(),
			array(
				'event_id'      => $event->id(),
				'event_title'   => $event->title(),
				'checkin_id'    => $checkin->id(),
				'checked_in_at' => $checkin->checked_in_at(),
			)
		);
	}

	public function bonus_entry_for_event( Event|int $event ): ?PointsEntry {
		$event_id = $event instanceof Event ? $event->id() : absint( $event );

		return $this->repository->first_by_source( self::SOURCE_EVENT_CHECKIN_BONUS, $event_id );
	}

	public function member_bonus_entry_for_event( Member $member, Event $event ): ?PointsEntry {
		return $this->repository->find_by_source( $member->user_id(), self::SOURCE_EVENT_CHECKIN_BONUS, $event->id() );
	}

	public function award_event_checkin_bonus( Member $member, Event $event, EventCheckIn $checkin, string $reason ): ?PointsEntry {
		$points = $event->checkin_bonus_points();

		if ( ! $event->checkin_bonus_enabled() || $points <= 0 ) {
			return null;
		}

		if ( null !== $this->bonus_entry_for_event( $event ) ) {
			return null;
		}

		return $this->create_entry(
			$member,
			$points,
			$reason,
			self::SOURCE_EVENT_CHECKIN_BONUS,
			$event->id(),
			$member->user_id(),
			array(
				'event_id'      => $event->id(),
				'event_title'   => $event->title(),
				'checkin_id'    => $checkin->id(),
				'checked_in_at' => $checkin->checked_in_at(),
			)
		);
	}

	/**
	 * @return PointsEntry|WP_Error
	 */
	public function adjust_member_points( Member $member, int $points, string $reason, int $admin_user_id ): PointsEntry|WP_Error {
		$reason = trim( sanitize_text_field( $reason ) );

		if ( 0 === $points ) {
			return new WP_Error( 'adam_membership_points_zero_adjustment', __( 'Indica um valor de pontos diferente de zero.', 'adam-membership' ) );
		}

		if ( '' === $reason ) {
			return new WP_Error( 'adam_membership_points_reason_required', __( 'O motivo do ajuste de pontos é obrigatório.', 'adam-membership' ) );
		}

		return $this->create_entry(
			$member,
			$points,
			$reason,
			self::SOURCE_ADMIN_ADJUSTMENT,
			0,
			$admin_user_id,
			array(
				'adjustment_type' => $points > 0 ? 'credit' : 'debit',
			)
		);
	}

	/**
	 * Deduct points after an approved reward redemption.
	 *
	 * @return PointsEntry|WP_Error
	 */
	public function redeem_reward_points( Member $member, int $points_cost, string $reason, int $reward_id, int $actor_user_id = 0 ): PointsEntry|WP_Error {
		$points_cost = absint( $points_cost );
		$reason      = trim( sanitize_text_field( $reason ) );

		if ( $points_cost <= 0 ) {
			return new WP_Error( 'adam_membership_reward_points_invalid', __( 'O custo em pontos da recompensa e invalido.', 'adam-membership' ) );
		}

		if ( '' === $reason ) {
			return new WP_Error( 'adam_membership_reward_reason_required', __( 'O motivo do resgate da recompensa e obrigatorio.', 'adam-membership' ) );
		}

		if ( $this->current_balance( $member ) < $points_cost ) {
			return new WP_Error( 'adam_membership_reward_not_enough_points', __( 'O socio nao tem pontos suficientes para este resgate.', 'adam-membership' ) );
		}

		return $this->create_entry(
			$member,
			0 - $points_cost,
			$reason,
			self::SOURCE_REWARD_REDEMPTION,
			$reward_id,
			$actor_user_id,
			array(
				'reward_id' => $reward_id,
			)
		);
	}

	/**
	 * @return array{total_points_awarded:int,total_events_that_awarded_points:int,top_members:array<int, array<string, mixed>>,recent_activity:array<int, PointsEntry>}
	 */
	public function dashboard_stats(): array {
		$entries              = $this->repository->query();
		$total_points_awarded = 0;
		$event_ids            = array();
		$balances             = array();

		foreach ( $entries as $entry ) {
			if ( $entry->points() > 0 ) {
				$total_points_awarded += $entry->points();
			}

			if ( self::SOURCE_EVENT_CHECK_IN === $entry->source_type() && $entry->points() > 0 ) {
				$event_ids[ $entry->source_id() ] = true;
			}

			if ( ! isset( $balances[ $entry->member_id() ] ) ) {
				$balances[ $entry->member_id() ] = 0;
			}

			$balances[ $entry->member_id() ] += $entry->points();
		}

		arsort( $balances );

		$top_members = array();

		foreach ( array_slice( $balances, 0, 5, true ) as $member_id => $balance ) {
			$member = $this->members->find( (int) $member_id );

			if ( null === $member ) {
				continue;
			}

			$top_members[] = array(
				'member'       => $member,
				'balance'      => (int) $balance,
				'total_earned' => $this->total_earned( $member ),
			);
		}

		return array(
			'total_points_awarded'           => $total_points_awarded,
			'total_events_that_awarded_points' => count( $event_ids ),
			'top_members'                    => $top_members,
			'recent_activity'                => array_slice( $entries, 0, 8 ),
		);
	}

	/**
	 * @param array<int, Event> $events Events list.
	 * @return array<int, array<string, mixed>>
	 */
	public function event_overview( array $events ): array {
		$rows = array();

		foreach ( $events as $event ) {
			$entries = $this->repository->query(
				array(
					'source_type' => self::SOURCE_EVENT_CHECK_IN,
					'source_id'   => $event->id(),
				)
			);

			$rows[] = array(
				'event'             => $event,
				'configured_points' => $event->checkin_points(),
				'recipients'        => count( $entries ),
				'total_awarded'     => array_sum( array_map( static fn ( PointsEntry $entry ): int => $entry->points(), $entries ) ),
			);
		}

		usort(
			$rows,
			static fn ( array $left, array $right ): int => $right['total_awarded'] <=> $left['total_awarded']
		);

		return $rows;
	}

	public function source_label( string $source_type ): string {
		return match ( $source_type ) {
			self::SOURCE_EVENT_CHECK_IN    => __( 'Check-in de evento', 'adam-membership' ),
			self::SOURCE_EVENT_CHECKIN_BONUS => __( 'Bónus especial do evento', 'adam-membership' ),
			self::SOURCE_ADMIN_ADJUSTMENT  => __( 'Ajuste administrativo', 'adam-membership' ),
			self::SOURCE_REWARD_REDEMPTION => __( 'Resgate de recompensa', 'adam-membership' ),
			self::SOURCE_BONUS             => __( 'Bónus', 'adam-membership' ),
			default                        => __( 'Movimento', 'adam-membership' ),
		};
	}

	/**
	 * @param array<string, mixed> $meta Extra metadata for future integrations.
	 */
	private function create_entry( Member $member, int $points, string $reason, string $source_type, int $source_id, int $created_by, array $meta = array() ): PointsEntry {
		$now   = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$entry = $this->repository->create(
			array(
				'member_id'   => $member->user_id(),
				'points'      => $points,
				'reason'      => $reason,
				'source_type' => sanitize_key( $source_type ),
				'source_id'   => $source_id,
				'created_at'  => $now,
				'created_by'  => $created_by,
				'meta'        => $this->sanitize_meta( $meta ),
			)
		);

		$actor_type = self::SOURCE_ADMIN_ADJUSTMENT === $source_type ? 'admin' : 'system';
		$actor_name = '';

		if ( $created_by > 0 ) {
			$user = get_user_by( 'id', $created_by );

			if ( $user instanceof WP_User ) {
				$actor_name = (string) $user->display_name;
			}
		}

		$this->history->create(
			array(
				'member_id'     => $member->user_id(),
				'member_number' => sanitize_text_field( (string) $member->field( 'numero_socio' ) ),
				'member_name'   => sanitize_text_field( $member->full_name() ),
				'member_email'  => sanitize_email( $member->email() ),
				'action_key'    => 'points_movement',
				'action_label'  => __( 'Pontos ADAM', 'adam-membership' ),
				'actor_type'    => $actor_type,
				'actor_id'      => $created_by,
				'actor_name'    => sanitize_text_field( $actor_name ),
				'description'   => sprintf(
					/* translators: 1: signed points amount, 2: reason */
					__( 'Movimento de pontos %1$s. Motivo: %2$s.', 'adam-membership' ),
					$this->format_signed_points( $points ),
					$reason
				),
				'details'       => array(
					'points'      => $points,
					'reason'      => $reason,
					'source_type' => sanitize_key( $source_type ),
					'source_id'   => $source_id,
				) + $this->sanitize_meta( $meta ),
				'created_at'    => $now,
			)
		);

		$this->logger->info(
			'Movimento de pontos registado.',
			array(
				'entry_id'    => $entry->id(),
				'member_id'   => $member->user_id(),
				'points'      => $points,
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'created_by'  => $created_by,
			)
		);

		return $entry;
	}

	private function format_signed_points( int $points ): string {
		return $points > 0 ? '+' . $points : (string) $points;
	}

	/**
	 * @param array<string, mixed> $meta Raw metadata.
	 * @return array<string, mixed>
	 */
	private function sanitize_meta( array $meta ): array {
		$sanitized = array();

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_meta( $value );
				continue;
			}

			if ( is_bool( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			if ( is_numeric( $value ) ) {
				$sanitized[ $key ] = 0 + $value;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}
}
