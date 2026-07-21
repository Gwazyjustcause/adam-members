<?php
/**
 * Communication category registry.
 *
 * @package AdamMembership\Communication
 */

declare(strict_types=1);

namespace AdamMembership\Communication;

/**
 * Defines the communication categories and whether members may unsubscribe.
 */
final class CommunicationCategoryRegistry {
	public const TYPE_MANDATORY = 'mandatory';
	public const TYPE_OPTIONAL  = 'optional';

	/**
	 * Get all registered category definitions.
	 *
	 * The filter is the extension point for future categories. Preferences are
	 * stored by stable category ID, so adding an entry requires no schema change.
	 *
	 * @return array<string, array{id:string,label:string,type:string,aliases:array<int,string>}>
	 */
	public function all(): array {
		$categories = (array) apply_filters(
			'adam_membership_communication_categories',
			array(
				'assembleia-geral'    => array(
					'label' => __( 'Assembleia Geral', 'adam-membership' ),
					'type'  => self::TYPE_MANDATORY,
				),
				'quotas'              => array(
					'label' => __( 'Quotas', 'adam-membership' ),
					'type'  => self::TYPE_MANDATORY,
				),
				'regulamentos'        => array(
					'label' => __( 'Regulamentos', 'adam-membership' ),
					'type'  => self::TYPE_MANDATORY,
				),
				'seguro'              => array(
					'label' => __( 'Seguro', 'adam-membership' ),
					'type'  => self::TYPE_MANDATORY,
				),
				'seguranca'           => array(
					'label'   => __( 'Segurança', 'adam-membership' ),
					'type'    => self::TYPE_MANDATORY,
					'aliases' => array( 'Seguranca' ),
				),
				'conta-autenticacao'  => array(
					'label'   => __( 'Conta e Autenticação', 'adam-membership' ),
					'type'    => self::TYPE_MANDATORY,
					'aliases' => array( 'Conta e Autenticacao' ),
				),
				'protecao-dados'      => array(
					'label'   => __( 'Proteção de Dados (GDPR)', 'adam-membership' ),
					'type'    => self::TYPE_MANDATORY,
					'aliases' => array( 'Protecao de Dados', 'Protecao de Dados (GDPR)' ),
				),
				'urgente'             => array(
					'label' => __( 'Urgente', 'adam-membership' ),
					'type'  => self::TYPE_MANDATORY,
				),
				'eventos'             => array(
					'label' => __( 'Eventos', 'adam-membership' ),
					'type'  => self::TYPE_OPTIONAL,
				),
				'website'             => array(
					'label' => __( 'Website', 'adam-membership' ),
					'type'  => self::TYPE_OPTIONAL,
				),
				'informacao-geral'    => array(
					'label'   => __( 'Informação Geral', 'adam-membership' ),
					'type'    => self::TYPE_OPTIONAL,
					'aliases' => array( 'Informacao Geral' ),
				),
				'formacao'            => array(
					'label'   => __( 'Formação', 'adam-membership' ),
					'type'    => self::TYPE_OPTIONAL,
					'aliases' => array( 'Formacao' ),
				),
				'parceiros'           => array(
					'label' => __( 'Parceiros', 'adam-membership' ),
					'type'  => self::TYPE_OPTIONAL,
				),
				'promocoes'           => array(
					'label'   => __( 'Promoções', 'adam-membership' ),
					'type'    => self::TYPE_OPTIONAL,
					'aliases' => array( 'Promocoes' ),
				),
				'projetos-associacao' => array(
					'label'   => __( 'Projetos da Associação', 'adam-membership' ),
					'type'    => self::TYPE_OPTIONAL,
					'aliases' => array( 'Projetos da Associacao' ),
				),
			)
		);

		$validated = array();

		foreach ( $categories as $category_id => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$id    = sanitize_title( (string) ( $definition['id'] ?? $category_id ) );
			$label = sanitize_text_field( (string) ( $definition['label'] ?? '' ) );
			$type  = sanitize_key( (string) ( $definition['type'] ?? self::TYPE_MANDATORY ) );

			if ( '' === $id || '' === $label ) {
				continue;
			}

			$aliases = isset( $definition['aliases'] ) && is_array( $definition['aliases'] ) ? $definition['aliases'] : array();
			$aliases = array_values(
				array_filter(
					array_map(
						static fn ( mixed $alias ): string => is_scalar( $alias ) ? sanitize_text_field( (string) $alias ) : '',
						$aliases
					)
				)
			);

			$validated[ $id ] = array(
				'id'      => $id,
				'label'   => $label,
				'type'    => self::TYPE_OPTIONAL === $type ? self::TYPE_OPTIONAL : self::TYPE_MANDATORY,
				'aliases' => $aliases,
			);
		}

		return $validated;
	}

	/**
	 * Get optional category definitions.
	 *
	 * @return array<string, array{id:string,label:string,type:string,aliases:array<int,string>}>
	 */
	public function optional(): array {
		return array_filter(
			$this->all(),
			static fn ( array $category ): bool => self::TYPE_OPTIONAL === $category['type']
		);
	}

	/**
	 * Get mandatory category definitions.
	 *
	 * @return array<string, array{id:string,label:string,type:string,aliases:array<int,string>}>
	 */
	public function mandatory(): array {
		return array_filter(
			$this->all(),
			static fn ( array $category ): bool => self::TYPE_MANDATORY === $category['type']
		);
	}

	/**
	 * Resolve a stored label, alias, or stable ID to a category ID.
	 *
	 * @param string $value Stored category value.
	 */
	public function id_for( string $value ): ?string {
		$needle = sanitize_title( $value );

		if ( '' === $needle ) {
			return null;
		}

		foreach ( $this->all() as $category ) {
			$candidates = array_merge( array( $category['id'], $category['label'] ), $category['aliases'] );

			foreach ( $candidates as $candidate ) {
				if ( sanitize_title( $candidate ) === $needle ) {
					return $category['id'];
				}
			}
		}

		return null;
	}

	/**
	 * Determine whether a stored category is optional.
	 *
	 * Unknown legacy categories are mandatory by default so this feature never
	 * suppresses an email that was previously deliverable.
	 *
	 * @param string $value Stored category value.
	 */
	public function is_optional( string $value ): bool {
		$id         = $this->id_for( $value );
		$categories = $this->all();

		return null !== $id && isset( $categories[ $id ] ) && self::TYPE_OPTIONAL === $categories[ $id ]['type'];
	}
}
