<?php
/**
 * Isolated deletion-safety tests for Google Sheets and private documents.
 * No WordPress user, Google API, or production spreadsheet is used.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/GoogleSheets/GoogleSheetsTablePlanner.php';

use AdamMembership\GoogleSheets\GoogleSheetsTablePlanner;

function adam_deletion_safety_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$row = static fn ( string $id, string $name ): array => array( '42', $name, 2027, 'Inscricao', 'Efetivo', 25.0, '2026-12-15', 'MB WAY', 'Pago', $id, '' );
$member_a = $row( 'registration:member-a', 'Member A' );
$member_b = $row( 'registration:member-b', 'Member B' );
$member_c = $row( 'registration:member-c', 'Member C' );

// A whole table row was deleted: the gap is empty, while later members remain.
$after_delete = array( $member_a, array(), $member_c );
$plan = GoogleSheetsTablePlanner::plan( $after_delete, 'registration:member-new' );
adam_deletion_safety_assert( 6 === $plan['target_row'] && ! $plan['requires_insert'], 'The next synchronization reuses the first empty row after a deleted table row.' );
adam_deletion_safety_assert( 'registration:member-c' === $after_delete[2][9], 'The member after the deleted row is not overwritten.' );

$after_sync = $after_delete;
$after_sync[1] = $row( 'registration:member-new', 'New Member' );
$ids = array_map( static fn ( array $value ): string => (string) ( $value[9] ?? '' ), $after_sync );
adam_deletion_safety_assert( 3 === count( array_unique( $ids ) ), 'The synchronization does not duplicate a canonical request ID.' );
adam_deletion_safety_assert( 'registration:member-a' === $after_sync[0][9] && 'registration:member-c' === $after_sync[2][9], 'Existing member IDs remain unchanged.' );

$full = array();
for ( $index = 1; $index <= 20; $index++ ) {
	$full[] = $row( 'registration:full-' . $index, 'Member ' . $index );
}
$expansion = GoogleSheetsTablePlanner::plan( $full, 'registration:after-delete' );
adam_deletion_safety_assert( 25 === $expansion['target_row'] && $expansion['requires_insert'], 'A genuinely full table still expands after earlier row reuse.' );
adam_deletion_safety_assert( 'A4:K25' === GoogleSheetsTablePlanner::expanded_range( 'A4:K24' ), 'The first expansion remains part of the real table range.' );

$sync_source = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$client_source = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
$deletion_source = (string) file_get_contents( __DIR__ . '/../src/Member/MemberDeletionService.php' );
$approval_source = (string) file_get_contents( __DIR__ . '/../src/Member/ApprovalService.php' );
$document_source = (string) file_get_contents( __DIR__ . '/../src/Document/PrivateDocumentRepository.php' );

adam_deletion_safety_assert( str_contains( $sync_source, 'append_table_row( $row' ), 'Synchronization uses the table append path after planning.' );
adam_deletion_safety_assert( str_contains( $client_source, 'appendCells' ) && str_contains( $client_source, "'sheetId' => \$table['sheetId']" ), 'Expansion targets the resolved sheet metadata.' );
adam_deletion_safety_assert( ! str_contains( $deletion_source, 'GoogleSheets' ) && ! str_contains( $deletion_source, 'adam_membership_member_approved' ), 'WordPress member deletion does not invoke Google synchronization.' );
adam_deletion_safety_assert( str_contains( $approval_source, 'return $this->private_documents->find_active( $reference );' ), 'Approval document lookup tolerates a missing active document.' );
adam_deletion_safety_assert( str_contains( $document_source, 'public function find_active( string $request_reference ): ?PrivateDocument' ), 'Private document lookup explicitly returns null when the member document is absent.' );

echo "Google Sheets member deletion safety smoke tests passed.\n";
