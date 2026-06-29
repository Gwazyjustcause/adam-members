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
	 * @param array<string, mixed> $data Reward data.
	 * @param array<string, mixed> $file Uploaded image.
	 * @return Reward|WP_Error
	 */
	public function save_reward( array $data, array $file = array(), int $id = 0 ): Reward|WP_Error {
		$prepared = $this->sanitize_reward_data( $data );

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

		$current = $this->repository->find_reward( $id );

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
	private function sanitize_reward_data( array $data ): array {
		$type = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : Reward::TYPE_PERMANENT_UNLOCK;

		if ( ! in_array( $type, Reward::types(), true ) ) {
			$type = Reward::TYPE_PERMANENT_UNLOCK;
		}

		$rarity = isset( $data['rarity'] ) ? sanitize_key( (string) $data['rarity'] ) : Reward::RARITY_COMMON;

		if ( ! in_array( $rarity, Reward::rarities(), true ) ) {
			$rarity = Reward::RARITY_COMMON;
		}

		return array(
			'name'                => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'description'         => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
			'category'            => isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : 'Outras',
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
		);
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

		if ( '' !== $current->image_url() ) {
			$prepared['image_url'] = $current->image_url();
		}

		if ( '' !== $current->availability_label() ) {
			$prepared['availability_label'] = $current->availability_label();
		}

		$prepared['redeemable']        = $current->redeemable();
		$prepared['approval_required'] = $current->approval_required();

		if ( $this->is_seasonal_reward( (string) ( $prepared['reward_value'] ?? '' ) ) ) {
			if ( empty( $current_data['seasonal_visibility_initialized'] ) ) {
				$prepared['active'] = false;
			} else {
				$prepared['active'] = $current->active();
			}

			$prepared['seasonal_visibility_initialized'] = 1;

			return $prepared;
		}

		$prepared['active'] = $current->active();

		return $prepared;
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
