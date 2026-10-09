<?php
/**
 * Emails: user invitations, quote requests, and company touches on WooCommerce order emails.
 * Sent through WooCommerce's mailer so they use the store's email template.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin emails.
 */
class B2BCP_Emails {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_recipient_new_order', array( __CLASS__, 'company_recipients' ), 20, 2 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'company_header' ), 5, 4 );
	}

	/**
	 * Send an HTML email through the WooCommerce mailer.
	 *
	 * @param string|string[] $to          Recipients.
	 * @param string          $subject     Subject.
	 * @param string          $heading     Heading in the template.
	 * @param string          $body        Body HTML.
	 * @param string[]        $headers     Extra headers.
	 * @param string[]        $attachments File paths.
	 * @return bool
	 */
	public static function send( $to, $subject, $heading, $body, array $headers = array(), array $attachments = array() ) {
		$mailer    = WC()->mailer();
		$message   = $mailer->wrap_message( $heading, $body );
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		return (bool) $mailer->send( $to, $subject, $message, implode( "\r\n", $headers ), $attachments );
	}

	/**
	 * Company logo and name, as an email-safe table.
	 *
	 * @param int $company_id Company.
	 * @return string
	 */
	public static function company_block( $company_id ) {
		$company = b2bcp_get_company( $company_id );
		if ( ! $company ) {
			return '';
		}
		$logo = $company['logo_id'] ? wp_get_attachment_image_url( $company['logo_id'], 'medium' ) : '';
		$line = $company['color'] ? $company['color'] : '#dddddd';
		$html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px 0;border-bottom:3px solid ' . esc_attr( $line ) . ';"><tr>';
		if ( $logo ) {
			$html .= '<td style="padding:0 12px 12px 0;width:1%;white-space:nowrap;vertical-align:middle;"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $company['name'] ) . '" height="48" style="height:48px;width:auto;display:block;border:0;"></td>';
		}
		$html .= '<td style="padding:0 0 12px 0;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;">' . esc_html( $company['name'] ) . '</td>';
		$html .= '</tr></table>';
		return $html;
	}

	/**
	 * Logo + name above the order table, for orders placed for a company.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin email.
	 * @param bool     $plain_text    Plain text.
	 * @param WC_Email $email         Email.
	 */
	public static function company_header( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$company_id = (int) $order->get_meta( '_b2bcp_company_id' );
		if ( ! $company_id ) {
			return;
		}
		if ( $plain_text ) {
			/* translators: %s: company */
			echo esc_html( sprintf( __( 'Company: %s', 'b2b-contract-portal' ), $order->get_meta( '_b2bcp_company_name' ) ) ) . "\n\n";
			return;
		}
		echo self::company_block( $company_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in company_block.
	}

	/**
	 * Add the company's extra recipients to "New order".
	 *
	 * @param string   $recipient Recipients.
	 * @param WC_Order $order     Order.
	 * @return string
	 */
	public static function company_recipients( $recipient, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $recipient;
		}
		$company = b2bcp_get_company( (int) $order->get_meta( '_b2bcp_company_id' ) );
		if ( $company && $company['notify_email'] ) {
			$recipient = trim( $recipient . ', ' . $company['notify_email'], ', ' );
		}
		return $recipient;
	}

	/**
	 * Invitation to order for a company.
	 *
	 * @param WP_User $user       User.
	 * @param int     $company_id Company.
	 * @param bool    $is_new     New account (needs a password).
	 */
	public static function send_invite( $user, $company_id, $is_new ) {
		$company   = b2bcp_get_company( $company_id );
		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$account   = wc_get_page_permalink( 'myaccount' );
		$cta_url   = $account;
		$cta_label = __( 'Go to your account', 'b2b-contract-portal' );

		if ( $is_new ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$cta_url   = add_query_arg(
					array(
						'key' => $key,
						'id'  => $user->ID,
					),
					wc_get_endpoint_url( 'lost-password', '', $account )
				);
				$cta_label = __( 'Set your password', 'b2b-contract-portal' );
			}
		}

		/* translators: 1: company, 2: store */
		$subject = sprintf( __( 'You can now order for %1$s at %2$s', 'b2b-contract-portal' ), $company ? $company['name'] : '', $site );
		$body    = self::company_block( $company_id );
		/* translators: %s: first name */
		$body .= '<p>' . esc_html( sprintf( __( 'Hi %s,', 'b2b-contract-portal' ), $user->first_name ? $user->first_name : $user->display_name ) ) . '</p>';
		/* translators: 1: company, 2: store */
		$body .= '<p>' . esc_html( sprintf( __( '%2$s set up an ordering account for %1$s. You will see your company\'s products and contract prices when you log in.', 'b2b-contract-portal' ), $company ? $company['name'] : '', $site ) ) . '</p>';
		if ( $is_new ) {
			/* translators: %s: username */
			$body .= '<p>' . esc_html( sprintf( __( 'Your username is %s. Choose a password with the button below.', 'b2b-contract-portal' ), $user->user_login ) ) . '</p>';
		}
		$body .= self::button( $cta_url, $cta_label, $company ? $company['color'] : '' );

		self::send( $user->user_email, $subject, __( 'Your ordering account', 'b2b-contract-portal' ), $body );
	}

	/**
	 * Email-safe button (works in Outlook desktop through VML).
	 *
	 * @param string $url   Link.
	 * @param string $label Text.
	 * @param string $color Background (#rrggbb) or '' for the store color.
	 * @return string
	 */
	public static function button( $url, $label, $color = '' ) {
		$bg  = $color ? $color : b2bcp_sanitize_hex( get_option( 'woocommerce_email_base_color', '#7f54b3' ) );
		$bg  = $bg ? $bg : '#7f54b3';
		$ink = b2bcp_text_on( $bg );
		$url = esc_url( $url );
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;"><tr><td>'
			. '<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $url . '" style="height:44px;v-text-anchor:middle;width:240px;" arcsize="10%" stroke="f" fillcolor="' . esc_attr( $bg ) . '"><w:anchorlock/><center style="color:' . esc_attr( $ink ) . ';font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">' . esc_html( $label ) . '</center></v:roundrect><![endif]-->'
			. '<!--[if !mso]><!--><a href="' . $url . '" style="background-color:' . esc_attr( $bg ) . ';border-radius:4px;color:' . esc_attr( $ink ) . ';display:inline-block;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;line-height:44px;text-align:center;text-decoration:none;padding:0 24px;">' . esc_html( $label ) . '</a><!--<![endif]-->'
			. '</td></tr></table>';
	}

	/**
	 * New quote request: one email to the store, a confirmation to the visitor.
	 *
	 * @param int $quote_id Request.
	 */
	public static function quote_received( $quote_id ) {
		$data    = B2BCP_Quotes::get( $quote_id );
		$product = $data['product'];
		$contact = $data['contact'];
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$rows = array(
			__( 'Product', 'b2b-contract-portal' ) => $product ? $product->get_name() : '',
			__( 'Name', 'b2b-contract-portal' )    => $contact['name'] ?? '',
			__( 'Company', 'b2b-contract-portal' ) => ( $contact['company'] ?? '' ) . ( $data['company_id'] ? ' (' . __( 'existing client', 'b2b-contract-portal' ) . ')' : '' ),
			__( 'Email', 'b2b-contract-portal' )   => $contact['email'] ?? '',
			__( 'Phone', 'b2b-contract-portal' )   => $contact['phone'] ?? '',
		);
		$attachments = array();
		$total       = 0;
		foreach ( $data['answers'] as $answer ) {
			$rows[ $answer['label'] ] = $answer['value'];
			if ( ! empty( $answer['attachment_id'] ) ) {
				$path = get_attached_file( (int) $answer['attachment_id'] );
				if ( $path && file_exists( $path ) && ( $total + filesize( $path ) ) <= B2BCP_Quotes::MAX_FILE_BYTES ) {
					$attachments[] = $path;
					$total        += filesize( $path );
				}
			}
		}

		$table = '<table role="presentation" cellpadding="6" cellspacing="0" border="1" width="100%" style="border-collapse:collapse;border-color:#e5e5e5;font-family:Arial,Helvetica,sans-serif;font-size:14px;">';
		foreach ( $rows as $label => $value ) {
			$table .= '<tr><th align="left" valign="top" style="width:35%;background:#f7f7f7;">' . esc_html( $label ) . '</th><td valign="top">' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
		}
		$table .= '</table>';

		// To the store.
		$to = B2BCP_Settings::quote_recipients();
		/* translators: 1: product, 2: name */
		$subject = sprintf( __( 'New quote request: %1$s from %2$s', 'b2b-contract-portal' ), $product ? $product->get_name() : '', $contact['name'] ?? '' );
		$body    = $table . self::button( admin_url( 'post.php?post=' . (int) $quote_id . '&action=edit' ), __( 'Open the request', 'b2b-contract-portal' ) );
		$headers = array();
		if ( ! empty( $contact['email'] ) ) {
			$headers[] = 'Reply-To: ' . ( $contact['name'] ? '"' . str_replace( '"', '', $contact['name'] ) . '" ' : '' ) . '<' . $contact['email'] . '>';
		}
		self::send( $to, $subject, __( 'New quote request', 'b2b-contract-portal' ), $body, $headers, $attachments );

		// To the visitor.
		if ( ! empty( $contact['email'] ) ) {
			/* translators: %s: store */
			$subject = sprintf( __( 'We received your quote request — %s', 'b2b-contract-portal' ), $site );
			$body = $data['company_id'] ? self::company_block( $data['company_id'] ) : '';
			/* translators: %s: name */
			$body .= '<p>' . esc_html( sprintf( __( 'Hi %s,', 'b2b-contract-portal' ), $contact['name'] ) ) . '</p>';
			$body .= '<p>' . esc_html__( 'Thanks for your request. We will review it and get back to you with pricing. Here is what you sent:', 'b2b-contract-portal' ) . '</p>';
			$body .= $table;
			self::send( $contact['email'], $subject, __( 'Quote request received', 'b2b-contract-portal' ), $body );
		}
	}
}
