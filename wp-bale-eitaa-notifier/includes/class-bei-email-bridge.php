<?php
/**
 * ماژول پل ایمیل — فوروارد ایمیل‌های سایت (wp_mail) به پیام‌رسان.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Email_Bridge
 */
final class Bei_Email_Bridge {

	/**
	 * ثبت فیلتر روی wp_mail.
	 */
	public function __construct() {
		add_filter( 'wp_mail', array( $this, 'bridge' ), 10, 1 );
	}

	/**
	 * هوک اصلی پل ایمیل — بدون اختلال در روند ارسال خود ایمیل.
	 *
	 * @param array $args آرایه to/subject/message/headers/attachments.
	 * @return array همان آرایه بدون تغییر.
	 */
	public function bridge( $args ) {
		$options = Bei_Settings::get_options();

		if ( empty( $options['email_bridge'] ) ) {
			return $args;
		}
		if ( empty( $args['subject'] ) ) {
			return $args;
		}

		$subject = trim( $args['subject'] );
		$message = isset( $args['message'] ) ? $args['message'] : '';

		// فیلتر موضوع (اختیاری): هر خط = یک عبارت لازم در موضوع.
		$filter = array_filter( array_map( 'trim', explode( "\n", (string) $options['email_subject_filter'] ) ) );
		if ( ! empty( $filter ) ) {
			$found = false;
			foreach ( $filter as $needle ) {
				if ( '' !== $needle && false !== stripos( $subject, $needle ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return $args;
			}
		}

		// جلوگیری از ارسال تکراری (برخی افزونه‌ها wp_mail را چند بار صدا می‌زنند).
		$hash = md5( $subject . '|' . substr( $message, 0, 500 ) );
		if ( get_transient( 'bei_mail_' . $hash ) ) {
			return $args;
		}
		set_transient( 'bei_mail_' . $hash, 1, 10 * MINUTE_IN_SECONDS );

		/**
		 * فیلتر متن نهایی پیام ایمیل — قابل تغییر از بیرون.
		 *
		 * @param string $text متن ساخته‌شده.
		 * @param array  $args آرایه کامل wp_mail.
		 */
		$text = apply_filters( 'bei_mail_text', $this->format_message( $subject, $message ), $args );
		if ( '' === $text ) {
			return $args;
		}

		bei()->queue()->notify_async( $text, $options['email_bridge_targets'] );

		return $args;
	}

	/**
	 * استخراج متن ساده از بدنه ایمیل.
	 * برای ایمیل‌های MIME بخش text/plain استخراج و کدگذاری‌های
	 * base64 و quoted-printable باز می‌شوند.
	 *
	 * @param string $message بدنه خام ایمیل.
	 * @return string
	 */
	public function extract_plain( $message ) {
		$body = $message;

		if ( false !== stripos( $message, 'Content-Type:' ) ) {
			// سربرگ‌های همان بخش هم جدا استخراج می‌شوند تا کدگذاری درست باز شود.
			if ( preg_match( '/Content-Type:\s*text\/plain[^\r\n]*\r?\n((?:[A-Za-z0-9-]+:[^\r\n]*\r?\n)*)\r?\n(.*?)(?=\r?\n--[A-Za-z0-9_\.\-]+|\r?\nContent-Type:\s|\z)/s', $message, $m ) ) {
				$headers = $m[1];
				$body    = $m[2];

				if ( preg_match( '/Content-Transfer-Encoding:\s*base64/i', $headers ) ) {
					$body = base64_decode( preg_replace( '/\s+/', '', $body ) );
				} elseif ( preg_match( '/Content-Transfer-Encoding:\s*quoted-printable/i', $headers ) ) {
					$body = quoted_printable_decode( $body );
				}
			}
		}

		return $body;
	}

	/**
	 * تبدیل ایمیل به متن قابل ارسال در پیام‌رسان.
	 *
	 * @param string $subject موضوع ایمیل.
	 * @param string $message بدنه ایمیل.
	 * @return string
	 */
	public function format_message( $subject, $message ) {
		$body = $this->extract_plain( $message );

		// تبدیل تگ‌های رایج HTML به خط جدید و حذف بقیه تگ‌ها.
		$body = preg_replace( '#<br\s*/?>#i', "\n", $body );
		$body = preg_replace( '#</(p|div|h[1-6]|li|tr)>#i', "\n", $body );
		$body = strip_tags( $body );
		$body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// پاک‌سازی خطوط خالی اضافی و محدودسازی طول.
		$body = preg_replace( "/\n{3,}/", "\n\n", trim( $body ) );
		$body = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 2000 ) : substr( $body, 0, 2000 );

		$blog = get_bloginfo( 'name' );

		return sprintf(
			/* translators: 1: نام سایت، 2: موضوع ایمیل، 3: بدنه */
			__( "📧 ایمیل از «%1\$s»\n\n**موضوع:** %2\$s\n\n%3\$s", 'bale-eitaa-notifier' ),
			$blog,
			$subject,
			$body
		);
	}
}
