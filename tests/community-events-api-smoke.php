<?php
/**
 * Runtime smoke test for the ADAM Comunidade Events consumer.
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['adam_legacy_options'] = array(
		'adam_membership_events' => array(
			99 => array( 'id' => 99, 'title' => 'Evento legado removido', 'slug' => 'legado', 'event_date' => '2099-01-01' ),
		),
	);
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_title( string $value ): string { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?? '' ), '-' ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function sanitize_email( string $value ): string { return trim( $value ); }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ?? '' ); }
	function esc_url_raw( string $value ): string { return $value; }
	function current_time( string $type ): int|string { return 'timestamp' === $type ? strtotime( '2030-01-01' ) : '2030-01-01 00:00:00'; }
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['adam_legacy_options'][ $key ] ?? $default; }
	function update_option( string $key, mixed $value, bool $autoload = true ): bool { unset( $autoload ); $GLOBALS['adam_legacy_options'][ $key ] = $value; return true; }
}

namespace ADAM\Comunidade\Events {
	final class Event {
		public function __construct( private array $payload ) {}
		public function data(): array { return $this->payload; }
	}
	final class Test_Api {
		/** @var array<int,array<string,mixed>> */
		public array $events = array(
			4 => array( 'id' => 4, 'title' => 'Evento canónico', 'slug' => 'evento-canonico', 'event_date' => '2099-05-20', 'status' => 'published' ),
		);
		public function get_event( int|string $identifier ): ?Event {
			foreach ( $this->events as $item ) {
				if ( ( is_numeric( $identifier ) && (int) $identifier === $item['id'] ) || ( ! is_numeric( $identifier ) && $identifier === $item['slug'] ) ) {
					return new Event( $item );
				}
			}
			return null;
		}
		public function get_events( array $filters = array() ): array { unset( $filters ); return array_map( static fn( array $item ): Event => new Event( $item ), array_values( $this->events ) ); }
		public function save_event( array $data, int $id = 0 ): Event {
			$id = $id ?: 5;
			$data['id'] = $id;
			$this->events[ $id ] = $data;
			return new Event( $data );
		}
		public function delete_event( int $id ): void { unset( $this->events[ $id ] ); }
	}
}

namespace {
	$GLOBALS['adam_community_api'] = new \ADAM\Comunidade\Events\Test_Api();
	function adam_comunidade_events(): \ADAM\Comunidade\Events\Test_Api { return $GLOBALS['adam_community_api']; }

	require dirname( __DIR__ ) . '/src/Event/Event.php';
	require dirname( __DIR__ ) . '/src/Event/EventRegistration.php';
	require dirname( __DIR__ ) . '/src/Event/EventCheckIn.php';
	require dirname( __DIR__ ) . '/src/Event/EventRepository.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	};
	$repository = new \AdamMembership\Event\EventRepository();
	$assert( 'Evento canónico' === $repository->find_event( 4 )?->title(), 'Canonical lookup by ID failed.' );
	$assert( 'evento-canonico' === $repository->find_event_by_slug( 'evento-canonico' )?->slug(), 'Canonical lookup by slug failed.' );
	$assert( 1 === count( $repository->query_events() ), 'Canonical list failed.' );
	$assert( null === $repository->find_event( 99 ), 'Active Community API must not resurrect deleted legacy events.' );

	$created = $repository->create_event( array( 'title' => 'Novo', 'slug' => 'novo', 'event_date' => '2099-06-01' ) );
	$assert( 5 === $created->id(), 'Canonical create failed.' );
	$updated = $repository->update_event( $created, array( 'title' => 'Atualizado' ) );
	$assert( 'Atualizado' === $updated->title(), 'Canonical update failed.' );
	$repository->delete_event( 5 );
	$assert( null === $repository->find_event( 5 ), 'Canonical delete failed.' );
	$assert( isset( $GLOBALS['adam_legacy_options']['adam_membership_events'][99] ), 'Canonical operations must preserve rollback data.' );

	echo "Community Events API consumer tests passed.\n";
}
