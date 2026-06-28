<?php
/**
 * Document repository.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

/**
 * Stores and retrieves document centre entries.
 */
final class DocumentRepository {
	private const OPTION_ITEMS   = 'adam_membership_documents';
	private const OPTION_NEXT_ID = 'adam_membership_document_next_id';

	/**
	 * Create a document.
	 *
	 * @param array<string, mixed> $data Document data.
	 */
	public function create( array $data ): Document {
		$id          = absint( get_option( self::OPTION_NEXT_ID, 1 ) );
		$documents   = $this->raw_items();
		$data['id']  = $id;
		$documents[ $id ] = $data;

		update_option( self::OPTION_ITEMS, $documents, false );
		update_option( self::OPTION_NEXT_ID, $id + 1, false );

		return new Document( $data );
	}

	/**
	 * Update a document.
	 *
	 * @param Document             $document Existing document.
	 * @param array<string, mixed> $data     Updated data.
	 */
	public function update( Document $document, array $data ): Document {
		$documents = $this->raw_items();
		$updated   = array_merge( $document->data(), $data );

		$documents[ $document->id() ] = $updated;
		update_option( self::OPTION_ITEMS, $documents, false );

		return new Document( $updated );
	}

	/**
	 * Delete a document.
	 *
	 * @param int $document_id Document ID.
	 */
	public function delete( int $document_id ): void {
		$documents = $this->raw_items();
		unset( $documents[ $document_id ] );
		update_option( self::OPTION_ITEMS, $documents, false );
	}

	/**
	 * Find one document.
	 *
	 * @param int $document_id Document ID.
	 */
	public function find( int $document_id ): ?Document {
		$documents = $this->raw_items();

		if ( ! isset( $documents[ $document_id ] ) || ! is_array( $documents[ $document_id ] ) ) {
			return null;
		}

		return new Document( $documents[ $document_id ] );
	}

	/**
	 * Query documents.
	 *
	 * @param array<string, mixed> $filters Query filters.
	 * @return array<int, Document>
	 */
	public function query( array $filters = array() ): array {
		$search   = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		$status   = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$category = isset( $filters['category'] ) ? sanitize_text_field( (string) $filters['category'] ) : '';

		$documents = array_map(
			static fn ( array $item ): Document => new Document( $item ),
			array_values( $this->raw_items() )
		);

		$documents = array_values(
			array_filter(
				$documents,
				static function ( Document $document ) use ( $search, $status, $category ): bool {
					if ( '' !== $status && $document->status() !== $status ) {
						return false;
					}

					if ( '' !== $category && $document->category() !== $category ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$document->title(),
								$document->description(),
								$document->category(),
								$document->version(),
								$document->file_name(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		usort(
			$documents,
			static function ( Document $left, Document $right ): int {
				if ( $left->important() !== $right->important() ) {
					return $left->important() ? -1 : 1;
				}

				return strcmp( $right->updated_at(), $left->updated_at() );
			}
		);

		return $documents;
	}

	/**
	 * Get raw stored items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_items(): array {
		$items = get_option( self::OPTION_ITEMS, array() );

		return is_array( $items ) ? $items : array();
	}
}
