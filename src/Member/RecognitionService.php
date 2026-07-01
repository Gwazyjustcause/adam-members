<?php
/**
 * Founder and loyalty recognition service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Helpers\Logger;
use AdamMembership\Reward\RewardService;

/**
 * Coordinates permanent founder and loyalty recognition.
 */
final class RecognitionService {
	private const FOUNDER_LIMIT = 50;

	private MemberRepository $members;
	private RewardService $rewards;
	private HistoryRepository $history;
	private Logger $logger;

	public function __construct( MemberRepository $members, RewardService $rewards, HistoryRepository $history, Logger $logger ) {
		$this->members = $members;
		$this->rewards = $rewards;
		$this->history = $history;
		$this->logger  = $logger;
	}

	/**
	 * Apply founder rules after an approved membership.
	 */
	public function handle_member_approved( Member $member ): void {
		if ( ! $member->is_founder() && count( $this->members->founding_members() ) < self::FOUNDER_LIMIT ) {
			$this->assign_founder( $member );
		}

		if ( $member->is_founder() ) {
			$this->grant_founder_rewards( $member );
		}
	}

	/**
	 * Apply founder and loyalty rules after a successful renewal.
	 */
	public function handle_renewal_approved( Member $member ): void {
		if ( $member->is_founder() ) {
			$this->grant_founder_rewards( $member );
		}

		$this->unlock_loyalty_rewards( $member );
	}

	/**
	 * Sync recognition after manual admin changes.
	 */
	public function sync_member( Member $member ): void {
		if ( $member->is_founder() ) {
			$this->grant_founder_rewards( $member );
		}
	}

	/**
	 * Manually assign founder status.
	 */
	public function assign_founder( Member $member, int $founder_number = 0 ): void {
		$number = $founder_number > 0 ? $founder_number : $this->next_founder_number();

		$member->save(
			array(
				'adam_founder_status' => '1',
				'adam_founder_number' => $number,
			)
		);

		$this->grant_founder_rewards( $member );
		$this->record_history(
			$member,
			'founder_assigned',
			__( 'Membro fundador atribuido', 'adam-membership' ),
			__( 'O estatuto de membro fundador foi atribuido permanentemente.', 'adam-membership' ),
			array(
				'founder_number' => $number,
			)
		);
	}

	/**
	 * Manually revoke founder status.
	 */
	public function revoke_founder( Member $member ): void {
		$this->revoke_founder_rewards( $member );
		$member->save(
			array(
				'adam_founder_status' => '',
				'adam_founder_number' => '',
				'adam_active_title_reward' => $this->founder_reward_values()[0] === (string) $member->field( 'adam_active_title_reward' ) ? '' : (string) $member->field( 'adam_active_title_reward' ),
				'adam_active_card_theme' => $this->founder_reward_values()[1] === (string) $member->field( 'adam_active_card_theme' ) ? '' : (string) $member->field( 'adam_active_card_theme' ),
				'adam_active_card_frame' => $this->founder_reward_values()[2] === (string) $member->field( 'adam_active_card_frame' ) ? '' : (string) $member->field( 'adam_active_card_frame' ),
			)
		);

		$this->record_history(
			$member,
			'founder_revoked',
			__( 'Membro fundador removido', 'adam-membership' ),
			__( 'O estatuto de membro fundador foi removido manualmente.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Get loyalty tier definitions.
	 *
	 * @return array<string, array{years:int,label:string,rewards:array<int,string>}>
	 */
	public function loyalty_tiers(): array {
		return array(
			'2y'  => array(
				'years'   => 2,
				'label'   => 'Veterano ADAM',
				'rewards' => array( 'title_veterano_adam', 'card_frame_veteran_bronze' ),
			),
			'3y'  => array(
				'years'   => 3,
				'label'   => 'Operador Experiente',
				'rewards' => array( 'title_operador_experiente', 'card_theme_carbon_fiber' ),
			),
			'5y'  => array(
				'years'   => 5,
				'label'   => 'Guardiao ADAM',
				'rewards' => array( 'title_guardiao_adam', 'card_theme_gold', 'card_frame_veteran_gold' ),
			),
			'10y' => array(
				'years'   => 10,
				'label'   => 'Lenda ADAM',
				'rewards' => array( 'title_lenda_adam', 'card_theme_legendary_loyalty', 'card_frame_legendary_loyalty' ),
			),
		);
	}

	/**
	 * Return reward values reserved for founders.
	 *
	 * @return array<int, string>
	 */
	public function founder_reward_values(): array {
		return array(
			'title_founder',
			'card_theme_founder',
			'card_frame_founder_frame',
		);
	}

	/**
	 * Return reward values reserved for loyalty tiers.
	 *
	 * @return array<int, string>
	 */
	public function loyalty_reward_values(): array {
		$values = array();

		foreach ( $this->loyalty_tiers() as $tier ) {
			$values = array_merge( $values, $tier['rewards'] );
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Build loyalty progress for the member.
	 *
	 * @return array{completed_years:int,completed_months:int,unlocked:array<int,string>,next_tier:?array{key:string,years:int,label:string,elapsed_label:string}}
	 */
	public function loyalty_progress( Member $member ): array {
		$completed_years  = 0;
		$completed_months = 0;
		$next_tier        = array(
			'key'           => '2y',
			'years'         => 2,
			'label'         => 'Veterano ADAM',
			'elapsed_label' => $this->elapsed_label( 0, 2 ),
		);

		$join_timestamp = $member->join_date_timestamp();

		if ( $join_timestamp > 0 ) {
			$joined = new \DateTimeImmutable( wp_date( 'Y-m-d', $join_timestamp ) );
			$today  = new \DateTimeImmutable( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );
			$diff   = $joined->diff( $today );

			$completed_years  = max( 0, (int) $diff->y );
			$completed_months = max( 0, (int) ( $diff->y * 12 + $diff->m ) );

			foreach ( $this->loyalty_tiers() as $key => $tier ) {
				if ( $completed_years < $tier['years'] ) {
					$next_tier = array(
						'key'           => $key,
						'years'         => $tier['years'],
						'label'         => $tier['label'],
						'elapsed_label' => $this->elapsed_label( $completed_months, $tier['years'] ),
					);
					break;
				}
			}

			if ( $completed_years >= 10 ) {
				$next_tier = null;
			}
		}

		return array(
			'completed_years'  => $completed_years,
			'completed_months' => $completed_months,
			'unlocked'         => $member->loyalty_unlocked(),
			'next_tier'        => $next_tier,
		);
	}

	private function unlock_loyalty_rewards( Member $member ): void {
		if ( ! $member->isActive() ) {
			return;
		}

		$join_timestamp = $member->join_date_timestamp();

		if ( $join_timestamp <= 0 ) {
			return;
		}

		$joined  = new \DateTimeImmutable( wp_date( 'Y-m-d', $join_timestamp ) );
		$today   = new \DateTimeImmutable( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );
		$diff    = $joined->diff( $today );
		$years   = max( 0, (int) $diff->y );
		$current = $member->loyalty_unlocked();
		$changed = false;

		foreach ( $this->loyalty_tiers() as $tier_key => $tier ) {
			if ( $years < $tier['years'] || in_array( $tier_key, $current, true ) ) {
				continue;
			}

			$current[] = $tier_key;
			$changed   = true;

			foreach ( $tier['rewards'] as $reward_value ) {
				$this->rewards->grant_reward_to_member(
					$member,
					$reward_value,
					'loyalty_reward_unlocked',
					'Recompensa de fidelidade desbloqueada',
					sprintf(
						/* translators: %s: loyalty tier label */
						__( 'A fidelidade do socio desbloqueou a recompensa %s.', 'adam-membership' ),
						$tier['label']
					)
				);
			}

			$this->record_history(
				$member,
				'loyalty_tier_unlocked',
				__( 'Marco de fidelidade desbloqueado', 'adam-membership' ),
				sprintf(
					/* translators: %s: loyalty tier label */
					__( 'Foi desbloqueado o marco de fidelidade %s.', 'adam-membership' ),
					$tier['label']
				),
				array(
					'tier_key' => $tier_key,
					'years'    => $tier['years'],
				)
			);
		}

		if ( $changed ) {
			$member->save(
				array(
					'adam_loyalty_unlocked' => array_values( array_unique( $current ) ),
				)
			);
		}
	}

	private function grant_founder_rewards( Member $member ): void {
		foreach ( $this->founder_reward_values() as $reward_value ) {
			$this->rewards->grant_reward_to_member(
				$member,
				$reward_value,
				'founder_reward_granted',
				'Recompensa de fundador atribuida',
				__( 'Foram atribuidas recompensas exclusivas de membro fundador.', 'adam-membership' )
			);
		}
	}

	/**
	 * Remove founder-specific rewards after a manual revocation.
	 */
	private function revoke_founder_rewards( Member $member ): void {
		foreach ( $this->founder_reward_values() as $reward_value ) {
			$this->rewards->revoke_reward_from_member(
				$member,
				$reward_value,
				'founder_reward_revoked',
				'Recompensa de fundador removida',
				__( 'As recompensas exclusivas de membro fundador foram removidas manualmente.', 'adam-membership' )
			);
		}
	}

	private function next_founder_number(): int {
		$next = 1;

		foreach ( $this->members->founding_members() as $founder ) {
			$next = max( $next, $founder->founder_number() + 1 );
		}

		return $next;
	}

	private function elapsed_label( int $completed_months, int $target_years ): string {
		$years  = intdiv( $completed_months, 12 );
		$months = $completed_months % 12;
		$target = $target_years . ' anos';

		if ( $months > 0 ) {
			return sprintf( '%d ano%s e %d mes%s / %s', $years, 1 === $years ? '' : 's', $months, 1 === $months ? '' : 'es', $target );
		}

		return sprintf( '%d ano%s / %s', $years, 1 === $years ? '' : 's', $target );
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private function record_history( Member $member, string $action_key, string $action_label, string $description, array $details ): void {
		$actor = wp_get_current_user();

		$this->history->create(
			array(
				'member_id'     => $member->user_id(),
				'member_number' => sanitize_text_field( (string) $member->field( 'numero_socio' ) ),
				'member_name'   => sanitize_text_field( $member->full_name() ),
				'member_email'  => sanitize_email( $member->email() ),
				'action_key'    => sanitize_key( $action_key ),
				'action_label'  => sanitize_text_field( $action_label ),
				'actor_type'    => current_user_can( 'manage_options' ) ? 'admin' : 'system',
				'actor_id'      => $actor instanceof \WP_User ? $actor->ID : 0,
				'actor_name'    => $actor instanceof \WP_User ? sanitize_text_field( (string) $actor->display_name ) : __( 'Sistema', 'adam-membership' ),
				'description'   => sanitize_text_field( $description ),
				'details'       => $details,
				'created_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);

		$this->logger->info(
			'Reconhecimento de socio atualizado.',
			array(
				'member_id'   => $member->user_id(),
				'action_key'  => $action_key,
				'details'     => $details,
			)
		);
	}
}
