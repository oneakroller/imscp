<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use iMSCP\TemplateEngine;
use iMSCP_Events as Events;
use iMSCP_Registry as Registry;

/***********************************************************************************************************************
 * Functions
 */

/**
 * Get domain name
 *
 * @param int $domainId Domain entity unique identifier
 * @param string $domainType Domain entity type to update (dmn|als|sub|alssub)
 * @return string|false Domain name or FALSE if the domain name is not found or not owned by logged-in customer
 */
function _client_getDomainName($domainId, $domainType)
{
    static $domainName = NULL;

    if ($domainName === NULL) {
        switch ($domainType) {
            case 'dmn':
                $query = 'SELECT domain_name FROM domain WHERE domain_id = ? AND domain_admin_id = ?';
                break;
            case 'als':
                $query = '
                    SELECT alias_name AS domain_name FROM domain_aliasses
                    JOIN domain USING(domain_id)
                    WHERE alias_id = ?
                    AND domain_admin_id = ?
            ';
                break;
            case 'sub':
                $query = "
                    SELECT CONCAT(subdomain_name, '.', domain_name) AS domain_name
                    FROM subdomain
                    JOIN domain USING(domain_id)
                    WHERE subdomain_id = ?
                    AND domain_admin_id = ?
                ";
                break;
            default:
                $query = "
                    SELECT CONCAT(subdomain_alias_name, '.', alias_name) AS domain_name
                    FROM subdomain_alias
                    JOIN domain_aliasses USING(alias_id)
                    JOIN domain USING(domain_id)
                    WHERE subdomain_alias_id = ?
                    AND domain_admin_id = ?
                ";
        }

        $stmt = exec_query($query, [$domainId, $_SESSION['user_id']]);

        if (!$stmt->rowCount()) {
            return false;
        }

        $row = $stmt->fetch();
        $domainName = $row['domain_name'];
    }

    return $domainName;
}

/**
 * Update status for the given domain
 *
 * @param int $domainId Domain entity unique identifier
 * @param string $domainType Domain entity type to update (dmn|als|sub|alssub)
 * @return void
 */
function _client_updateDomainStatus($domainId, $domainType)
{
    switch ($domainType) {
        case 'dmn':
            $query = "UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?";
            break;
        case 'als':
            $query = "UPDATE domain_aliasses SET alias_status = 'tochange' WHERE alias_id = ?";
            break;
        case 'sub':
            $query = "UPDATE subdomain SET subdomain_status = 'tochange' WHERE subdomain_id = ?";
            break;
        default:
            $query = "UPDATE subdomain_alias SET subdomain_alias_status = 'tochange' WHERE subdomain_alias_id = ?";
    }

    exec_query($query, [$domainId]);
}

/**
 * Generate temporary openssl configuration file
 *
 * @param array $data User data
 * @return bool|string Path to generate openssl temporary file, FALSE on failure
 */
function _client_generateOpenSSLConfFile($data)
{
    global $domainType, $domainId;

    $tpl = new TemplateEngine();
    $tpl->define_inline([
        'openssl'           => <<<'EOF'
[req]
distinguished_name = req_distinguished_name
default_bits = 2048
default_md = sha256
default_days = 365
x509_extensions = v3_req
string_mask = utf8only
prompt = no

[req_distinguished_name]
CN = {COMMON_NAME}
O = N/A
L = N/A
ST = N/A
C = US
emailAddress = {EMAIL_ADDRESS}

[v3_req]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer:always
basicConstraints = critical,CA:FALSE
keyUsage = keyCertSign, nonRepudiation, digitalSignature, keyEncipherment
subjectAltName = @alt_names
issuerAltName = issuer:copy

[alt_names]
<!-- BDP: openssl_alt_names -->
DNS.{IDX} = {ALT_NAMES}
<!-- EDP: openssl_alt_names -->

EOF
        ,
        'openssl_alt_names' => 'openssl'
    ]);
    $tpl->assign([
        'COMMON_NAME'       => $data['domain_name'],
        'EMAIL_ADDRESS'     => $data['email'],
        'DOMAIN_NAME'       => $data['domain_name'],
        'BASE_SERVER_VHOST' => Registry::get('config')['BASE_SERVER_VHOST']
    ]);

    foreach (
        [
            '{DOMAIN_NAME}',
            'www.{DOMAIN_NAME}',
            $domainType == 'als' ? "alssub$domainId.{BASE_SERVER_VHOST}" : "$domainType$domainId.{BASE_SERVER_VHOST}"
        ] as $idx => $altName
    ) {
        $tpl->assign([
            'ALT_NAMES' => $altName,
            'IDX'       => $idx + 1
        ]);
        $tpl->parse('OPENSSL_ALT_NAMES', '.openssl_alt_names');
    }

    $tpl->parse('OPENSSL', 'openssl');

    $opensslConfFile = @tempnam(sys_get_temp_dir(), $_SESSION['user_id'] . '-openssl.cnf');
    if ($opensslConfFile === false) {
        write_log("Couldn't create temporary openssl configuration file.", E_USER_ERROR);
        return false;
    }

    register_shutdown_function(function ($file) {
        @unlink($file);
    }, $opensslConfFile);

    if (!@file_put_contents($opensslConfFile, $tpl->getLastParseResult())) {
        write_log(sprintf("Couldn't write in %s openssl temporary configuration file.", $opensslConfFile), E_USER_ERROR);
        return false;
    }

    return $opensslConfFile;
}

/**
 * Generate an self-signed certificate
 *
 * @param string $domainName Domain name
 * @return bool TRUE on success, FALSE otherwise
 */
function client_generateSelfSignedCert($domainName)
{
    $stmt = exec_query('SELECT firm, city, state, country, email FROM admin WHERE admin_id = ?', [
        $_SESSION['user_id']
    ]);

    if (!$stmt->rowCount()) {
        return false;
    }

    $row = $stmt->fetch();
    $row['domain_name'] = $domainName;

    if (!($sslConfigFilePath = _client_generateOpenSSLConfFile($row))) {
        return false;
    }

    $distinguishedName = [
        'countryName'         => 'US', // TODO map of country names to ISO-3166 codes
        'stateOrProvinceName' => !empty($row['state']) ? $row['state'] : 'N/A',
        'localityName'        => !empty($row['city']) ? $row['city'] : 'N/A',
        'organizationName'    => !empty($row['firm']) ? $row['firm'] : 'N/A',
        'commonName'          => $domainName,
        'emailAddress'        => $row['email']
    ];

    $sslConfig = ['config' => $sslConfigFilePath];
    $csr = @openssl_csr_new($distinguishedName, $pkey, $sslConfig);
    if (!is_resource($csr)) {
        write_log(sprintf("Couldn't generate SSL certificate signing request: %s", openssl_error_string()), E_USER_ERROR);
        return false;
    }

    if (@openssl_pkey_export($pkey, $pkeyStr, NULL, $sslConfig) !== true) {
        write_log(sprintf("Coudln't export private key: %s", openssl_error_string()), E_USER_ERROR);
        return false;
    }

    $cert = @openssl_csr_sign($csr, NULL, $pkeyStr, 365, $sslConfig, (int)($_SESSION['user_id'] . time()));
    if (!is_resource($cert)) {
        write_log(sprintf("Couldn't generate SSL certificate: %s", openssl_error_string()));
        return false;
    }

    if (@openssl_x509_export($cert, $certStr) !== true) {
        write_log(sprintf("Couldn't export SSL certificate: %s", openssl_error_string()), E_USER_ERROR);
        return false;
    }

    openssl_pkey_free($pkey);
    openssl_x509_free($cert);

    $_POST['passphrase'] = '';
    $_POST['private_key'] = $pkeyStr;
    $_POST['certificate'] = $certStr;
    $_POST['ca_bundle'] = '';

    return true;
}

/**
 * Add or update an SSL certificate
 *
 * @throws iMSCP_Exception
 * @param int $domainId domain unique identifier
 * @param string $domainType Domain type (dmn|als|sub|alssub)
 * @return void
 */
function client_addSslCert($domainId, $domainType)
{
    $config = Registry::get('config');
    $domainName = _client_getDomainName($domainId, $domainType);
    $allowHSTS = (isset($_POST['allow_hsts']) && in_array($_POST['allow_hsts'], ['on', 'off'], true))
        ? $_POST['allow_hsts'] : 'off';
    $hstsMaxAge = ($allowHSTS == 'on' && isset($_POST['hsts_max_age']) && is_number($_POST['hsts_max_age'])
        && $_POST['hsts_max_age'] >= 0) ? intval($_POST['hsts_max_age']) : '31536000';
    $hstsIncludeSubDomains = ($allowHSTS == 'on' && isset($_POST['hsts_include_subdomains'])
        && in_array($_POST['hsts_include_subdomains'], ['on', 'off'], true))
        ? $_POST['hsts_include_subdomains'] : 'off';
    $selfSigned = (isset($_POST['selfsigned']) && $_POST['selfsigned'] === 'on');

    if ($domainName === false) {
        showBadRequestErrorPage();
    }

    if ($selfSigned && !client_generateSelfSignedCert($domainName)) {
        set_page_message(tr('Could not generate SSL certificate. An unexpected error occurred.'), 'error');
        return;
    }

    if (!isset($_POST['passphrase'])
        || !isset($_POST['private_key'])
        || !isset($_POST['certificate'])
        || !isset($_POST['ca_bundle'])
        || !isset($_POST['cert_id'])
    ) {
        showBadRequestErrorPage();
    }

    $passPhrase = clean_input($_POST['passphrase']);
    $privateKey = clean_input($_POST['private_key']);
    $certificate = clean_input($_POST['certificate']);
    $caBundle = clean_input($_POST['ca_bundle']);
    $certId = intval($_POST['cert_id']);

    if (!$selfSigned) { // Validate SSL certificate (private key, SSL certificate and certificate chain)
        $privateKey = @openssl_pkey_get_private($privateKey, $passPhrase);
        if (!is_resource($privateKey)) {
            set_page_message(tr('Invalid private key or passphrase.'), 'error');
            return;
        }

        $certificateStr = $certificate;
        $certificate = @openssl_x509_read($certificate);

        if (!is_resource($certificate)) {
            set_page_message(tr('Invalid SSL certificate.'), 'error');
            return;
        }

        if (@openssl_x509_check_private_key($certificate, $privateKey) !== true) {
            set_page_message(tr("The private key doesn't belong to the provided SSL certificate."), 'error');
            return;
        }

        $tmpfname = @tempnam(sys_get_temp_dir(), $_SESSION['user_id'] . 'ssl-ca');
        if ($tmpfname === false) {
            write_log("Couldn't create temporary file for CA bundle.", E_USER_ERROR);
            set_page_message(tr('Could not add/update SSL certificate. An unexpected error occurred.'), 'error');
            return;
        }

        register_shutdown_function(function ($file) {
            @unlink($file);
        }, $tmpfname);

        if ($caBundle !== '') {
            if (@file_put_contents($tmpfname, $caBundle) === false) {
                write_log("Couldn't write CA bundle in temporary file.", E_USER_ERROR);
                set_page_message(tr('Could not add/update SSL certificate. An unexpected error occurred.'), 'error');
                return;
            }

            if (@openssl_x509_checkpurpose($certificate, X509_PURPOSE_SSL_SERVER, [$config['DISTRO_CA_BUNDLE']], $tmpfname) !== true) {
                set_page_message(tr('At least one intermediate certificate is invalid or missing.'), 'error');
                return;
            }
        } else {
            if (@file_put_contents($tmpfname, $certificateStr) === false) {
                write_log("Couldn't write SSL certificate in temporary file.", E_USER_ERROR);
                set_page_message(tr('Could not add/update SSL certificate. An unexpected error occurred.'), 'error');
                return;
            }

            // Note: Here we also add the certificate in the trusted chain to support self-signed certificates
            if (@openssl_x509_checkpurpose($certificate, X509_PURPOSE_SSL_SERVER, [$config['DISTRO_CA_BUNDLE'], $tmpfname]) !== true) {
                set_page_message(tr('At least one intermediate certificate is invalid or missing.'), 'error');
                return;
            }
        }
    }

    // Preparing data for insertion in database
    if (!$selfSigned) {
        if (@openssl_pkey_export($privateKey, $privateKeyStr) === false) {
            write_log("Couldn't export private key.", E_USER_ERROR);
            set_page_message(tr('Could not add/update SSL certificate. An unexpected error occurred.'), 'error');
            return;
        }

        @openssl_pkey_free($privateKey);
        if (@openssl_x509_export($certificate, $certificateStr) === false) {
            write_log("Couldn't export SSL certificate.", E_USER_ERROR);
            set_page_message(tr('Could not add/update SSL certificate. An unexpected error occurred.'), 'error');
            return;
        }

        @openssl_x509_free($certificate);
        $caBundleStr = str_replace("\r\n", "\n", $caBundle);
    } else {
        $privateKeyStr = $privateKey;
        $certificateStr = $certificate;
        $caBundleStr = $caBundle;
    }

    /** @var iMSCP_Database $db */
    $db = Registry::get('iMSCP_Application')->getDatabase();

    try {
        $db->beginTransaction();

        if ($certId == 0) { // Add new certificate
            exec_query(
                "
                    INSERT INTO ssl_certs (
                        domain_id, domain_type, private_key, certificate, ca_bundle, allow_hsts, hsts_max_age,
                        hsts_include_subdomains, status
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, 'toadd'
                    )
                ",
                [
                    $domainId, $domainType, $privateKeyStr, $certificateStr, $caBundleStr, $allowHSTS,
                    $hstsMaxAge, $hstsIncludeSubDomains
                ]
            );
        } else { // Update existing certificate
            exec_query(
                "
                    UPDATE ssl_certs SET private_key = ?, certificate = ?, ca_bundle = ?, allow_hsts = ?,
                        hsts_max_age = ?, hsts_include_subdomains = ?, status = 'tochange'
                    WHERE cert_id = ?
                    AND domain_id = ?
                    AND domain_type = ?
                ",
                [
                    $privateKeyStr, $certificateStr, $caBundleStr, $allowHSTS, $hstsMaxAge, $hstsIncludeSubDomains,
                    $certId, $domainId, $domainType
                ]
            );
        }

        _client_updateDomainStatus($domainId, $domainType);

        $db->commit();

        send_request();

        if ($certId == 0) {
            set_page_message(tr('SSL certificate successfully scheduled for addition.'), 'success');
            write_log(sprintf('%s added a new SSL certificate for the %s domain', $_SESSION['user_logged'], decode_idna($domainName)), E_USER_NOTICE);
        } else {
            set_page_message(tr('SSL certificate successfully scheduled for update.'), 'success');
            write_log(sprintf('%s updated an SSL certificate for the %s domain', $_SESSION['user_logged'], $domainName), E_USER_NOTICE);
        }

        redirectTo("cert_view.php?id=$domainId&type=$domainType");
    } catch (iMSCP_Exception $e) {
        $db->rollBack();
        write_log("Couldn't add/update SSL certificate in database", E_USER_ERROR);
        set_page_message(tr('An unexpected error occurred. Please contact your reseller.'), 'error');
    }
}

/**
 * Delete an SSL certificate
 *
 * @param int $domainId domain unique identifier
 * @param string $domainType Domain type (dmn, als, sub, alssub)
 * @return void
 */
function client_deleteSslCert($domainId, $domainType)
{
    $domainName = _client_getDomainName($domainId, $domainType);

    if ($domainName === false) {
        showBadRequestErrorPage();
    }

    if (!isset($_POST['cert_id'])) {
        showBadRequestErrorPage();
    }

    $certId = intval($_POST['cert_id']);

    /** @var iMSCP_Database $db */
    $db = Registry::get('iMSCP_Application')->getDatabase();

    try {
        $db->beginTransaction();

        exec_query("UPDATE ssl_certs SET status = 'todelete' WHERE cert_id = ? AND domain_id = ? AND domain_type = ?", [
            $certId, $domainId, $domainType
        ]);

        _client_updateDomainStatus($domainId, $domainType);

        $db->commit();

        send_request();
        set_page_message(tr('SSL certificate successfully scheduled for deletion.'), 'success');
        write_log(sprintf('%s deleted SSL certificate for the %s domain.', $_SESSION['user_logged'], decode_idna($domainName)), E_USER_NOTICE);
        redirectTo('domains_manage.php');
    } catch (iMSCP_Exception $e) {
        $db->rollBack();
        write_log(sprintf("Couldn't export SSL certificate: %s", $e->getMessage()), E_USER_ERROR);
        set_page_message(tr('Could not delete SSL certificate. An unexpected error occurred.'), 'error');
    }
}

/**
 * Generate page
 *
 * @param TemplateEngine $tpl
 * @param int $domainId Domain entity unique identifier
 * @param string $domainType Domain entity type
 * @return void
 */
function client_generatePage(TemplateEngine $tpl, $domainId, $domainType)
{
    $domainName = _client_getDomainName($domainId, $domainType);

    if ($domainName === false) {
        showBadRequestErrorPage();
    }

    $stmt = exec_query('SELECT * FROM ssl_certs WHERE domain_id = ? AND domain_type = ?', [$domainId, $domainType]);

    if ($stmt->rowCount()) {
        $row = $stmt->fetch();
        $dynTitle = (customerHasFeature('ssl') && $row['status'] == 'ok')
            ? tr('Edit SSL certificate') : tr('Show SSL certificate');
        $certId = $row['cert_id'];
        $privateKey = tohtml($row['private_key']);
        $certificate = tohtml($row['certificate']);
        $caBundle = tohtml($row['ca_bundle']);
        $allowHSTS = ($row['allow_hsts'] == 'on');
        $hstsMaxAge = $row['hsts_max_age'];
        $hstsIncludeSubDomains = ($row['hsts_include_subdomains'] == 'on');
        $trAction = tr('Update');
        $status = $row['status'];
        $tpl->assign('STATUS', in_array($status, ['toadd', 'tochange', 'todelete', 'ok'])
            ? translate_dmn_status($status) : '<span style="color: red;font-weight: bold">' . $status . "</span>"
        );
    } elseif (customerHasFeature('ssl')) {
        $dynTitle = tr('Add SSL certificate');
        $trAction = tr('Add');
        $certId = '0';
        $privateKey = '';
        $certificate = '';
        $caBundle = '';
        $allowHSTS = false;
        $hstsMaxAge = '31536000';
        $hstsIncludeSubDomains = false;
        $tpl->assign([
            'SSL_CERTIFICATE_STATUS'        => '',
            'SSL_CERTIFICATE_ACTION_DELETE' => ''
        ]);
    } else {
        set_page_message('SSL feature is currently disabled.', 'static_warning');
        redirectTo('domains_manage.php');
        return;
    }

    if (customerHasFeature('ssl') && isset($_POST['cert_id']) && isset($_POST['private_key'])
        && isset($_POST['certificate']) && isset($_POST['ca_bundle'])
    ) {
        $certId = $_POST['cert_id'];
        $privateKey = $_POST['private_key'];
        $certificate = $_POST['certificate'];
        $caBundle = $_POST['ca_bundle'];
        $allowHSTS = (isset($_POST['allow_hsts']) && $_POST['allow_hsts'] === 'on');
        $hstsMaxAge = ($allowHSTS && isset($_POST['hsts_max_age']) && is_number($_POST['hsts_max_age'])
            && $_POST['hsts_max_age'] >= 0) ? intval($_POST['hsts_max_age']) : '31536000';
        $hstsIncludeSubDomains = ($allowHSTS && isset($_POST['hsts_include_subdomains'])
            && $_POST['hsts_include_subdomains'] === 'on');
    }

    $tpl->assign([
        'TR_DYNAMIC_TITLE'           => $dynTitle,
        'DOMAIN_NAME'                => tohtml(decode_idna($domainName)),
        'HSTS_CHECKED_ON'            => $allowHSTS ? ' checked' : '',
        'HSTS_CHECKED_OFF'           => !$allowHSTS ? ' checked' : '',
        'HSTS_MAX_AGE'               => tohtml($hstsMaxAge, 'htmlAttr'),
        'HSTS_INCLUDE_SUBDOMAIN_ON'  => $hstsIncludeSubDomains ? ' checked' : '',
        'HSTS_INCLUDE_SUBDOMAIN_OFF' => !$hstsIncludeSubDomains ? ' checked' : '',
        'KEY_CERT'                   => tohtml(trim($privateKey)),
        'CERTIFICATE'                => tohtml(trim($certificate)),
        'CA_BUNDLE'                  => tohtml(trim($caBundle)),
        'CERT_ID'                    => tohtml($certId),
        'TR_ACTION'                  => tohtml($trAction, 'htmlAttr'),
        'TR_YES'                     => tr('Yes'),
        'TR_NO'                      => tr('No')
    ]);

    if (!customerHasFeature('ssl')
        || (isset($status) && in_array($status, ['toadd', 'tochange', 'todelete']))
    ) {
        $tpl->assign('SSL_CERTIFICATE_ACTIONS', '');

        if (!customerHasFeature('ssl')) {
            set_page_message(tr('SSL feature is not available. You can only view your certificate.'), 'static_warning');
        }
    }
}

/***********************************************************************************************************************
 * Main
 */

require_once 'imscp-lib.php';

check_login('user');
Registry::get('iMSCP_Application')->getEventsManager()->dispatch(Events::onClientScriptStart);
isset($_GET['id']) && isset($_GET['type']) && in_array($_GET['type'], ['dmn', 'als', 'sub', 'alssub']) or showBadRequestErrorPage();

$tpl = new TemplateEngine();
$tpl->define([
    'layout'                  => 'shared/layouts/ui.tpl',
    'page'                    => 'client/cert_view.tpl',
    'page_message'            => 'layout',
    'ssl_certificate_status'  => 'page',
    'ssl_certificate_actions' => 'page'
]);



$domainId = intval($_GET['id']);
$domainType = clean_input($_GET['type']);

if (customerHasFeature('ssl')
    && !empty($_POST)
) {
    if (isset($_POST['add_update'])) {
        client_addSslCert($domainId, $domainType);
    } elseif (isset($_POST['delete'])) {
        client_deleteSslCert($domainId, $domainType);
    } else {
        showBadRequestErrorPage();
    }
}

$tpl->assign([
    'TR_PAGE_TITLE'                      => tohtml(tr('Client / Domains / SSL Certificate')),
    'TR_CERTIFICATE_DATA'                => tohtml(tr('Certificate data')),
    'TR_CERT_FOR'                        => tohtml(tr('Common name')),
    'TR_STATUS'                          => tohtml(tr('Status')),
    'TR_ALLOW_HSTS'                      => tohtml(tr('HSTS (HTTP Strict Transport Security)')),
    'TR_HSTS_MAX_AGE'                    => tohtml(tr('HSTS max-age directive value')),
    'TR_SEC'                             => tohtml(tr('Sec.')),
    'TR_HSTS_INCLUDE_SUBDOMAINS'         => tohtml(tr('HSTS includeSubDomains directive')),
    'TR_HSTS_INCLUDE_SUBDOMAINS_TOOLTIP' => tohtml(tr("You should enable this directive only if all subdomains of this domain are served through SSL. Note that even if you add this directive, this will not automatically activate the HSTS feature for the subdomains of this domain."), 'htmlAttr'),
    'TR_GENERATE_SELFSIGNED_CERTIFICAT'  => tohtml(tr('Generate a self-signed certificate')),
    'TR_PASSWORD'                        => tohtml(tr('Private key passphrase if any')),
    'TR_PRIVATE_KEY'                     => tohtml(tr('Private key')),
    'TR_CERTIFICATE'                     => tohtml(tr('Certificate')),
    'TR_CA_BUNDLE'                       => tohtml(tr('Intermediate certificate(s)')),
    'TR_DELETE'                          => tohtml(tr('Delete')),
    'TR_CANCEL'                          => tohtml(tr('Cancel')),
    'DOMAIN_ID'                          => tohtml($domainId),
    'DOMAIN_TYPE'                        => tohtml($domainType)
]);

generateNavigation($tpl);
client_generatePage($tpl, $domainId, $domainType);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
Registry::get('iMSCP_Application')->getEventsManager()->dispatch(Events::onClientScriptEnd, ['templateEngine' => $tpl]);
$tpl->prnt();

unsetMessages();
