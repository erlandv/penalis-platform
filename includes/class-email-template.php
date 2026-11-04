<?php
/**
 * Branded HTML email layout for transactional messages.
 *
 * @package Penalis_Platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders plain-text message content inside the Penalis email design.
 */
final class Penalis_Email_Template {

	/**
	 * Render a complete HTML email.
	 *
	 * URLs on their own line become a prominent, accessible call-to-action;
	 * URLs within a sentence remain ordinary links. All dynamic text and URLs
	 * are escaped at the point where they enter the markup.
	 *
	 * @param string $content   Plain-text body.
	 * @param string $preheader      Preview text shown by email clients.
	 * @param string $footer_context Purpose-specific explanation for the recipient.
	 * @return string
	 */
	public static function render( $content, $preheader = '', $footer_context = '' ) {
		$logo_url       = 'https://penalis.com/wp-content/uploads/2021/01/logo-penalis.png';
		$body           = self::render_content( (string) $content );
		$preview        = esc_html( (string) $preheader );
		$footer_context = '' !== (string) $footer_context ? (string) $footer_context : 'Email ini dikirim otomatis. Kamu menerima email ini karena kamu terdaftar sebagai penulis di platform Penalis. Beberapa isi email mungkin bersifat rahasia, sebaiknya jangan bagikan isi email ini kepada siapapun. Jika ada pertanyaan atau kendala, hubungi admin@penalis.com.';
		$footer         = self::render_content( $footer_context );
		$footer         = str_replace( 'admin@penalis.com', '<a href="mailto:admin@penalis.com" style="color:#2873c8;">admin@penalis.com</a>', $footer );

		return '<!doctype html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . $preview . '</title></head>' .
			'<body style="margin:0;padding:0;background-color:#f5f5f5;">' .
			'<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $preview . '</div>' .
			'<table role="presentation" border="0" width="100%" cellspacing="0" cellpadding="0" style="margin:0;padding:0;background-color:#f5f5f5;"><tr><td align="center" style="padding:20px 10px;">' .
			'<table role="presentation" border="0" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background-color:#fefefe;border:1px solid #e5e5e5;border-radius:8px;border-collapse:separate;overflow:hidden;">' .
			'<tr><td style="padding:16px;background-color:#f0f0f0;"><img src="' . esc_url( $logo_url ) . '" alt="Penalis" width="130" style="display:block;width:130px;max-width:100%;height:auto;border:0;"></td></tr>' .
			'<tr><td style="padding:20px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#333333;">' . $body . '</td></tr>' .
			'<tr><td style="padding:20px;background-color:#f0f0f0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#777777;"><i>' . $footer . '</i></td></tr>' .
			'</table></td></tr></table></body></html>';
	}

	/**
	 * Choose an explicit label for common account actions.
	 *
	 * @param string $url Action URL.
	 * @return string
	 */
	private static function get_action_label( $url ) {
		$labels = array(
			'penalis_verify'       => 'Konfirmasi Email',
			'penalis_reset'        => 'Atur Password Baru',
			'penalis_activate'     => 'Aktifkan Akun',
			'penalis_email_change' => 'Konfirmasi Email Baru',
		);
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( (string) $query, $parameters );

		foreach ( $labels as $parameter => $label ) {
			if ( isset( $parameters[ $parameter ] ) ) {
				return $label;
			}
		}

		return 'Buka Dashboard';
	}

	/**
	 * Render escaped paragraphs and safe links.
	 *
	 * @param string $content Plain-text body.
	 * @return string
	 */
	private static function render_content( $content ) {
		$paragraphs = preg_split( '/\n\s*\n/', trim( $content ) );
		$html       = '';

		foreach ( (array) $paragraphs as $paragraph ) {
			$lines = explode( "\n", trim( $paragraph ) );
			if ( 1 === count( $lines ) && preg_match( '#\Ahttps?://[^\s]+\z#i', trim( $lines[0] ) ) ) {
				$url   = esc_url( trim( $lines[0] ) );
				$label = self::get_action_label( $url );
				$html .= '<p style="margin:0 0 20px;"><a href="' . $url . '" style="display:inline-block;padding:12px 20px;border-radius:4px;background-color:#5145cd;color:#ffffff;font-weight:700;text-decoration:none;">' . esc_html( $label ) . '</a></p>';
				continue;
			}

			$escaped_lines = array();
			foreach ( $lines as $line ) {
				$escaped_lines[] = self::render_line( $line );
			}

			$html .= '<p style="margin:0 0 16px;">' . implode( '<br>', $escaped_lines ) . '</p>';
		}

		return $html;
	}

	/**
	 * Escape a line while linking any HTTPS or HTTP URLs it contains.
	 *
	 * @param string $line Plain-text line.
	 * @return string
	 */
	private static function render_line( $line ) {
		$pattern = '#https?://[^\s<]+#i';
		$count   = preg_match_all( $pattern, $line, $matches, PREG_OFFSET_CAPTURE );

		if ( ! $count ) {
			return esc_html( $line );
		}

		$html   = '';
		$offset = 0;
		foreach ( $matches[0] as $match ) {
			$url    = $match[0];
			$start  = $match[1];
			$html  .= esc_html( substr( $line, $offset, $start - $offset ) );
			$html  .= '<a href="' . esc_url( $url ) . '" style="color:#2873c8;text-decoration:underline;">' . esc_html( $url ) . '</a>';
			$offset = $start + strlen( $url );
		}

		return $html . esc_html( substr( $line, $offset ) );
	}
}
