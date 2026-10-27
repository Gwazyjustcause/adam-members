<?php
/** Production header, active/pending panels and preferences dialog with local data. */
declare(strict_types=1);
namespace AdamMembership\Announcement {
	class AnnouncementService {
		public function visible_for_member($member, $homepage = false): array { return [(object) ['id' => 1], (object) ['id' => 2]]; }
		public function is_unread($member, $announcement): bool { return true; }
	}
}
namespace AdamMembership\Core {
	class ManagedPages { public static function url($key): string { return 'https://member.test/socio/'; } }
	class SettingsRepository { public function renewal_page_url(): string { return 'https://member.test/renovar/'; } }
}
namespace AdamMembership\Member {
	class CardService {
		public function card_presentation($member): array { return ['selected_values' => []]; }
		public function member_cosmetic_options($member): array {
			return ['titles' => [['key' => 'title-explorer', 'name' => 'Explorador']], 'themes' => [['key' => 'theme-moss', 'name' => 'Floresta']], 'frames' => [['key' => 'frame-gold', 'name' => 'Dourado']]];
		}
		public function card_data($member): array { return ['member_number_ui' => 'ADAM-0123', 'validation_url' => 'https://member.test/validar/', 'qr_image_url' => 'https://member.test/qr/']; }
		public function render_card($data, $presentation): string { return '<article class="adam-digital-card">Card fixture</article>'; }
	}
}
namespace {
	class WP_User { public $display_name = 'Maria Silva'; public $user_email = 'maria@example.test'; }
	function __($s, $domain = '') { return $s; }
	function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
	function esc_html($s) { return esc_attr($s); }
	function esc_url($s) { return esc_attr($s); }
	function esc_html_e($s, $domain = '') { echo esc_html($s); }
	function esc_attr_e($s, $domain = '') { echo esc_attr($s); }
	function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $s)); }
	function sanitize_text_field($s) { return trim($s); }
	function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9-]/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s))), '-'); }
	function apply_filters($hook, $value) { return $value; }
	function get_user_by($key, $id) { return new WP_User(); }
	function get_current_user_id() { return 42; }
	function get_user_meta($id, $key, $single = true) {
		return ['estado' => ($GLOBALS['argv'][1] ?? '') === 'pending' ? 'Pendente' : 'Ativo', 'numero_socio' => 'ADAM-0123', 'validade_quota' => ($GLOBALS['argv'][1] ?? '') === 'expired' ? '2025-12-31' : '2027-12-31', 'data_adesao' => '2026-01-01', 'telefone' => '+351 900 000 000', 'equipa' => 'Mondego'][$key] ?? '';
	}
	function absint($n) { return abs((int) $n); }
	function current_time($format) { return strtotime('2026-10-27'); }
	function wp_date($format, $time) { return date($format, $time); }
	function wp_unslash($s) { return $s; }
	function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
	function checked($value) { if ($value) { echo 'checked'; } }
	function selected($value, $current) { if ($value === $current) { echo 'selected'; } }
	function sanitize_file_name($value) { return preg_replace('/[^a-zA-Z0-9._-]/', '', $value); }
	function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
	require __DIR__ . '/../src/Communication/CommunicationCategoryRegistry.php';
	require __DIR__ . '/../src/Communication/CommunicationPreferences.php';
	require __DIR__ . '/../src/Core/DisplayLabels.php';
	require __DIR__ . '/../src/Member/Member.php';
	require __DIR__ . '/../src/Member/MemberArea.php';
	$r = new \ReflectionClass(\AdamMembership\Member\MemberArea::class);
	$area = $r->newInstanceWithoutConstructor();
	$r->getProperty('announcements')->setValue($area, new \AdamMembership\Announcement\AnnouncementService());
	$r->getProperty('settings')->setValue($area, new \AdamMembership\Core\SettingsRepository());
	$r->getProperty('cards')->setValue($area, new \AdamMembership\Member\CardService());
	$r->getProperty('communication_preferences')->setValue($area, new \AdamMembership\Communication\CommunicationPreferences(new \AdamMembership\Communication\CommunicationCategoryRegistry()));
	$member = new \AdamMembership\Member\Member(42);
	echo '<!doctype html><html lang="pt"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0;font-family:Arial,sans-serif;background:#f4f7f3"><main class="adam-member-area adam-member-dashboard">';
	$r->getMethod('render_header')->invoke($area, $member);
	if (($GLOBALS['argv'][1] ?? '') === 'active') { $r->getMethod('render_card_workspace')->invoke($area, $member); }
	$r->getMethod(match ($GLOBALS['argv'][1] ?? '') { 'pending' => 'render_pending', 'expired' => 'render_expired', default => 'render_active' })->invoke($area, $member);
	echo '</main></body></html>';
}
