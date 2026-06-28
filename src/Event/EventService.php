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
 * Coordinates event data and registrations.
 */
final class EventService {
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

	/**
	 * Get repository.
	 */
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
		$prepared = $this->sanitize_event_data( $data, $id );

		if ( '' === $prepared['title'] ) {
			return new WP_Error( 'adam_membership_event_title_required', __( 'Event title is required.', 'adam-membership' ) );
		}

		if ( '' === $prepared['event_date'] ) {
			return new WP_Error( 'adam_membership_event_date_required', __( 'Event date is required.', 'adam-membership' ) );
		}

		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		if ( 0 === $id ) {
			$prepared['created_at'] = $now;
			$prepared['updated_at'] = $now;
			$event = $this->repository->create_event( $prepared );
			$this->logger->info( 'Event created.', array( 'event_id' => $event->id() ) );

			return $event;
		}

		$current = $this->repository->find_event( $id );

		if ( null === $current ) {
			return new WP_Error( 'adam_membership_event_not_found', __( 'Event not found.', 'adam-membership' ) );
		}

		$prepared['updated_at'] = $now;
		$event = $this->repository->update_event( $current, $prepared );
		$this->logger->info( 'Event updated.', array( 'event_id' => $event->id() ) );

		return $event;
	}

	/**
	 * Delete an event.
	 */
	public function delete_event( int $event_id ): void {
		$this->repository->delete_event( $event_id );
		$this->logger->info( 'Event deleted.', array( 'event_id' => $event_id ) );
	}

	/**
	 * Get frontend-visible events.
	 *
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

	/**
	 * Find a visible event by slug.
	 */
	public function visible_event_by_slug( string $slug ): ?Event {
		$event = $this->repository->find_event_by_slug( $slug );

		if ( null === $event || ! $event->is_visible() ) {
			return null;
		}

		return $event;
	}

	/**
	 * Register one participant for an event.
	 *
	 * @param Event                $event         Event.
	 * @param array<string, mixed> $data          Request data.
	 * @param int                  $current_user_id Current user ID.
	 * @return EventRegistration|WP_Error
	 */
	public function register_participant( Event $event, array $data, int $current_user_id = 0 ): EventRegistration|WP_Error {
		if ( ! $event->is_registration_open() ) {
			return new WP_Error( 'adam_membership_event_registration_closed', __( 'Event registration is closed.', 'adam-membership' ) );
		}

		$member           = $current_user_id > 0 ? $this->members->find( $current_user_id ) : null;
		$is_active_member = $member instanceof Member && $member->isActive();

		if ( Event::ACCESS_MEMBERS_ONLY === $event->access_mode() && ! $is_active_member ) {
			return new WP_Error( 'adam_membership_event_members_only', __( 'Only active ADAM members can register for this event.', 'adam-membership' ) );
		}

		$prepared = $this->sanitize_registration_data( $data, $member );

		if ( '' === $prepared['name'] || '' === $prepared['email'] ) {
			return new WP_Error( 'adam_membership_event_registration_fields', __( 'Name and email are required.', 'adam-membership' ) );
		}

		if ( ! $member instanceof Member && '' === $prepared['phone'] ) {
			return new WP_Error( 'adam_membership_event_registration_phone_required', __( 'Phone is required for non-member registrations.', 'adam-membership' ) );
		}

		if ( $this->has_existing_registration( $event, $prepared['email'], $prepared['member_id'] ) ) {
			return new WP_Error( 'adam_membership_event_duplicate_registration', __( 'A registration already exists for this participant.', 'adam-membership' ) );
		}

		$status = $this->determine_registration_status( $event, $is_active_member );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$registration = $this->repository->create_registration(
			array_merge(
				$prepared,
				array(
					'event_id'      => $event->id(),
					'status'        => $status,
					'manage_token'  => wp_generate_password( 20, false, false ),
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			)
		);

		$this->logger->info(
			'Event registration created.',
			array(
				'event_id'        => $event->id(),
				'registration_id' => $registration->id(),
				'status'          => $registration->status(),
				'member_id'       => $registration->member_id(),
			)
		);
		$this->record_history( $event, $registration, 'event_registered', __( 'Event registration created', 'adam-membership' ) );

		return $registration;
	}

	/**
	 * Cancel a registration.
	 *
	 * @return EventRegistration|WP_Error
	 */
	public function cancel_registration( EventRegistration $registration ): EventRegistration|WP_Error {
		if ( EventRegistration::STATUS_CANCELLED === $registration->status() ) {
			return $registration;
		}

		$updated = $this->repository->update_registration(
			$registration,
			array(
				'status'     => EventRegistration::STATUS_CANCELLED,
				'updated_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);

		$event = $this->repository->find_event( $registration->event_id() );

		if ( null !== $event ) {
			$this->record_history( $event, $updated, 'event_registration_cancelled', __( 'Event registration cancelled', 'adam-membership' ) );
		}

		return $updated;
	}

	/**
	 * Update one registration status from the admin area.
	 *
	 * @return EventRegistration|WP_Error
	 */
	public function update_registration_status( int $registration_id, string $status ): EventRegistration|WP_Error {
		$registration = $this->repository->find_registration( $registration_id );

		if ( null === $registration ) {
			return new WP_Error( 'adam_membership_registration_not_found', __( 'Registration not found.', 'adam-membership' ) );
		}

		$status = sanitize_key( $status );

		if ( ! in_array( $status, EventRegistration::statuses(), true ) ) {
			return new WP_Error( 'adam_membership_registration_invalid_status', __( 'Invalid registration status.', 'adam-membership' ) );
		}

		$event = $this->repository->find_event( $registration->event_id() );

		if ( null === $event ) {
			return new WP_Error( 'adam_membership_event_not_found', __( 'Event not found.', 'adam-membership' ) );
		}

		if ( EventRegistration::STATUS_CONFIRMED === $status && ! $this->has_confirmed_capacity( $event, $registration->id() ) ) {
			return new WP_Error( 'adam_membership_registration_full', __( 'No confirmed spots are available for this event.', 'adam-membership' ) );
		}

		$updated = $this->repository->update_registration(
			$registration,
			array(
				'status'     => $status,
				'updated_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);

		$this->record_history( $event, $updated, 'event_registration_status_changed', __( 'Event registration status changed', 'adam-membership' ) );

		return $updated;
	}

	/**
	 * Get all registrations for one event.
	 *
	 * @return array<int, EventRegistration>
	 */
	public function registrations_for_event( int $event_id ): array {
		return $this->repository->query_registrations( array( 'event_id' => $event_id ) );
	}

	/**
	 * Find a registration for the current member or email.
	 */
	public function attendee_registration( Event $event, ?Member $member, string $email = '' ): ?EventRegistration {
		if ( $member instanceof Member ) {
			$matches = $this->repository->query_registrations(
				array(
					'event_id'   => $event->id(),
					'member_id'  => $member->user_id(),
				)
			);

			foreach ( $matches as $match ) {
				if ( $match->is_active() ) {
					return $match;
				}
			}
		}

		if ( '' !== $email ) {
			$matches = $this->repository->query_registrations(
				array(
					'event_id' => $event->id(),
					'email'    => $email,
				)
			);

			foreach ( $matches as $match ) {
				if ( $match->is_active() ) {
					return $match;
				}
			}
		}

		return null;
	}

	/**
	 * Count confirmed registrations.
	 */
	public function confirmed_count( Event $event ): int {
		return count(
			array_filter(
				$this->registrations_for_event( $event->id() ),
				static fn ( EventRegistration $registration ): bool => EventRegistration::STATUS_CONFIRMED === $registration->status()
			)
		);
	}

	/**
	 * Count waiting list registrations.
	 */
	public function waiting_list_count( Event $event ): int {
		return count(
			array_filter(
				$this->registrations_for_event( $event->id() ),
				static fn ( EventRegistration $registration ): bool => EventRegistration::STATUS_WAITING_LIST === $registration->status()
			)
		);
	}

	/**
	 * Get a registration by token.
	 */
	public function registration_by_token( string $token ): ?EventRegistration {
		return $this->repository->find_registration_by_token( $token );
	}

	/**
	 * Build event URL.
	 */
	public function event_url( Event $event ): string {
		return home_url( '/eventos/' . $event->slug() . '/' );
	}

	/**
	 * Get admin list rows.
	 *
	 * @return array<int, Event>
	 */
	public function admin_events( array $filters = array() ): array {
		return $this->repository->query_events( $filters );
	}

	/**
	 * Sanitize event data.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @param int                  $id   Existing ID.
	 * @return array<string, mixed>
	 */
	private function sanitize_event_data( array $data, int $id ): array {
		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$slug  = $this->repository->unique_slug( $title, $id );

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : Event::STATUS_DRAFT;

		if ( ! in_array( $status, Event::statuses(), true ) ) {
			$status = Event::STATUS_DRAFT;
		}

		$access_mode = isset( $data['access_mode'] ) ? sanitize_key( (string) $data['access_mode'] ) : Event::ACCESS_MEMBERS_ONLY;

		if ( ! in_array( $access_mode, Event::access_modes(), true ) ) {
			$access_mode = Event::ACCESS_MEMBERS_ONLY;
		}

		return array(
			'slug'                  => $slug,
			'title'                 => $title,
			'short_description'     => isset( $data['short_description'] ) ? sanitize_textarea_field( (string) $data['short_description'] ) : '',
			'full_description'      => isset( $data['full_description'] ) ? wp_kses_post( (string) $data['full_description'] ) : '',
			'event_date'            => $this->sanitize_date( (string) ( $data['event_date'] ?? '' ) ),
			'start_time'            => $this->sanitize_time( (string) ( $data['start_time'] ?? '' ) ),
			'end_time'              => $this->sanitize_time( (string) ( $data['end_time'] ?? '' ) ),
			'location'              => isset( $data['location'] ) ? sanitize_text_field( (string) $data['location'] ) : '',
			'map_link'              => isset( $data['map_link'] ) ? esc_url_raw( (string) $data['map_link'] ) : '',
			'cover_image'           => isset( $data['cover_image'] ) ? esc_url_raw( (string) $data['cover_image'] ) : '',
			'access_mode'           => $access_mode,
			'max_players'           => max( 0, absint( $data['max_players'] ?? 0 ) ),
			'waiting_list_enabled'  => ! empty( $data['waiting_list_enabled'] ),
			'waiting_list_limit'    => max( 0, absint( $data['waiting_list_limit'] ?? 0 ) ),
			'registration_deadline' => $this->sanitize_datetime_local( (string) ( $data['registration_deadline'] ?? '' ) ),
			'priority_deadline'     => Event::ACCESS_MEMBER_PRIORITY === $access_mode ? $this->sanitize_datetime_local( (string) ( $data['priority_deadline'] ?? '' ) ) : '',
			'status'                => $status,
		);
	}

	/**
	 * Sanitize registration data.
	 *
	 * @param array<string, mixed> $data   Raw data.
	 * @param Member|null          $member Member when logged in.
	 * @return array<string, mixed>
	 */
	private function sanitize_registration_data( array $data, ?Member $member ): array {
		if ( $member instanceof Member ) {
			return array(
				'member_id' => $member->user_id(),
				'name'      => $member->full_name(),
				'email'     => $member->email(),
				'phone'     => sanitize_text_field( (string) $member->field( 'telefone' ) ),
				'team'      => sanitize_text_field( (string) $member->field( 'equipa' ) ),
				'notes'     => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
			);
		}

		return array(
			'member_id' => 0,
			'name'      => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'email'     => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
			'phone'     => isset( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : '',
			'team'      => isset( $data['team'] ) ? sanitize_text_field( (string) $data['team'] ) : '',
			'notes'     => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
		);
	}

	/**
	 * Determine the correct registration status.
	 *
	 * @return string|WP_Error
	 */
	private function determine_registration_status( Event $event, bool $is_active_member ): string|WP_Error {
		if ( ! $this->has_confirmed_capacity( $event ) ) {
			if ( $event->waiting_list_enabled() && $this->has_waiting_list_capacity( $event ) ) {
				return EventRegistration::STATUS_WAITING_LIST;
			}

			return new WP_Error( 'adam_membership_event_full', __( 'This event is already full.', 'adam-membership' ) );
		}

		if ( Event::ACCESS_MEMBER_PRIORITY === $event->access_mode() && $event->priority_window_open() && ! $is_active_member ) {
			return EventRegistration::STATUS_PENDING;
		}

		return EventRegistration::STATUS_CONFIRMED;
	}

	/**
	 * Check whether a participant already has an active registration.
	 */
	private function has_existing_registration( Event $event, string $email, int $member_id ): bool {
		$registrations = $this->registrations_for_event( $event->id() );

		foreach ( $registrations as $registration ) {
			if ( ! $registration->is_active() ) {
				continue;
			}

			if ( $member_id > 0 && $registration->member_id() === $member_id ) {
				return true;
			}

			if ( '' !== $email && $registration->email() === $email ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check confirmed capacity.
	 *
	 * @param Event $event      Event.
	 * @param int   $ignore_id  Registration ID to ignore while recounting.
	 */
	private function has_confirmed_capacity( Event $event, int $ignore_id = 0 ): bool {
		if ( $event->max_players() <= 0 ) {
			return true;
		}

		$count = 0;

		foreach ( $this->registrations_for_event( $event->id() ) as $registration ) {
			if ( $ignore_id === $registration->id() ) {
				continue;
			}

			if ( EventRegistration::STATUS_CONFIRMED === $registration->status() ) {
				++$count;
			}
		}

		return $count < $event->max_players();
	}

	/**
	 * Check waiting list capacity.
	 */
	private function has_waiting_list_capacity( Event $event ): bool {
		if ( $event->waiting_list_limit() <= 0 ) {
			return true;
		}

		return $this->waiting_list_count( $event ) < $event->waiting_list_limit();
	}

	/**
	 * Record event-related history when a registration is tied to a member.
	 */
	private function record_history( Event $event, EventRegistration $registration, string $action_key, string $action_label ): void {
		$member = $registration->member_id() > 0 ? $this->members->find( $registration->member_id() ) : null;

		if ( null === $member ) {
			return;
		}

		$this->history->create(
			array(
				'member_id'     => $member->user_id(),
				'member_number' => sanitize_text_field( (string) $member->field( 'numero_socio' ) ),
				'member_name'   => $member->full_name(),
				'member_email'  => $member->email(),
				'actor_type'    => 'system',
				'actor_id'      => get_current_user_id(),
				'actor_name'    => 'ADAM Events',
				'action_key'    => $action_key,
				'action_label'  => $action_label,
				'description'   => sprintf(
					/* translators: 1: event title, 2: registration status */
					__( 'Event "%1$s" registration status: %2$s', 'adam-membership' ),
					$event->title(),
					$registration->status()
				),
				'details'       => array(
					'event_id'         => $event->id(),
					'event_title'      => $event->title(),
					'registration_id'  => $registration->id(),
					'registration_status' => $registration->status(),
				),
				'created_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);
	}

	/**
	 * Sanitize a Y-m-d date.
	 */
	private function sanitize_date( string $date ): string {
		$date = trim( $date );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Sanitize an H:i time.
	 */
	private function sanitize_time( string $time ): string {
		$time = trim( $time );

		return preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '';
	}

	/**
	 * Sanitize an HTML datetime-local value.
	 */
	private function sanitize_datetime_local( string $value ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
