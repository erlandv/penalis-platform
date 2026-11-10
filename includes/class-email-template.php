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
			'penalis_reset' => 'Atur Password Baru',
		);
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( (string) $query, $parameters );

		foreach ( $labels as $parameter => $label ) {
			if ( isset( $parameters[ $parameter ] ) ) {
				return $label;
			}
		}

		if ( isset( $parameters['page'] ) && 'penalis-review' === $parameters['page'] ) {
			return 'Review Karya Tulis';
		}

		if ( isset( $parameters['view'] ) && 'editor' === $parameters['view'] ) {
			return 'Buka Karya Tulis';
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

			// Standalone markdown link on its own line becomes a prominent button.
			if ( 1 === count( $lines ) && preg_match( '#\A\[([^\]\r\n]+)\]\((https?://[^\s\)\"\'<>]+)\)\z#i', trim( $lines[0] ), $btn_match ) ) {
				$label = trim( $btn_match[1] );
				$url   = esc_url( trim( $btn_match[2] ) );
				$html .= '<p style="margin:0 0 20px;"><a href="' . $url . '" style="display:inline-block;padding:12px 20px;border-radius:4px;background-color:#5145cd;color:#ffffff;font-weight:700;text-decoration:none;">' . esc_html( $label ) . '</a></p>';
				continue;
			}

			// Standalone raw URL on its own line becomes a button with an inferred label.
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
	 * Escape a line while parsing markdown links, bold text, and raw URLs.
	 *
	 * @param string $line Plain-text line.
	 * @return string
	 */
	private static function render_line( $line ) {
		$tokens = array();
		$i      = 0;

		// 1. Markdown links: [text](https://url).
		$line = preg_replace_callback(
			'#\[([^\]\r\n]+)\]\((https?://[^\s\)\"\'<>]+)\)#i',
			static function ( $matches ) use ( &$tokens, &$i ) {
				$key            = '###PENALISLINK' . ( $i++ ) . '###';
				$text           = esc_html( $matches[1] );
				$url            = esc_url( $matches[2] );
				$tokens[ $key ] = '<a href="' . $url . '" style="color:#2873c8;text-decoration:underline;">' . $text . '</a>';
				return $key;
			},
			$line
		);

		// 2. Bold text: **text**.
		$line = preg_replace_callback(
			'#\*\*([^*\r\n]+)\*\*#',
			static function ( $matches ) use ( &$tokens, &$i ) {
				$key            = '###PENALISBOLD' . ( $i++ ) . '###';
				$text           = esc_html( $matches[1] );
				$tokens[ $key ] = '<strong>' . $text . '</strong>';
				return $key;
			},
			$line
		);

		// 3. Raw URLs: https://...
		$line = preg_replace_callback(
			'#https?://[^\s<>]+#i',
			static function ( $matches ) use ( &$tokens, &$i ) {
				$key            = '###PENALISRAW' . ( $i++ ) . '###';
				$url            = esc_url( $matches[0] );
				$tokens[ $key ] = '<a href="' . $url . '" style="color:#2873c8;text-decoration:underline;">' . esc_html( $matches[0] ) . '</a>';
				return $key;
			},
			$line
		);

		// 4. Escape all remaining text for safety.
		$escaped = esc_html( $line );

		// 5. Restore substituted safe HTML tokens.
		if ( ! empty( $tokens ) ) {
			$escaped = strtr( $escaped, $tokens );
		}

		return $escaped;
	}
}
