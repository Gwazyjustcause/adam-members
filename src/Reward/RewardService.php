<?php
/**
 * Reward service.
 *
 * @package AdamMembership\Reward
 */

declare(strict_types=1);

namespace AdamMembership\Reward;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Points\PointsService;
use WP_Error;

/**
 * Coordinates rewards and redemption requests.
 */
final class RewardService {
	private RewardRepository $repository;
	private PointsService $points;
	private MemberRepository $members;
	private HistoryRepository $history;
	private Logger $logger;

	public function __construct( RewardRepository $repository, PointsService $points, MemberRepository $members, HistoryRepository $history, Logger $logger ) {
		$this->repository = $repository;
		$this->points     = $points;
		$this->members    = $members;
		$this->history    = $history;
		$this->logger     = $logger;
	}

	public function ensure_initial_catalogue(): void {
		$existing_rewards = array();

		foreach ( $this->repository->query_rewards() as $reward ) {
			$value = $reward->reward_value();

			if ( '' !== $value ) {
				$existing_rewards[ $value ] = $reward;
			}
		}

		$created = 0;
		$updated = 0;
		$now     = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		foreach ( $this->initial_catalogue() as $reward_data ) {
			$reward_value = sanitize_text_field( (string) ( $reward_data['reward_value'] ?? '' ) );

			if ( '' === $reward_value ) {
				continue;
			}

			$prepared = $this->sanitize_reward_data( $reward_data );

			if ( isset( $existing_rewards[ $reward_value ] ) ) {
				$current                = $existing_rewards[ $reward_value ];
				$prepared               = $this->merge_catalogue_reward( $current, $prepared );
				$prepared['created_at'] = $current->created_at();
				$prepared['updated_at'] = $now;
				$this->repository->update_reward( $current, $prepared );
				++$updated;
				continue;
			}

			$prepared['created_at'] = $now;
			$prepared['updated_at'] = $now;

			$this->repository->create_reward( $prepared );
			++$created;
		}

		if ( $created > 0 || $updated > 0 ) {
			$this->logger->info(
				'Catalogo inicial de recompensas sincronizado.',
				array(
					'created_rewards' => $created,
					'updated_rewards' => $updated,
				)
			);
		}
	}

	public function repository(): RewardRepository {
		return $this->repository;
	}

	public function find_reward_by_value( string $reward_value ): ?Reward {
		return $this->repository->find_reward_by_value( $reward_value );
	}

	public function grant_reward_to_member( Member $member, string $reward_value, string $action_key = 'reward_granted', string $action_label = 'Recompensa atribuida automaticamente', string $description = '' ): bool {
		$reward = $this->repository->find_reward_by_value( $reward_value );

		if ( null === $reward ) {
			return false;
		}

		if ( $this->member_owns_reward( $member, $reward ) ) {
			return true;
		}

		$now        = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$redemption = $this->repository->create_redemption(
			array(
				'reward_id'       => $reward->id(),
				'member_id'       => $member->user_id(),
				'reward_name'     => $reward->name(),
				'reward_type'     => $reward->type(),
				'points_cost'     => 0,
				'status'          => RewardRedemption::STATUS_DELIVERED,
				'created_at'      => $now,
				'approved_at'     => $now,
				'approved_by'     => 0,
				'delivered_at'    => $now,
				'delivered_by'    => 0,
				'points_entry_id' => 0,
				'revealed_reward' => $reward->is_mystery() ? $reward->mystery_reveal_text() : '',
			)
		);

		$this->record_history(
			$member->user_id(),
			$action_key,
			$action_label,
			'' !== $description ? $description : sprintf( __( 'A recompensa %s foi atribuida automaticamente.', 'adam-membership' ), $reward->name() ),
			array(
				'reward_id'     => $reward->id(),
				'reward_value'  => $reward->reward_value(),
				'redemption_id' => $redemption->id(),
			)
		);

		return true;
	}

	/**
	 * Remove a previously granted reward from a member.
	 */
	public function revoke_reward_from_member( Member $member, string $reward_value, string $action_key = 'reward_revoked', string $action_label = 'Recompensa removida', string $description = '' ): bool {
		$reward = $this->repository->find_reward_by_value( $reward_value );

		if ( null === $reward ) {
			return false;
		}

		$removed = $this->repository->delete_redemptions(
			array(
				'member_id' => $member->user_id(),
				'reward_id' => $reward->id(),
			)
		);

		if ( 0 === $removed ) {
			return false;
		}

		$this->record_history(
			$member->user_id(),
			$action_key,
			$action_label,
			'' !== $description ? $description : sprintf( __( 'A recompensa %s foi removida manualmente.', 'adam-membership' ), $reward->name() ),
			array(
				'reward_id'    => $reward->id(),
				'reward_value' => $reward->reward_value(),
				'removed'      => $removed,
			)
		);

		$this->logger->info(
			'Recompensa removida.',
			array(
				'member_id'     => $member->user_id(),
				'reward_id'     => $reward->id(),
				'reward_value'  => $reward->reward_value(),
				'removed_count' => $removed,
			)
		);

		return true;
	}

	/**
	 * @param array<string, mixed> $data Reward data.
	 * @param array<string, mixed> $file Uploaded image.
	 * @return Reward|WP_Error
	 */
	public function save_reward( array $data, array $file = array(), int $id = 0 ): Reward|WP_Error {
		$current = $id > 0 ? $this->repository->find_reward( $id ) : null;
		$prepared = $this->sanitize_reward_data( $data, $current );

		if ( '' === $prepared['name'] ) {
			return new WP_Error( 'adam_membership_reward_name_required', __( 'O nome da recompensa e obrigatorio.', 'adam-membership' ) );
		}

		if ( $prepared['points_cost'] < 0 ) {
			return new WP_Error( 'adam_membership_reward_points_invalid', __( 'O custo em pontos e invalido.', 'adam-membership' ) );
		}

		if ( $this->has_uploaded_file( $file ) ) {
			$image = $this->handle_image_upload( $file );

			if ( is_wp_error( $image ) ) {
				return $image;
			}

			$prepared['image_url'] = $image['image_url'];
		}

		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		if ( 0 === $id ) {
			$prepared['created_at'] = $now;
			$prepared['updated_at'] = $now;
			$reward                 = $this->repository->create_reward( $prepared );
			$this->record_history( 0, 'reward_created', __( 'Recompensa criada', 'adam-membership' ), $reward->name(), array( 'reward_id' => $reward->id() ) );
			$this->logger->info(
				'Recompensa criada.',
				array(
					'reward_id' => $reward->id(),
					'active'    => $reward->active(),
					'type'      => $reward->type(),
				)
			);

			return $reward;
		}

		if ( null === $current ) {
			return new WP_Error( 'adam_membership_reward_not_found', __( 'Recompensa nao encontrada.', 'adam-membership' ) );
		}

		$prepared['updated_at'] = $now;
		$reward                 = $this->repository->update_reward( $current, $prepared );
		$this->record_history( 0, 'reward_updated', __( 'Recompensa atualizada', 'adam-membership' ), $reward->name(), array( 'reward_id' => $reward->id() ) );
		$this->logger->info(
			'Recompensa atualizada.',
			array(
				'reward_id' => $reward->id(),
				'active'    => $reward->active(),
				'type'      => $reward->type(),
			)
		);

		return $reward;
	}

	public function delete_reward( int $reward_id ): void {
		$reward = $this->repository->find_reward( $reward_id );

		if ( null !== $reward ) {
			$this->record_history( 0, 'reward_deleted', __( 'Recompensa eliminada', 'adam-membership' ), $reward->name(), array( 'reward_id' => $reward->id() ) );
			$this->logger->info( 'Recompensa eliminada.', array( 'reward_id' => $reward->id() ) );
		}

		$this->repository->delete_reward( $reward_id );
	}

	/**
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, Reward>
	 */
	public function admin_rewards( array $filters = array() ): array {
		return $this->repository->query_rewards( $filters );
	}

	/**
	 * @return array<int, Reward>
	 */
	public function active_rewards(): array {
		return $this->repository->query_rewards( array( 'active' => true ) );
	}

	public function is_founder_reward( Reward|string $reward ): bool {
		return in_array( $this->reward_value_key( $reward ), array( 'title_founder', 'card_theme_founder', 'card_frame_founder_frame' ), true );
	}

	public function is_loyalty_reward( Reward|string $reward ): bool {
		return in_array(
			$this->reward_value_key( $reward ),
			array(
				'title_veterano_adam',
				'title_operador_experiente',
				'title_guardiao_adam',
				'title_lenda_adam',
				'card_theme_carbon_fiber',
				'card_theme_gold',
				'card_theme_legendary_loyalty',
				'card_frame_veteran_bronze',
				'card_frame_veteran_gold',
				'card_frame_legendary_loyalty',
			),
			true
		);
	}

	public function is_seasonal_reward( Reward|string $reward ): bool {
		return in_array(
			$this->reward_value_key( $reward ),
			array(
				'card_theme_christmas_edition',
				'card_theme_halloween_edition',
				'card_theme_anniversary_edition',
				'card_theme_summer_event_edition',
			),
			true
		);
	}

	/**
	 * @return array<int, Reward>
	 */
	public function member_catalogue( Member $member ): array {
		$rewards = $this->active_rewards();

		usort(
			$rewards,
			fn ( Reward $left, Reward $right ): int => $this->member_reward_rank( $member, $left ) <=> $this->member_reward_rank( $member, $right )
		);

		return $rewards;
	}

	/**
	 * @return RewardRedemption|WP_Error
	 */
	public function redeem( Member $member, int $reward_id ): RewardRedemption|WP_Error {
		$reward = $this->repository->find_reward( $reward_id );

		if ( null === $reward || ! $reward->is_visible() ) {
			return new WP_Error( 'adam_membership_reward_unavailable', __( 'Esta recompensa nao esta disponivel neste momento.', 'adam-membership' ) );
		}

		if ( ! $reward->redeemable() ) {
			return new WP_Error( 'adam_membership_reward_not_redeemable', __( 'Esta recompensa nao pode ser resgatada diretamente.', 'adam-membership' ) );
		}

		if ( $this->points->current_balance( $member ) < $reward->points_cost() ) {
			return new WP_Error( 'adam_membership_reward_not_enough_points', __( 'Nao tens pontos suficientes para resgatar esta recompensa.', 'adam-membership' ) );
		}

		if ( $reward->is_single_claim() ) {
			if ( $this->member_owns_reward( $member, $reward ) ) {
				return new WP_Error( 'adam_membership_reward_already_owned', __( 'Ja tens esta recompensa desbloqueada.', 'adam-membership' ) );
			}

			if ( $this->member_has_pending_request( $member, $reward ) ) {
				return new WP_Error( 'adam_membership_reward_pending_request', __( 'Ja existe um pedido pendente para esta recompensa.', 'adam-membership' ) );
			}
		}

		$now        = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$redemption = $this->repository->create_redemption(
			array(
				'reward_id'       => $reward->id(),
				'member_id'       => $member->user_id(),
				'reward_name'     => $reward->name(),
				'reward_type'     => $reward->type(),
				'points_cost'     => $reward->points_cost(),
				'status'          => RewardRedemption::STATUS_PENDING,
				'created_at'      => $now,
				'revealed_reward' => $reward->is_mystery() ? $reward->mystery_reveal_text() : '',
			)
		);

		$this->record_history(
			$member->user_id(),
			'reward_requested',
			__( 'Pedido de recompensa', 'adam-membership' ),
			sprintf(
				/* translators: %s: reward name */
				__( 'Pedido criado para a recompensa %s.', 'adam-membership' ),
				$reward->name()
			),
			array(
				'reward_id'     => $reward->id(),
				'redemption_id' => $redemption->id(),
				'points_cost'   => $reward->points_cost(),
			)
		);

		$this->logger->info(
			'Pedido de recompensa criado.',
			array(
				'reward_id'     => $reward->id(),
				'redemption_id' => $redemption->id(),
				'member_id'     => $member->user_id(),
			)
		);

		if ( ! $reward->approval_required() ) {
			$approved = $this->approve_redemption( $redemption->id(), $member->user_id(), true );

			if ( is_wp_error( $approved ) ) {
				return $approved;
			}

			return $approved;
		}

		return $redemption;
	}

	/**
	 * @return RewardRedemption|WP_Error
	 */
	public function approve_redemption( int $redemption_id, int $actor_user_id, bool $automatic = false ): RewardRedemption|WP_Error {
		$redemption = $this->repository->find_redemption( $redemption_id );

		if ( null === $redemption ) {
			return new WP_Error( 'adam_membership_redemption_not_found', __( 'Pedido de recompensa nao encontrado.', 'adam-membership' ) );
		}

		if ( RewardRedemption::STATUS_PENDING !== $redemption->status() && ! $automatic ) {
			return new WP_Error( 'adam_membership_redemption_not_pending', __( 'Este pedido ja nao esta pendente.', 'adam-membership' ) );
		}

		$reward = $this->repository->find_reward( $redemption->reward_id() );
		$member = $this->members->find( $redemption->member_id() );

		if ( null === $reward || null === $member ) {
			return new WP_Error( 'adam_membership_redemption_missing_context', __( 'Nao foi possivel validar o pedido de recompensa.', 'adam-membership' ) );
		}

		if ( $reward->is_single_claim() && $this->member_owns_reward( $member, $reward, $redemption->id() ) ) {
			return new WP_Error( 'adam_membership_reward_already_owned', __( 'O socio ja tem esta recompensa desbloqueada.', 'adam-membership' ) );
		}

		$points_entry = $this->points->redeem_reward_points(
			$member,
			$reward->points_cost(),
			sprintf(
				/* translators: %s: reward name */
				__( 'Resgate da recompensa %s', 'adam-membership' ),
				$reward->name()
			),
			$reward->id(),
			$actor_user_id
		);

		if ( is_wp_error( $points_entry ) ) {
			return $points_entry;
		}

		$updated = $this->repository->update_redemption(
			$redemption,
			array(
				'status'          => RewardRedemption::STATUS_APPROVED,
				'approved_at'     => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'approved_by'     => $actor_user_id,
				'points_entry_id' => $points_entry->id(),
				'revealed_reward' => $reward->is_mystery() ? $reward->mystery_reveal_text() : $redemption->revealed_reward(),
			)
		);

		$this->record_history(
			$member->user_id(),
			$automatic ? 'automatic_reward_approved' : 'reward_approved',
			$automatic ? __( 'Recompensa aprovada automaticamente', 'adam-membership' ) : __( 'Recompensa aprovada', 'adam-membership' ),
			sprintf(
				/* translators: %s: reward name */
				__( 'A recompensa %s foi aprovada.', 'adam-membership' ),
				$reward->name()
			),
			array(
				'reward_id'       => $reward->id(),
				'redemption_id'   => $updated->id(),
				'points_cost'     => $reward->points_cost(),
				'points_entry_id' => $points_entry->id(),
			)
		);

		$this->logger->info(
			'Recompensa aprovada.',
			array(
				'reward_id'       => $reward->id(),
				'redemption_id'   => $updated->id(),
				'member_id'       => $member->user_id(),
				'points_entry_id' => $points_entry->id(),
				'automatic'       => $automatic,
			)
		);

		return $updated;
	}

	/**
	 * @return RewardRedemption|WP_Error
	 */
	public function reject_redemption( int $redemption_id, int $actor_user_id, string $reason = '' ): RewardRedemption|WP_Error {
		$redemption = $this->repository->find_redemption( $redemption_id );

		if ( null === $redemption ) {
			return new WP_Error( 'adam_membership_redemption_not_found', __( 'Pedido de recompensa nao encontrado.', 'adam-membership' ) );
		}

		$updated = $this->repository->update_redemption(
			$redemption,
			array(
				'status'           => RewardRedemption::STATUS_REJECTED,
				'rejected_at'      => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'rejected_by'      => $actor_user_id,
				'rejection_reason' => sanitize_textarea_field( $reason ),
			)
		);

		$this->record_history(
			$redemption->member_id(),
			'reward_rejected',
			__( 'Recompensa rejeitada', 'adam-membership' ),
			sprintf(
				/* translators: %s: reward name */
				__( 'O pedido da recompensa %s foi rejeitado.', 'adam-membership' ),
				$redemption->reward_name()
			),
			array(
				'redemption_id'    => $updated->id(),
				'reward_id'        => $updated->reward_id(),
				'rejection_reason' => sanitize_textarea_field( $reason ),
			)
		);

		$this->logger->info(
			'Recompensa rejeitada.',
			array(
				'reward_id'     => $updated->reward_id(),
				'redemption_id' => $updated->id(),
				'member_id'     => $updated->member_id(),
			)
		);

		return $updated;
	}

	/**
	 * @return RewardRedemption|WP_Error
	 */
	public function mark_delivered( int $redemption_id, int $actor_user_id ): RewardRedemption|WP_Error {
		$redemption = $this->repository->find_redemption( $redemption_id );

		if ( null === $redemption ) {
			return new WP_Error( 'adam_membership_redemption_not_found', __( 'Pedido de recompensa nao encontrado.', 'adam-membership' ) );
		}

		$updated = $this->repository->update_redemption(
			$redemption,
			array(
				'status'       => RewardRedemption::STATUS_DELIVERED,
				'delivered_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'delivered_by' => $actor_user_id,
			)
		);

		$this->record_history(
			$redemption->member_id(),
			'reward_delivered',
			__( 'Recompensa entregue', 'adam-membership' ),
			sprintf(
				/* translators: %s: reward name */
				__( 'A recompensa %s foi marcada como entregue.', 'adam-membership' ),
				$redemption->reward_name()
			),
			array(
				'redemption_id' => $updated->id(),
				'reward_id'     => $updated->reward_id(),
			)
		);

		$this->logger->info(
			'Recompensa marcada como entregue.',
			array(
				'reward_id'     => $updated->reward_id(),
				'redemption_id' => $updated->id(),
				'member_id'     => $updated->member_id(),
			)
		);

		return $updated;
	}

	/**
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, RewardRedemption>
	 */
	public function admin_redemptions( array $filters = array() ): array {
		return $this->repository->query_redemptions( $filters );
	}

	/**
	 * @return array<int, RewardRedemption>
	 */
	public function member_redemptions( Member $member, int $limit = 0 ): array {
		$filters = array( 'member_id' => $member->user_id() );

		if ( $limit > 0 ) {
			$filters['limit'] = $limit;
		}

		return $this->repository->query_redemptions( $filters );
	}

	/**
	 * @return array<int, RewardRedemption>
	 */
	public function recent_redeemed_rewards( Member $member, int $limit = 3 ): array {
		return array_slice(
			array_values(
				array_filter(
					$this->repository->query_redemptions( array( 'member_id' => $member->user_id(), 'limit' => max( $limit * 3, 10 ) ) ),
					static fn ( RewardRedemption $redemption ): bool => in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true )
				)
			),
			0,
			max( 1, $limit )
		);
	}

	public function member_owns_reward( Member $member, Reward $reward, int $exclude_redemption_id = 0 ): bool {
		foreach ( $this->repository->query_redemptions( array( 'member_id' => $member->user_id(), 'reward_id' => $reward->id() ) ) as $redemption ) {
			if ( $exclude_redemption_id > 0 && $redemption->id() === $exclude_redemption_id ) {
				continue;
			}

			if ( in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) {
				return true;
			}
		}

		return false;
	}

	public function member_has_pending_request( Member $member, Reward $reward ): bool {
		foreach ( $this->repository->query_redemptions( array( 'member_id' => $member->user_id(), 'reward_id' => $reward->id(), 'status' => RewardRedemption::STATUS_PENDING ) ) as $redemption ) {
			return true;
		}

		return false;
	}

	public function member_can_redeem( Member $member, Reward $reward ): bool {
		if ( ! $reward->is_visible() || ! $reward->redeemable() ) {
			return false;
		}

		if ( $this->points->current_balance( $member ) < $reward->points_cost() ) {
			return false;
		}

		if ( $reward->is_single_claim() && ( $this->member_owns_reward( $member, $reward ) || $this->member_has_pending_request( $member, $reward ) ) ) {
			return false;
		}

		return true;
	}

	public function public_reward_description( Reward $reward, ?RewardRedemption $owned_redemption = null ): string {
		if ( ! $reward->is_mystery() ) {
			return $reward->description();
		}

		if ( $owned_redemption instanceof RewardRedemption && '' !== $owned_redemption->revealed_reward() ) {
			return $owned_redemption->revealed_reward();
		}

		return __( 'O conteudo desta recompensa sera revelado depois do resgate.', 'adam-membership' );
	}

	/**
	 * @return array<int, string>
	 */
	public function categories(): array {
		return array(
			'Cartao Digital',
			'Titulos',
			'Reconhecimento',
			'Surpresa',
			'Sorteios',
			'Fisicas',
			'Experiencias',
			'Outras',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function type_labels(): array {
		return array(
			Reward::TYPE_PERMANENT_UNLOCK => __( 'Desbloqueio permanente', 'adam-membership' ),
			Reward::TYPE_CONSUMABLE       => __( 'Consumivel', 'adam-membership' ),
			Reward::TYPE_MYSTERY_REWARD   => __( 'Recompensa misterio', 'adam-membership' ),
			Reward::TYPE_PHYSICAL_REWARD  => __( 'Recompensa fisica', 'adam-membership' ),
			Reward::TYPE_MANUAL_REWARD    => __( 'Recompensa manual', 'adam-membership' ),
			Reward::TYPE_DIGITAL_COSMETIC => __( 'Cosmetica digital', 'adam-membership' ),
			Reward::TYPE_RAFFLE_TICKET    => __( 'Bilhete extra de sorteio', 'adam-membership' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function rarity_labels(): array {
		return array(
			Reward::RARITY_COMMON          => __( 'Comum', 'adam-membership' ),
			Reward::RARITY_UNCOMMON        => __( 'Incomum', 'adam-membership' ),
			Reward::RARITY_RARE            => __( 'Rara', 'adam-membership' ),
			Reward::RARITY_EPIC            => __( 'Epica', 'adam-membership' ),
			Reward::RARITY_LEGENDARY       => __( 'Lendaria', 'adam-membership' ),
			Reward::RARITY_LIMITED_EDITION => __( 'Edicao limitada', 'adam-membership' ),
		);
	}

	/**
	 * Get the resolved visual style for a reward card.
	 *
	 * @return array<string, mixed>
	 */
	public function reward_visual_style( Reward $reward ): array {
		$defaults = $this->default_reward_visual_style( $reward->rarity(), $reward->category() );
		$raw_style = $reward->visual_style();
		$stored = $this->sanitize_visual_style( $raw_style, $defaults, $raw_style );
		$this->maybe_migrate_reward_visual_style( $reward, $raw_style, $stored );
		$preset  = $this->preset_visual_style_for_reward( sanitize_key( $reward->reward_value() ) );
		$subtype = (string) ( $stored['card_subtype'] ?? $preset['card_subtype'] ?? '' );

		if ( 'frame' === $subtype ) {
			$subtype = 'card_style';
		}

		if ( '' === $subtype ) {
			$value   = strtolower( $reward->reward_value() );
			$name    = strtolower( $reward->name() );
			$subtype = str_contains( $value, 'frame' ) || str_contains( $value, 'style' ) || str_contains( $name, 'frame' ) || str_contains( $name, 'moldura' ) || str_contains( $name, 'estilo' ) ? 'card_style' : 'background';
		}

		$preset_background = is_array( $preset['background'] ?? null ) ? (array) $preset['background'] : array();
		$preset_style      = is_array( $preset['style'] ?? null ) ? (array) $preset['style'] : array();
		$runtime  = array_merge(
			$defaults,
			'card_style' === $subtype ? $preset_style : $preset_background,
			'card_style' === $subtype ? (array) ( $stored['style'] ?? array() ) : (array) ( $stored['background'] ?? array() ),
			array(
				'card_subtype' => $subtype,
				'image_url'    => $reward->image_url(),
				'background'   => array_merge( $preset_background, is_array( $stored['background'] ?? null ) ? $stored['background'] : array() ),
				'style'        => array_merge( $preset_style, is_array( $stored['style'] ?? null ) ? $stored['style'] : array() ),
				'title_badge'  => array_merge(
					is_array( $defaults['title_badge'] ?? null ) ? (array) $defaults['title_badge'] : $this->default_title_badge_style( $defaults ),
					is_array( $stored['title_badge'] ?? null ) ? (array) $stored['title_badge'] : array()
				),
			)
		);

		$runtime['shapes'] = $this->sanitize_visual_shapes( $runtime['shapes'] ?? array() );

		return $this->complete_reward_visual_style( $runtime, $reward->rarity(), $reward->category() );
	}

	/**
	 * Get default editor values for a reward card style.
	 *
	 * @return array<string, mixed>
	 */
	public function default_reward_visual_style( string $rarity = Reward::RARITY_COMMON, string $category = '' ): array {
		$rarity = sanitize_key( $rarity );

		$palette = match ( $rarity ) {
			Reward::RARITY_UNCOMMON => array(
				'background_color'           => '#0d1f16',
				'background_color_secondary' => '#1c5a33',
				'accent_color'               => '#6ee7b7',
				'frame_color'                => '#4ade80',
				'border_color'               => '#4ade80',
				'text_color'                 => '#f4fff7',
				'muted_text_color'           => 'rgba(236, 253, 245, 0.78)',
			),
			Reward::RARITY_RARE => array(
				'background_color'           => '#10233d',
				'background_color_secondary' => '#1d4f91',
				'accent_color'               => '#93c5fd',
				'frame_color'                => '#60a5fa',
				'border_color'               => '#60a5fa',
				'text_color'                 => '#f2f8ff',
				'muted_text_color'           => 'rgba(219, 234, 254, 0.78)',
			),
			Reward::RARITY_EPIC => array(
				'background_color'           => '#261141',
				'background_color_secondary' => '#6d28d9',
				'accent_color'               => '#d8b4fe',
				'frame_color'                => '#c084fc',
				'border_color'               => '#c084fc',
				'text_color'                 => '#faf5ff',
				'muted_text_color'           => 'rgba(243, 232, 255, 0.78)',
			),
			Reward::RARITY_LEGENDARY => array(
				'background_color'           => '#4c2b06',
				'background_color_secondary' => '#c27a10',
				'accent_color'               => '#fde68a',
				'frame_color'                => '#fbbf24',
				'border_color'               => '#fbbf24',
				'text_color'                 => '#fff9eb',
				'muted_text_color'           => 'rgba(254, 240, 138, 0.82)',
			),
			Reward::RARITY_LIMITED_EDITION => array(
				'background_color'           => '#40111a',
				'background_color_secondary' => '#b91c1c',
				'accent_color'               => '#fca5a5',
				'frame_color'                => '#ef4444',
				'border_color'               => '#ef4444',
				'text_color'                 => '#fff5f5',
				'muted_text_color'           => 'rgba(254, 226, 226, 0.82)',
			),
			default => array(
				'background_color'           => '#143826',
				'background_color_secondary' => '#215b39',
				'accent_color'               => '#86efac',
				'frame_color'                => '#9ca3af',
				'border_color'               => '#9ca3af',
				'text_color'                 => '#f8fafc',
				'muted_text_color'           => 'rgba(226, 232, 240, 0.78)',
			),
		};

		$pattern = 'grid';

		if ( str_contains( strtolower( $category ), 'cartao' ) ) {
			$pattern = Reward::RARITY_COMMON === $rarity ? 'diagonal' : 'carbon';
		}

		if ( str_contains( strtolower( $category ), 'titulos' ) ) {
			$pattern = 'dots';
		}

		return array_merge(
			$palette,
			array(
				'card_subtype'                => 'background',
				'background_mode'             => 'gradient',
				'background_color_tertiary'   => $palette['background_color'],
				'gradient_angle'              => 135,
				'gradient_origin'             => 'center',
				'gradient_midpoint'           => 52,
				'gradient_stop_secondary'     => 52,
				'gradient_stop_tertiary'      => 100,
				'gradient_opacity'            => 100,
				'frame_enabled'               => false,
				'frame_color'                 => $palette['frame_color'],
				'frame_highlight_color'       => '#ffffff',
				'frame_thickness'             => 0,
				'border_width'                => 0,
				'border_radius'               => 28,
				'frame_style'                 => 'none',
				'frame_gradient_color_1'      => $palette['frame_color'],
				'frame_gradient_color_2'      => '#ffd700',
				'frame_gradient_color_3'      => '#146aff',
				'frame_inner_color'           => '#ffffff',
				'frame_shine_intensity'       => 0,
				'frame_gradient_color'        => '#60a5fa',
				'frame_gradient_angle'        => 135,
				'content_padding'             => 28,
				'content_gap'                 => 20,
				'meta_align'                  => 'space-between',
				'stats_align'                 => 'left',
				'background_image_url'        => '',
				'background_image_opacity'    => 18,
				'background_image_position'   => 'center',
				'background_image_size'       => 100,
				'background_image_blend_mode' => 'screen',
				'pattern'                     => $pattern,
				'pattern_color'               => $palette['accent_color'],
				'pattern_background_color'    => $palette['background_color'],
				'pattern_opacity'             => Reward::RARITY_COMMON === $rarity ? 10 : 18,
				'pattern_scale'               => 24,
				'pattern_density'             => 2,
				'pattern_rotation'            => 0,
				'pattern_spacing'             => 24,
				'card_image_opacity'          => 22,
				'card_image_position'         => 'top-right',
				'card_image_size'             => 36,
				'card_image_layer'            => 'overlay',
				'member_name_color'           => $palette['text_color'],
				'member_name_weight'          => 900,
				'title_width'                 => 100,
				'description_color'           => $palette['muted_text_color'],
				'description_size'            => 16,
				'description_weight'          => 500,
				'description_align'           => 'left',
				'description_shadow'          => 0,
				'description_width'           => 66,
				'shapes'                      => $this->default_visual_shapes( $rarity ),
				'title_badge'                 => $this->default_title_badge_style( $palette ),
			)
		);
	}

	/**
	 * Provide curated preset defaults for built-in digital card rewards.
	 *
	 * @return array<string, mixed>
	 */
	private function preset_visual_style_for_reward( string $reward_value ): array {
		return match ( $reward_value ) {
			'card_theme_carbon_fiber' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#091912',
					'background_color_secondary'  => '#0d2a1c',
					'background_color_tertiary'   => '#112a1f',
					'gradient_angle'              => 135,
					'gradient_origin'             => 'top-right',
					'gradient_stop_secondary'     => 46,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'carbon',
					'pattern_color'               => '#2ecc71',
					'pattern_background_color'    => '#08140f',
					'pattern_opacity'             => 30,
					'pattern_scale'               => 18,
					'pattern_density'             => 3,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 18,
				),
			),
			'card_theme_gold' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#5a3a09',
					'background_color_secondary'  => '#d3a52b',
					'background_color_tertiary'   => '#7a5310',
					'gradient_angle'              => 128,
					'gradient_origin'             => 'top-left',
					'gradient_stop_secondary'     => 44,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'diagonal',
					'pattern_color'               => '#f6d365',
					'pattern_background_color'    => '#5a3a09',
					'pattern_opacity'             => 18,
					'pattern_scale'               => 28,
					'pattern_density'             => 2,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 26,
				),
			),
			'card_theme_woodland' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#132415',
					'background_color_secondary'  => '#284f2d',
					'background_color_tertiary'   => '#0f1d14',
					'gradient_angle'              => 145,
					'gradient_origin'             => 'center',
					'gradient_stop_secondary'     => 48,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'diagonal',
					'pattern_color'               => '#5e8a49',
					'pattern_background_color'    => '#122217',
					'pattern_opacity'             => 16,
					'pattern_scale'               => 30,
					'pattern_density'             => 3,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 24,
				),
			),
			'card_theme_flecktarn' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#1b2617',
					'background_color_secondary'  => '#3d4f28',
					'background_color_tertiary'   => '#1f2818',
					'gradient_angle'              => 138,
					'gradient_origin'             => 'top',
					'gradient_stop_secondary'     => 52,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'dots',
					'pattern_color'               => '#7b8f4b',
					'pattern_background_color'    => '#212b17',
					'pattern_opacity'             => 22,
					'pattern_scale'               => 22,
					'pattern_density'             => 4,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 18,
				),
			),
			'card_theme_desert' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#7f6847',
					'background_color_secondary'  => '#c9ab72',
					'background_color_tertiary'   => '#8d7147',
					'gradient_angle'              => 135,
					'gradient_origin'             => 'right',
					'gradient_stop_secondary'     => 42,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'grid',
					'pattern_color'               => '#e6d0a5',
					'pattern_background_color'    => '#7b6443',
					'pattern_opacity'             => 12,
					'pattern_scale'               => 30,
					'pattern_density'             => 2,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 26,
				),
			),
			'card_theme_urban' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#1b2430',
					'background_color_secondary'  => '#445168',
					'background_color_tertiary'   => '#151c25',
					'gradient_angle'              => 135,
					'gradient_origin'             => 'bottom-right',
					'gradient_stop_secondary'     => 48,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'grid',
					'pattern_color'               => '#8b98ab',
					'pattern_background_color'    => '#151d27',
					'pattern_opacity'             => 14,
					'pattern_scale'               => 26,
					'pattern_density'             => 2,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 22,
				),
			),
			'card_theme_anniversary_edition' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#12351f',
					'background_color_secondary'  => '#22a55b',
					'background_color_tertiary'   => '#0f2b1a',
					'gradient_angle'              => 120,
					'gradient_origin'             => 'top-left',
					'gradient_stop_secondary'     => 50,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'grid',
					'pattern_color'               => '#c7f9d4',
					'pattern_background_color'    => '#12351f',
					'pattern_opacity'             => 14,
					'pattern_scale'               => 24,
					'pattern_density'             => 2,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 22,
				),
			),
			'card_theme_christmas_edition' => array(
				'card_subtype' => 'background',
				'background'   => array(
					'background_mode'             => 'gradient',
					'background_color'            => '#17391f',
					'background_color_secondary'  => '#a31e2e',
					'background_color_tertiary'   => '#132f1a',
					'gradient_angle'              => 135,
					'gradient_origin'             => 'top-right',
					'gradient_stop_secondary'     => 56,
					'gradient_stop_tertiary'      => 100,
					'gradient_opacity'            => 100,
					'pattern'                     => 'dots',
					'pattern_color'               => '#f6d365',
					'pattern_background_color'    => '#17391f',
					'pattern_opacity'             => 18,
					'pattern_scale'               => 18,
					'pattern_density'             => 3,
					'pattern_rotation'            => 0,
					'pattern_spacing'             => 20,
				),
			),
			'card_frame_standard_silver' => array(
				'card_subtype' => 'card_style',
				'style'        => array(
					'border_color'        => '#d8dee9',
					'border_width'        => 10,
					'frame_style'         => 'simple',
				),
			),
			'card_frame_tactical_green' => array(
				'card_subtype' => 'card_style',
				'style'        => array(
					'border_color'        => '#2fbf71',
					'border_width'        => 11,
					'frame_style'         => 'simple',
				),
			),
			'card_frame_carbon_edge' => array(
				'card_subtype' => 'card_style',
				'style'        => array(
					'border_color'        => '#7fb7aa',
					'border_width'        => 12,
					'frame_style'         => 'metallic',
					'frame_inner_color'   => '#d1fae5',
					'frame_shine_intensity' => 34,
				),
			),
			'card_frame_golden_honor' => array(
				'card_subtype' => 'card_style',
				'style'        => array(
					'border_color'        => '#f4c84b',
					'border_width'        => 12,
					'frame_style'         => 'metallic',
					'frame_inner_color'   => '#fff2b2',
					'frame_shine_intensity' => 48,
					'badge_style'         => 'glow',
					'rarity_effect'       => 'metallic',
				),
			),
			'card_frame_diamond_frame' => array(
				'card_subtype' => 'card_style',
				'style'        => array(
					'border_color'        => '#ecfeff',
					'border_width'        => 12,
					'frame_style'         => 'gradient',
					'frame_inner_color'   => '#ffffff',
					'frame_gradient_color'=> '#93c5fd',
					'frame_gradient_angle'=> 135,
					'badge_style'         => 'glow',
					'rarity_effect'       => 'glow',
				),
			),
			default => array(),
		};
	}

	/**
	 * Build a reward card presentation payload for renderers.
	 *
	 * @return array<string, mixed>
	 */
	public function reward_card_presentation( Reward $reward ): array {
		$style            = $this->complete_reward_visual_style( $this->reward_visual_style( $reward ), $reward->rarity(), $reward->category() );
		$background_value = $this->reward_background_value( $style );
		$badge_style      = sanitize_html_class( (string) $style['badge_style'] );
		$effect           = sanitize_html_class( (string) $style['rarity_effect'] );
		$image_position   = sanitize_html_class( (string) $style['card_image_position'] );
		$frame_style      = sanitize_html_class( (string) $style['frame_style'] );
		$image_layer      = sanitize_html_class( (string) $style['card_image_layer'] );

		return array(
			'style'                => $style,
			'inline_style'         => $this->reward_card_inline_style( $style, $background_value ),
			'pattern_class'        => 'adam-reward-card__pattern--' . sanitize_html_class( (string) $style['pattern'] ),
			'badge_style_class'    => 'adam-reward-card--badge-' . $badge_style,
			'effect_class'         => 'adam-reward-card--effect-' . ( 'auto' === $effect ? sanitize_html_class( $reward->rarity() ) : $effect ),
			'frame_style_class'    => 'adam-reward-card--frame-' . $frame_style,
			'corner_style_class'   => '',
			'image_position_class' => 'adam-reward-card__art--' . $image_position,
			'image_layer_class'    => 'adam-reward-card__art--layer-' . $image_layer,
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function redemption_status_labels(): array {
		return array(
			RewardRedemption::STATUS_PENDING   => __( 'Pendente', 'adam-membership' ),
			RewardRedemption::STATUS_APPROVED  => __( 'Aprovada', 'adam-membership' ),
			RewardRedemption::STATUS_REJECTED  => __( 'Rejeitada', 'adam-membership' ),
			RewardRedemption::STATUS_DELIVERED => __( 'Entregue', 'adam-membership' ),
		);
	}

	/**
	 * @param array<string, mixed> $data Raw reward data.
	 * @return array<string, mixed>
	 */
	private function sanitize_reward_data( array $data, ?Reward $current = null ): array {
		$type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : Reward::TYPE_PERMANENT_UNLOCK;

		if ( ! in_array( $type, Reward::types(), true ) ) {
			$type = Reward::TYPE_PERMANENT_UNLOCK;
		}

		$rarity = isset( $data['rarity'] ) ? sanitize_key( (string) $data['rarity'] ) : Reward::RARITY_COMMON;

		if ( ! in_array( $rarity, Reward::rarities(), true ) ) {
			$rarity = Reward::RARITY_COMMON;
		}

		$category = isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : 'Outras';

		return array(
			'name'                => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'description'         => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
			'category'            => $category,
			'type'                => $type,
			'rarity'              => $rarity,
			'points_cost'         => max( 0, absint( $data['points_cost'] ?? 0 ) ),
			'image_url'           => isset( $data['image_url'] ) ? esc_url_raw( (string) $data['image_url'] ) : '',
			'availability_label'  => isset( $data['availability_label'] ) ? sanitize_text_field( (string) $data['availability_label'] ) : __( 'Disponivel', 'adam-membership' ),
			'active'              => ! empty( $data['active'] ),
			'redeemable'          => array_key_exists( 'redeemable', $data ) ? ! empty( $data['redeemable'] ) : Reward::TYPE_MANUAL_REWARD !== $type,
			'approval_required'   => ! empty( $data['approval_required'] ),
			'mystery_reveal_text' => isset( $data['mystery_reveal_text'] ) ? sanitize_textarea_field( (string) $data['mystery_reveal_text'] ) : '',
			'reward_value'        => isset( $data['reward_value'] ) ? sanitize_text_field( (string) $data['reward_value'] ) : '',
			'visual_style'        => $this->sanitize_visual_style(
				$data['visual_style'] ?? array(),
				$this->default_reward_visual_style( $rarity, $category ),
				$current instanceof Reward ? $current->visual_style() : array()
			),
		);
	}

	/**
	 * Sanitize reward visual style payloads.
	 *
	 * @param mixed $style Raw style data.
	 * @return array<string, mixed>
	 */
	private function sanitize_visual_style( mixed $style, array $defaults = array(), mixed $existing_style = array() ): array {
		if ( is_string( $style ) ) {
			$decoded = json_decode( $style, true );
			$style   = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_string( $existing_style ) ) {
			$decoded_existing = json_decode( $existing_style, true );
			$existing_style   = is_array( $decoded_existing ) ? $decoded_existing : array();
		}

		if ( ! is_array( $style ) ) {
			$style = array();
		}

		if ( ! is_array( $existing_style ) ) {
			$existing_style = array();
		}

		if ( array() === $defaults ) {
			$defaults = $this->default_reward_visual_style();
		}

		$has_explicit_subtype   = isset( $style['card_subtype'] );
		$existing_grouped       = is_array( $existing_style['background'] ?? null ) || is_array( $existing_style['style'] ?? null );
		$existing_subtype       = isset( $existing_style['card_subtype'] ) ? sanitize_key( (string) $existing_style['card_subtype'] ) : 'background';
		$card_subtype           = $has_explicit_subtype ? sanitize_key( (string) $style['card_subtype'] ) : $existing_subtype;

		if ( 'frame' === $card_subtype ) {
			$card_subtype = 'card_style';
		}

		if ( ! in_array( $card_subtype, array( 'background', 'card_style' ), true ) ) {
			$card_subtype = 'background';
		}

		$existing_background = is_array( $existing_style['background'] ?? null ) ? (array) $existing_style['background'] : ( $existing_grouped ? array() : $existing_style );
		$existing_card_style = is_array( $existing_style['style'] ?? null ) ? (array) $existing_style['style'] : ( $existing_grouped ? array() : $existing_style );
		$existing_title_badge = is_array( $existing_style['title_badge'] ?? null ) ? (array) $existing_style['title_badge'] : $this->extract_legacy_title_badge_source( $existing_style );
		$background_source   = is_array( $style['background'] ?? null ) ? array_merge( $existing_background, (array) $style['background'] ) : array_merge( $existing_background, $style );
		$style_source        = is_array( $style['style'] ?? null ) ? array_merge( $existing_card_style, (array) $style['style'] ) : array_merge( $existing_card_style, $style );
		$title_badge_source  = is_array( $style['title_badge'] ?? null ) ? array_merge( $existing_title_badge, (array) $style['title_badge'] ) : array_merge( $existing_title_badge, $this->extract_legacy_title_badge_source( $style ) );
		$background          = $this->sanitize_background_style_config( $background_source, $defaults );
		$card_style          = $this->sanitize_card_style_config( $style_source, $defaults );
		$title_badge         = $this->sanitize_title_badge_config( $title_badge_source, $defaults );

		return array(
			'card_subtype' => $card_subtype,
			'background'   => 'background' === $card_subtype ? $background : $existing_background,
			'style'        => 'card_style' === $card_subtype ? $card_style : $existing_card_style,
			'title_badge'  => $title_badge,
		);
	}

	/**
	 * @param array<string, mixed> $style Raw style data.
	 * @param array<string, mixed> $defaults Default runtime payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_background_style_config( array $style, array $defaults ): array {
		$background_mode        = isset( $style['background_mode'] ) ? sanitize_key( (string) $style['background_mode'] ) : (string) $defaults['background_mode'];
		$gradient_origin        = isset( $style['gradient_origin'] ) ? sanitize_key( (string) $style['gradient_origin'] ) : (string) $defaults['gradient_origin'];
		$pattern                = isset( $style['pattern'] ) ? sanitize_key( (string) $style['pattern'] ) : (string) $defaults['pattern'];
		$background_image_pos   = isset( $style['background_image_position'] ) ? sanitize_key( (string) $style['background_image_position'] ) : (string) $defaults['background_image_position'];
		$background_image_blend = isset( $style['background_image_blend_mode'] ) ? sanitize_key( (string) $style['background_image_blend_mode'] ) : (string) $defaults['background_image_blend_mode'];

		if ( ! in_array( $background_mode, array( 'solid', 'gradient', 'image' ), true ) ) {
			$background_mode = (string) $defaults['background_mode'];
		}

		if ( ! in_array( $gradient_origin, array( 'top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right' ), true ) ) {
			$gradient_origin = (string) $defaults['gradient_origin'];
		}

		if ( ! in_array( $pattern, array( 'none', 'grid', 'carbon', 'diagonal', 'dots' ), true ) ) {
			$pattern = (string) $defaults['pattern'];
		}

		if ( ! in_array( $background_image_pos, array( 'top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right' ), true ) ) {
			$background_image_pos = (string) $defaults['background_image_position'];
		}

		if ( ! in_array( $background_image_blend, array( 'normal', 'screen', 'overlay', 'soft-light', 'multiply' ), true ) ) {
			$background_image_blend = (string) $defaults['background_image_blend_mode'];
		}

		return array(
			'background_mode'             => $background_mode,
			'background_color'            => $this->sanitize_color_value( $style['background_color'] ?? $defaults['background_color'] ),
			'background_color_secondary'  => $this->sanitize_color_value( $style['background_color_secondary'] ?? $defaults['background_color_secondary'] ),
			'background_color_tertiary'   => $this->sanitize_color_value( $style['background_color_tertiary'] ?? $defaults['background_color_tertiary'] ),
			'gradient_angle'              => max( 0, min( 360, (int) ( $style['gradient_angle'] ?? $defaults['gradient_angle'] ) ) ),
			'gradient_origin'             => $gradient_origin,
			'gradient_midpoint'           => max( 0, min( 100, (int) ( $style['gradient_midpoint'] ?? $defaults['gradient_midpoint'] ) ) ),
			'gradient_stop_secondary'     => max( 0, min( 100, (int) ( $style['gradient_stop_secondary'] ?? $defaults['gradient_stop_secondary'] ) ) ),
			'gradient_stop_tertiary'      => max( 0, min( 100, (int) ( $style['gradient_stop_tertiary'] ?? $defaults['gradient_stop_tertiary'] ) ) ),
			'gradient_opacity'            => max( 0, min( 100, (int) ( $style['gradient_opacity'] ?? $defaults['gradient_opacity'] ) ) ),
			'background_image_url'        => esc_url_raw( (string) ( $style['background_image_url'] ?? $defaults['background_image_url'] ) ),
			'background_image_opacity'    => max( 0, min( 100, (int) ( $style['background_image_opacity'] ?? $defaults['background_image_opacity'] ) ) ),
			'background_image_position'   => $background_image_pos,
			'background_image_size'       => max( 20, min( 200, (int) ( $style['background_image_size'] ?? $defaults['background_image_size'] ) ) ),
			'background_image_blend_mode' => $background_image_blend,
			'pattern'                     => $pattern,
			'pattern_color'               => $this->sanitize_color_value( $style['pattern_color'] ?? $defaults['pattern_color'] ),
			'pattern_background_color'    => $this->sanitize_color_value( $style['pattern_background_color'] ?? $defaults['pattern_background_color'] ),
			'pattern_opacity'             => max( 0, min( 100, (int) ( $style['pattern_opacity'] ?? $defaults['pattern_opacity'] ) ) ),
			'pattern_scale'               => max( 6, min( 120, (int) ( $style['pattern_scale'] ?? $defaults['pattern_scale'] ) ) ),
			'pattern_density'             => max( 1, min( 12, (int) ( $style['pattern_density'] ?? $defaults['pattern_density'] ) ) ),
			'pattern_rotation'            => max( 0, min( 360, (int) ( $style['pattern_rotation'] ?? $defaults['pattern_rotation'] ) ) ),
			'pattern_spacing'             => max( 6, min( 120, (int) ( $style['pattern_spacing'] ?? $defaults['pattern_spacing'] ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $style Raw style data.
	 * @param array<string, mixed> $defaults Default runtime payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_card_style_config( array $style, array $defaults ): array {
		$style              = $this->normalize_frame_style_schema( $style, $defaults );
		$image_position     = isset( $style['card_image_position'] ) ? sanitize_key( (string) $style['card_image_position'] ) : (string) $defaults['card_image_position'];
		$card_image_layer   = isset( $style['card_image_layer'] ) ? sanitize_key( (string) $style['card_image_layer'] ) : (string) $defaults['card_image_layer'];
		$frame_style        = $this->normalize_frame_preset( $style['frame_style'] ?? $defaults['frame_style'] );
		$meta_align         = isset( $style['meta_align'] ) ? sanitize_key( (string) $style['meta_align'] ) : (string) $defaults['meta_align'];
		$stats_align        = isset( $style['stats_align'] ) ? sanitize_key( (string) $style['stats_align'] ) : (string) $defaults['stats_align'];
		$description_align  = isset( $style['description_align'] ) ? sanitize_key( (string) $style['description_align'] ) : (string) $defaults['description_align'];

		if ( ! in_array( $image_position, array( 'top-left', 'top-right', 'center', 'bottom-right', 'bottom-left' ), true ) ) {
			$image_position = (string) $defaults['card_image_position'];
		}

		if ( ! in_array( $card_image_layer, array( 'underlay', 'overlay' ), true ) ) {
			$card_image_layer = (string) $defaults['card_image_layer'];
		}

		if ( ! in_array( $meta_align, array( 'left', 'center', 'right', 'space-between' ), true ) ) {
			$meta_align = (string) $defaults['meta_align'];
		}

		if ( ! in_array( $stats_align, array( 'left', 'center', 'right' ), true ) ) {
			$stats_align = (string) $defaults['stats_align'];
		}

		if ( ! in_array( $description_align, array( 'left', 'center', 'right' ), true ) ) {
			$description_align = (string) $defaults['description_align'];
		}

		$frame_enabled      = 'none' !== $frame_style;
		$frame_thickness    = $frame_enabled ? max( 0, min( 16, (int) ( $style['frame_thickness'] ?? $style['border_width'] ?? $defaults['frame_thickness'] ?? $defaults['border_width'] ) ) ) : 0;
		$frame_color        = $this->sanitize_color_value( $style['frame_color'] ?? $style['border_color'] ?? $defaults['frame_color'] ?? $defaults['border_color'] );
		$frame_highlight    = $this->sanitize_color_value( $style['frame_highlight_color'] ?? $style['frame_inner_color'] ?? $defaults['frame_highlight_color'] ?? $defaults['frame_inner_color'] );
		$border_width       = $frame_thickness;
		$border_color       = $frame_color;
		$secondary_color     = $this->frame_supports_secondary_color( $frame_style )
			? $this->sanitize_color_value( $style['frame_inner_color'] ?? $frame_highlight ?? $defaults['frame_inner_color'] )
			: $border_color;
		$gradient_color_1    = $this->sanitize_color_value( $style['frame_gradient_color_1'] ?? $style['frame_color'] ?? $defaults['frame_gradient_color_1'] ?? $frame_color );
		$gradient_color_2    = $this->sanitize_color_value( $style['frame_gradient_color_2'] ?? $style['frame_inner_color'] ?? $defaults['frame_gradient_color_2'] ?? $frame_highlight );
		$gradient_color_3    = $this->sanitize_color_value( $style['frame_gradient_color_3'] ?? $style['frame_gradient_color'] ?? $defaults['frame_gradient_color_3'] ?? $defaults['frame_gradient_color'] );
		$tertiary_color      = 'gradient' === $frame_style ? $gradient_color_3 : $secondary_color;
		$shine_intensity     = 'metallic' === $frame_style ? max( 0, min( 100, (int) ( $style['frame_shine_intensity'] ?? $defaults['frame_shine_intensity'] ) ) ) : 0;
		$frame_gradient_angle = 'gradient' === $frame_style ? max( 0, min( 360, (int) ( $style['frame_gradient_angle'] ?? $defaults['frame_gradient_angle'] ) ) ) : (int) $defaults['frame_gradient_angle'];

		return array(
			'text_color'          => $this->sanitize_color_value( $style['text_color'] ?? $defaults['text_color'] ),
			'muted_text_color'    => $this->sanitize_color_value( $style['muted_text_color'] ?? $defaults['muted_text_color'] ),
			'member_name_color'   => $this->sanitize_color_value( $style['member_name_color'] ?? $style['text_color'] ?? $defaults['member_name_color'] ?? $defaults['text_color'] ),
			'member_name_weight'  => max( 700, min( 900, (int) ( $style['member_name_weight'] ?? $defaults['member_name_weight'] ?? 900 ) ) ),
			'frame_enabled'       => $frame_enabled,
			'frame_color'         => $frame_color,
			'frame_highlight_color' => $frame_highlight,
			'frame_thickness'     => $frame_thickness,
			'border_color'        => $border_color,
			'border_width'        => $border_width,
			'border_radius'       => (int) $defaults['border_radius'],
			'frame_style'         => $frame_style,
			'frame_inner_color'   => $secondary_color,
			'frame_shine_intensity' => $shine_intensity,
			'frame_gradient_color_1' => $gradient_color_1,
			'frame_gradient_color_2' => $gradient_color_2,
			'frame_gradient_color_3' => $gradient_color_3,
			'frame_gradient_color' => $tertiary_color,
			'frame_gradient_angle' => $frame_gradient_angle,
			'content_padding'     => max( 12, min( 48, (int) ( $style['content_padding'] ?? $defaults['content_padding'] ) ) ),
			'content_gap'         => max( 6, min( 32, (int) ( $style['content_gap'] ?? $defaults['content_gap'] ) ) ),
			'meta_align'          => $meta_align,
			'stats_align'         => $stats_align,
			'card_image_opacity'  => max( 0, min( 100, (int) ( $style['card_image_opacity'] ?? $defaults['card_image_opacity'] ) ) ),
			'card_image_position' => $image_position,
			'card_image_size'     => max( 10, min( 80, (int) ( $style['card_image_size'] ?? $defaults['card_image_size'] ) ) ),
			'card_image_layer'    => $card_image_layer,
			'title_width'         => max( 40, min( 100, (int) ( $style['title_width'] ?? $defaults['title_width'] ) ) ),
			'description_color'   => $this->sanitize_color_value( $style['description_color'] ?? $defaults['description_color'] ),
			'description_size'    => max( 12, min( 24, (int) ( $style['description_size'] ?? $defaults['description_size'] ) ) ),
			'description_weight'  => max( 300, min( 900, (int) ( $style['description_weight'] ?? $defaults['description_weight'] ) ) ),
			'description_align'   => $description_align,
			'description_shadow'  => max( 0, min( 40, (int) ( $style['description_shadow'] ?? $defaults['description_shadow'] ) ) ),
			'description_width'   => max( 30, min( 100, (int) ( $style['description_width'] ?? $defaults['description_width'] ) ) ),
			'shapes'              => $this->sanitize_visual_shapes( $style['shapes'] ?? $defaults['shapes'] ?? array() ),
		);
	}

	/**
	 * Collapse legacy frame fields into the compact schema.
	 *
	 * @param array<string, mixed> $style
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	private function normalize_frame_style_schema( array $style, array $defaults ): array {
		if ( ! isset( $style['frame_color'] ) || '' === trim( (string) $style['frame_color'] ) ) {
			$style['frame_color'] = $style['border_color'] ?? $defaults['frame_color'] ?? $defaults['border_color'];
		}

		if ( ! isset( $style['frame_thickness'] ) || '' === trim( (string) $style['frame_thickness'] ) ) {
			$style['frame_thickness'] = $style['border_width'] ?? $defaults['frame_thickness'] ?? $defaults['border_width'];
		}

		$style['frame_thickness'] = max( 0, min( 16, (int) $style['frame_thickness'] ) );

		if ( ! isset( $style['member_name_color'] ) || '' === trim( (string) $style['member_name_color'] ) ) {
			$style['member_name_color'] = $style['text_color'] ?? $defaults['member_name_color'] ?? $defaults['text_color'];
		}

		if ( ! isset( $style['member_name_weight'] ) || '' === trim( (string) $style['member_name_weight'] ) ) {
			$style['member_name_weight'] = $style['title_weight'] ?? $defaults['member_name_weight'] ?? 900;
		}

		$requested_frame_style = $style['frame_style'] ?? $defaults['frame_style'];
		$style['frame_style']  = $this->normalize_frame_preset( $requested_frame_style );

		if ( 'none' === $style['frame_style'] && 0 < (int) ( $style['frame_thickness'] ?? $style['border_width'] ?? 0 ) ) {
			$style['frame_style'] = 'simple';
		}

		if ( ! isset( $style['frame_highlight_color'] ) || '' === trim( (string) $style['frame_highlight_color'] ) ) {
			$style['frame_highlight_color'] = $style['frame_inner_color'] ?? $style['frame_color'] ?? $defaults['frame_highlight_color'] ?? $defaults['frame_inner_color'];
		}

		if ( ! isset( $style['frame_inner_color'] ) || '' === trim( (string) $style['frame_inner_color'] ) ) {
			$style['frame_inner_color'] = $style['frame_highlight_color'] ?? $style['frame_color'] ?? $style['border_color'] ?? $defaults['frame_inner_color'];
		}

		if ( ! isset( $style['frame_gradient_color_1'] ) || '' === trim( (string) $style['frame_gradient_color_1'] ) ) {
			$style['frame_gradient_color_1'] = $style['frame_color'] ?? $style['border_color'] ?? $defaults['frame_gradient_color_1'] ?? $defaults['frame_color'];
		}

		if ( ! isset( $style['frame_gradient_color_2'] ) || '' === trim( (string) $style['frame_gradient_color_2'] ) ) {
			$style['frame_gradient_color_2'] = $style['frame_highlight_color'] ?? $style['frame_inner_color'] ?? $defaults['frame_gradient_color_2'] ?? $defaults['frame_inner_color'];
		}

		if ( ! isset( $style['frame_gradient_color_3'] ) || '' === trim( (string) $style['frame_gradient_color_3'] ) ) {
			$style['frame_gradient_color_3'] = $style['frame_gradient_color'] ?? $defaults['frame_gradient_color_3'] ?? $defaults['frame_gradient_color'];
		}

		if ( ! isset( $style['frame_gradient_color'] ) || '' === trim( (string) $style['frame_gradient_color'] ) ) {
			$style['frame_gradient_color'] = $style['frame_gradient_color_3'] ?? $style['frame_inner_color'] ?? $defaults['frame_gradient_color'];
		}

		if ( isset( $style['frame_enabled'] ) ) {
			$enabled = filter_var( $style['frame_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			if ( false === $enabled ) {
				$style['frame_style'] = 'none';
				$style['frame_thickness'] = 0;
				$style['border_width'] = 0;
			}
		}

		if ( 0 === (int) ( $style['frame_thickness'] ?? $style['border_width'] ?? $defaults['frame_thickness'] ?? $defaults['border_width'] ) ) {
			$style['frame_style'] = 'none';
		}

		unset(
			$style['frame_enabled'],
			$style['frame_finish'],
			$style['frame_shine_enabled'],
			$style['frame_shine_angle'],
			$style['frame_shine_width'],
			$style['frame_shine_animated'],
			$style['frame_shine_speed'],
			$style['frame_inner_highlight'],
			$style['frame_inner_glow'],
			$style['frame_accent_line'],
			$style['accent_color'],
			$style['badge_background_color'],
			$style['badge_text_color'],
			$style['badge_border_color'],
			$style['badge_border_width'],
			$style['badge_icon_color'],
			$style['badge_icon_highlight_color'],
			$style['badge_icon_glow'],
			$style['badge_style'],
			$style['rarity_effect'],
			$style['title_color'],
			$style['title_size'],
			$style['title_weight'],
			$style['title_align'],
			$style['title_shadow']
		);

		return $style;
	}

	/**
	 * @param array<string, mixed> $style Raw badge data.
	 * @param array<string, mixed> $defaults Runtime defaults.
	 * @return array<string, mixed>
	 */
	private function sanitize_title_badge_config( array $style, array $defaults ): array {
		$default_badge = is_array( $defaults['title_badge'] ?? null ) ? (array) $defaults['title_badge'] : $this->default_title_badge_style( $defaults );

		return array(
			'background_color'     => $this->sanitize_color_value( $style['background_color'] ?? $style['badge_background_color'] ?? $default_badge['background_color'] ?? '#36523f' ),
			'text_color'           => $this->sanitize_color_value( $style['text_color'] ?? $style['badge_text_color'] ?? $default_badge['text_color'] ?? '#ffffff' ),
			'border_color'         => $this->sanitize_color_value( $style['border_color'] ?? $style['badge_border_color'] ?? $default_badge['border_color'] ?? '#86efac' ),
			'border_width'         => max( 1, min( 4, (int) ( $style['border_width'] ?? $style['badge_border_width'] ?? $default_badge['border_width'] ?? 1 ) ) ),
			'icon_color'           => $this->sanitize_color_value( $style['icon_color'] ?? $style['badge_icon_color'] ?? $default_badge['icon_color'] ?? '#2f4b3b' ),
			'icon_highlight_color' => $this->sanitize_color_value( $style['icon_highlight_color'] ?? $style['badge_icon_highlight_color'] ?? $default_badge['icon_highlight_color'] ?? '#ffffff' ),
			'icon_glow'            => max( 0, min( 40, (int) ( $style['icon_glow'] ?? $style['badge_icon_glow'] ?? $default_badge['icon_glow'] ?? 10 ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $style Raw style payload.
	 * @return array<string, mixed>
	 */
	private function extract_legacy_title_badge_source( array $style ): array {
		$mapping = array(
			'badge_background_color'      => 'background_color',
			'badge_text_color'            => 'text_color',
			'badge_border_color'          => 'border_color',
			'badge_border_width'          => 'border_width',
			'badge_icon_color'            => 'icon_color',
			'badge_icon_highlight_color'  => 'icon_highlight_color',
			'badge_icon_glow'             => 'icon_glow',
		);
		$badge   = array();

		foreach ( $mapping as $legacy_key => $new_key ) {
			if ( array_key_exists( $legacy_key, $style ) ) {
				$badge[ $new_key ] = $style[ $legacy_key ];
			}
		}

		return $badge;
	}

	/**
	 * @param array<string, mixed> $palette Base reward palette.
	 * @return array<string, mixed>
	 */
	private function default_title_badge_style( array $palette ): array {
		return array(
			'background_color'     => (string) ( $palette['background_color_secondary'] ?? '#36523f' ),
			'text_color'           => (string) ( $palette['text_color'] ?? '#ffffff' ),
			'border_color'         => (string) ( $palette['accent_color'] ?? '#86efac' ),
			'border_width'         => 1,
			'icon_color'           => '#2f4b3b',
			'icon_highlight_color' => '#ffffff',
			'icon_glow'            => 10,
		);
	}

	/**
	 * Normalize legacy frame preset names to the new renderer presets.
	 */
	private function normalize_frame_preset( mixed $value ): string {
		$preset = sanitize_key( (string) $value );

		return match ( $preset ) {
			'solid', 'simple' => 'simple',
			'double', 'segmented', 'accent' => 'simple',
			'metallic', 'neon', 'premium' => 'metallic',
			'gradient' => 'gradient',
			'none' => 'none',
			default => 'none',
		};
	}

	/**
	 * Determine whether a frame preset uses a secondary tone.
	 */
	private function frame_supports_secondary_color( string $preset ): bool {
		return in_array( $preset, array( 'metallic', 'gradient' ), true );
	}

	/**
	 * Sanitize simple shape definitions used by the reward editor.
	 *
	 * @param mixed $shapes Raw shape data.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_visual_shapes( mixed $shapes ): array {
		if ( is_string( $shapes ) ) {
			$decoded = json_decode( $shapes, true );
			$shapes  = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $shapes ) ) {
			return array();
		}

		$clean = array();

		foreach ( $shapes as $shape ) {
			if ( ! is_array( $shape ) ) {
				continue;
			}

			$type = isset( $shape['type'] ) ? sanitize_key( (string) $shape['type'] ) : 'circle';

			if ( ! in_array( $type, array( 'circle', 'square' ), true ) ) {
				continue;
			}

			$clean[] = array(
				'type'     => $type,
				'x'        => max( 0, min( 100, (int) ( $shape['x'] ?? 72 ) ) ),
				'y'        => max( 0, min( 100, (int) ( $shape['y'] ?? 20 ) ) ),
				'width'    => max( 4, min( 90, (int) ( $shape['width'] ?? 18 ) ) ),
				'height'   => max( 2, min( 90, (int) ( $shape['height'] ?? 18 ) ) ),
				'rotation' => max( 0, min( 360, (int) ( $shape['rotation'] ?? 0 ) ) ),
				'opacity'  => max( 0, min( 100, (int) ( $shape['opacity'] ?? 28 ) ) ),
				'color'    => $this->sanitize_color_value( $shape['color'] ?? '#ffffff' ),
			);
		}

		return array_slice( $clean, 0, 12 );
	}

	/**
	 * Get starter shapes based on rarity.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_visual_shapes( string $rarity ): array {
		return match ( $rarity ) {
			Reward::RARITY_EPIC => array(
				array( 'type' => 'circle', 'x' => 84, 'y' => 16, 'width' => 14, 'height' => 14, 'rotation' => 0, 'opacity' => 24, 'color' => '#ffffff' ),
			),
			Reward::RARITY_LEGENDARY => array(
				array( 'type' => 'circle', 'x' => 84, 'y' => 14, 'width' => 16, 'height' => 16, 'rotation' => 0, 'opacity' => 28, 'color' => '#fff4b8' ),
			),
			default => array(),
		};
	}

	/**
	 * Build the CSS background value for a reward card.
	 */
	private function reward_background_value( array $style ): string {
		$primary     = (string) $style['background_color'];
		$secondary   = (string) $style['background_color_secondary'];
		$tertiary    = (string) $style['background_color_tertiary'];
		$angle       = (int) $style['gradient_angle'];
		$mode        = (string) $style['background_mode'];
		$midpoint    = (int) $style['gradient_stop_secondary'];
		$final_stop  = (int) $style['gradient_stop_tertiary'];

		if ( 'solid' === $mode ) {
			return $primary;
		}

		return 'linear-gradient(' . $angle . 'deg, ' . $primary . ' 0%, ' . $secondary . ' ' . $midpoint . '%, ' . $tertiary . ' ' . $final_stop . '%)';
	}

	/**
	 * Build an inline style string for reward card renderers.
	 */
	private function reward_card_inline_style( array $style, string $background_value ): string {
		$vars = array(
			'--adam-reward-card-background'          => $background_value,
			'--adam-reward-card-text'                => (string) $style['text_color'],
			'--adam-reward-card-muted'               => (string) $style['muted_text_color'],
			'--adam-reward-card-accent'              => (string) $style['accent_color'],
			'--adam-reward-card-border'              => (string) $style['border_color'],
			'--adam-reward-card-border-width'        => (string) (int) $style['border_width'] . 'px',
			'--adam-reward-card-radius'              => (string) (int) $style['border_radius'] . 'px',
			'--adam-reward-card-gradient-origin'     => str_replace( '-', ' ', (string) $style['gradient_origin'] ),
			'--adam-reward-card-gradient-opacity'    => (string) ( (int) $style['gradient_opacity'] / 100 ),
			'--adam-reward-card-pattern-opacity'     => (string) ( (int) $style['pattern_opacity'] / 100 ),
			'--adam-reward-card-pattern-color'       => (string) $style['pattern_color'],
			'--adam-reward-card-pattern-base'        => (string) $style['pattern_background_color'],
			'--adam-reward-card-pattern-size'        => (string) (int) $style['pattern_scale'] . 'px',
			'--adam-reward-card-pattern-spacing'     => (string) (int) $style['pattern_spacing'] . 'px',
			'--adam-reward-card-pattern-density'     => (string) (int) $style['pattern_density'],
			'--adam-reward-card-pattern-rotation'    => (string) (int) $style['pattern_rotation'] . 'deg',
			'--adam-reward-card-image-opacity'       => (string) ( (int) $style['card_image_opacity'] / 100 ),
			'--adam-reward-card-image-size'          => (string) (int) $style['card_image_size'] . '%',
			'--adam-reward-card-background-opacity'  => (string) ( (int) $style['background_image_opacity'] / 100 ),
			'--adam-reward-card-background-size'     => (string) (int) $style['background_image_size'] . '%',
			'--adam-reward-card-background-position' => str_replace( '-', ' ', (string) $style['background_image_position'] ),
			'--adam-reward-card-background-blend'    => (string) $style['background_image_blend_mode'],
			'--adam-reward-card-frame-opacity'       => '1',
			'--adam-reward-card-frame-glow'          => '0px',
			'--adam-reward-card-frame-shadow'        => '0px',
			'--adam-reward-card-frame-inner-width'   => in_array( (string) $style['frame_style'], array( 'metallic', 'gradient' ), true ) ? '2px' : '0px',
			'--adam-reward-card-frame-inner-color'   => (string) ( $style['frame_inner_color'] ?? $style['border_color'] ),
			'--adam-reward-card-frame-corner'        => '0px',
			'--adam-reward-card-frame-inset'         => '0px',
			'--adam-reward-card-content-padding'     => (string) (int) $style['content_padding'] . 'px',
			'--adam-reward-card-content-gap'         => (string) (int) $style['content_gap'] . 'px',
			'--adam-reward-card-meta-align'          => $this->css_alignment_value( (string) $style['meta_align'], true ),
			'--adam-reward-card-stats-align'         => $this->css_alignment_value( (string) $style['stats_align'], false ),
			'--adam-reward-card-title-color'         => (string) $style['title_color'],
			'--adam-reward-card-title-size'          => (string) (int) $style['title_size'] . 'px',
			'--adam-reward-card-title-weight'        => (string) (int) $style['title_weight'],
			'--adam-reward-card-title-align'         => (string) $style['title_align'],
			'--adam-reward-card-title-shadow'        => (string) ( (int) $style['title_shadow'] / 3 ) . 'px',
			'--adam-reward-card-title-width'         => (string) (int) $style['title_width'] . '%',
			'--adam-reward-card-description-color'   => (string) $style['description_color'],
			'--adam-reward-card-description-size'    => (string) (int) $style['description_size'] . 'px',
			'--adam-reward-card-description-weight'  => (string) (int) $style['description_weight'],
			'--adam-reward-card-description-align'   => (string) $style['description_align'],
			'--adam-reward-card-description-shadow'  => (string) ( (int) $style['description_shadow'] / 3 ) . 'px',
			'--adam-reward-card-description-width'   => (string) (int) $style['description_width'] . '%',
		);

		$declarations = array();

		foreach ( $vars as $property => $value ) {
			$declarations[] = $property . ':' . $value;
		}

		return implode( ';', $declarations ) . ';';
	}

	/**
	 * Sanitize CSS color values used in reward editor settings.
	 */
	private function sanitize_color_value( mixed $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '#ffffff';
		}

		if ( preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^rgba?\([\d\s.,%]+\)$/', $value ) ) {
			return $value;
		}

		return '#ffffff';
	}

	/**
	 * Persist upgraded visual-style schema for older rewards when needed.
	 *
	 * @param array<string, mixed> $raw_style Original stored payload.
	 * @param array<string, mixed> $migrated_style Canonicalized payload.
	 */
	private function maybe_migrate_reward_visual_style( Reward $reward, array $raw_style, array $migrated_style ): void {
		if ( $raw_style == $migrated_style ) {
			return;
		}

		$data = $reward->data();
		$data['visual_style'] = $migrated_style;

		$this->repository->update_reward( $reward, $data );
	}

	/**
	 * Ensure a runtime reward style always contains the full current schema.
	 *
	 * @param array<string, mixed> $style Style payload.
	 * @return array<string, mixed>
	 */
	private function complete_reward_visual_style( array $style, string $rarity, string $category ): array {
		$defaults = $this->default_reward_visual_style( $rarity, $category );

		if ( isset( $style['background'] ) && is_array( $style['background'] ) ) {
			$style['background'] = wp_parse_args( $style['background'], $this->sanitize_background_style_config( array(), $defaults ) );
		}

		if ( isset( $style['style'] ) && is_array( $style['style'] ) ) {
			$style['style'] = wp_parse_args( $style['style'], $this->sanitize_card_style_config( array(), $defaults ) );
		}

		if ( isset( $style['title_badge'] ) && is_array( $style['title_badge'] ) ) {
			$style['title_badge'] = wp_parse_args( $style['title_badge'], $this->sanitize_title_badge_config( array(), $defaults ) );
		}

		return wp_parse_args( $style, $defaults );
	}

	private function css_alignment_value( string $alignment, bool $allow_space_between ): string {
		return match ( $alignment ) {
			'left' => 'flex-start',
			'right' => 'flex-end',
			'center' => 'center',
			'space-between' => $allow_space_between ? 'space-between' : 'flex-start',
			default => 'flex-start',
		};
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function initial_catalogue(): array {
		return array_merge(
			$this->title_rewards(),
			$this->card_background_rewards(),
			$this->card_frame_rewards()
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function title_rewards(): array {
		return array(
			$this->seed_reward( 'Operador', 'Titulo desbloqueavel para socios que estao a comecar a sua progressao ADAM.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_COMMON, 5, 'title_operador' ),
			$this->seed_reward( 'Explorador', 'Titulo para membros que continuam a marcar presenca e a subir de nivel.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_COMMON, 10, 'title_explorador' ),
			$this->seed_reward( 'Sobrevivente', 'Titulo para quem se mantem ativo e presente nos eventos ADAM.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_COMMON, 15, 'title_sobrevivente' ),
			$this->seed_reward( 'Veterano ADAM', 'Titulo de fidelidade desbloqueado aos 2 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_UNCOMMON, 0, 'title_veterano_adam', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Mestre do CQB', 'Titulo inspirado nos jogadores mais confortaveis em ambientes apertados.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_UNCOMMON, 35, 'title_mestre_do_cqb' ),
			$this->seed_reward( 'Atirador de Elite', 'Reconhecimento para quem quer um perfil de prestigio mais avancado.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_RARE, 50, 'title_atirador_de_elite' ),
			$this->seed_reward( 'Operador Elite', 'Titulo raro para socios que acumulam presenca e consistencia.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_RARE, 60, 'title_operador_elite' ),
			$this->seed_reward( 'Operador Experiente', 'Titulo de fidelidade desbloqueado aos 3 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_RARE, 0, 'title_operador_experiente', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Lider de Esquadra', 'Titulo de destaque para perfis de referencia dentro da comunidade.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_RARE, 70, 'title_lider_de_esquadra' ),
			$this->seed_reward( 'Lenda ADAM', 'Titulo maximo de fidelidade desbloqueado aos 10 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'title_lenda_adam', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Guardiao ADAM', 'Distincao de fidelidade desbloqueada aos 5 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_EPIC, 0, 'title_guardiao_adam', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Comandante ADAM', 'O topo da hierarquia de titulos desbloqueaveis.', 'Titulos', Reward::TYPE_PERMANENT_UNLOCK, Reward::RARITY_LEGENDARY, 180, 'title_comandante_adam' ),
			$this->seed_reward( 'Fundador Honorario', 'Titulo exclusivo para atribuicao manual pela administracao.', 'Titulos', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'title_fundador_honorario', 'Atribuicao manual', true, false ),
			$this->seed_reward( 'Founder', 'Titulo exclusivo para um dos primeiros 50 socios aprovados da ADAM.', 'Fundadores', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'title_founder', 'Exclusivo Fundadores', true, false ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function card_background_rewards(): array {
		return array(
			$this->seed_reward( 'Woodland', 'Fundo classico de inspiracao florestal para o cartao digital.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_COMMON, 5, 'card_theme_woodland' ),
			$this->seed_reward( 'Desert', 'Fundo claro e seco para variar o visual do cartao digital.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_COMMON, 5, 'card_theme_desert' ),
			$this->seed_reward( 'Urban', 'Visual urbano para um estilo mais tecnico e moderno.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_COMMON, 5, 'card_theme_urban' ),
			$this->seed_reward( 'Carbon Fiber', 'Fundo de fidelidade desbloqueado aos 3 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_UNCOMMON, 0, 'card_theme_carbon_fiber', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Flecktarn', 'Padrao inspirado em camuflagem classica europeia.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_UNCOMMON, 25, 'card_theme_flecktarn' ),
			$this->seed_reward( 'Digital Green', 'Tema digital verde para manter a identidade ADAM.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_UNCOMMON, 25, 'card_theme_digital_green' ),
			$this->seed_reward( 'Blue Steel', 'Acabamento azulado e metalico para um visual distinto.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_UNCOMMON, 30, 'card_theme_blue_steel' ),
			$this->seed_reward( 'Midnight Black', 'Tema escuro de alto contraste para perfis raros.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_RARE, 45, 'card_theme_midnight_black' ),
			$this->seed_reward( 'Arctic White', 'Tema claro e elegante com presenca rara.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_RARE, 50, 'card_theme_arctic_white' ),
			$this->seed_reward( 'Crimson Strike', 'Fundo intenso para quem quer um cartao com mais energia visual.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_RARE, 55, 'card_theme_crimson_strike' ),
			$this->seed_reward( 'Purple Nebula', 'Tema cosmico e distinto para colecoes mais avancadas.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_RARE, 60, 'card_theme_purple_nebula' ),
			$this->seed_reward( 'Gold', 'Fundo de fidelidade desbloqueado aos 5 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_EPIC, 0, 'card_theme_gold', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Emerald', 'Tema esmeralda para reforcar estatuto e longevidade.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_EPIC, 100, 'card_theme_emerald' ),
			$this->seed_reward( 'Sapphire', 'Tema safira com acabamento intenso e elegante.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_EPIC, 100, 'card_theme_sapphire' ),
			$this->seed_reward( 'Ruby', 'Tema rubi reservado para quem investe a serio na progressao.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_EPIC, 110, 'card_theme_ruby' ),
			$this->seed_reward( 'Obsidian', 'Tema lendario escuro e premium para o cartao digital.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_LEGENDARY, 150, 'card_theme_obsidian' ),
			$this->seed_reward( 'Phoenix', 'Tema lendario com identidade vibrante e exclusiva.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_LEGENDARY, 175, 'card_theme_phoenix' ),
			$this->seed_reward( 'Platinum', 'Tema lendario de topo para perfis verdadeiramente distinguidos.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_LEGENDARY, 200, 'card_theme_platinum' ),
			$this->seed_reward( 'Founder', 'Fundo exclusivo para um dos primeiros 50 socios aprovados da ADAM.', 'Fundadores', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'card_theme_founder', 'Exclusivo Fundadores', true, false ),
			$this->seed_reward( 'Legado ADAM', 'Fundo lendario exclusivo desbloqueado aos 10 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'card_theme_legendary_loyalty', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Christmas Edition', 'Visual sazonal de Natal para campanhas ou atribuicoes especiais.', 'Cartao Digital', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LIMITED_EDITION, 0, 'card_theme_christmas_edition', 'Edicao sazonal', false, false ),
			$this->seed_reward( 'Halloween Edition', 'Tema limitado de Halloween para eventos e campanhas especiais.', 'Cartao Digital', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LIMITED_EDITION, 0, 'card_theme_halloween_edition', 'Edicao sazonal', false, false ),
			$this->seed_reward( 'Anniversary Edition', 'Fundo comemorativo para marcos importantes da ADAM.', 'Cartao Digital', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LIMITED_EDITION, 0, 'card_theme_anniversary_edition', 'Edicao sazonal', false, false ),
			$this->seed_reward( 'Summer Event Edition', 'Tema de verao pensado para eventos especiais e atribuicoes temporarias.', 'Cartao Digital', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LIMITED_EDITION, 0, 'card_theme_summer_event_edition', 'Edicao sazonal', false, false ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function card_frame_rewards(): array {
		return array(
			$this->seed_reward( 'Standard Silver', 'Moldura simples e elegante para personalizar o cartao digital.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_COMMON, 10, 'card_frame_standard_silver' ),
			$this->seed_reward( 'Tactical Green', 'Moldura verde tatica alinhada com a identidade ADAM.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_COMMON, 15, 'card_frame_tactical_green' ),
			$this->seed_reward( 'Veteran Bronze', 'Moldura de fidelidade desbloqueada aos 2 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_UNCOMMON, 0, 'card_frame_veteran_bronze', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Carbon Edge', 'Moldura tecnica com acabamento premium e discreto.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_UNCOMMON, 35, 'card_frame_carbon_edge' ),
			$this->seed_reward( 'Elite Blue', 'Moldura rara com destaque azul e acabamento mais vibrante.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_RARE, 60, 'card_frame_elite_blue' ),
			$this->seed_reward( 'Titanium', 'Moldura metalica rara com visual sofisticado.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_RARE, 70, 'card_frame_titanium' ),
			$this->seed_reward( 'Veteran Gold', 'Moldura de fidelidade desbloqueada aos 5 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_EPIC, 0, 'card_frame_veteran_gold', 'Fidelidade ADAM', true, false ),
			$this->seed_reward( 'Golden Honor', 'Moldura epica dourada para socios mais consistentes.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_EPIC, 110, 'card_frame_golden_honor' ),
			$this->seed_reward( 'Emerald Prestige', 'Moldura epica com toque esmeralda e acabamento premium.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_EPIC, 120, 'card_frame_emerald_prestige' ),
			$this->seed_reward( 'Diamond Frame', 'Moldura lendaria pensada para perfis de topo.', 'Cartao Digital', Reward::TYPE_DIGITAL_COSMETIC, Reward::RARITY_LEGENDARY, 180, 'card_frame_diamond_frame' ),
			$this->seed_reward( 'Founder Frame', 'Moldura exclusiva para um dos primeiros 50 socios aprovados da ADAM.', 'Fundadores', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'card_frame_founder_frame', 'Exclusivo Fundadores', true, false ),
			$this->seed_reward( 'Legacy Frame', 'Moldura lendaria exclusiva desbloqueada aos 10 anos de associacao ativa.', 'Fidelidade ADAM', Reward::TYPE_MANUAL_REWARD, Reward::RARITY_LEGENDARY, 0, 'card_frame_legendary_loyalty', 'Fidelidade ADAM', true, false ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seed_reward( string $name, string $description, string $category, string $type, string $rarity, int $points_cost, string $reward_value, string $availability_label = '', bool $active = true, bool $redeemable = true ): array {
		return array(
			'name'                => $name,
			'description'         => $description,
			'category'            => $category,
			'type'                => $type,
			'rarity'              => $rarity,
			'points_cost'         => $points_cost,
			'image_url'           => '',
			'availability_label'  => '' !== $availability_label ? $availability_label : __( 'Disponivel', 'adam-membership' ),
			'active'              => $active,
			'redeemable'          => $redeemable,
			'approval_required'   => false,
			'mystery_reveal_text' => '',
			'reward_value'        => $reward_value,
		);
	}

	/**
	 * @return array<string, string>|WP_Error
	 */
	private function handle_image_upload( array $file ): array|WP_Error {
		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$allowed = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		$checked = wp_check_filetype_and_ext( $tmp, $name, $allowed );
		$type    = isset( $checked['type'] ) && is_string( $checked['type'] ) ? $checked['type'] : '';

		if ( '' === $type ) {
			return new WP_Error( 'adam_membership_reward_image_type', __( 'O tipo de imagem nao e permitido para recompensas.', 'adam-membership' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $allowed,
			)
		);

		if ( ! is_array( $uploaded ) || empty( $uploaded['url'] ) ) {
			return new WP_Error( 'adam_membership_reward_image_upload', __( 'Nao foi possivel guardar a imagem da recompensa.', 'adam-membership' ) );
		}

		return array( 'image_url' => esc_url_raw( (string) $uploaded['url'] ) );
	}

	private function has_uploaded_file( array $file ): bool {
		return isset( $file['tmp_name'], $file['error'] ) && UPLOAD_ERR_OK === (int) $file['error'] && is_uploaded_file( (string) $file['tmp_name'] );
	}

	/**
	 * @param array<string, mixed> $prepared
	 * @return array<string, mixed>
	 */
	private function merge_catalogue_reward( Reward $current, array $prepared ): array {
		$current_data = $current->data();
		$merged       = array_merge( $prepared, $current_data );
		$defaults     = $this->default_reward_visual_style( $current->rarity(), $current->category() );

		/*
		 * Seed catalogue sync must never restore the original preset over an
		 * administrator-edited reward. Existing values stay authoritative and
		 * defaults are only used to backfill genuinely missing keys.
		 */
		$merged['visual_style'] = $this->sanitize_visual_style(
			$current_data['visual_style'] ?? array(),
			$defaults,
			$current_data['visual_style'] ?? array()
		);

		$merged['redeemable']        = array_key_exists( 'redeemable', $current_data ) ? $current->redeemable() : (bool) $prepared['redeemable'];
		$merged['approval_required'] = array_key_exists( 'approval_required', $current_data ) ? $current->approval_required() : (bool) $prepared['approval_required'];

		if ( $this->is_seasonal_reward( (string) ( $prepared['reward_value'] ?? '' ) ) ) {
			if ( empty( $current_data['seasonal_visibility_initialized'] ) ) {
				$merged['active'] = false;
			} else {
				$merged['active'] = $current->active();
			}

			$merged['seasonal_visibility_initialized'] = 1;

			return $merged;
		}

		$merged['active'] = $current->active();

		return $merged;
	}

	private function member_reward_rank( Member $member, Reward $reward ): int {
		if ( $this->member_owns_reward( $member, $reward ) ) {
			return 3_000_000 + $reward->points_cost();
		}

		if ( $this->member_has_pending_request( $member, $reward ) ) {
			return 2_000_000 + $reward->points_cost();
		}

		if ( ! $this->member_can_redeem( $member, $reward ) ) {
			return 1_000_000 + $reward->points_cost();
		}

		return $reward->points_cost();
	}

	/**
	 * @param array<string, mixed> $details Extra details.
	 */
	private function record_history( int $member_id, string $action_key, string $action_label, string $description, array $details = array() ): void {
		$member = $member_id > 0 ? $this->members->find( $member_id ) : null;
		$actor  = wp_get_current_user();

		$this->history->create(
			array(
				'member_id'     => $member_id,
				'member_number' => null !== $member ? sanitize_text_field( (string) $member->field( 'numero_socio' ) ) : '',
				'member_name'   => null !== $member ? sanitize_text_field( $member->full_name() ) : '',
				'member_email'  => null !== $member ? sanitize_email( $member->email() ) : '',
				'action_key'    => sanitize_key( $action_key ),
				'action_label'  => $action_label,
				'actor_type'    => current_user_can( 'manage_options' ) ? 'admin' : 'member',
				'actor_id'      => $actor instanceof \WP_User ? $actor->ID : 0,
				'actor_name'    => $actor instanceof \WP_User ? sanitize_text_field( (string) $actor->display_name ) : '',
				'description'   => $description,
				'details'       => $details,
				'created_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);
	}

	private function reward_value_key( Reward|string $reward ): string {
		if ( $reward instanceof Reward ) {
			return sanitize_key( $reward->reward_value() );
		}

		return sanitize_key( $reward );
	}
}
