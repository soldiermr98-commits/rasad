<?php
/**
 * Referrer classification: direct / search / social / other / campaign,
 * plus internal site-search term extraction.
 *
 * Pure logic — no database. Row resolution (rsd_referrers upserts) happens
 * in Rasad_Process_Aggregator.
 *
 * @package Rasad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies incoming referrers and campaign parameters.
 */
final class Rasad_Process_Referrer {

	// rsd_referrers.type values (part of the data model — never renumber).
	const TYPE_DIRECT   = 1;
	const TYPE_SEARCH   = 2;
	const TYPE_SOCIAL   = 3;
	const TYPE_OTHER    = 4;
	const TYPE_CAMPAIGN = 5;

	/**
	 * Two-level public suffixes where the registrable domain needs 3 labels.
	 *
	 * @var string[]
	 */
	private static $extra_suffixes = array(
		'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'sch.uk',
		'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
		'co.nz', 'net.nz', 'org.nz',
		'co.za', 'org.za', 'net.za',
		'co.ir', 'com.ir', 'net.ir', 'org.ir', 'ac.ir', 'gov.ir', 'web.ir', 'blog.ir',
		'co.il', 'org.il', 'net.il', 'ac.il', 'gov.il',
		'co.in', 'firm.in', 'net.in', 'org.in', 'gen.in', 'ind.in',
		'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp', 'ad.jp',
		'com.br', 'net.br', 'org.br', 'gov.br',
		'com.mx', 'org.mx', 'net.mx', 'gob.mx',
		'com.ar', 'net.ar', 'org.ar', 'gob.ar',
		'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn',
		'com.tw', 'net.tw', 'org.tw', 'gov.tw',
		'com.hk', 'net.hk', 'org.hk', 'gov.hk',
		'com.sg', 'net.sg', 'org.sg', 'gov.sg',
		'co.kr', 'or.kr', 'ne.kr', 're.kr', 'pe.kr',
		'com.tr', 'net.tr', 'org.tr', 'gov.tr', 'edu.tr',
		'com.ua', 'net.ua', 'org.ua', 'gov.ua', 'in.ua',
		'com.pl', 'net.pl', 'org.pl', 'gov.pl',
		'com.sa', 'net.sa', 'org.sa', 'gov.sa',
		'com.pk', 'net.pk', 'org.pk', 'gov.pk',
		'com.eg', 'net.eg', 'org.eg', 'gov.eg',
		'com.vn', 'net.vn', 'org.vn', 'gov.vn',
		'com.my', 'net.my', 'org.my', 'gov.my',
		'com.ph', 'net.ph', 'org.ph', 'gov.ph',
		'com.bd', 'net.bd', 'org.bd', 'gov.bd',
		'com.ng', 'net.ng', 'org.ng', 'gov.ng',
		'com.co', 'net.co', 'org.co', 'gov.co',
		'com.ve', 'net.ve', 'org.ve', 'gov.ve',
		'com.pe', 'net.pe', 'org.pe', 'gob.pe',
		'com.ec', 'net.ec', 'org.ec', 'gov.ec',
		'com.uy', 'net.uy', 'org.uy', 'gub.uy',
		'com.gh', 'net.gh', 'org.gh', 'gov.gh',
		'com.kw', 'net.kw', 'org.kw', 'gov.kw',
		'com.qa', 'net.qa', 'org.qa', 'gov.qa',
		'com.bh', 'net.bh', 'org.bh', 'gov.bh',
		'com.om', 'net.om', 'org.om', 'gov.om',
		'com.jo', 'net.jo', 'org.jo', 'gov.jo',
		'com.lb', 'net.lb', 'org.lb', 'gov.lb',
		'com.iq', 'net.iq', 'org.iq', 'gov.iq',
		'com.sy', 'net.sy', 'org.sy', 'gov.sy',
		'com.ye', 'net.ye', 'org.ye', 'gov.ye',
		'com.dz', 'net.dz', 'org.dz', 'gov.dz',
		'com.tn', 'net.tn', 'org.tn', 'gov.tn',
		'com.ma', 'net.ma', 'org.ma', 'gov.ma',
		'co.th', 'in.th', 'ac.th', 'go.th', 'or.th',
		'co.id', 'or.id', 'ac.id', 'web.id', 'go.id',
	);

	/**
	 * Known search engines: host regex => display name.
	 *
	 * @return array<string,string>
	 */
	public static function search_engines() {
		$map = array(
			'/(?:^|\.)google\.[a-z]{2,3}(?:\.[a-z]{2})?$/'      => 'Google',
			'/(?:^|\.)bing\.com$/'                              => 'Bing',
			'/(?:^|\.)duckduckgo\.com$/'                        => 'DuckDuckGo',
			'/(?:^|\.)yandex\.[a-z.]+$/'                        => 'Yandex',
			'/(?:^|\.)yahoo\.[a-z.]+$/'                         => 'Yahoo',
			'/(?:^|\.)baidu\.com$/'                             => 'Baidu',
			'/(?:^|\.)baidu\.cn$/'                              => 'Baidu',
			'/(?:^|\.)search\.brave\.com$/'                     => 'Brave Search',
			'/(?:^|\.)ecosia\.org$/'                            => 'Ecosia',
			'/(?:^|\.)startpage\.com$/'                         => 'Startpage',
			'/(?:^|\.)qwant\.com$/'                             => 'Qwant',
			'/(?:^|\.)naver\.com$/'                             => 'Naver',
			'/(?:^|\.)seznam\.cz$/'                             => 'Seznam',
			'/(?:^|\.)ask\.com$/'                               => 'Ask',
			'/(?:^|\.)aol\.com$/'                               => 'AOL',
			'/(?:^|\.)swisscows\.com$/'                         => 'Swisscows',
			'/(?:^|\.)presearch\.org$/'                         => 'Presearch',
			'/(?:^|\.)yep\.com$/'                               => 'Yep',
			'/(?:^|\.)search\.marcellus\.ir$/'                  => 'Marcellus',
		);

		/**
		 * Filters the search-engine host patterns.
		 *
		 * @param array<string,string> $map Host regex => display name.
		 */
		return (array) apply_filters( 'rasad_search_engines', $map );
	}

	/**
	 * Known social networks: host regex => display name.
	 *
	 * @return array<string,string>
	 */
	public static function social_networks() {
		$map = array(
			'/(?:^|\.)instagram\.com$/'      => 'Instagram',
			'/(?:^|\.)facebook\.com$/'       => 'Facebook',
			'/(?:^|\.)fb\.me$/'              => 'Facebook',
			'/(?:^|\.)fb\.com$/'             => 'Facebook',
			'/(?:^|\.)x\.com$/'              => 'X (Twitter)',
			'/(?:^|\.)twitter\.com$/'        => 'X (Twitter)',
			'/(?:^|\.)t\.co$/'               => 'X (Twitter)',
			'/(?:^|\.)linkedin\.com$/'       => 'LinkedIn',
			'/(?:^|\.)pinterest\.[a-z.]+$/'  => 'Pinterest',
			'/(?:^|\.)t\.me$/'               => 'Telegram',
			'/(?:^|\.)telegram\.me$/'        => 'Telegram',
			'/(?:^|\.)telegram\.org$/'       => 'Telegram',
			'/(?:^|\.)whatsapp\.com$/'       => 'WhatsApp',
			'/(?:^|\.)wa\.me$/'              => 'WhatsApp',
			'/(?:^|\.)eitaa\.com$/'          => 'Eitaa',
			'/(?:^|\.)rubika\.ir$/'          => 'Rubika',
			'/(?:^|\.)rubika\.com$/'         => 'Rubika',
			'/(?:^|\.)aparat\.com$/'         => 'Aparat',
			'/(?:^|\.)a360\.ir$/'            => 'Aparat',
			'/(?:^|\.)youtube\.com$/'        => 'YouTube',
			'/(?:^|\.)youtu\.be$/'           => 'YouTube',
			'/(?:^|\.)tiktok\.com$/'         => 'TikTok',
			'/(?:^|\.)dailymotion\.com$/'    => 'Dailymotion',
			'/(?:^|\.)reddit\.com$/'         => 'Reddit',
			'/(?:^|\.)discord\.com$/'        => 'Discord',
			'/(?:^|\.)discord\.gg$/'         => 'Discord',
			'/(?:^|\.)vk\.com$/'             => 'VK',
			'/(?:^|\.)vk\.ru$/'              => 'VK',
			'/(?:^|\.)ok\.ru$/'              => 'OK.ru',
			'/(?:^|\.)tumblr\.com$/'         => 'Tumblr',
			'/(?:^|\.)medium\.com$/'         => 'Medium',
			'/(?:^|\.)snapchat\.com$/'       => 'Snapchat',
			'/(?:^|\.)quora\.com$/'          => 'Quora',
			'/(?:^|\.)mastodon\.social$/'    => 'Mastodon',
			'/(?:^|\.)mstdn\.social$/'       => 'Mastodon',
			'/(?:^|\.)threads\.net$/'        => 'Threads',
			'/(?:^|\.)koo\.app$/'            => 'Koo',
			'/(?:^|\.)namnak\.com$/'         => 'Namnak',
			'/(?:^|\.)cloob\.com$/'          => 'Cloob',
			'/(?:^|\.)iranlounge\.ir$/'      => 'IranLounge',
		);

		/**
		 * Filters the social-network host patterns.
		 *
		 * @param array<string,string> $map Host regex => display name.
		 */
		return (array) apply_filters( 'rasad_social_patterns', $map );
	}

	/**
	 * Classifies a raw referrer URL into a referrer row descriptor.
	 *
	 * @param string $referer Raw referrer URL (usually HTTP_REFERER).
	 * @return array{domain:string,type:int,name:string} Direct => type 1, domain ''.
	 */
	public static function classify( $referer ) {
		$direct = array(
			'domain' => '',
			'type'   => self::TYPE_DIRECT,
			'name'   => '',
		);

		if ( ! is_string( $referer ) || '' === trim( $referer ) ) {
			return $direct;
		}

		$host = strtolower( (string) parse_url( trim( $referer ), PHP_URL_HOST ) );

		if ( '' === $host ) {
			// Relative referrer or malformed — treat as direct.
			return $direct;
		}

		$host = self::normalize_host( $host );
		$site = self::normalize_host( strtolower( (string) Rasad_Settings::site_host() ) );

		// Internal navigation is not a referral.
		if ( '' !== $site && ( $host === $site || self::is_subdomain_of( $host, $site ) ) ) {
			return $direct;
		}

		$match = self::match_map( $host, self::search_engines() );

		if ( null !== $match ) {
			$row = array(
				'domain' => self::base_domain( $host ),
				'type'   => self::TYPE_SEARCH,
				'name'   => $match,
			);
		} else {
			$match = self::match_map( $host, self::social_networks() );

			if ( null !== $match ) {
				$row = array(
					'domain' => self::base_domain( $host ),
					'type'   => self::TYPE_SOCIAL,
					'name'   => $match,
				);
			} else {
				$row = array(
					'domain' => self::base_domain( $host ),
					'type'   => self::TYPE_OTHER,
					'name'   => '',
				);
			}
		}

		/**
		 * Filters the classification of one referrer.
		 *
		 * @param array{domain:string,type:int,name:string} $row     Row descriptor.
		 * @param string                                    $referer Raw referrer URL.
		 * @param string                                    $host    Normalized host.
		 */
		$row = apply_filters( 'rasad_referrer_classification', $row, $referer, $host );

		$row['domain'] = isset( $row['domain'] ) ? (string) $row['domain'] : '';
		$row['type']   = isset( $row['type'] ) ? (int) $row['type'] : self::TYPE_OTHER;
		$row['name']   = isset( $row['name'] ) ? (string) $row['name'] : '';

		return $row;
	}

	/**
	 * Builds a campaign referrer row from UTM parameters (type 5).
	 *
	 * @param array<string,mixed> $hit Sanitized hit with us/um/uc/ut/ud keys.
	 * @return array{domain:string,type:int,name:string}|null Null when no UTM data.
	 */
	public static function campaign( $hit ) {
		$source   = isset( $hit['us'] ) ? trim( (string) $hit['us'] ) : '';
		$medium   = isset( $hit['um'] ) ? trim( (string) $hit['um'] ) : '';
		$campaign = isset( $hit['uc'] ) ? trim( (string) $hit['uc'] ) : '';

		if ( '' === $source && '' === $medium && '' === $campaign ) {
			return null;
		}

		$label = '' !== $campaign ? $campaign : ( '' !== $source ? $source : $medium );
		$label = wp_strip_all_tags( $label );
		$label = trim( str_replace( array( "\r", "\n", "\0" ), ' ', $label ) );

		if ( function_exists( 'mb_substr' ) ) {
			$label = mb_substr( $label, 0, 100 );
		} else {
			$label = substr( $label, 0, 100 );
		}

		$slug = '' !== $source ? $source : ( '' !== $campaign ? $campaign : $medium );
		$slug = strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '-', (string) $slug ) );
		$slug = trim( (string) $slug, '-._' );

		if ( '' === $slug ) {
			$slug = 'campaign';
		}

		if ( strlen( $slug ) > 80 ) {
			$slug = substr( $slug, 0, 80 );
		}

		return array(
			'domain' => 'utm:' . $slug,
			'type'   => self::TYPE_CAMPAIGN,
			'name'   => '' !== $label ? $label : $slug,
		);
	}

	/**
	 * Extracts the internal WordPress site-search term from a page path.
	 *
	 * @param string $path Page path including query string, e.g. `/?s=hello`.
	 * @return string Term (<= 100 bytes) or '' when this is not a search page.
	 */
	public static function internal_search( $path ) {
		if ( ! is_string( $path ) ) {
			return '';
		}

		$qpos = strpos( $path, '?' );

		if ( false === $qpos ) {
			return '';
		}

		$params = array();
		parse_str( substr( $path, $qpos + 1 ), $params );

		if ( empty( $params['s'] ) || ! is_string( $params['s'] ) ) {
			return '';
		}

		$term = wp_strip_all_tags( $params['s'] );
		$term = trim( str_replace( array( "\r", "\n", "\0" ), ' ', $term ) );

		if ( '' === $term ) {
			return '';
		}

		return function_exists( 'mb_substr' )
			? mb_substr( $term, 0, 100 )
			: substr( $term, 0, 100 );
	}

	/**
	 * Registrable base domain of a host (lightweight 2/3-label heuristic).
	 *
	 * @param string $host Lowercase host without port/www.
	 * @return string
	 */
	public static function base_domain( $host ) {
		$host  = self::normalize_host( $host );
		$parts = explode( '.', $host );

		if ( count( $parts ) < 3 ) {
			return $host;
		}

		$last2 = $parts[ count( $parts ) - 2 ] . '.' . $parts[ count( $parts ) - 1 ];

		if ( in_array( $last2, self::$extra_suffixes, true ) ) {
			return $parts[ count( $parts ) - 3 ] . '.' . $last2;
		}

		return $last2;
	}

	/**
	 * Lowercases a host, strips www. and port.
	 *
	 * @param string $host Raw host.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		$host = preg_replace( '/^www\./', '', (string) $host );

		return (string) $host;
	}

	/**
	 * Whether $host is $site itself or one of its subdomains.
	 *
	 * @param string $host Normalized host.
	 * @param string $site Normalized site host.
	 * @return bool
	 */
	private static function is_subdomain_of( $host, $site ) {
		if ( '' === $site ) {
			return false;
		}

		return (bool) preg_match( '/(^|\.)' . preg_quote( $site, '/' ) . '$/', $host );
	}

	/**
	 * Matches a host against a regex => name map (first hit wins).
	 *
	 * @param string               $host Normalized host.
	 * @param array<string,string> $map  Host regex => display name.
	 * @return string|null Display name or null.
	 */
	private static function match_map( $host, $map ) {
		foreach ( $map as $pattern => $name ) {
			if ( preg_match( $pattern, $host ) ) {
				return $name;
			}
		}

		return null;
	}
}
