<?php
/**
 * Shared cURL safety settings for every outbound HTTP request in this project.
 *
 * - Only http and https are allowed (blocks file://, ftp://, gopher:// and similar schemes,
 *   including on redirects).
 * - TLS certificates are verified by default. On XAMPP, php.ini sets curl.cainfo to
 *   xampp/apache/bin/curl-ca-bundle.crt, which is what verification uses.
 * - Verification can be turned off only by setting the environment variable
 *   WEBCRAWLER_INSECURE_TLS=1. Use this only for a trusted host with a self-signed
 *   certificate. It is never enabled silently.
 */

function wc_insecure_tls_allowed() {
    $value = strtolower(trim((string)getenv('WEBCRAWLER_INSECURE_TLS')));
    return $value === '1' || $value === 'true';
}

function wc_apply_http_safety($ch) {
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

    if (wc_insecure_tls_allowed()) {
        error_log('[HTTP] WARNING: TLS certificate verification is disabled (WEBCRAWLER_INSECURE_TLS=1)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
}
