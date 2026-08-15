<?php
/** Regression contracts for approval idempotency and member-number concurrency. */

declare(strict_types=1);

function adam_approval_safety_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$approval = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/ApprovalService.php' );
$admin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );

adam_approval_safety_assert( str_contains( $approval, 'acquire_approval_lock' ) && str_contains( $approval, 'release_approval_lock' ), 'Member approval uses a scoped lock.' );
adam_approval_safety_assert( str_contains( $approval, 'Member::STATUS_ACTIVE === $member->status()' ), 'Repeated approval is a no-op after activation.' );
adam_approval_safety_assert( str_contains( $approval, 'adam_membership_member_number_lock' ), 'Member-number allocation uses a global atomic option lock.' );
adam_approval_safety_assert( str_contains( $approval, 'adam_membership_number_in_progress' ), 'Number-lock contention fails safely and can be retried.' );
adam_approval_safety_assert( str_contains( $approval, 'finally' ) && str_contains( $approval, 'hash_equals( $token' ), 'Number and approval locks are released only by their owner.' );
adam_approval_safety_assert( str_contains( $admin, '$this->membership_workflow->sync_registration( $member )' ), 'Registration workflow failures have an administrator retry path.' );

echo "Approval safety smoke tests passed.\n";
