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
		$branding      = Penalis_Config::email_branding();
		$logo_url      = Penalis_Config::resolved_email_logo_url();
		$contact_email = (string) $branding['contact_email'];
		$button_color  = (string) $branding['button_color'];
		$link_color    = (string) $branding['link_color'];
		$body          = self::render_content( (string) $content, $button_color, $link_color );
		$preview       = esc_html( (string) $preheader );

		if ( '' !== (string) $footer_context ) {
			$footer_raw = (string) $footer_context;
		} else {
			$footer_raw = str_replace( '{email_kontak}', $contact_email, (string) $branding['footer_text'] );
		}

		$footer = self::render_content( $footer_raw, $button_color, $link_color );
		$mailto = '<a href="mailto:' . esc_attr( $contact_email ) . '" style="color:' . esc_attr( $link_color ) . ';">' . esc_html( $contact_email ) . '</a>';
		$footer = str_replace( esc_html( $contact_email ), $mailto, $footer );
		if ( 'admin@penalis.com' !== $contact_email ) {
			$footer = str_replace( 'admin@penalis.com', $mailto, $footer );
		}

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
	 * @param string $content      Plain-text body.
	 * @param string $button_color CTA button hex color.
	 * @param string $link_color   Inline link hex color.
	 * @return string
	 */
	private static function render_content( $content, $button_color = Penalis_Config::DEFAULT_BUTTON_COLOR, $link_color = Penalis_Config::DEFAULT_LINK_COLOR ) {
		$paragraphs = preg_split( '/\n\s*\n/', trim( $content ) );
		$html       = '';

		foreach ( (array) $paragraphs as $paragraph ) {
			$lines = explode( "\n", trim( $paragraph ) );

			// Standalone markdown link on its own line becomes a prominent button.
			if ( 1 === count( $lines ) && preg_match( '#\A\[([^\]\r\n]+)\]\((https?://[^\s\)\"\'<>]+)\)\z#i', trim( $lines[0] ), $btn_match ) ) {
				$label = trim( $btn_match[1] );
				$url   = esc_url( trim( $btn_match[2] ) );
				$html .= '<p style="margin:0 0 20px;"><a href="' . $url . '" style="display:inline-block;padding:12px 20px;border-radius:4px;background-color:' . esc_attr( $button_color ) . ';color:#ffffff;font-weight:700;text-decoration:none;">' . esc_html( $label ) . '</a></p>';
				continue;
			}

			// Standalone raw URL on its own line becomes a button with an inferred label.
			if ( 1 === count( $lines ) && preg_match( '#\Ahttps?://[^\s]+\z#i', trim( $lines[0] ) ) ) {
				$url   = esc_url( trim( $lines[0] ) );
				$label = self::get_action_label( $url );
				$html .= '<p style="margin:0 0 20px;"><a href="' . $url . '" style="display:inline-block;padding:12px 20px;border-radius:4px;background-color:' . esc_attr( $button_color ) . ';color:#ffffff;font-weight:700;text-decoration:none;">' . esc_html( $label ) . '</a></p>';
				continue;
			}

			// Standalone six-digit verification code on its own line becomes a highlighted OTP box.
			if ( 1 === count( $lines ) && preg_match( '/\A\d{6}\z/', trim( $lines[0] ) ) ) {
				$code  = esc_html( trim( $lines[0] ) );
				$html .= '<p style="display:inline-block;margin:0 0 16px;padding:10px 14px 10px 18px;border-radius:6px;border:1px solid #e5e5e5;background-color:#f0f0f0;color:#333333;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\'Liberation Mono\',\'Courier New\',monospace;font-size:18px;font-weight:700;line-height:1.2;letter-spacing:4px;-webkit-user-select:all;user-select:all;">' . $code . '</p>';
				continue;
			}

			$escaped_lines = array();
			foreach ( $lines as $line ) {
				$escaped_lines[] = self::render_line( $line, $link_color );
			}

			$html .= '<p style="margin:0 0 16px;">' . implode( '<br>', $escaped_lines ) . '</p>';
		}

		return $html;
	}

	/**
	 * Escape a line while parsing markdown links, bold text, and raw URLs.
	 *
	 * @param string $line       Plain-text line.
	 * @param string $link_color Inline link hex color.
	 * @return string
	 */
	private static function render_line( $line, $link_color = Penalis_Config::DEFAULT_LINK_COLOR ) {
		$tokens = array();
		$i      = 0;

		// 1. Markdown links: [text](https://url).
		$line = preg_replace_callback(
			'#\[([^\]\r\n]+)\]\((https?://[^\s\)\"\'<>]+)\)#i',
			static function ( $matches ) use ( &$tokens, &$i, $link_color ) {
				$key            = '###PENALISLINK' . ( $i++ ) . '###';
				$text           = esc_html( $matches[1] );
				$url            = esc_url( $matches[2] );
				$tokens[ $key ] = '<a href="' . $url . '" style="color:' . esc_attr( $link_color ) . ';text-decoration:underline;">' . $text . '</a>';
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
			static function ( $matches ) use ( &$tokens, &$i, $link_color ) {
				$key            = '###PENALISRAW' . ( $i++ ) . '###';
				$url            = esc_url( $matches[0] );
				$tokens[ $key ] = '<a href="' . $url . '" style="color:' . esc_attr( $link_color ) . ';text-decoration:underline;">' . esc_html( $matches[0] ) . '</a>';
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
