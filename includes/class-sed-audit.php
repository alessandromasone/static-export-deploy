<?php
/**
 * Audit finale: link/risorse interne rotte + controlli SEO di base
 * (port di genera_report dello script Python).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SED_Audit {

	/** @var string */
	private $dir;

	/** @var array */
	private $opts;

	/** @var string */
	private $staging_regex;

	/** @var string|null */
	private $https_regex = null;

	/** @var string|null Regex residui host sorgente (mappatura dominio). */
	private $source_host_regex = null;

	public function __construct( $opt_dir, $opts ) {
		$this->dir           = untrailingslashit( $opt_dir );
		$this->opts          = $opts;
		$this->staging_regex = '/(?<![\w.\-])' . preg_quote( $opts['sub_staging'], '/' ) . '\.([a-z0-9\-]+(?:\.[a-z0-9\-]+)+)/i';
		if ( ! empty( $opts['force_https'] ) && ! empty( $opts['site_domain'] ) ) {
			$this->https_regex = '#http:(\\\\?/\\\\?/)((?:[a-z0-9\-]+\.)*' . preg_quote( $opts['site_domain'], '#' ) . ')(?![a-z0-9\-.])#i';
		}
		if ( ! empty( $opts['target_host'] ) && ! empty( $opts['source_host'] ) && $opts['target_host'] !== $opts['source_host'] ) {
			$this->source_host_regex = '/(?<![\w.\-])' . preg_quote( $opts['source_host'], '/' ) . '(?![a-z0-9\-.])/i';
		}
	}

	/**
	 * Esegue l'audit completo e scrive il report.
	 *
	 * @param string $report_path Percorso del file di report.
	 * @return array ['broken' => int, 'seo' => int]
	 */
	public function run( $report_path ) {
		// Indice di tutti i file esistenti.
		$existing = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->dir, FilesystemIterator::SKIP_DOTS )
		);
		$html_files = array();
		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}
			$rel              = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $this->dir ) ) ), '/' );
			$existing[ $rel ] = true;
			if ( preg_match( '/\.html?$/i', $rel ) ) {
				$html_files[] = $rel;
			}
		}

		$broken = array();
		$seo    = array();

		foreach ( $html_files as $page ) {
			$content = @file_get_contents( $this->dir . '/' . $page );
			if ( false === $content ) {
				continue;
			}

			$dom = new DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING );
			libxml_clear_errors();
			$xpath = new DOMXPath( $dom );

			// --- Controllo link/risorse interne ---
			$refs = array();
			foreach ( $xpath->query( '//*[@href]' ) as $node ) {
				$refs[] = $node->getAttribute( 'href' );
			}
			foreach ( $xpath->query( '//*[@src]' ) as $node ) {
				$refs[] = $node->getAttribute( 'src' );
			}
			foreach ( $xpath->query( '//*[@srcset]' ) as $node ) {
				foreach ( explode( ',', $node->getAttribute( 'srcset' ) ) as $part ) {
					$tokens = preg_split( '/\s+/', trim( $part ) );
					if ( ! empty( $tokens[0] ) ) {
						$refs[] = $tokens[0];
					}
				}
			}

			$page_dir = dirname( $page );
			foreach ( $refs as $link ) {
				$target = $this->normalize_target( $link, $page_dir );
				if ( null === $target ) {
					continue;
				}
				if ( isset( $existing[ $target ] ) ) {
					continue;
				}
				if ( isset( $existing[ rtrim( $target, '/' ) . '/index.html' ] ) ) {
					continue;
				}
				$broken[] = "In pagina: {$page} -> Risorsa/link mancante: {$link}";
			}

			// --- Audit SEO ---
			$title_node = $dom->getElementsByTagName( 'title' )->item( 0 );
			if ( ! $title_node || '' === trim( $title_node->textContent ) ) {
				$seo[] = "[TITLE]    {$page}: <title> mancante o vuoto";
			}

			$desc = $xpath->query( '//meta[translate(@name,"DESCRIPTION","description")="description"]' )->item( 0 );
			if ( ! $desc || '' === trim( $desc->getAttribute( 'content' ) ) ) {
				$seo[] = "[META]     {$page}: meta description mancante o vuota";
			}

			$canonical = $xpath->query( '//link[contains(translate(@rel,"CANONICAL","canonical"),"canonical")]' )->item( 0 );
			if ( ! $canonical || '' === trim( $canonical->getAttribute( 'href' ) ) ) {
				$seo[] = "[CANONICAL]{$page}: link rel=canonical mancante";
			}

			$h1_count = $dom->getElementsByTagName( 'h1' )->length;
			if ( 0 === $h1_count ) {
				$seo[] = "[H1]       {$page}: nessun <h1>";
			} elseif ( $h1_count > 1 ) {
				$seo[] = "[H1]       {$page}: {$h1_count} tag <h1> (consigliato uno solo)";
			}

			$html_tag = $dom->getElementsByTagName( 'html' )->item( 0 );
			if ( ! $html_tag || '' === trim( $html_tag->getAttribute( 'lang' ) ) ) {
				$seo[] = "[LANG]     {$page}: attributo lang mancante su <html>";
			}

			$no_alt = 0;
			foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
				if ( ! $img->hasAttribute( 'alt' ) ) {
					$no_alt++;
				}
			}
			if ( $no_alt > 0 ) {
				$seo[] = "[ALT]      {$page}: {$no_alt} <img> senza attributo alt";
			}

			if ( $this->source_host_regex ) {
				if ( preg_match_all( $this->source_host_regex, $content, $dm ) ) {
					$seo[] = "[DOMINIO]  {$page}: " . count( $dm[0] ) . " riferimenti residui all'host di origine '{$this->opts['source_host']}'";
				}
			} elseif ( preg_match( $this->staging_regex, $content ) ) {
				$seo[] = "[STAGING]  {$page}: presenti URL residui verso il sottodominio di staging '{$this->opts['sub_staging']}.'";
			}

			if ( $this->https_regex && preg_match_all( $this->https_regex, $content, $hm ) ) {
				$seo[] = "[HTTPS]    {$page}: " . count( $hm[0] ) . ' URL interni ancora in http:// (mixed content)';
			}
		}

		$broken = array_values( array_unique( $broken ) );

		// --- Scrittura report ---
		$out   = array();
		$out[] = 'REPORT OTTIMIZZAZIONE / SEO';
		$out[] = 'Sito: ' . home_url();
		$out[] = 'Generato: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$out[] = str_repeat( '=', 60 );
		$out[] = '';
		$out[] = 'LINK/RISORSE ROTTE (' . count( $broken ) . ')';
		$out[] = str_repeat( '-', 60 );
		$out   = array_merge( $out, $broken ?: array( 'Nessun link o risorsa interna mancante.' ) );
		$out[] = '';
		$out[] = '';
		$out[] = 'AUDIT SEO (' . count( $seo ) . ')';
		$out[] = str_repeat( '-', 60 );
		$out   = array_merge( $out, $seo ?: array( 'Nessun problema SEO rilevato.' ) );

		file_put_contents( $report_path, implode( "\n", $out ) . "\n" );

		return array(
			'broken' => count( $broken ),
			'seo'    => count( $seo ),
		);
	}

	/**
	 * Risolve un link relativo in un path relativo alla root dell'export,
	 * o null se esterno / non navigabile (port di _normalizza_target).
	 */
	private function normalize_target( $link, $page_dir ) {
		$clean = preg_replace( '/[#?].*$/', '', (string) $link );
		if ( '' === $clean ) {
			return null;
		}
		if ( preg_match( '#^(https?://|mailto:|tel:|data:|javascript:|//)#i', $clean ) ) {
			return null;
		}
		$clean = rawurldecode( $clean );

		if ( '/' === $clean[0] ) {
			$target = ltrim( $clean, '/' );
		} else {
			$base   = ( '.' === $page_dir ) ? '' : $page_dir . '/';
			$target = $base . $clean;
		}

		// Normalizza ./ e ../
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', $target ) ) as $segment ) {
			if ( '.' === $segment || '' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		$normalized = implode( '/', $parts );
		if ( '/' === substr( $clean, -1 ) && '' !== $normalized ) {
			$normalized .= '/';
		}
		return $normalized;
	}
}
