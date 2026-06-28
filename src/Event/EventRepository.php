<?php
/**
 * Event repository.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

/**
 * Stores events and registrations in plugin options.
 */
final class EventRepository {
	private const OPTION_EVENTS                = 'adam_membership_events';
	private const OPTION_EVENT_NEXT_ID         = 'adam_membership_event_next_id';
	private const OPTION_REGISTRATIONS         = 'adam_membership_event_registrations';
	private const OPTION_REGISTRATION_NEXT_ID  = 'adam_membership_event_registration_next_id';
	private const OPTION_CHECKINS              = 'adam_membership_event_checkins';
	private const OPTION_CHECKIN_NEXT_ID       = 'adam_membership_event_checkin_next_id';

	/**
	 * Create an event.
	 *
	 * @param array<string, mixed> $data Event data.
	 */
	public function create_event( array $data ): Event {
		$id     = absint( get_option( self::OPTION_EVENT_NEXT_ID, 1 ) );
		$events = $this->raw_events();

		$data['id']  = $id;
		$events[ $id ] = $data;

		update_option( self::OPTION_EVENTS, $events, false );
		update_option( self::OPTION_EVENT_NEXT_ID, $id + 1, false );

		return new Event( $data );
	}

	/**
	 * Update an event.
	 *
	 * @param Event                $event Event.
	 * @param array<string, mixed> $data  Updated data.
	 */
	public function update_event( Event $event, array $data ): Event {
		$events = $this->raw_events();
		$item   = array_merge( $event->data(), $data );

		$events[ $event->id() ] = $item;
		update_option( self::OPTION_EVENTS, $events, false );

		return new Event( $item );
	}

	/**
	 * Delete event and its registrations.
	 *
	 * @param int $event_id Event ID.
	 */
	public function delete_event( int $event_id ): void {
		$events = $this->raw_events();
		unset( $events[ $event_id ] );
		update_option( self::OPTION_EVENTS, $events, false );

		$registrations = $this->raw_registrations();

		foreach ( $registrations as $registration_id => $registration ) {
			if ( ! is_array( $registration ) || absint( $registration['event_id'] ?? 0 ) !== $event_id ) {
				continue;
			}

			unset( $registrations[ $registration_id ] );
		}

		update_option( self::OPTION_REGISTRATIONS, $registrations, false );

		$checkins = $this->raw_checkins();

		foreach ( $checkins as $checkin_id => $checkin ) {
			if ( ! is_array( $checkin ) || absint( $checkin['event_id'] ?? 0 ) !== $event_id ) {
				continue;
			}

			unset( $checkins[ $checkin_id ] );
		}

		update_option( self::OPTION_CHECKINS, $checkins, false );
	}

	/**
	 * Find an event by ID.
	 */
	public function find_event( int $event_id ): ?Event {
		$events = $this->raw_events();

		if ( ! isset( $events[ $event_id ] ) || ! is_array( $events[ $event_id ] ) ) {
			return null;
		}

		return new Event( $events[ $event_id ] );
	}

	/**
	 * Find an event by slug.
	 */
	public function find_event_by_slug( string $slug ): ?Event {
		foreach ( $this->raw_events() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( sanitize_title( (string) ( $event['slug'] ?? '' ) ) === sanitize_title( $slug ) ) {
				return new Event( $event );
			}
		}

		return null;
	}

	/**
	 * Query events.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, Event>
	 */
	public function query_events( array $filters = array() ): array {
		$search = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		$status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';

		$events = array_map(
			static fn ( array $item ): Event => new Event( $item ),
			array_values( $this->raw_events() )
		);

		$events = array_values(
			array_filter(
				$events,
				static function ( Event $event ) use ( $search, $status ): bool {
					if ( '' !== $status && $event->status() !== $status ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$event->title(),
								$event->short_description(),
								$event->location(),
								$event->access_mode(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		usort(
			$events,
			static fn ( Event $left, Event $right ): int => $left->starts_at_timestamp() <=> $right->starts_at_timestamp()
		);

		return $events;
	}

	/**
	 * Create a registration.
	 *
	 * @param array<string, mixed> $data Registration data.
	 */
	public function create_registration( array $data ): EventRegistration {
		$id             = absint( get_option( self::OPTION_REGISTRATION_NEXT_ID, 1 ) );
		$registrations  = $this->raw_registrations();
		$data['id']     = $id;
		$registrations[ $id ] = $data;

		update_option( self::OPTION_REGISTRATIONS, $registrations, false );
		update_option( self::OPTION_REGISTRATION_NEXT_ID, $id + 1, false );

		return new EventRegistration( $data );
	}

	/**
	 * Update a registration.
	 *
	 * @param EventRegistration    $registration Registration.
	 * @param array<string, mixed> $data         Updated data.
	 */
	public function update_registration( EventRegistration $registration, array $data ): EventRegistration {
		$registrations = $this->raw_registrations();
		$item          = array_merge( $registration->data(), $data );

		$registrations[ $registration->id() ] = $item;
		update_option( self::OPTION_REGISTRATIONS, $registrations, false );

		return new EventRegistration( $item );
	}

	/**
	 * Find a registration by ID.
	 */
	public function find_registration( int $registration_id ): ?EventRegistration {
		$registrations = $this->raw_registrations();

		if ( ! isset( $registrations[ $registration_id ] ) || ! is_array( $registrations[ $registration_id ] ) ) {
			return null;
		}

		return new EventRegistration( $registrations[ $registration_id ] );
	}

	/**
	 * Find a registration by manage token.
	 */
	public function find_registration_by_token( string $token ): ?EventRegistration {
		foreach ( $this->raw_registrations() as $registration ) {
			if ( ! is_array( $registration ) ) {
				continue;
			}

			if ( hash_equals( (string) ( $registration['manage_token'] ?? '' ), $token ) ) {
				return new EventRegistration( $registration );
			}
		}

		return null;
	}

	/**
	 * Query registrations.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, EventRegistration>
	 */
	public function query_registrations( array $filters = array() ): array {
		$event_id = isset( $filters['event_id'] ) ? absint( $filters['event_id'] ) : 0;
		$status   = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$member_id = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
		$email    = isset( $filters['email'] ) ? sanitize_email( (string) $filters['email'] ) : '';

		$registrations = array_map(
			static fn ( array $item ): EventRegistration => new EventRegistration( $item ),
			array_values( $this->raw_registrations() )
		);

		$registrations = array_values(
			array_filter(
				$registrations,
				static function ( EventRegistration $registration ) use ( $event_id, $status, $member_id, $email ): bool {
					if ( 0 !== $event_id && $registration->event_id() !== $event_id ) {
						return false;
					}

					if ( '' !== $status && $registration->status() !== $status ) {
						return false;
					}

					if ( 0 !== $member_id && $registration->member_id() !== $member_id ) {
						return false;
					}

					if ( '' !== $email && $registration->email() !== $email ) {
						return false;
					}

					return true;
				}
			)
		);

		usort(
			$registrations,
			static fn ( EventRegistration $left, EventRegistration $right ): int => strtotime( $left->created_at() ) <=> strtotime( $right->created_at() )
		);

		return $registrations;
	}

	/**
	 * Create a check-in.
	 *
	 * @param array<string, mixed> $data Check-in data.
	 */
	public function create_checkin( array $data ): EventCheckIn {
		$id        = absint( get_option( self::OPTION_CHECKIN_NEXT_ID, 1 ) );
		$checkins  = $this->raw_checkins();
		$data['id'] = $id;
		$checkins[ $id ] = $data;

		update_option( self::OPTION_CHECKINS, $checkins, false );
		update_option( self::OPTION_CHECKIN_NEXT_ID, $id + 1, false );

		return new EventCheckIn( $data );
	}

	/**
	 * Find a member check-in for one event.
	 */
	public function find_checkin_for_member( int $event_id, int $member_id ): ?EventCheckIn {
		foreach ( $this->raw_checkins() as $checkin ) {
			if ( ! is_array( $checkin ) ) {
				continue;
			}

			if ( absint( $checkin['event_id'] ?? 0 ) === $event_id && absint( $checkin['member_id'] ?? 0 ) === $member_id ) {
				return new EventCheckIn( $checkin );
			}
		}

		return null;
	}

	/**
	 * Query check-ins.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, EventCheckIn>
	 */
	public function query_checkins( array $filters = array() ): array {
		$event_id  = isset( $filters['event_id'] ) ? absint( $filters['event_id'] ) : 0;
		$member_id = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;

		$checkins = array_map(
			static fn ( array $item ): EventCheckIn => new EventCheckIn( $item ),
			array_values( $this->raw_checkins() )
		);

		$checkins = array_values(
			array_filter(
				$checkins,
				static function ( EventCheckIn $checkin ) use ( $event_id, $member_id ): bool {
					if ( 0 !== $event_id && $checkin->event_id() !== $event_id ) {
						return false;
					}

					if ( 0 !== $member_id && $checkin->member_id() !== $member_id ) {
						return false;
					}

					return true;
				}
			)
		);

		usort(
			$checkins,
			static fn ( EventCheckIn $left, EventCheckIn $right ): int => strtotime( $right->checked_in_at() ) <=> strtotime( $left->checked_in_at() )
		);

		return $checkins;
	}

	/**
	 * Build a unique event slug.
	 */
	public function unique_slug( string $title, int $ignore_id = 0 ): string {
		$base = sanitize_title( $title );

		if ( '' === $base ) {
			$base = 'evento';
		}

		$slug    = $base;
		$counter = 2;

		while ( $this->slug_exists( $slug, $ignore_id ) ) {
			$slug = $base . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	/**
	 * Get raw events.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_events(): array {
		$events = get_option( self::OPTION_EVENTS, array() );

		return is_array( $events ) ? $events : array();
	}

	/**
	 * Get raw registrations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_registrations(): array {
		$registrations = get_option( self::OPTION_REGISTRATIONS, array() );

		return is_array( $registrations ) ? $registrations : array();
	}

	/**
	 * Get raw check-ins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_checkins(): array {
		$checkins = get_option( self::OPTION_CHECKINS, array() );

		return is_array( $checkins ) ? $checkins : array();
	}

	/**
	 * Check whether an event slug already exists.
	 *
	 * @param string $slug      Slug.
	 * @param int    $ignore_id Event ID to ignore.
	 */
	private function slug_exists( string $slug, int $ignore_id ): bool {
		foreach ( $this->raw_events() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( absint( $event['id'] ?? 0 ) === $ignore_id ) {
				continue;
			}

			if ( sanitize_title( (string) ( $event['slug'] ?? '' ) ) === $slug ) {
				return true;
			}
		}

		return false;
	}
}
