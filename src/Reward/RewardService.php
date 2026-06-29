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

	public function repository(): RewardRepository {
		return $this->repository;
	}

	/**
	 * @param array<string, mixed> $data Reward data.
	 * @param array<string, mixed> $file Uploaded image.
	 * @return Reward|WP_Error
	 */
	public function save_reward( array $data, array $file = array(), int $id = 0 ): Reward|WP_Error {
		$prepared = $this->sanitize_reward_data( $data );

		if ( '' === $prepared['name'] ) {
			return new WP_Error( 'adam_membership_reward_name_required', __( 'O nome da recompensa é obrigatório.', 'adam-membership' ) );
		}

		if ( $prepared['points_cost'] < 0 ) {
			return new WP_Error( 'adam_membership_reward_points_invalid', __( 'O custo em pontos é inválido.', 'adam-membership' ) );
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
			return new WP_Error( 'adam_membership_reward_not_found', __( 'Recompensa não encontrada.', 'adam-membership' ) );
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
			return new WP_Error( 'adam_membership_reward_unavailable', __( 'Esta recompensa não está disponível neste momento.', 'adam-membership' ) );
		}

		if ( $this->points->current_balance( $member ) < $reward->points_cost() ) {
			return new WP_Error( 'adam_membership_reward_not_enough_points', __( 'Não tens pontos suficientes para resgatar esta recompensa.', 'adam-membership' ) );
		}

		if ( $reward->is_single_claim() ) {
			if ( $this->member_owns_reward( $member, $reward ) ) {
				return new WP_Error( 'adam_membership_reward_already_owned', __( 'Já tens esta recompensa desbloqueada.', 'adam-membership' ) );
			}

			if ( $this->member_has_pending_request( $member, $reward ) ) {
				return new WP_Error( 'adam_membership_reward_pending_request', __( 'Já existe um pedido pendente para esta recompensa.', 'adam-membership' ) );
			}
		}

		$now        = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$redemption = $this->repository->create_redemption(
			array(
				'reward_id'      => $reward->id(),
				'member_id'      => $member->user_id(),
				'reward_name'    => $reward->name(),
				'reward_type'    => $reward->type(),
				'points_cost'    => $reward->points_cost(),
				'status'         => RewardRedemption::STATUS_PENDING,
				'created_at'     => $now,
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
				'reward_id'       => $reward->id(),
				'redemption_id'   => $redemption->id(),
				'points_cost'     => $reward->points_cost(),
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
			return new WP_Error( 'adam_membership_redemption_not_found', __( 'Pedido de recompensa não encontrado.', 'adam-membership' ) );
		}

		if ( RewardRedemption::STATUS_PENDING !== $redemption->status() && ! $automatic ) {
			return new WP_Error( 'adam_membership_redemption_not_pending', __( 'Este pedido já não está pendente.', 'adam-membership' ) );
		}

		$reward = $this->repository->find_reward( $redemption->reward_id() );
		$member = $this->members->find( $redemption->member_id() );

		if ( null === $reward || null === $member ) {
			return new WP_Error( 'adam_membership_redemption_missing_context', __( 'Não foi possível validar o pedido de recompensa.', 'adam-membership' ) );
		}

		if ( $reward->is_single_claim() && $this->member_owns_reward( $member, $reward, $redemption->id() ) ) {
			return new WP_Error( 'adam_membership_reward_already_owned', __( 'O sócio já tem esta recompensa desbloqueada.', 'adam-membership' ) );
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
			return new WP_Error( 'adam_membership_redemption_not_found', __( 'Pedido de recompensa não encontrado.', 'adam-membership' ) );
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
			return new WP_Error( 'adam_membership_redemption_not_found', __( 'Pedido de recompensa não encontrado.', 'adam-membership' ) );
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
		if ( ! $reward->is_visible() ) {
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

		return __( 'O conteúdo desta recompensa será revelado depois do resgate.', 'adam-membership' );
	}

	/**
	 * @return array<int, string>
	 */
	public function categories(): array {
		return array(
			'Cartão Digital',
			'Títulos',
			'Reconhecimento',
			'Surpresa',
			'Sorteios',
			'Físicas',
			'Experiências',
			'Outras',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function type_labels(): array {
		return array(
			Reward::TYPE_PERMANENT_UNLOCK => __( 'Desbloqueio permanente', 'adam-membership' ),
			Reward::TYPE_CONSUMABLE       => __( 'Consumível', 'adam-membership' ),
			Reward::TYPE_MYSTERY_REWARD   => __( 'Recompensa mistério', 'adam-membership' ),
			Reward::TYPE_PHYSICAL_REWARD  => __( 'Recompensa física', 'adam-membership' ),
			Reward::TYPE_MANUAL_REWARD    => __( 'Recompensa manual', 'adam-membership' ),
			Reward::TYPE_DIGITAL_COSMETIC => __( 'Cosmética digital', 'adam-membership' ),
			Reward::TYPE_RAFFLE_TICKET    => __( 'Bilhete extra de sorteio', 'adam-membership' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function rarity_labels(): array {
		return array(
			Reward::RARITY_COMMON    => __( 'Comum', 'adam-membership' ),
			Reward::RARITY_RARE      => __( 'Rara', 'adam-membership' ),
			Reward::RARITY_EPIC      => __( 'Épica', 'adam-membership' ),
			Reward::RARITY_LEGENDARY => __( 'Lendária', 'adam-membership' ),
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
			'name'              => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'description'       => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
			'category'          => isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : 'Outras',
			'type'              => $type,
			'rarity'            => $rarity,
			'points_cost'       => max( 0, absint( $data['points_cost'] ?? 0 ) ),
			'image_url'         => isset( $data['image_url'] ) ? esc_url_raw( (string) $data['image_url'] ) : '',
			'availability_label' => isset( $data['availability_label'] ) ? sanitize_text_field( (string) $data['availability_label'] ) : __( 'Disponível', 'adam-membership' ),
			'active'            => ! empty( $data['active'] ),
			'approval_required' => ! empty( $data['approval_required'] ),
			'mystery_reveal_text' => isset( $data['mystery_reveal_text'] ) ? sanitize_textarea_field( (string) $data['mystery_reveal_text'] ) : '',
			'reward_value'      => isset( $data['reward_value'] ) ? sanitize_text_field( (string) $data['reward_value'] ) : '',
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
			return new WP_Error( 'adam_membership_reward_image_type', __( 'O tipo de imagem não é permitido para recompensas.', 'adam-membership' ) );
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
			return new WP_Error( 'adam_membership_reward_image_upload', __( 'Não foi possível guardar a imagem da recompensa.', 'adam-membership' ) );
		}

		return array( 'image_url' => esc_url_raw( (string) $uploaded['url'] ) );
	}

	private function has_uploaded_file( array $file ): bool {
		return isset( $file['tmp_name'], $file['error'] ) && UPLOAD_ERR_OK === (int) $file['error'] && is_uploaded_file( (string) $file['tmp_name'] );
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
}
