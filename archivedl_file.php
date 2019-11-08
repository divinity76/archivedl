#!/usr/bin/php
<?php
declare(strict_types = 1);
require_once ('hhb_.inc.php');
hhb_init ();
if ($argc !== 3 && $argc !== 2) {
	fprintf ( STDERR, "usage: %s timestamp url\n", $argv [0] );
	fprintf ( STDERR, "usage: %s url\n", $argv [0] );
	fprintf ( STDERR, "example: %s 20091012061648 http://www.p4w.se\n", $argv [0] );
	fprintf ( STDERR, "example: %s 'https://web.archive.org/web/20170613155747/http://www.tradgardscafet.se'\n", $argv [0] );
	die ( 1 );
}
if ($argc === 2) {
	$tmp = $argv [1];
	if (! preg_match ( "/web\.archive\.org\/web\/(\d+)\/(.*)/", $tmp, $matches ) || count ( $matches ) !== 3) {
		fprintf ( STDERR, "error: could not understand url: %s\n", $tmp );
		die ( 1 );
	}
	// var_dump($matches) & die();
	$argv [1] = $matches [1];
	$argv [2] = $matches [2];
}
// var_dump($argc, $argv) & die();
$timestamp = $argv [1];
if (! preg_match ( '/^[1-9][0-9]+$/', ( string ) $timestamp )) {
	throw new \InvalidArgumentException ( 'invalid timestamp! (failed regex validation /^[1-9][0-9]+$/ )' );
}
$timestamp = ( int ) $timestamp;
if (! filter_var ( $argv [2], FILTER_VALIDATE_URL )) {
	throw new \InvalidArgumentException ( 'invalid url! (failed FILTER_VALIDATE_URL ) ' );
}
$archive_url = 'https://web.archive.org/web/' . $timestamp . 'id_/';
define ( "ARCHIVE_URL", $archive_url );
$FINAL_HTML = '';
$url = $argv [2]; // https://web.archive.org/web/20091012061648id_/http://www.p4w.se
$hc = new hhb_curl ( '', true );
$hc->setopt_array ( array (
		CURLOPT_TIMEOUT => 20,
		CURLOPT_CONNECTTIMEOUT => 10 
) ); // sometimes archive.org lags for quite a bit, so account for that.
$url = $raw_url = geturl ( $url );
$file = "index." . parse_url ( $argv [2], PHP_URL_HOST ) . "_" . $timestamp . ".html"; // "index." . bin2hex ( random_bytes ( 4 ) ) . ".html";
$headers = '';
fetchUrl ( $url, $headers );
// var_dump($headers) & die();
$response = $hc->getinfo ( CURLINFO_RESPONSE_CODE );
if ($response !== 200) {
	// ...
	fprintf ( STDERR, "warning: because http response code %d, failed to get the index file, fatal error, idk what to do, call the cops, erroring\n", $response, $url );
	die ( 1 );
}
$html = $hc->getResponseBody ();
$domd = @DOMDocument::loadHTML ( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
if (! $domd) {
	// seriously malformed HTML, or not actually a HTML at all (like ico served with text/html content header... yes, that actually happened on http://www.gravatar.com/blavatar/2e776b945e937c41193b102c956044c8s=16&d=http:/s.wordpress.com/favicon.ico )
	fprintf ( STDERR, "error: seriously malformed HTML, or not actually a HTML at all (like ico served with text/html content header... yes, that actually happened on http://www.gravatar.com/blavatar/2e776b945e937c41193b102c956044c8s=16&d=http:/s.wordpress.com/favicon.ico )" );
}
foreach ( $domd->getElementsByTagName ( "script" ) as $script ) {
	/** @var DOMElement $script */
	$src = $script->getAttribute ( "src" );
	if (empty ( $src )) {
		continue;
	}
	$src = relative_to_absolute ( $src, $url );
	if (empty ( $src )) {
		fprintf ( STDERR, "warning: could not understand src url in script tag, ignoring: %s\n", $script->getAttribute ( "src" ) );
		continue;
	}
	$scriptContent = fetchUrl ( $src );
	$response = $hc->getinfo ( CURLINFO_RESPONSE_CODE );
	if ($response !== 200) {
		// ...
		fprintf ( STDERR, "warning: because http response code %d, ignoring url %s\n", $response, $src );
		continue;
	}
	if (($tmpdoc = @DOMDocument::loadHTML ( $scriptContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOBLANKS | LIBXML_NOXMLDECL )) && $tmpdoc->getElementsByTagName ( "*" )->length > 10) {
		fprintf ( STDERR, "warning: the response is supposed to be javascript, but looks too much like HTML, thus ignoring url %s\n", $src );
		continue;
	}
	unset ( $tmpdoc );
	$script->removeAttribute ( "src" );
	$script->textContent = "\n{$scriptContent}\n";
}
foreach ( $domd->getElementsByTagName ( "img" ) as $img ) {
	/** @var DOMElement $img */
	$src = $img->getAttribute ( "src" );
	if (empty ( $src )) {
		continue;
	}
	$src = relative_to_absolute ( $src, $url );
	if (empty ( $src )) {
		// probably already base64
		continue;
	}
	$imageBinary = fetchUrl ( $src );
	$response = $hc->getinfo ( CURLINFO_RESPONSE_CODE );
	if ($response !== 200) {
		// ...
		fprintf ( STDERR, "warning: because http response code %d, ignoring url %s\n", $response, $src );
		continue;
	}
	
	$mime = image_mime ( $imageBinary, $src, $success );
	if (! $success) {
		// continue;
	}
	$src = "data:{$mime};base64," . base64_encode ( $imageBinary );
	$img->setAttribute ( 'src', $src );
}
foreach ( $domd->getElementsByTagName ( "style" ) as $style ) {
	/** @var DOMElement $style */
	/** @var DOMDocument $domd */
	// TODO: CSS URL() PARSING
	$html = $style->textContent;
	$urls = [ ];
	preg_match_all ( "/url\(\s*(.*?)\s*\)/", $html, $urls );
	if (! empty ( $urls ) && ! empty ( $urls [1] )) {
		$urls = $urls [1];
		foreach ( $urls as $tmp ) {
			$original = $tmp;
			$tmp = str_ireplace ( ARCHIVE_URL, '', $tmp );
			$tmp = relative_to_absolute ( $tmp, $url );
			// hhb_var_dump($original,$tmp);continue;
			if (empty ( $tmp )) {
				// unsupported url (like mailto: or javascript )
				continue;
			}
			// ///////////////////////////////////////////////////////////////////////////////////////////////////
			$imageBinary = fetchUrl ( $tmp );
			$response = $hc->getinfo ( CURLINFO_RESPONSE_CODE );
			if ($response !== 200) {
				// ...
				fprintf ( STDERR, "warning: because http response code %d, ignoring CSS url %s\n", $response, $src );
				continue;
			}
			
			$mime = image_mime ( $imageBinary, $tmp, $success );
			if (! $success) {
				// continue;
			}
			$mime = "data:{$mime};base64," . base64_encode ( $imageBinary );
			// hhb_var_dump($original,$tmp,absolute_to_relaitve ( $tmp, $url ));continue;
			$html = str_replace ( $original, $mime, $html );
			continue;
		}
	}
	$style->textContent = $html;
}
foreach ( $domd->getElementsByTagName ( "link" ) as $link ) {
	/** @var DOMElement $link */
	/** @var DOMDocument $domd */
	$href = $link->getAttribute ( "href" );
	if (empty ( $href )) {
		continue;
	}
	$href = relative_to_absolute ( $href, $url );
	if (empty ( $href )) {
		continue;
	}
	$rel = $link->getAttribute ( 'rel' );
	if (empty ( $rel ) || strtolower ( $rel ) !== 'stylesheet') {
		continue;
	}
	$raw = ($html = fetchUrl ( $href ));
	$response = $hc->getinfo ( CURLINFO_RESPONSE_CODE );
	if ($response !== 200) {
		// ...
		fprintf ( STDERR, "warning: because http response code %d, ignoring url %s\n", $response, $href );
		continue;
	}
	if (($tmpdoc = @DOMDocument::loadHTML ( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOBLANKS | LIBXML_NOXMLDECL )) && $tmpdoc->getElementsByTagName ( "*" )->length > 10) {
		fprintf ( STDERR, "warning: the response is supposed to be CSS, but looks too much like HTML, thus ignoring url %s\n", $href );
		continue;
	}
	unset ( $tmpdoc );
	// TODO: CSS URL() PARSING
	$urls = [ ];
	preg_match_all ( "/url\(\s*(.*?)\s*\)/", $html, $urls );
	if (! empty ( $urls ) && ! empty ( $urls [1] )) {
		$urls = $urls [1];
		foreach ( $urls as $tmp ) {
			$original = $tmp;
			$tmp = str_ireplace ( ARCHIVE_URL, '', $tmp );
			$tmp = relative_to_absolute ( $tmp, $href );
			// hhb_var_dump($original,$tmp);continue;
			if (empty ( $tmp )) {
				// unsupported url (like mailto: or javascript )
				continue;
			}
			// ///////////////////////////////////////////////////////////////////////////////////////////////////
			$imageBinary = fetchUrl ( $tmp );
			$response = $hc->getinfo ( CURLINFO_RESPONSE_CODE );
			if ($response !== 200) {
				// ...
				fprintf ( STDERR, "warning: because http response code %d, ignoring CSS url %s\n", $response, $src );
				continue;
			}
			
			$mime = image_mime ( $imageBinary, $tmp, $success );
			if (! $success) {
				// continue;
			}
			$mime = "data:{$mime};base64," . base64_encode ( $imageBinary );
			// hhb_var_dump($original,$tmp,absolute_to_relaitve ( $tmp, $url ));continue;
			$html = str_replace ( $original, $mime, $html );
			continue;
		}
	}
	$raw = $html;
	// var_dump ( $urls );
	
	// die ( "FIXME" . __LINE__ );
	
	$new = $domd->createElement ( 'style' );
	
	$new->textContent = $raw;
	$link->parentNode->insertBefore ( $new, $link );
	$link->parentNode->removeChild ( $link );
}

foreach ( $domd->getElementsByTagName ( "a" ) as $a ) {
	$a->setAttribute ( "href", "#" );
}
$html = $domd->saveHTML ();
file_put_contents ( "script.{$file}", $html );
echo "saved as script.{$file}\n";
foreach ( $domd->getElementsByTagName ( "script" ) as $script ) {
	$script->parentNode->removeChild ( $script );
}
$html = $domd->saveHTML ();
file_put_contents ( "noscript.{$file}", $html );
echo "saved as noscript.{$file}\n";

if (($CSS_URL_WEIRDNESS = false)) {
	$urls = [ ];
	preg_match_all ( "/url\(\s*(.*?)\s*\)/", $html, $urls );
	if (! empty ( $urls ) && ! empty ( $urls [1] )) {
		$urls = $urls [1];
		foreach ( $urls as $tmp ) {
			$original = $tmp;
			$tmp = str_ireplace ( ARCHIVE_URL, '', $tmp );
			$tmp = relative_to_absolute ( $tmp, $url );
			// hhb_var_dump($original,$tmp);continue;
			if (empty ( $tmp )) {
				// unsupported url (like mailto: or javascript )
				continue;
			}
			// FIXME: the css urls should be css decoded and css re-encoded...
			// hhb_var_dump($original,$tmp,absolute_to_relaitve ( $tmp, $url ));continue;
			$html = str_replace ( $original, absolute_to_relaitve ( $tmp, $raw_url ), $html );
			if (in_array ( $tmp, $to_download, true )) {
				// already queued to download
				continue;
			}
			if (in_array ( $tmp, $downloaded, true )) {
				// already downloaded.
				continue;
			}
			if (! in_array ( strtolower ( pathinfo ( parse_url ( $tmp ) ['path'], PATHINFO_EXTENSION ) ), $whitelist, true )) {
				// blacklisted extension
				// var_dump($url);
				continue;
			}
			echo 'CSSadding ' . $tmp . " ( " . geturl ( $tmp ) . " )", PHP_EOL;
			// die ( "FIXME" . __LINE__ );
			++ $total;
			$to_download [] = $tmp;
			continue;
		}
		unset ( $fixurl, $addme );
	}
	// var_dump ( $urls );
	
	// die ( "FIXME" . __LINE__ );
}
function image_mime(string $imageBinary, string $warningUrl = NULL, bool &$sucess = NULL): string {
	static $tmp = NULL;
	if ($tmp === NULL) {
		$tmp = tmpfile ();
	}
	ftruncate ( $tmp, 0 );
	fwrite ( $tmp, $imageBinary );
	$mime = exif_imagetype ( stream_get_meta_data ( $tmp ) ['uri'] );
	if (false === $mime) {
		$ret = "application/octet-stream";
	} else {
		$ret = image_type_to_mime_type ( $mime );
	}
	if ($ret === 'application/octet-stream') {
		if ($warningUrl) {
			fprintf ( STDERR, "Warning: could not recognize image type from url %s , so setting mime to application/octet-stream.\n", $warningUrl );
		}
		$sucess = false;
	} else {
		$sucess = true;
	}
	return $ret;
}
function absolute_url_to_filepath(string $url): string {
	$info = parse_url ( $url );
	$uri = preg_replace ( '/[\\\\\\/]+/u', DIRECTORY_SEPARATOR, '/' . $info ['host'] . '/' . ($info ['path'] ?? '') );
	// hhb_var_dump($uri, basename($uri));
	if (DIRECTORY_SEPARATOR === substr ( $uri, - 1 ) || empty ( basename ( $uri ) )) {
		if (DIRECTORY_SEPARATOR !== substr ( $uri, - 1 )) {
			$uri .= DIRECTORY_SEPARATOR;
		}
		$uri .= 'index.html';
	}
	// hhb_var_dump($uri);
	return /* getcwd() . */
	substr ( $uri, 1 );
}
function absolute_to_relaitve(string $absolute, string $source): string {
	$absolute_ = $absolute;
	$source_ = $source;
	//
	$source = parse_url ( $source );
	$source = $source ['host'] . '/' . ($source ['path'] ?? ''); // . ($source['query'] ?? '');
	$source = preg_replace ( '/[\\/]+/', '/', $source );
	// hhb_var_dump($absolute, $source) & die();
	$absolute = parse_url ( $absolute );
	// hhb_var_dump($absolute, $source) & die();
	$absolute = $absolute ['host'] . '/' . ($absolute ['path'] ?? '') . ($absolute ['query'] ?? '');
	$absolute = preg_replace ( '/[\\/]+/', '/', $absolute );
	// hhb_var_dump($absolute, $source) & die();
	if (0 === strpos ( $absolute, $source )) {
		$absolute = substr ( $absolute, strlen ( $source ) );
		if (substr ( $absolute, - 1 ) === '/') {
			// .........$absolute = substr ( $absolute, 0, - 1 );
		} elseif (false === strpos ( $absolute, '.' ) || (false !== strpos ( $absolute, '/' ) && substr ( $absolute, - 1 ) !== '/' && false === strpos ( basename ( $absolute ), '.' ))) {
			// for foo containing .. vs foo/ containing .. , makes a subtle but page-breaking difference
			$absolute .= '/';
		}
	} else {
		if (0 === stripos ( $absolute, MASTER_DIR . '/' )) {
			// EWIQJWQI
			// $absolute = substr ( $absolute, strlen ( MASTER_DIR ) + 1 );
		}
		$amt = substr_count ( $source, '/' );
		$str = '';
		// EWIQJWQI
		for($i = 0; $i < $amt; ++ $i) {
			$str .= '../';
		}
		if (false === strpos ( $absolute, '.' ) || (false !== strpos ( $absolute, '/' ) && substr ( $absolute, - 1 ) !== '/' && false === strpos ( basename ( $absolute ), '.' ))) {
			// for foo containing .. vs foo/ containing .. , makes a subtle but page-breaking difference
			if (substr ( $absolute, - 1 ) !== '/') {
				$absolute .= '/';
			}
		}
		$absolute = $str . $absolute;
	}
	// hhb_var_dump($absolute_, $absolute, $source, $source_) & (rand(2, 30) === 1 ? die() : 1);
	return $absolute;
}
function relative_to_absolute(string $relative, string $source): string {
	if (empty ( $relative )) {
		return "";
	}
	$info = parse_url ( $relative );
	$whitelist = array (
			'http',
			'https',
			'ftp' 
	);
	if (! empty ( $info ['scheme'] ) && ! in_array ( strtolower ( $info ['scheme'] ), $whitelist, true )) {
		// like mailto: or tlf: or whatever, unsupported
		return "";
	}
	if (empty ( $relative ) || $relative [0] === '#') {
		return "";
	}
	if (false !== strpos ( $relative, '(' ) && false !== strpos ( $relative, ')' )) {
		// PROBABLY javascript (otherwise these characters would be urlencoded to %whatever)
		return "";
	}
	$sourceinfo = parse_url ( $source );
	$relainfo = parse_url ( $relative );
	$ret = '';
	if (empty ( $relainfo ['host'] )) {
		$ret = $sourceinfo ['host'] . dirname ( $sourceinfo ['path'] ?? '') . '/' . $relainfo ['path'];
		again:
		$ret = explode ( "/", $ret );
		for($i = 0, $count = count ( $ret ); $i < $count; ++ $i) {
			if ($ret [$i] === '.') {
				unset ( $ret [$i] );
				$ret = implode ( '/', $ret );
				goto again;
			}
			if ($ret [$i] === '..') {
				unset ( $ret [$i] );
				unset ( $ret [$i - 1] );
				$ret = implode ( '/', $ret );
				goto again;
			}
			continue;
		}
		$ret = implode ( '/', $ret );
		$ret = preg_replace ( '/[\\/]+/', '/', $ret );
		$ret = $sourceinfo ['scheme'] . '://' . $ret;
		unset ( $i, $count );
	} else {
		$ret = ($relainfo ['host'] ?? $sourceinfo ['host']) . ((isset ( $relainfo ['port'] ) || isset ( $sourceinfo ['port'] )) ? (':' . ($relainfo ['port'] ?? $sourceinfo ['port'])) : '') . '/' . ($relainfo ['path'] ?? $sourceinfo ['path'] ?? '') . ($relainfo ['query'] ?? '');
		$ret = preg_replace ( '/[\\/]+/', '/', $ret );
		
		$ret = ($relainfo ['scheme'] ?? $sourceinfo ['scheme']) . '://' . $ret;
	}
	// hhb_var_dump($sourceinfo, $relainfo, $ret) & die(); //
	
	return $ret;
}
function geturl(string $url): string {
	if (false === stripos ( $url, ARCHIVE_URL )) {
		return ARCHIVE_URL . $url;
	} else {
		// this part is only hit from css url() urls.
		// archive does some funky stuff with CSS urls, even when using the underscore urls, it seems...
		return $url;
	}
}
function fetchUrl(string $url, string &$headers = NULL, string &$body = NULL): string {
	global $hc;
	$realurl = geturl ( $url );
	$url = $realurl;
	echo " url: {$url}..";
	$url = $realurl;
	try {
		$headers = implode ( " ", $hc->exec ( $url )->getResponseHeaders () );
	} catch ( Exception $ex ) {
		try {
			$headers = implode ( " ", $hc->exec ( $url )->getResponseHeaders () );
		} catch ( Exception $ex ) {
			// sometimes connection fails for no good reason, and a retry (or 2) fixes it...
			// here we give up and deliberately not catch the 3rd exception (if any)
			$headers = implode ( " ", $hc->exec ( $url )->getResponseHeaders () );
		}
	}
	echo ".\n";
	return ($body = $hc->getResponseBody ());
}
function file_force_contents(string $filename, string $data, int $flags = 0) {
	if (0 === strpos ( $filename, MASTER_DIR . DIRECTORY_SEPARATOR )) {
		// EWIQJWQI
		// $filename = substr ( $filename, strlen ( MASTER_DIR ) + 1 );
	}
	$tmp = dirname ( $filename );
	if (! is_dir ( $tmp )) {
		if (file_exists ( $tmp )) {
			$tmpf = file_get_contents ( $tmp );
			unlink ( $tmp );
			mkdir ( $tmp . DIRECTORY_SEPARATOR, 0777, TRUE );
			file_put_contents ( $tmp . DIRECTORY_SEPARATOR . 'index.html', $tmpf );
		} else {
			mkdir ( $tmp . DIRECTORY_SEPARATOR, 0777, TRUE );
		}
	}
	return file_put_contents ( $filename, $data, $flags );
}
