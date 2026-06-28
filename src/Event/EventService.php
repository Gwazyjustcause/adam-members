<?php
/**
 * Event service.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use WP_Error;

/**
 * Coordinates event data, external registration details, and member check-ins.
 */
final class EventService {
	private const MEMBER_POINTS_META = 'adam_membership_event_points_balance';

	private EventRepository $repository;
	private MemberRepository $members;
	private Logger $logger;
	private HistoryRepository $history;

	public function __construct( EventRepository $repository, MemberRepository $members, Logger $logger, HistoryRepository $history ) {
		$this->repository = $repository;
		$this->members    = $members;
		$this->logger     = $logger;
		$this->history    = $history;
	}

	public function repository(): EventRepository {
		return $this->repository;
	}

	/**
	 * Save an event.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @param int                  $id   Existing ID.
	 * @return Event|WP_Error
	 */
	public function save_event( array $data, int $id = 0 ): Event|WP_Error {
		$existing = $id > 0 ? $this->repository->find_event( $id ) : null;
		$prepared = $this->sanitize_event_data( $data, $id, $existing );

		if ( '' === $prepared['title'] ) {
			return new WP_Error( 'adam_membership_event_title_required', __( 'O título do evento é obrigatório.', 'adam-membership' ) );
		}

		if ( '' === $prepared['event_date'] ) {
			return new WP_Error( 'adam_membership_event_date_required', __( 'A data do evento é obrigatória.', 'adam-membership' ) );
		}

		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		if ( null === $existing ) {
			$prepared['created_at'] = $now;
			$prepared['updated_at'] = $now;
			$event                  = $this->repository->create_event( $prepared );
			$this->logger->info( 'Evento criado.', array( 'event_id' => $event->id() ) );

			return $event;
		}

		$prepared['updated_at'] = $now;
		$event                  = $this->repository->update_event( $existing, $prepared );
		$this->logger->info( 'Evento atualizado.', array( 'event_id' => $event->id() ) );

		return $event;
	}

	/**
	 * Delete an event.
	 */
	public function delete_event( int $event_id ): void {
		$this->repository->delete_event( $event_id );
		$this->logger->info( 'Evento eliminado.', array( 'event_id' => $event_id ) );
	}

	/**
	 * @return array<int, Event>
	 */
	public function visible_events(): array {
		return array_values(
			array_filter(
				$this->repository->query_events(),
				static fn ( Event $event ): bool => $event->is_visible()
			)
		);
	}

	public function visible_event_by_slug( string $slug ): ?Event {
		$event = $this->repository->find_event_by_slug( $slug );

		if ( null === $event || ! $event->is_visible() ) {
			return null;
		}

		return $event;
	}

	public function event_by_checkin_token( string $token ): ?Event {
		$token = sanitize_text_field( $token );

		if ( '' === $token ) {
			return null;
		}

		foreach ( $this->repository->query_events() as $event ) {
			if ( hash_equals( $event->checkin_token(), $token ) ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * @return array<int, Event>
	 */
	public function admin_events( array $filters = array() ): array {
		return $this->repository->query_events( $filters );
	}

	public function event_url( Event $event ): string {
		return home_url( '/eventos/' . $event->slug() . '/' );
	}

	public function checkin_url( Event $event ): string {
		return home_url( '/eventos/check-in/' . rawurlencode( $event->checkin_token() ) . '/' );
	}

	public function checkin_qr_image_url( Event $event ): string {
		return add_query_arg(
			array(
				'size' => '320x320',
				'data' => $this->checkin_url( $event ),
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);
	}

	public function checked_in_count( Event $event ): int {
		return count( $this->event_checkins( $event->id() ) );
	}

	/**
	 * @return array<int, EventCheckIn>
	 */
	public function event_checkins( int $event_id ): array {
		return $this->repository->query_checkins( array( 'event_id' => $event_id ) );
	}

	public function member_checkin_for_event( Event $event, Member $member ): ?EventCheckIn {
		return $this->repository->find_checkin_for_member( $event->id(), $member->user_id() );
	}

	public function points_balance( Member $member ): int {
		return max( 0, absint( get_user_meta( $member->user_id(), self::MEMBER_POINTS_META, true ) ) );
	}

	/**
	 * Check whether a member can check in.
	 *
	 * @return true|WP_Error
	 */
	public function validate_checkin_eligibility( Event $event, Member $member ): true|WP_Error {
		if ( ! $event->checkin_enabled() ) {
			return new WP_Error( 'adam_membership_event_checkin_disabled', __( 'O check-in para este evento não está ativo.', 'adam-membership' ) );
		}

		if ( ! $member->isActive() ) {
			return new WP_Error( 'adam_membership_event_member_not_active', __( 'Os pontos de participação estão disponíveis apenas para sócios ADAM com estado Ativo.', 'adam-membership' ) );
		}

		if ( ! $event->is_checkin_window_open() ) {
			return new WP_Error( 'adam_membership_event_checkin_closed', __( 'O período de check-in deste evento não está disponível neste momento.', 'adam-membership' ) );
		}

		if ( null !== $this->member_checkin_for_event( $event, $member ) ) {
			return new WP_Error( 'adam_membership_event_already_checked_in', __( 'Já efetuaste o check-in neste evento e os pontos já foram atribuídos.', 'adam-membership' ) );
		}

		return true;
	}

	/**
	 * Perform a member check-in.
	 *
	 * @return EventCheckIn|WP_Error
	 */
	public function check_in_member( Event $event, Member $member ): EventCheckIn|WP_Error {
		$eligibility = $this->validate_checkin_eligibility( $event, $member );

		if ( is_wp_error( $eligibility ) ) {
			return $eligibility;
		}

		$now     = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$points  = $event->checkin_points();
		$checkin = $this->repository->create_checkin(
			array(
				'event_id'        => $event->id(),
				'member_id'       => $member->user_id(),
				'points_awarded'  => $points,
				'checked_in_at'   => $now,
				'created_at'      => $now,
			)
		);

		update_user_meta( $member->user_id(), self::MEMBER_POINTS_META, $this->points_balance( $member ) + $points );

		$this->logger->info(
			'Check-in de evento efetuado.',
			array(
				'event_id'   => $event->id(),
				'member_id'  => $member->user_id(),
				'points'     => $points,
				'checkin_id' => $checkin->id(),
			)
		);

		$this->history->create(
			array(
				'member_id'     => $member->user_id(),
				'member_number' => sanitize_text_field( (string) $member->field( 'numero_socio' ) ),
				'member_name'   => sanitize_text_field( $member->full_name() ),
				'member_email'  => sanitize_email( $member->email() ),
				'action_key'    => 'event_checkin',
				'action_label'  => __( 'Check-in em evento', 'adam-membership' ),
				'actor_type'    => 'member',
				'actor_id'      => $member->user_id(),
				'actor_name'    => sanitize_text_field( $member->full_name() ),
				'description'   => sprintf(
					/* translators: 1: event title, 2: points awarded */
					__( 'Check-in efetuado no evento "%1$s". Pontos atribuídos: +%2$d.', 'adam-membership' ),
					$event->title(),
					$points
				),
				'details'       => array(
					'event_id'        => $event->id(),
					'event_title'     => $event->title(),
					'points_awarded'  => $points,
					'checkin_id'      => $checkin->id(),
					'checked_in_at'   => $checkin->checked_in_at(),
				),
				'created_at'    => $now,
			)
		);

		return $checkin;
	}

	/**
	 * Sanitize event data.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @param int                  $id Existing ID.
	 * @param Event|null           $existing Existing event.
	 * @return array<string, mixed>
	 */
	private function sanitize_event_data( array $data, int $id, ?Event $existing ): array {
		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$slug  = $this->repository->unique_slug( $title, $id );

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : Event::STATUS_DRAFT;

		if ( ! in_array( $status, Event::statuses(), true ) ) {
			$status = Event::STATUS_DRAFT;
		}

		$provider = isset( $data['external_provider_name'] ) ? sanitize_text_field( (string) $data['external_provider_name'] ) : '';
		$provider = '' !== $provider ? $provider : ( null !== $existing ? $existing->external_provider_name() : 'Jogar Airsoft' );

		$checkin_token = isset( $data['checkin_token'] ) ? sanitize_text_field( (string) $data['checkin_token'] ) : '';

		if ( '' === $checkin_token && null !== $existing ) {
			$checkin_token = $existing->checkin_token();
		}

		if ( '' === $checkin_token ) {
			$checkin_token = wp_generate_password( 48, false, false );
		}

		return array(
			'slug'                      => $slug,
			'title'                     => $title,
			'short_description'         => isset( $data['short_description'] ) ? sanitize_textarea_field( (string) $data['short_description'] ) : '',
			'full_description'          => isset( $data['full_description'] ) ? wp_kses_post( (string) $data['full_description'] ) : '',
			'event_date'                => $this->sanitize_date( (string) ( $data['event_date'] ?? '' ) ),
			'start_time'                => $this->sanitize_time( (string) ( $data['start_time'] ?? '' ) ),
			'end_time'                  => $this->sanitize_time( (string) ( $data['end_time'] ?? '' ) ),
			'location'                  => isset( $data['location'] ) ? sanitize_text_field( (string) $data['location'] ) : '',
			'map_link'                  => isset( $data['map_link'] ) ? esc_url_raw( (string) $data['map_link'] ) : '',
			'cover_image'               => isset( $data['cover_image'] ) ? esc_url_raw( (string) $data['cover_image'] ) : '',
			'external_registration_url' => isset( $data['external_registration_url'] ) ? esc_url_raw( (string) $data['external_registration_url'] ) : '',
			'external_provider_name'    => $provider,
			'is_paid'                   => ! empty( $data['is_paid'] ),
			'price'                     => isset( $data['price'] ) ? sanitize_text_field( (string) $data['price'] ) : '',
			'player_limit'              => max( 0, absint( $data['player_limit'] ?? $data['max_players'] ?? 0 ) ),
			'notes'                     => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
			'status'                    => $status,
			'checkin_token'             => $checkin_token,
			'checkin_enabled'           => ! empty( $data['checkin_enabled'] ),
			'checkin_open_at'           => $this->sanitize_datetime_local( (string) ( $data['checkin_open_at'] ?? '' ) ),
			'checkin_close_at'          => $this->sanitize_datetime_local( (string) ( $data['checkin_close_at'] ?? '' ) ),
			'checkin_points'            => max( 1, absint( $data['checkin_points'] ?? 1 ) ),
			'access_mode'               => null !== $existing ? $existing->access_mode() : Event::ACCESS_OPEN,
			'max_players'               => max( 0, absint( $data['player_limit'] ?? $data['max_players'] ?? 0 ) ),
			'waiting_list_enabled'      => null !== $existing ? $existing->waiting_list_enabled() : false,
			'waiting_list_limit'        => null !== $existing ? $existing->waiting_list_limit() : 0,
			'registration_deadline'     => '',
			'priority_deadline'         => '',
		);
	}

	private function sanitize_date( string $date ): string {
		$date = trim( $date );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	private function sanitize_time( string $time ): string {
		$time = trim( $time );

		return preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '';
	}

	private function sanitize_datetime_local( string $value ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
