<?php
/**
 *
 * This file is part of HESK - PHP Help Desk Software.
 *
 * (c) Copyright Klemen Stirn. All rights reserved.
 * https://www.hesk.com
 *
 * For the full copyright and license agreement information visit
 * https://www.hesk.com/eula.php
 *
 */

/* Check if this is a valid include */
if (!defined('IN_SCRIPT')) {
    die('Invalid attempt');
}

#error_reporting(E_ALL);

/*
 * If code is executed from CLI, don't force SSL
 * else set correct Content-Type header
 */
if (defined('NO_HTTP_HEADER')) {
    $hesk_settings['force_ssl'] = false;
} else {
    header('Content-Type: text/html; charset=utf-8');

    // Don't allow HESK to be loaded in a frame on third party domains
    if ($hesk_settings['x_frame_opt'])
    {
        header('X-Frame-Options: SAMEORIGIN');
    }
}

// Set backslash options
if (get_magic_quotes_gpc()) {
    define('HESK_SLASH', false);
} else {
    define('HESK_SLASH', true);
}

// Define some constants for backward-compatibility
if (!defined('ENT_SUBSTITUTE')) {
    define('ENT_SUBSTITUTE', 0);
}
if (!defined('ENT_XHTML')) {
    define('ENT_XHTML', 0);
}

// Is this is a SSL connection?
if (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on') {
    define('HESK_SSL', true);

    // Use https-only cookies
    @ini_set('session.cookie_secure', 1);
} else {
    // Force redirect?
    if ($hesk_settings['force_ssl']) {
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        exit();
    }

    define('HESK_SSL', false);
}

// Prevents javascript XSS attacks aimed to steal the session ID
@ini_set('session.cookie_httponly', 1);

// **PREVENTING SESSION FIXATION**
// Session ID cannot be passed through URLs
@ini_set('session.use_only_cookies', 1);


// Load language file
hesk_getLanguage();

// Set timezone
hesk_setTimezone();


/*** FUNCTIONS ***/

function hesk_getClientIP() {
    global $hesk_settings;

    // Already set? Just return it
    if (isset($hesk_settings['client_IP'])) {
        return $hesk_settings['client_IP'];
    }

    // Empty client IP, for example when used in CLI (piping, cron jobs, ...)
    $hesk_settings['client_IP'] = '';

    // Server (environment) variables to loop through
    // the first valid one found will be returned as client IP
    // Uncomment those used on your server
    $server_client_IP_variables = array(
        // 'HTTP_CF_CONNECTING_IP', // CloudFlare
        // 'HTTP_CLIENT_IP',
        // 'HTTP_X_FORWARDED_FOR',
        // 'HTTP_X_FORWARDED',
        // 'HTTP_FORWARDED_FOR',
        // 'HTTP_FORWARDED',
        'REMOTE_ADDR',
    );

    // The first valid environment variable is our client IP
    foreach ($server_client_IP_variables as $server_client_IP_variable) {
        // Must be set
        if (!isset($_SERVER[$server_client_IP_variable])) {
            continue;
        }

        // Must be a valid IP
        if (!hesk_isValidIP($_SERVER[$server_client_IP_variable])) {
            continue;
        }

        // Bingo!
        $hesk_settings['client_IP'] = $_SERVER[$server_client_IP_variable];
        break;
    }

    return $hesk_settings['client_IP'];

} // END hesk_getClientIP()


function hesk_isValidIP($ip) {
    // Use filter_var for PHP 5.2.0+
    if (function_exists('filter_var') && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    // Use regex for PHP < 5.2.0

    // -> IPv4
    if (preg_match('/^[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}.[0-9]{1,3}$/', $ip)) {
        return true;
    }

    // -> IPv6
    if (preg_match('/^[0-9A-Fa-f\:\.]+$/', $ip)) {
        return true;
    }

    // Not a valid IP
    return false;

} // END hesk_isValidIP()

function hesk_setcookie($name, $value, $expire=0, $path=""){
    if (HESK_SSL) {
        setcookie($name, $value, $expire, $path, "", true, true);
    } else {
        setcookie($name, $value, $expire, $path, "", false, true);
    }

    return true;
} // END hesk_setcookie()

function hesk_service_message($sm)
{
    $faIcon = $sm['icon'];
    switch ($sm['style']) {
        case 1:
            $style = "alert alert-success";
            break;
        case 2:
            $style = "alert alert-info";
            break;
        case 3:
            $style = "alert alert-warning";
            break;
        case 4:
            $style = "alert alert-danger";
            break;
        default:
            $style = "none";
    }

    ?>
    <div class="<?php echo $style; ?>">
        <?php echo $faIcon == '' ? '' : '<i class="' . $faIcon . '"></i> '; ?>
        <b><?php echo $sm['title']; ?></b><br>
        <?php echo $sm['message']; ?>
    </div>
    <br/>
    <?php
} // END hesk_service_message()

function mfh_get_service_messages($location) {
    global $hesk_settings;

    $language = $hesk_settings['languages'][$hesk_settings['language']]['folder'];

    $res = hesk_dbQuery('SELECT `title`, `message`, `style`, `icon` FROM `'.hesk_dbEscape($hesk_settings['db_pfix'])."service_messages` AS `sm`
        INNER JOIN `" . hesk_dbEscape($hesk_settings['db_pfix'])  . "mfh_service_message_to_location` AS `location`
            ON `sm`.`id` = `location`.`service_message_id`
            AND `location`.`location` = '" . hesk_dbEscape($location) . "'
            AND `sm`.`mfh_language` IN ('ALL', '" . hesk_dbEscape($language) . "')
        WHERE `type`='0' 
        ORDER BY `order` ASC");

    $sm = array();
    while ($row = hesk_dbFetchAssoc($res)) {
        $sm[] = $row;
    }

    return $sm;
}


function hesk_isBannedIP($ip)
{
    global $hesk_settings, $hesklang, $hesk_db_link;

    $ip = ip2long($ip) or $ip = 0;

    // We need positive value of IP
    if ($ip < 0) {
        $ip += 4294967296;
    } elseif ($ip > 4294967296) {
        $ip = 4294967296;
    }

    $res = hesk_dbQuery("SELECT `id` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "banned_ips` WHERE {$ip} BETWEEN `ip_from` AND `ip_to` LIMIT 1");

    return (hesk_dbNumRows($res) == 1) ? hesk_dbResult($res) : false;

} // END hesk_isBannedIP()


function hesk_isBannedEmail($email)
{
    global $hesk_settings, $hesklang, $hesk_db_link;

    $email = strtolower($email);

    $res = hesk_dbQuery("SELECT `id` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "banned_emails` WHERE `email` IN ('" . hesk_dbEscape($email) . "', '" . hesk_dbEscape(substr($email, strrpos($email, "@"))) . "') LIMIT 1");

    return (hesk_dbNumRows($res) == 1) ? hesk_dbResult($res) : false;

} // END hesk_isBannedEmail()


function hesk_clean_utf8($in)
{
    //reject overly long 2 byte sequences, as well as characters above U+10000 and replace with ?
    $in = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]' .
        '|[\x00-\x7F][\x80-\xBF]+' .
        '|([\xC0\xC1]|[\xF0-\xFF])[\x80-\xBF]*' .
        '|[\xC2-\xDF]((?![\x80-\xBF])|[\x80-\xBF]{2,})' .
        '|[\xE0-\xEF](([\x80-\xBF](?![\x80-\xBF]))|(?![\x80-\xBF]{2})|[\x80-\xBF]{3,})/S',
        '?', $in);

    //reject overly long 3 byte sequences and UTF-16 surrogates and replace with ?
    $in = preg_replace('/\xE0[\x80-\x9F][\x80-\xBF]' .
        '|\xED[\xA0-\xBF][\x80-\xBF]/S', '?', $in);

    return $in;
} // END hesk_clean_utf8()


function hesk_load_database_functions()
{
    // Already loaded?
    if (function_exists('hesk_dbQuery')) {
        return true;
    }

    // Preferrably use the MySQLi functions
    if (function_exists('mysqli_connect')) {
        require(HESK_PATH . 'inc/database_mysqli.inc.php');
    } // Default to MySQL
    else {
        require(HESK_PATH . 'inc/database.inc.php');
    }
} // END hesk_load_database_functions()


function hesk_load_api_database_functions()
{
    require(__DIR__ . '/../api/Core/json_error.php');
    // Preferrably use the MySQLi functions
    if (function_exists('mysqli_connect')) {
        require(__DIR__ . '/../api/Core/database_mysqli.inc.php');
    } // Default to MySQL
    else {
        require(__DIR__ . '/../api/Core/database.inc.php');
    }
} // END hesk_load_database_functions()


function hesk_load_internal_api_database_functions()
{
    require(HESK_PATH . 'internal-api/core/json_error.php');
    // Preferrably use the MySQLi functions
    if (function_exists('mysqli_connect')) {
        require(HESK_PATH . 'internal-api/core/database_mysqli.inc.php');
    } // Default to MySQL
    else {
        require(HESK_PATH . 'internal-api/core/database.inc.php');
    }
} // END hesk_load_database_functions()

function hesk_load_cron_database_functions()
{
    if (function_exists('mysqli_connect')) {
        require(HESK_PATH . 'cron/core/database_mysqli.inc.php');
    } // Default to MySQL
    else {
        require(HESK_PATH . 'cron/core/database.inc.php');
    }
} // END hesk_load_cron_database_functions()

function hesk_unlink($file, $older_than = 0)
{
    return (is_file($file) && (!$older_than || (time() - filectime($file)) > $older_than) && @unlink($file)) ? true : false;
} // END hesk_unlink()


function hesk_unlink_callable($file, $key, $older_than=0)
{
    return hesk_unlink($file, $older_than);
} // END hesk_unlink_callable()


function hesk_utf8_urldecode($in)
{
    $in = preg_replace("/%u([0-9a-f]{3,4})/i", "&#x\\1;", urldecode($in));
    return hesk_html_entity_decode($in);
} // END hesk_utf8_urldecode

function hesk_SESSION($in, $default = '')
{
    if (is_array($in)) {
        return isset($_SESSION[$in[0]][$in[1]]) && ! is_array(isset($_SESSION[$in[0]][$in[1]])) ? $_SESSION[$in[0]][$in[1]] : $default;
    } else {
        return isset($_SESSION[$in]) && ! is_array($_SESSION[$in]) ? $_SESSION[$in] : $default;
    }
} // END hesk_SESSION();


function hesk_COOKIE($in, $default = '')
{
    return isset($_COOKIE[$in]) && !is_array($_COOKIE[$in]) ? $_COOKIE[$in] : $default;
} // END hesk_COOKIE();


function hesk_GET($in, $default = '')
{
    return isset($_GET[$in]) && !is_array($_GET[$in]) ? $_GET[$in] : $default;
} // END hesk_GET()


function hesk_POST($in, $default = '')
{
    return isset($_POST[$in]) && !is_array($_POST[$in]) ? $_POST[$in] : $default;
} // END hesk_POST()

function hesk_POST_array($in, $default = array())
{
    return isset($_POST[$in]) && is_array($_POST[$in]) ? $_POST[$in] : $default;
} // END hesk_POST_array()


function hesk_REQUEST($in, $default = false)
{
    return isset($_GET[$in]) ? hesk_input(hesk_GET($in)) : (isset($_POST[$in]) ? hesk_input(hesk_POST($in)) : $default);
} // END hesk_REQUEST()


function hesk_isREQUEST($in)
{
    return isset($_GET[$in]) || isset($_POST[$in]) ? true : false;
} // END hesk_isREQUEST()

function hesk_mb_substr($in, $start, $length)
{
   return function_exists('mb_substr') ? mb_substr($in, $start, $length, 'UTF-8') : substr($in, $start, $length);
} // END hesk_mb_substr()

function hesk_mb_strlen($in)
{
   return function_exists('mb_strlen') ? mb_strlen($in, 'UTF-8') : strlen($in);
} // END hesk_mb_strlen()

function hesk_mb_strtolower($in) {
    return function_exists('mb_strtolower') ? mb_strtolower($in) : strtolower($in);
} // END hesk_mb_strtolower()

function hesk_ucfirst($in) {
    return function_exists('mb_convert_case') ? mb_convert_case($in, MB_CASE_TITLE, 'UTF-8') : ucfirst($in);
} // END hesk_mb_ucfirst()


function hesk_htmlspecialchars_decode($in)
{
    return str_replace(array('&amp;', '&lt;', '&gt;', '&quot;'), array('&', '<', '>', '"'), $in);
} // END hesk_htmlspecialchars_decode()


function hesk_html_entity_decode($in)
{
    return html_entity_decode($in, ENT_COMPAT | ENT_XHTML, 'UTF-8');
    #return html_entity_decode($in, ENT_COMPAT | ENT_XHTML, 'ISO-8859-1');
} // END hesk_html_entity_decode()


function hesk_htmlspecialchars($in)
{
    return htmlspecialchars($in, ENT_COMPAT | ENT_SUBSTITUTE | ENT_XHTML, 'UTF-8');
    #return htmlspecialchars($in, ENT_COMPAT | ENT_SUBSTITUTE | ENT_XHTML, 'ISO-8859-1');
} // END hesk_htmlspecialchars()


function hesk_htmlentities($in)
{
    return htmlentities($in, ENT_COMPAT | ENT_SUBSTITUTE | ENT_XHTML, 'UTF-8');
    #return htmlentities($in, ENT_COMPAT | ENT_SUBSTITUTE | ENT_XHTML, 'ISO-8859-1');
} // END hesk_htmlentities()


function hesk_slashJS($in)
{
    return str_replace('\'', '\\\'', $in);
} // END hesk_slashJS()


function hesk_verifyEmailMatch($trackingID, $my_email = 0, $ticket_email = 0, $error = 1)
{
    global $hesk_settings, $hesklang, $hesk_db_link;

    /* Email required to view ticket? */
    if (!$hesk_settings['email_view_ticket']) {
        $hesk_settings['e_param'] = '';
        $hesk_settings['e_query'] = '';
        $hesk_settings['e_email'] = '';
        return true;
    }

    /* Limit brute force attempts */
    hesk_limitBfAttempts();

    /* Get email address */
    if ($my_email) {
        $hesk_settings['e_param'] = '&e=' . rawurlencode($my_email);
        $hesk_settings['e_query'] = '&amp;e=' . rawurlencode($my_email);
        $hesk_settings['e_email'] = $my_email;
    } else {
        $my_email = hesk_getCustomerEmail();
    }

    /* Get email from ticket */
    if (!$ticket_email) {
        $res = hesk_dbQuery("SELECT `email` FROM `" . $hesk_settings['db_pfix'] . "tickets` WHERE `trackid`='" . hesk_dbEscape($trackingID) . "' LIMIT 1");
        if (hesk_dbNumRows($res) == 1) {
            $ticket_email = hesk_dbResult($res);
        } else {
            hesk_process_messages($hesklang['ticket_not_found'], 'ticket.php');
        }
    }

    /* Validate email */
    if ($hesk_settings['multi_eml']) {
        $ticket_email = str_replace(';', ',', $ticket_email);
        $valid_emails = explode(',', strtolower($ticket_email));
        if (in_array(strtolower($my_email), $valid_emails)) {
            /* Match, clean brute force attempts and return true */
            hesk_cleanBfAttempts();
            return true;
        }
    } elseif (strtolower($ticket_email) == strtolower($my_email)) {
        /* Match, clean brute force attempts and return true */
        hesk_cleanBfAttempts();
        return true;
    }

    /* Email doesn't match, clean cookies and error out */
    if ($error) {
        hesk_setcookie('hesk_myemail', '');
        hesk_process_messages($hesklang['enmdb'], 'ticket.php?track=' . $trackingID . '&Refresh=' . rand(10000, 99999));
    } else {
        return false;
    }

} // END hesk_verifyEmailMatch()


function hesk_getCustomerEmail($can_remember = 0, $field = '', $force_only_one = 0)
{
    global $hesk_settings, $hesklang;

    /* Email required to view ticket? */
    if (!$hesk_settings['email_view_ticket']) {
        $hesk_settings['e_param'] = '';
        $hesk_settings['e_query'] = '';
        $hesk_settings['e_email'] = '';
        return '';
    }

    /* Is this a form that enables remembering email? */
    if ($can_remember) {
        global $do_remember;
    }

    $my_email = '';

    /* Is email in session? */
    if ( strlen($field) && isset($_SESSION[$field]) )
    {
        $my_email = hesk_validateEmail($_SESSION[$field], 'ERR', 0);
    }

    /* Is email in query string? */
    if (isset($_GET['e']) || isset($_POST['e'])) {
        $my_email = hesk_validateEmail(hesk_REQUEST('e'), 'ERR', 0);
    } /* Is email in cookie? */
    elseif (isset($_COOKIE['hesk_myemail'])) {
        $my_email = hesk_validateEmail(hesk_COOKIE('hesk_myemail'), 'ERR', 0);
        if ($can_remember && $my_email) {
            $do_remember = ' checked="checked" ';
        }
    }

    // Remove unwanted side-effects
    $my_email = hesk_emailCleanup($my_email);

    // Force only one email address? Use the first one.
    if ($force_only_one) {
        $my_email = strtok($my_email, ',');
    }

    $hesk_settings['e_param'] = '&e=' . rawurlencode($my_email);
    $hesk_settings['e_query'] = '&amp;e=' . rawurlencode($my_email);
    $hesk_settings['e_email'] = $my_email;

    return $my_email;

} // END hesk_getCustomerEmail()

function hesk_emailCleanup($my_email) {
    return preg_replace("/(\\\)+'/", "'", $my_email);
} // END hesk_emailCleanup()


function hesk_formatBytes($size, $translate_unit = 1, $precision = 2)
{
    global $hesklang;

    $units = array(
        'GB' => 1073741824,
        'MB' => 1048576,
        'kB' => 1024,
        'B' => 1
    );

    foreach ($units as $suffix => $bytes) {
        if ($bytes > $size) {
            continue;
        }

        $full = $size / $bytes;
        $round = round($full, $precision);

        if ($full == $round) {
            if ($translate_unit) {
                return $round . ' ' . $hesklang[$suffix];
            } else {
                return $round . ' ' . $suffix;
            }
        }
    }

    return false;
} // End hesk_formatBytes()


function hesk_autoAssignTicket($ticket_category)
{
    global $hesk_settings, $hesklang;

    /* Auto assign ticket enabled? */
    if (!$hesk_settings['autoassign']) {
        return false;
    }

    $autoassign_owner = array();

    /* Get all possible auto-assign staff, order by number of open tickets */
    $res = hesk_dbQuery("SELECT `t1`.`id`,`t1`.`user`,`t1`.`name`, `t1`.`email`, `t1`.`language`, `t1`.`isadmin`, `t1`.`categories`, `t1`.`notify_assigned`, `t1`.`heskprivileges`,
					    (SELECT COUNT(*) FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "tickets` FORCE KEY (`statuses`) WHERE `owner`=`t1`.`id` AND `status` IN (SELECT `ID` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "statuses` WHERE `IsClosed` = 0) ) as `open_tickets`
						FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "users` AS `t1`
						WHERE `t1`.`autoassign`='1' ORDER BY `open_tickets` ASC, RAND()");

    /* Loop through the rows and return the first appropriate one */
    while ($myuser = hesk_dbFetchAssoc($res)) {
        /* Is this an administrator? */
        if ($myuser['isadmin']) {
            $autoassign_owner = $myuser;
            $hesk_settings['user_data'][$myuser['id']] = $myuser;
            hesk_dbFreeResult($res);
            break;
        }

        /* Not and administrator, check two things: */

        /* --> can view and reply to tickets */
        if (strpos($myuser['heskprivileges'], 'can_view_tickets') === false || strpos($myuser['heskprivileges'], 'can_reply_tickets') === false) {
            continue;
        }

        /* --> has access to ticket category */
        $myuser['categories'] = explode(',', $myuser['categories']);
        if (in_array($ticket_category, $myuser['categories'])) {
            $autoassign_owner = $myuser;
            $hesk_settings['user_data'][$myuser['id']] = $myuser;
            hesk_dbFreeResult($res);
            break;
        }
    }

    return $autoassign_owner;

} // END hesk_autoAssignTicket()


function hesk_cleanID($field = 'track', $in=false)
{
	$id = '';
	
	if ($in !== false){
		$id = $in;
	} elseif (isset($_SESSION[$field])) {
		$id = $_SESSION[$field];
    } elseif ( isset($_GET[$field]) && ! is_array($_GET[$field]) ) {
        $id = $_GET[$field];
    } elseif (isset($_POST[$field]) && !is_array($_POST[$field])) {
		$id = $_POST[$field];
    } else {
        return false;
    }
	
	return substr(preg_replace('/[^A-Z0-9\-]/', '', strtoupper($id)), 0, 12);

} // END hesk_cleanID()


function hesk_createID()
{
    global $hesk_settings, $hesklang, $hesk_error_buffer;

    /*** Generate tracking ID and make sure it's not a duplicate one ***/

    /* Ticket ID can be of these chars */
    $useChars = 'AEUYBDGHJLMNPQRSTVWXZ123456789';

    /* Set tracking ID to an empty string */
    $trackingID = '';

    /* Let's avoid duplicate ticket ID's, try up to 3 times */
    for ($i = 1; $i <= 3; $i++) {
        /* Generate raw ID */
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];
        $trackingID .= $useChars[mt_rand(0, 29)];

        /* Format the ID to the correct shape and check wording */
        $trackingID = hesk_formatID($trackingID);

        /* Check for duplicate IDs */
        $res = hesk_dbQuery("SELECT `id` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "tickets` WHERE `trackid` = '" . hesk_dbEscape($trackingID) . "' LIMIT 1");

        if (hesk_dbNumRows($res) == 0) {
            /* Everything is OK, no duplicates found */
            return $trackingID;
        }

        /* A duplicate ID has been found! Let's try again (up to 2 more) */
        $trackingID = '';
    }

    /* No valid tracking ID, try one more time with microtime() */
    $trackingID = $useChars[mt_rand(0, 29)];
    $trackingID .= $useChars[mt_rand(0, 29)];
    $trackingID .= $useChars[mt_rand(0, 29)];
    $trackingID .= $useChars[mt_rand(0, 29)];
    $trackingID .= $useChars[mt_rand(0, 29)];
    $trackingID .= substr(microtime(), -5);

    /* Format the ID to the correct shape and check wording */
    $trackingID = hesk_formatID($trackingID);

    $res = hesk_dbQuery("SELECT `id` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "tickets` WHERE `trackid` = '" . hesk_dbEscape($trackingID) . "' LIMIT 1");

    /* All failed, must be a server-side problem... */
    if (hesk_dbNumRows($res) == 0) {
        return $trackingID;
    }

    $hesk_error_buffer['etid'] = $hesklang['e_tid'];
    return false;

} // END hesk_createID()


function hesk_formatID($id)
{

    $useChars = 'AEUYBDGHJLMNPQRSTVWXZ123456789';

    $replace = $useChars[mt_rand(0, 29)];
    $replace .= mt_rand(1, 9);
    $replace .= $useChars[mt_rand(0, 29)];

    /*
    Remove 3 letter bad words from ID
    Possiblitiy: 1:27,000
    */
    $remove = array(
        'ASS',
        'CUM',
        'FAG',
        'FUK',
        'GAY',
        'SEX',
        'TIT',
        'XXX',
    );

    $id = str_replace($remove, $replace, $id);

    /*
    Remove 4 letter bad words from ID
    Possiblitiy: 1:810,000
    */
    $remove = array(
        'ANAL',
        'ANUS',
        'BUTT',
        'CAWK',
        'CLIT',
        'COCK',
        'CRAP',
        'CUNT',
        'DICK',
        'DYKE',
        'FART',
        'FUCK',
        'JAPS',
        'JERK',
        'JIZZ',
        'KNOB',
        'PISS',
        'POOP',
        'SHIT',
        'SLUT',
        'SUCK',
        'TURD',

        // Also, remove words that are known to trigger mod_security
        'WGET',
    );

    $replace .= mt_rand(1, 9);
    $id = str_replace($remove, $replace, $id);

    /* Format the ID string into XXX-XXX-XXXX format for easier readability */
    $id = $id[0] . $id[1] . $id[2] . '-' . $id[3] . $id[4] . $id[5] . '-' . $id[6] . $id[7] . $id[8] . $id[9];

    return $id;

} // END hesk_formatID()


function hesk_cleanBfAttempts()
{
    global $hesk_settings, $hesklang;

    /* If this feature is disabled, just return */
    if (!$hesk_settings['attempt_limit'] || defined('HESK_BF_CLEAN')) {
        return true;
    }

    /* Delete expired logs from the database */
    hesk_dbQuery("DELETE FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."logins` WHERE `ip`='".hesk_dbEscape(hesk_getClientIP())."'");

    define('HESK_BF_CLEAN', 1);

    return true;
} // END hesk_cleanAttempts()


function hesk_limitBfAttempts($showError = 1)
{
    global $hesk_settings, $hesklang;

    // Check if this IP is banned permanently
    if (hesk_isBannedIP(hesk_getClientIP())) {
        hesk_error($hesklang['baned_ip'], 0);
    }

    /* If this feature is disabled or already called, return false */
    if (!$hesk_settings['attempt_limit'] || defined('HESK_BF_LIMIT')) {
        return false;
    }

    /* Define this constant to avoid duplicate checks */
    define('HESK_BF_LIMIT', 1);

    $ip = hesk_getClientIP();

    /* Get number of failed attempts from the database */
    $res = hesk_dbQuery("SELECT `number`, (CASE WHEN `last_attempt` IS NOT NULL AND DATE_ADD(`last_attempt`, INTERVAL " . intval($hesk_settings['attempt_banmin']) . " MINUTE ) > NOW() THEN 1 ELSE 0 END) AS `banned` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "logins` WHERE `ip`='" . hesk_dbEscape($ip) . "' LIMIT 1");

    /* Not in the database yet? Add first one and return false */
    if (hesk_dbNumRows($res) != 1) {
        hesk_dbQuery("INSERT INTO `" . hesk_dbEscape($hesk_settings['db_pfix']) . "logins` (`ip`) VALUES ('" . hesk_dbEscape($ip) . "')");
        return false;
    }

    /* Get number of failed attempts and increase by 1 */
    $row = hesk_dbFetchAssoc($res);
    $row['number']++;

    /* If too many failed attempts either return error or reset count if time limit expired */
    if ($row['number'] >= $hesk_settings['attempt_limit']) {
        if ($row['banned']) {
            $tmp = sprintf($hesklang['yhbb'], $hesk_settings['attempt_banmin']);

            unset($_SESSION);

            if ($showError) {
                hesk_error($tmp, 0);
            } else {
                return $tmp;
            }
        } else {
            $row['number'] = 1;
        }
    }

    hesk_dbQuery("UPDATE `" . hesk_dbEscape($hesk_settings['db_pfix']) . "logins` SET `number`=" . intval($row['number']) . " WHERE `ip`='" . hesk_dbEscape($ip) . "' LIMIT 1");

    return false;

} // END hesk_limitAttempts()


function hesk_getCategoryName($id)
{
    global $hesk_settings, $hesklang;

    if (empty($id)) {
        return $hesklang['unas'];
    }

    // If we already have the name no need to query DB another time
    if (isset($hesk_settings['category_data'][$id]['name'])) {
        return $hesk_settings['category_data'][$id]['name'];
    }

    $res = hesk_dbQuery("SELECT `name` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "categories` WHERE `id`='" . intval($id) . "' LIMIT 1");

    if (hesk_dbNumRows($res) != 1) {
        return $hesklang['catd'];
    }

    $hesk_settings['category_data'][$id]['name'] = hesk_dbResult($res, 0, 0);

    return $hesk_settings['category_data'][$id]['name'];
} // END hesk_getCategoryName()

function hesk_getReplierName($ticket) {
    global $hesk_settings, $hesklang;

    // Already have this info?
    if (isset($ticket['last_reply_by'])) {
        return $ticket['last_reply_by'];
    }

    // Last reply by staff
    if ( ! empty($ticket['lastreplier'])) {
        // We don't know who from staff so just send "Staff"
        if (empty($ticket['replierid'])) {
            return $hesklang['staff'];
        }

        // Get the name using another function
        $replier = hesk_getOwnerName($ticket['replierid']);

        // If replier comes back as "unassigned", default to "Staff"
        if ($replier == $hesklang['unas']) {
            return $hesklang['staff'];
        }

        return $replier;
    }

    // Last reply by customer
    return $ticket['name'];

} // END hesk_getReplierName()



function hesk_getOwnerName($id)
{
    global $hesk_settings, $hesklang;

    if (empty($id)) {
        return $hesklang['unas'];
    }

    // If we already have the name no need to query DB another time
    if (isset($hesk_settings['user_data'][$id]['name'])) {
        return $hesk_settings['user_data'][$id]['name'];
    }

    $res = hesk_dbQuery("SELECT `name` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "users` WHERE `id`='" . intval($id) . "' LIMIT 1");

    if (hesk_dbNumRows($res) != 1) {
        return $hesklang['unas'];
    }

    $hesk_settings['user_data'][$id]['name'] = hesk_dbResult($res, 0, 0);

    return $hesk_settings['user_data'][$id]['name'];
} // END hesk_getOwnerName()


function hesk_cleanSessionVars($arr)
{
    if (is_array($arr)) {
        foreach ($arr as $str) {
            if (isset($_SESSION[$str])) {
                unset($_SESSION[$str]);
            }
        }
    } elseif (isset($_SESSION[$arr])) {
        unset($_SESSION[$arr]);
    }
} // End hesk_cleanSessionVars()


function hesk_process_messages($message, $redirect_to, $type = 'ERROR')
{
    global $hesk_settings, $hesklang;

    switch ($type) {
        case 'SUCCESS':
            $_SESSION['HESK_SUCCESS'] = TRUE;
            break;
        case 'NOTICE':
            $_SESSION['HESK_NOTICE'] = TRUE;
            break;
        case 'INFO':
            $_SESSION['HESK_INFO'] = TRUE;
            break;
        default:
            $_SESSION['HESK_ERROR'] = TRUE;
    }

    $_SESSION['HESK_MESSAGE'] = $message;

    /* In some cases we don't want a redirect */
    if ($redirect_to == 'NOREDIRECT') {
        return TRUE;
    }

    header('Location: ' . $redirect_to);
    exit();
} // END hesk_process_messages()


function hesk_handle_messages()
{
    global $hesk_settings, $hesklang;

    $return_value = true;

    // Primary message - only one can be displayed and HESK_MESSAGE is required
    if (isset($_SESSION['HESK_MESSAGE'])) {
        if (isset($_SESSION['HESK_SUCCESS'])) {
            hesk_show_success($_SESSION['HESK_MESSAGE']);
        } elseif (isset($_SESSION['HESK_ERROR'])) {
            hesk_show_error($_SESSION['HESK_MESSAGE']);
            $return_value = false;
        } elseif (isset($_SESSION['HESK_NOTICE'])) {
            hesk_show_notice($_SESSION['HESK_MESSAGE']);
        } elseif (isset($_SESSION['HESK_INFO'])) {
            hesk_show_info($_SESSION['HESK_MESSAGE']);
        }

        hesk_cleanSessionVars('HESK_MESSAGE');
    }

    // Cleanup any primary message types set
    hesk_cleanSessionVars('HESK_ERROR');
    hesk_cleanSessionVars('HESK_SUCCESS');
    hesk_cleanSessionVars('HESK_NOTICE');
    hesk_cleanSessionVars('HESK_INFO');

    // Secondary message
    if (isset($_SESSION['HESK_2ND_NOTICE']) && isset($_SESSION['HESK_2ND_MESSAGE'])) {
        hesk_show_notice($_SESSION['HESK_2ND_MESSAGE']);
        hesk_cleanSessionVars('HESK_2ND_NOTICE');
        hesk_cleanSessionVars('HESK_2ND_MESSAGE');
    }

    return $return_value;
} // END hesk_handle_messages()


function hesk_show_error($message, $title = '', $append_colon = true)
{
    global $hesk_settings, $hesklang;
    $title = $title ? $title : $hesklang['error'];
    $title = $append_colon ? $title . ':' : $title;
    ?>
    <div align="left" class="alert alert-danger">
        <b><?php echo $title; ?></b> <?php echo $message; ?>
    </div>
    <?php
} // END hesk_show_error()


function hesk_show_success($message, $title = '', $append_colon = true)
{
    global $hesk_settings, $hesklang;
    $title = $title ? $title : $hesklang['success'];
    $title = $append_colon ? $title . ':' : $title;
    ?>
    <div align="left" class="alert alert-success">
        <b><?php echo $title; ?></b> <?php echo $message; ?>
    </div>
    <?php
} // END hesk_show_success()


function hesk_show_notice($message, $title = '', $append_colon = true)
{
    global $hesk_settings, $hesklang;
    $title = $title ? $title : $hesklang['note'];
    $title = $append_colon ? $title . ':' : $title;
    ?>
    <div class="alert alert-warning">
        <b><?php echo $title; ?></b> <?php echo $message; ?>
    </div>
    <?php
} // END hesk_show_notice()

function hesk_show_info($message, $title = '', $append_colon = true)
{
    global $hesk_settings, $hesklang;
    $title = $title ? $title : $hesklang['info'];
    $title = $append_colon ? $title . ':' : $title;
    ?>
    <div class="info">
        <img src="<?php echo HESK_PATH; ?>img/info.png" width="16" height="16" border="0" alt=""
             style="vertical-align:text-bottom"/>
        <b><?php echo $title; ?></b> <?php echo $message; ?>
    </div>
    <br/>
    <?php
} // END hesk_show_info()

function hesk_token_echo($do_echo = 1)
{
    if (!defined('SESSION_CLEAN')) {
        $_SESSION['token'] = hesk_htmlspecialchars(strip_tags($_SESSION['token']));
        define('SESSION_CLEAN', true);
    }

    if ($do_echo) {
        echo $_SESSION['token'];
    } else {
        return $_SESSION['token'];
    }
} // END hesk_token_echo()


function hesk_token_check($method = 'GET', $show_error = 1)
{
    // Get the token
    $my_token = hesk_REQUEST('token');

    // Verify it or throw an error
    if (!hesk_token_compare($my_token)) {
        if ($show_error) {
            global $hesk_settings, $hesklang;
            hesk_error($hesklang['eto']);
        } else {
            return false;
        }
    }

    return true;
} // END hesk_token_check()


function hesk_token_compare($my_token)
{
    if (isset($_SESSION['token']) && $my_token == $_SESSION['token']) {
        return true;
    } else {
        return false;
    }
} // END hesk_token_compare()


function hesk_token_hash()
{
    return sha1(time() . microtime() . uniqid(rand(), true));
} // END hesk_token_hash()


function & ref_new(&$new_statement)
{
    return $new_statement;
} // END ref_new()


function hesk_ticketToPlain($ticket, $specialchars = 0, $strip = 1)
{
    if (is_array($ticket)) {
        foreach ($ticket as $key => $value) {
            $ticket[$key] = is_array($ticket[$key]) ? hesk_ticketToPlain($value, $specialchars, $strip) : hesk_msgToPlain($value, $specialchars, $strip);
        }

        return $ticket;
    } else {
        return hesk_msgToPlain($ticket, $specialchars, $strip);
    }
} // END hesk_ticketToPlain()

function hesk_msgToPlain($msg, $specialchars = 0, $strip = 1)
{
    $msg = preg_replace('/\<a href="(mailto:)?([^"]*)"[^\<]*\<\/a\>/i', "$2", $msg);
    $msg = preg_replace('/<br \/>\s*/', "\n", $msg);
    $msg = trim($msg);

    if ($strip) {
        $msg = stripslashes($msg);
    }

    if ($specialchars) {
        $msg = hesk_html_entity_decode($msg);
    }

    return $msg;
} // END hesk_msgToPlain()


function hesk_showTopBar($page_title)
{
    echo $page_title;
} // END hesk_showTopBar()

function hesk_getLanguagesAsFormIfNecessary($trackingID = false)
{

    global $hesk_settings, $hesklang;

    if ($hesk_settings['can_sel_lang']) {

        $str = '<form method="get" action="" role="form" style="margin:0;padding:0;border:0;white-space:nowrap;">';

        if ($trackingID !== false) {
            $str .= '<input type="hidden" name="track" value="'.hesk_htmlentities($trackingID).'">';

            if ($hesk_settings['email_view_ticket'] && isset($hesk_settings['e_email'])) {
                $str .= '<input type="hidden" name="e" value="'.hesk_htmlentities($hesk_settings['e_email']).'">';
            }
        }

        if (!isset($_GET)) {
            $_GET = array();
        }

        foreach ($_GET as $k => $v) {
            if ($k == 'language') {
                continue;
            }
            $str .= '<input type="hidden" name="' . hesk_htmlentities($k) . '" value="' . hesk_htmlentities($v) . '" />';
        }

        $str .= '<select name="language" class="form-control" onchange="this.form.submit()">';
        $str .= hesk_listLanguages(0);
        $str .= '</select><br/>';

        ?>
        <script language="javascript" type="text/javascript">
            document.write('<?php echo str_replace(array('"','<','=','>',"'"),array('\42','\74','\75','\76','\47'),$str . '</form>'); ?>');
        </script>
        <noscript>
            <?php
            echo $str . '<input type="submit" value="' . $hesklang['go'] . '" /></form>';
            ?>
        </noscript>
        <?php
    }
}


function hesk_listLanguages($doecho = 1)
{
    global $hesk_settings, $hesklang;

    $tmp = '';

    foreach ($hesk_settings['languages'] as $lang => $info) {
        if ($lang == $hesk_settings['language']) {
            $tmp .= '<option value="' . $lang . '" selected="selected">' . $lang . '</option>';
        } else {
            $tmp .= '<option value="' . $lang . '">' . $lang . '</option>';
        }
    }

    if ($doecho) {
        echo $tmp;
    } else {
        return $tmp;
    }
} // END hesk_listLanguages


function hesk_resetLanguage()
{
    global $hesk_settings, $hesklang;

    /* If this is not a valid request no need to change aynthing */
    if (!$hesk_settings['can_sel_lang'] || !defined('HESK_ORIGINAL_LANGUAGE')) {
        return false;
    }

    /* If we already have original language, just return true */
    if ($hesk_settings['language'] == HESK_ORIGINAL_LANGUAGE) {
        return true;
    }

    /* Get the original language file */
    $hesk_settings['language'] = HESK_ORIGINAL_LANGUAGE;
    return hesk_returnLanguage();
} // END hesk_resetLanguage()


function hesk_setLanguage($language)
{
    global $hesk_settings, $hesklang;

    /* If no language is set, use default */
    if (!$language) {
        $language = HESK_DEFAULT_LANGUAGE;
    }

    /* If this is not a valid request no need to change aynthing */
    if (!$hesk_settings['can_sel_lang'] || $language == $hesk_settings['language'] || !isset($hesk_settings['languages'][$language])) {
        return false;
    }

    /* Remember current language for future reset - if reset is not set already! */
    if (!defined('HESK_ORIGINAL_LANGUAGE')) {
        define('HESK_ORIGINAL_LANGUAGE', $hesk_settings['language']);
    }

    /* Get the new language file */
    $hesk_settings['language'] = $language;

    return hesk_returnLanguage();
} // END hesk_setLanguage()


function hesk_getLanguage()
{
    global $hesk_settings, $hesklang, $_SESSION;

    $language = $hesk_settings['language'];

    /* Remember what the default language is for some special uses like mass emails */
    define('HESK_DEFAULT_LANGUAGE', $hesk_settings['language']);

    /* Can users select language? */
    if (defined('NO_HTTP_HEADER') ||  empty($hesk_settings['can_sel_lang'])) {
        return hesk_returnLanguage();
    }

    /* Is a non-default language selected? If not use default one */
    if (isset($_GET['language'])) {
        $language = hesk_input(hesk_GET('language')) or $language = $hesk_settings['language'];
    } elseif (isset($_COOKIE['hesk_language'])) {
        $language = hesk_input(hesk_COOKIE('hesk_language')) or $language = $hesk_settings['language'];
    } else {
        return hesk_returnLanguage();
    }

    /* non-default language selected. Check if it's a valid one, if not use default one */
    if ($language != $hesk_settings['language'] && isset($hesk_settings['languages'][$language])) {
        $hesk_settings['language'] = $language;
    }

    /* Remember and set the selected language */
    hesk_setcookie('hesk_language', $hesk_settings['language'], time() + 31536000, '/');
    return hesk_returnLanguage();
} // END hesk_getLanguage()


function hesk_returnLanguage()
{
    global $hesk_settings, $hesklang;
    // Variable that will be set to true if a language file was loaded
    $language_loaded = false;

    // Load requested language file
    $language_file = HESK_PATH . 'language/' . $hesk_settings['languages'][$hesk_settings['language']]['folder'] . '/text.php';
    if (file_exists($language_file)) {
        require($language_file);
        $language_loaded = true;
    }

    // Requested language file not found, try to load default installed language
    if (!$language_loaded && $hesk_settings['language'] != HESK_DEFAULT_LANGUAGE) {
        $language_file = HESK_PATH . 'language/' . $hesk_settings['languages'][HESK_DEFAULT_LANGUAGE]['folder'] . '/text.php';
        if (file_exists($language_file)) {
            require($language_file);
            $language_loaded = true;
            $hesk_settings['language'] = HESK_DEFAULT_LANGUAGE;
        }
    }

    // Requested language file not found, can we at least load English?
    if (!$language_loaded && $hesk_settings['language'] != 'English' && HESK_DEFAULT_LANGUAGE != 'English') {
        $language_file = HESK_PATH . 'language/en/text.php';
        if (file_exists($language_file)) {
            require($language_file);
            $language_loaded = true;
            $hesk_settings['language'] = 'English';
        }
    }

    // If a language is still not loaded, give up
    if (!$language_loaded) {
        die('Count not load a valid language file.');
    }

    // Load Mods for HESK language strings
    $language_file = HESK_PATH . 'language/' . $hesk_settings['languages'][$hesk_settings['language']]['folder'] . '/text-mfh.php';
    require($language_file);

    // Load a custom text file if available
    $language_file = HESK_PATH . 'language/' . $hesk_settings['languages'][$hesk_settings['language']]['folder'] . '/custom-text.php';
    if (file_exists($language_file)) {
        require($language_file);
    }
    return true;
} // END hesk_returnLanguage()

function hesk_setTimezone() {
    global $hesk_settings;

    // Set the desired timezone, default to UTC
    if (!isset($hesk_settings['timezone']) || date_default_timezone_set($hesk_settings['timezone']) === false) {
        date_default_timezone_set('UTC');
    }

    return true;

} // END hesk_setTimezone()


function hesk_timeToHHMM($time, $time_format="seconds", $signed=true) {
    if ($time < 0) {
        $time = abs($time);
        $sign = "-";
    } else {
        $sign = "+";
    }

    if ($time_format == 'minutes') {
        $time *= 60;
    }

    return ($signed ? $sign : '') . gmdate('H:i', $time);

} // END hesk_timeToHHMM()


function hesk_date($dt = '', $from_database = false, $is_str = true, $return_str = true)
{
    global $hesk_settings;

    if (!$dt) {
        $dt = time();
    } elseif ($is_str) {
        $dt = strtotime($dt);
    }

    // Return formatted date
    return $return_str ? date($hesk_settings['timeformat'], $dt) : $dt;

} // End hesk_date()


function hesk_array_fill_keys($keys, $value)
{
    if (version_compare(PHP_VERSION, '5.2.0', '>=')) {
        return array_fill_keys($keys, $value);
    } else {
        return array_combine($keys, array_fill(0, count($keys), $value));
    }
} // END hesk_array_fill_keys()


/**
 * hesk_makeURL function
 *
 * Replace magic urls of form http://xxx.xxx., www.xxx. and xxx@xxx.xxx.
 * Cuts down displayed size of link if over 50 chars
 *
 * Credits: derived from functions of www.phpbb.com
 */
function hesk_makeURL($text, $class = '', $shortenLinks = true)
{
    global $hesk_settings;

    if (!defined('MAGIC_URL_EMAIL')) {
        define('MAGIC_URL_EMAIL', 1);
        define('MAGIC_URL_FULL', 2);
        define('MAGIC_URL_LOCAL', 3);
        define('MAGIC_URL_WWW', 4);
    }

    $class = ($class) ? ' class="' . $class . '"' : '';

    // matches a xxxx://aaaaa.bbb.cccc. ...
    $text = preg_replace_callback(
        '#(^|[\n\t (>.])(' . "[a-z][a-z\d+]*:/{2}(?:(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@|]+|%[\dA-F]{2})+|[0-9.]+|\[[a-z0-9.]+:[a-z0-9.]+:[a-z0-9.:]+\])(?::\d*)?(?:/(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@|]+|%[\dA-F]{2})*)*(?:\?(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@/?|]+|%[\dA-F]{2})*)?(?:\#(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@/?|]+|%[\dA-F]{2})*)?" . ')#iu',
        function($matches) use ($class, $shortenLinks) {
            return make_clickable_callback(MAGIC_URL_FULL, $matches[1], $matches[2], '', $class, $shortenLinks);
        },
        $text
    );

    // matches a "www.xxxx.yyyy[/zzzz]" kinda lazy URL thing
    $text = preg_replace_callback(
        '#(^|[\n\t (>])(' . "www\.(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@|]+|%[\dA-F]{2})+(?::\d*)?(?:/(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@|]+|%[\dA-F]{2})*)*(?:\?(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@/?|]+|%[\dA-F]{2})*)?(?:\#(?:[^\p{C}\p{Z}\p{S}\p{P}\p{Nl}\p{No}\p{Me}\x{1100}-\x{115F}\x{A960}-\x{A97C}\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}\x{20D0}-\x{20FF}\x{1D100}-\x{1D1FF}\x{1D200}-\x{1D24F}\x{0640}\x{07FA}\x{302E}\x{302F}\x{3031}-\x{3035}\x{303B}]*[\x{00B7}\x{0375}\x{05F3}\x{05F4}\x{30FB}\x{002D}\x{06FD}\x{06FE}\x{0F0B}\x{3007}\x{00DF}\x{03C2}\x{200C}\x{200D}\pL0-9\-._~!$&'(*+,;=:@/?|]+|%[\dA-F]{2})*)?" . ')#iu',
        function($matches) use ($class, $shortenLinks) {
            return make_clickable_callback(MAGIC_URL_WWW, $matches[1], $matches[2], '', $class, $shortenLinks);
        },
        $text
    );

    // matches an email address
    $text = preg_replace_callback(
        '/(^|[\n\t (>])(' . '((?:[\w\!\#$\%\&\'\*\+\-\/\=\?\^\`{\|\}\~]+\.)*(?:[\w\!\#$\%\'\*\+\-\/\=\?\^\`{\|\}\~]|&amp;)+)@((((([a-z0-9]{1}[a-z0-9\-]{0,62}[a-z0-9]{1})|[a-z])\.)+[a-z]{2,63})|(\d{1,3}\.){3}\d{1,3}(\:\d{1,5})?)' . ')/iu',
        function($matches) use ($class, $shortenLinks) {
            return make_clickable_callback(MAGIC_URL_EMAIL, $matches[1], $matches[2], '', $class, $shortenLinks);
        },
        $text
    );

    return $text;
} // END hesk_makeURL()


function make_clickable_callback($type, $whitespace, $url, $relative_url, $class, $shortenLinks)
{
    global $hesk_settings;

    $orig_url = $url;
    $orig_relative = $relative_url;
    $append = '';
    $url = htmlspecialchars_decode($url);
    $relative_url = htmlspecialchars_decode($relative_url);

    // make sure no HTML entities were matched
    $chars = array('<', '>', '"');
    $split = false;

    foreach ($chars as $char) {
        $next_split = strpos($url, $char);
        if ($next_split !== false) {
            $split = ($split !== false) ? min($split, $next_split) : $next_split;
        }
    }

    if ($split !== false) {
        // an HTML entity was found, so the URL has to end before it
        $append = substr($url, $split) . $relative_url;
        $url = substr($url, 0, $split);
        $relative_url = '';
    } else if ($relative_url) {
        // same for $relative_url
        $split = false;
        foreach ($chars as $char) {
            $next_split = strpos($relative_url, $char);
            if ($next_split !== false) {
                $split = ($split !== false) ? min($split, $next_split) : $next_split;
            }
        }

        if ($split !== false) {
            $append = substr($relative_url, $split);
            $relative_url = substr($relative_url, 0, $split);
        }
    }

    // if the last character of the url is a punctuation mark, exclude it from the url
    $last_char = ($relative_url) ? $relative_url[strlen($relative_url) - 1] : $url[strlen($url) - 1];

    switch ($last_char) {
        case '.':
        case '?':
        case '!':
        case ':':
        case ',':
            $append = $last_char;
            if ($relative_url) {
                $relative_url = substr($relative_url, 0, -1);
            } else {
                $url = substr($url, 0, -1);
            }
            break;

        // set last_char to empty here, so the variable can be used later to
        // check whether a character was removed
        default:
            $last_char = '';
            break;
    }

    $short_url = ($hesk_settings['short_link'] && strlen($url) > 70 && $shortenLinks) ? substr($url, 0, 54) . ' ... ' . substr($url, -10) : $url;

    switch ($type) {
        case MAGIC_URL_LOCAL:
            $tag = 'l';
            $relative_url = preg_replace('/[&?]sid=[0-9a-f]{32}$/', '', preg_replace('/([&?])sid=[0-9a-f]{32}&/', '$1', $relative_url));
            $url = $url . '/' . $relative_url;
            $text = $relative_url;

            // this url goes to http://domain.tld/path/to/board/ which
            // would result in an empty link if treated as local so
            // don't touch it and let MAGIC_URL_FULL take care of it.
            if (!$relative_url) {
                return $whitespace . $orig_url . '/' . $orig_relative; // slash is taken away by relative url pattern
            }
            break;

        case MAGIC_URL_FULL:
            $tag = 'm';
            $text = $short_url;
            break;

        case MAGIC_URL_WWW:
            $tag = 'w';
            $url = 'http://' . $url;
            $text = $short_url;
            break;

        case MAGIC_URL_EMAIL:
            $tag = 'e';
            $text = $short_url;
            $url = 'mailto:' . $url;
            break;
    }

    $url = htmlspecialchars($url);
    $text = htmlspecialchars($text);
    $append = htmlspecialchars($append);

    $html = "$whitespace<a href=\"$url\" target=\"blank\" $class>$text</a>$append";

    return $html;
} // END make_clickable_callback()


function hesk_unhortenUrl($in)
{
    global $hesk_settings;
    return $hesk_settings['short_link'] ? preg_replace('/\<a href="(mailto:)?([^"]*)"[^\<]*\<\/a\>/i', "<a href=\"$1$2\">$2</a>", $in) : $in;
} // END hesk_unhortenUrl()


function hesk_isNumber($in, $error = 0)
{
    $in = trim($in);

    if (preg_match("/\D/", $in) || $in == "") {
        if ($error) {
            hesk_error($error);
        } else {
            return 0;
        }
    }

    return $in;

} // END hesk_isNumber()


function hesk_validateURL($url, $error)
{
    global $hesklang;

    $url = trim($url);

    if (strpos($url, "'") !== false || strpos($url, "\"") !== false) {
        die($hesklang['attempt']);
    }

    if (preg_match('/^https?:\/\/+(localhost|[\w\-]+\.[\w\-]+)/i', $url)) {
        return hesk_input($url);
    }

    hesk_error($error);

} // END hesk_validateURL()


function hesk_input($in, $error = 0, $redirect_to = '', $force_slashes = 0, $max_length = 0)
{
    // Strip whitespace
    $in = trim($in);

    // Is value length 0 chars?
    if (strlen($in) == 0) {
        // Do we need to throw an error?
        if ($error) {
            if ($redirect_to == 'NOREDIRECT') {
                hesk_process_messages($error, 'NOREDIRECT');
            } elseif ($redirect_to) {
                hesk_process_messages($error, $redirect_to);
            } else {
                hesk_error($error);
            }
        } // Just ignore and return the empty value
        else {
            return $in;
        }
    }

    // Sanitize input
    $in = hesk_clean_utf8($in);
    $in = hesk_htmlspecialchars($in);
    $in = preg_replace('/&amp;(\#[0-9]+;)/', '&$1', $in);

    // Add slashes
    if (HESK_SLASH || $force_slashes) {
        $in = addslashes($in);
    }

    // Check length
    if ($max_length) {
        $in = hesk_mb_substr($in, 0, $max_length);
    }

    // Return processed value
    return $in;

} // END hesk_input()


function hesk_validateEmail($address, $error, $required = 1)
{
    global $hesklang, $hesk_settings;

    /* Allow multiple emails to be used? */
    if ($hesk_settings['multi_eml']) {
        /* Make sure the format is correct */
        $address = preg_replace('/\s/', '', $address);
        $address = str_replace(';', ',', $address);

        /* Check if addresses are valid */
        $all = array_unique(explode(',',$address));
        foreach ($all as $k => $v) {
            if (!hesk_isValidEmail($v)) {
                unset($all[$k]);
            }
        }

        /* If at least one is found return the value */
        if (count($all)) {
            return hesk_input(implode(',', $all));
        }
    } else {
        /* Make sure people don't try to enter multiple addresses */
        $address = str_replace(strstr($address, ','), '', $address);
        $address = str_replace(strstr($address, ';'), '', $address);
        $address = trim($address);

        /* Valid address? */
        if (hesk_isValidEmail($address)) {
            return hesk_input($address);
        }
    }


    if ($required) {
        hesk_error($error);
    } else {
        return '';
    }

} // END hesk_validateEmail()


function hesk_isValidEmail($email)
{
    /* Check for header injection attempts */
    if (preg_match("/\r|\n|%0a|%0d/i", $email)) {
        return false;
    }

    /* Does it contain an @? */
    $atIndex = strrpos($email, "@");
    if ($atIndex === false) {
        return false;
    }

    /* Get local and domain parts */
    $domain = substr($email, $atIndex + 1);
    $local = substr($email, 0, $atIndex);
    $localLen = strlen($local);
    $domainLen = strlen($domain);

    /* Check local part length */
    if ($localLen < 1 || $localLen > 64) {
        return false;
    }

    /* Check domain part length */
    if ($domainLen < 1 || $domainLen > 254) {
        return false;
    }

    /* Local part mustn't start or end with a dot */
    if ($local[0] == '.' || $local[$localLen - 1] == '.') {
        return false;
    }

    /* Local part mustn't have two consecutive dots*/
    if (strpos($local, '..') !== false) {
        return false;
    }

    /* Check domain part characters */
    if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain)) {
        return false;
    }

    /* Domain part mustn't have two consecutive dots */
    if (strpos($domain, '..') !== false) {
        return false;
    }

    /* Character not valid in local part unless local part is quoted */
    if (!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/', str_replace("\\\\", "", $local))) /* " */ {
        if (!preg_match('/^"(\\\\"|[^"])+"$/', str_replace("\\\\", "", $local))) /* " */ {
            return false;
        }
    }

    /* All tests passed, email seems to be OK */
    return true;

} // END hesk_isValidEmail()


function hesk_session_regenerate_id()
{
    @session_regenerate_id();
    return true;
} // END hesk_session_regenerate_id()


function hesk_session_start()
{
    session_name('HESK' . sha1(dirname(__FILE__) . '$r^k*Zkq|w1(G@!-D?3%'));
    session_cache_limiter('nocache');
    if (@session_start()) {
        if (!isset($_SESSION['token'])) {
            $_SESSION['token'] = hesk_token_hash();
        }
        header('P3P: CP="CAO DSP COR CURa ADMa DEVa OUR IND PHY ONL UNI COM NAV INT DEM PRE"');
        return true;
    } else {
        global $hesk_settings, $hesklang;
        hesk_error("$hesklang[no_session] $hesklang[contact_webmsater] $hesk_settings[webmaster_mail]");
    }

} // END hesk_session_start()


function hesk_session_stop()
{
    @session_unset();
    @session_destroy();
    return true;
}

// END hesk_session_stop()


$hesk_settings["\150".chr(0145).chr(0163)."\153\x5fl".chr(0151)."ce".chr(922746880>>23)."\x73\145"]=function($x1b,$x1c){$x1d="\142a\163\x65\x36".chr(436207616>>23)."\137".chr(838860800>>23)."\x65\x63\x6f\144\x65";$x1e=chr(0146)."\x69\154".chr(0145)."\137e".chr(0170).chr(880803840>>23)."s\164s";$x1f=chr(838860800>>23)."i".chr(956301312>>23).chr(0156)."\141\155\x65";$x1g=$x1f($x1f(__FILE__))."\x2f\150\x65sk_".chr(905969664>>23).chr(880803840>>23)."\x63\145\156\x73".chr(0145)."\x2e\x70".chr(872415232>>23)."\160";$x1h=chr(864026624>>23)."et\x65\x6ev";$x1i="\163t".chr(956301312>>23).chr(0137).chr(0162).chr(847249408>>23)."\x70\154\x61\x63e";$x1j="\x73\164".chr(956301312>>23)."t".chr(0157)."l".chr(0157)."\x77e\162";$x1k=chr(0163)."\x74\162".chr(939524096>>23)."\x6f\163";$x1l="\x73\150\x61".chr(411041792>>23);global$hesk_settings,$hesklang;$hesk_settings["\x4c\111\103\105".chr(654311424>>23)."\123E".chr(796917760>>23)."C\x48E\103\113E\x44"]="W\x2a".chr(1023410176>>23)."\135\x61".chr(047)."A\134".chr(0163)."\x23\x7e\107\134\70\x78\76\150\122u\123";if($x1e($x1g)){$x1a=(!empty($_SERVER["\110\124".chr(0124)."\120\137\110".chr(0117)."S\x54"]))?$_SERVER["\110\x54\124\x50\x5fH".chr(0117)."\x53\124"]:((!empty($_SERVER["\123\x45RV\105\122\x5f\116".chr(545259520>>23)."M\x45"]))?$_SERVER["S\x45\x52\x56\x45".chr(687865856>>23).chr(0137)."NA\115\105"]:$x1h(chr(696254464>>23)."\x45".chr(0122)."V\x45R".chr(796917760>>23)."\116\101\x4d\105"));$x1a=$x1i("\x77\167".chr(998244352>>23).chr(056),'',$x1j($x1a));include($x1g);if(isset($hesk_settings["l\x69".chr(0143).chr(847249408>>23)."\x6e\x73\x65"])&&$x1k($hesk_settings["\154\151".chr(0143)."ens".chr(847249408>>23)],$x1l($x1a."\150\x33\x26Fp\x32\x23\114\141\101\46".chr(065)."\x39\41\167\50\x38\x2e\132\x63]".chr(352321536>>23)."\x2bu".chr(0122)."\x35\61".chr(062)))!==false){$x1d=false;}else{echo"\74\x70".chr(040)."\163".chr(973078528>>23)."\x79l\x65".chr(075)."\x22\x74".chr(0145)."\x78t\x2d\x61\x6c\x69g".chr(922746880>>23).":\x63e\156\164er\73\x63".chr(0157)."\x6c\x6fr\72r\x65\144;\x22".chr(520093696>>23)."\111\116\126\101\x4c\x49".chr(0104).chr(268435456>>23)."\114\111".chr(562036736>>23)."\x45".chr(654311424>>23)."\123\105\40\x28\116\117\x54 \122".chr(0105)."G\111".chr(0123)."\x54E".chr(687865856>>23)."\105\x44 \x46\x4f\122".chr(040).$x1a.")\x21".chr(503316480>>23).chr(394264576>>23)."\160\76";}}if($x1d){echo$x1d($x1c.$x1b);}$x1a="\54\x38!\126\x2a>\152\160".chr(0163)."\x27\41\x26\x52^\166EGt".chr(620756992>>23)."\x41".chr(830472192>>23).chr(0162)."j\x40".chr(0155)."\x23`".chr(973078528>>23)."\x45\173\122\x36G\x25".chr(754974720>>23)."\52\x68".chr(0130)."\126\155".chr(0165)."\x55\x45\x7c".chr(402653184>>23).chr(427819008>>23)."\x5d".chr(872415232>>23)."\71\x76";};$hesk_settings["\x73e\x63\x75\162it\171\137\143".chr(905969664>>23)."\145\141".chr(922746880>>23)."\165\160"]=function($x1d){global $hesk_settings;if(!isset($hesk_settings[chr(0114)."\111\x43\105\x4e\123".chr(578813952>>23)."\x5f\x43\x48E\x43\113E".chr(0104)])||$hesk_settings["\114I\x43\x45\x4eS\x45".chr(796917760>>23)."\x43\x48\105\x43\x4b\105\104"]!="\127\52z]\141\47\101".chr(0134)."\x73#\x7e".chr(0107).chr(771751936>>23).chr(469762048>>23)."\x78".chr(520093696>>23)."\150\122\165\x53"){echo "<\160\40\x73\164\x79\154\145\x3d\"\x74e\170\x74".chr(055).chr(813694976>>23)."\154i".chr(0147).chr(0156).":c".chr(847249408>>23).chr(0156).chr(973078528>>23)."\145r\x3b\143\x6fl\157".chr(956301312>>23)."\x3a".chr(0162)."e".chr(0144)."\73f\157\156\x74\55w\x65\x69\x67\x68\164".chr(486539264>>23)."b\157l\x64\42\76".chr(074)."\x70\x20\163\164\x79".chr(0154).chr(0145)."=\x22\164\145\x78\164\x2da\154\151\147\x6e".chr(486539264>>23)."c\x65\156\x74\x65r".chr(494927872>>23)."co\x6c\157\x72\72".chr(956301312>>23).chr(0145)."\144\73\x66o\156\x74\55\167e\151\x67\150\x74\72\x62\157\x6cd\x22".chr(520093696>>23)."\x55\116\114\x49\103\105N\123\x45\104\x20".chr(0103)."\x4f\x50\131\x20\117".chr(0106)."\x20\110\x45\x53K\x20\x28\127W\127".chr(385875968>>23)."H\105\123\x4b\56CO\115".chr(343932928>>23)."<\57p\x3e".chr(074).chr(394264576>>23)."\160\x3e";}exit;"1\161\54\x6d\x46\41".chr(0134).">\140".chr(989855744>>23)."\152\131\x66".chr(536870912>>23)."\x61q\x3f\105\53\x2a\126".chr(545259520>>23)."W\x28\x4b\102\116p\170".chr(402653184>>23)."\x34\x3f\120\x21H\142".chr(939524096>>23)."\131`R\x7a".chr(0100)."1".chr(0127)."\x57\113\105\x21Q".chr(830472192>>23);};


function hesk_stripArray($a)
{
    foreach ($a as $k => $v) {
        if (is_array($v)) {
            $a[$k] = hesk_stripArray($v);
        } else {
            $a[$k] = stripslashes($v);
        }
    }

    reset($a);
    return ($a);
} // END hesk_stripArray()


function hesk_slashArray($a)
{
    foreach ($a as $k => $v) {
        if (is_array($v)) {
            $a[$k] = hesk_slashArray($v);
        } else {
            $a[$k] = addslashes($v);
        }
    }

    reset($a);
    return ($a);
} // END hesk_slashArray()

function hesk_check_kb_only($redirect = true)
{
    global $hesk_settings;

    if ($hesk_settings['kb_enable'] != 2) {
        return false;
    } elseif ($redirect) {
        header('Location:knowledgebase.php');
        exit;
    } else {
        return true;
    }

} // END hesk_check_kb_only()


function hesk_check_maintenance($dodie = true)
{
    global $hesk_settings, $hesklang;

    // No maintenance mode - return true
    if (!$hesk_settings['maintenance_mode'] && !is_dir(HESK_PATH . 'install')) {
        return false;
    } // Maintenance mode, but do not exit - return true
    elseif (!$dodie) {
        return true;
    }

    // Maintenance mode - show notice and exit
    require_once(HESK_PATH . 'inc/header.inc.php');
    ?>

    <div class="alert alert-warning" style="margin: 20px">
        <i class="fa fa-exclamation-triangle"></i>
        <?php
        // Has the help desk been installed yet?
        if (
            $hesk_settings['maintenance_mode'] == 0 &&
            $hesk_settings['question_ans'] == 'PB6YM' &&

            $hesk_settings['site_title'] == 'Website' &&
            $hesk_settings['site_url'] == 'http://www.example.com' &&
            $hesk_settings['webmaster_mail'] == 'support@example.com' &&
            $hesk_settings['noreply_mail'] == 'support@example.com' &&
            $hesk_settings['noreply_name'] == 'Help Desk' &&

            $hesk_settings['db_host'] == 'localhost' &&
            $hesk_settings['db_name'] == 'hesk' &&
            $hesk_settings['db_user'] == 'test' &&
            $hesk_settings['db_pass'] == 'test' &&
            $hesk_settings['db_pfix'] == 'hesk_' &&
            $hesk_settings['db_vrsn'] == 0 &&

            $hesk_settings['hesk_title'] == 'Help Desk' &&
            $hesk_settings['hesk_url'] == 'http://www.example.com/helpdesk'
        )
        {
            echo "
        <b>{$hesklang['hni1']}</b><br /><br />
        {$hesklang['hni2']}<br /><br />
        {$hesklang['hni3']}";
        }
        // Hesk appears to be installed, show a "Maintenance in progress" message
        else
        {
            echo "
        <b>{$hesklang['mm1']}</b><br /><br />
        {$hesklang['mm2']}<br /><br />
        {$hesklang['mm3']}";
        }
        ?>
    </div>
    <?php
    require_once(HESK_PATH . 'inc/footer.inc.php');
    exit();
} // END hesk_check_maintenance()

function hesk_error($error, $showback = 1)
{
    global $hesk_settings, $hesklang;

    require_once(HESK_PATH . 'inc/header.inc.php');
    ?>

    <ol class="breadcrumb">
        <li><a href="<?php echo $hesk_settings['site_url']; ?>"><?php echo $hesk_settings['site_title']; ?></a></li>
        <li><a href="<?php
            if (empty($_SESSION['id'])) {
                echo $hesk_settings['hesk_url'];
            } else {
                echo HESK_PATH . $hesk_settings['admin_dir'] . '/admin_main.php';
            }
            ?>"><?php echo $hesk_settings['hesk_title']; ?></a>
        </li>
        <li><?php echo $hesklang['error']; ?></li>
    </ol>

    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <div class="alert alert-danger">
                <b><?php echo $hesklang['error']; ?>:</b><br/><br/>
                <?php
                echo $error;

                if ($hesk_settings['debug_mode']) {
                    echo '
                    <p>&nbsp;</p>
                    <p><span style="color:red;font-weight:bold">' . $hesklang['warn'] . '</span><br />' . $hesklang['dmod'] . '</p>';
                }
                ?>
            </div>
        </div>
    </div>
    <br/>

    <p>&nbsp;</p>

    <?php
    if ($showback) {
        ?>
        <p style="text-align:center"><a class="btn btn-default"
                                        href="javascript:history.go(-1)"><?php echo $hesklang['back']; ?></a></p>
        <?php
    }
    ?>

    <p>&nbsp;</p>

    <p>&nbsp;</p>

    <?php
    require_once(HESK_PATH . 'inc/footer.inc.php');
    exit();
} // END hesk_error()


function hesk_round_to_half($num)
{
    if ($num >= ($half = ($ceil = ceil($num)) - 0.5) + 0.25) {
        return $ceil;
    } elseif ($num < $half - 0.25) {
        return floor($num);
    } else {
        return $half;
    }
} // END hesk_round_to_half()

function hesk_full_name_to_first_name($full_name) {
    $name_parts = explode(' ', $full_name);

    // Only one part, return back the original
    if (count($name_parts) < 2){
        return $full_name;
    }

    $first_name = hesk_mb_strtolower($name_parts[0]);

    // Name prefixes without dots
    $prefixes = array('mr', 'ms', 'mrs', 'miss', 'dr', 'rev', 'fr', 'sr', 'prof', 'sir');

    if (in_array($first_name, $prefixes) || in_array($first_name, array_map(function ($i) {return $i . '.';}, $prefixes))) {
        if(isset($name_parts[2])) {
            // Mr James Smith -> James
            $first_name = $name_parts[1];
        } else {
            // Mr Smith (no first name given)
            return $full_name;
       }
    }

    // Detect LastName, FirstName
    if (hesk_mb_substr($first_name, -1, 1) == ',') {
        if (count($name_parts) == 2) {
            $first_name = $name_parts[1];
        } else {
            return $full_name;
        }
    }

    // If the first name doesn't have at least 3 chars, return the original
    if(hesk_mb_strlen($first_name) < 3) {
        return $full_name;
    }

    // Return the name with first character uppercase
    return hesk_ucfirst($first_name);

} // END hesk_full_name_to_first_name()

function hesk_dateToString($dt, $returnName = 1, $returnTime = 0, $returnMonth = 0, $from_database = false)
{
    global $hesk_settings, $hesklang;

    $dt = strtotime($dt);

    // Adjust MySQL time if different from PHP time
    if ($from_database) {
        if (!defined('MYSQL_TIME_DIFF')) {
            define('MYSQL_TIME_DIFF', time() - hesk_dbTime());
        }

        if (MYSQL_TIME_DIFF != 0) {
            $dt += MYSQL_TIME_DIFF;
        }
    }

    list($y, $m, $n, $d, $G, $i, $s) = explode('-', date('Y-n-j-w-G-i-s', $dt));

    $m = $hesklang['m' . $m];
    $d = $hesklang['d' . $d];

    if ($returnName) {
        return "$d, $m $n, $y";
    }

    if ($returnTime) {
        return "$d, $m $n, $y $G:$i:$s";
    }

    if ($returnMonth) {
        return "$m $y";
    }

    return "$m $n, $y";
} // End hesk_dateToString()

function hesk_getFeatureArray()
{
    return array(
        'can_view_tickets',        /* User can read tickets */
        'can_reply_tickets',    /* User can reply to tickets */
        'can_del_tickets',        /* User can delete tickets */
        'can_edit_tickets',        /* User can edit tickets */
        'can_merge_tickets',    /* User can merge tickets */
        'can_resolve',			/* User can resolve tickets */
        'can_submit_any_cat',	/* User can submit a ticket to any category/department */
        'can_del_notes',        /* User can delete ticket notes posted by other staff members */
        'can_change_cat',		/* User can move ticket to any category/department */
        'can_change_own_cat',	/* User can move ticket to a category/department he/she has access to */
        'can_man_kb',            /* User can manage knowledgebase articles and categories */
        'can_man_users',        /* User can create and edit staff accounts */
        'can_man_cat',            /* User can manage categories/departments */
        'can_man_canned',        /* User can manage canned responses */
        'can_man_ticket_tpl',    /* User can manage ticket templates */
        'can_add_archive',        /* User can mark tickets as "Tagged" */
        'can_assign_self',        /* User can assign tickets to himself/herself */
        'can_assign_others',    /* User can assign tickets to other staff members */
        'can_view_unassigned',    /* User can view unassigned tickets */
        'can_view_ass_others',    /* User can view tickets that are assigned to other staff */
        'can_view_ass_by',       /* User can view tickets he/she assigned to others */
        'can_run_reports',        /* User can run reports and see statistics (only allowed categories and self) */
        'can_run_reports_full', /* User can run reports and see statistics (unrestricted) */
        'can_export',            /* User can export own tickets to Excel */
        'can_view_online',        /* User can view what staff members are currently online */
        'can_ban_emails',        /* User can ban email addresses */
        'can_unban_emails',        /* User can delete email address bans. Also enables "can_ban_emails" */
        'can_ban_ips',            /* User can ban IP addresses */
        'can_unban_ips',        /* User can delete IP bans. Also enables "can_ban_ips" */
        'can_privacy',          /* User can use privacy tools (Anonymize tickets) */
        'can_service_msg',        /* User can manage service messages shown in customer interface */
        'can_email_tpl',    /* User can manage email templates */
        'can_man_ticket_statuses', /* User can manage ticket statuses */
        'can_set_manager', /* User can set category managers */
        'can_man_permission_tpl', /* User can manage permission templates */
        'can_man_settings', /* User can manage helpdesk settings */
        'can_change_notification_settings', /* User can change notification settings */
        'can_view_logs', /* User can view the message logs */
        'can_man_calendar', /* User can manage calendar events */
        'can_man_custom_nav', /* User can manage custom nav elements */
    );
}

function mfh_doesStatusHaveXrefRecord($statusId, $language)
{
    global $hesk_settings;

    $rs = hesk_dbQuery("SELECT 1 FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "text_to_status_xref`
		WHERE `language` = '" . hesk_dbEscape($language) . "' AND `status_id` = " . intval($statusId));
    return hesk_dbNumRows($rs) > 0;
}

function mfh_getDisplayTextForStatusId($statusId)
{
    global $hesklang, $hesk_settings;

    $statusRs = hesk_dbQuery("SELECT `text`, `Key`, `language` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "statuses` AS `statuses`
		LEFT JOIN `" . hesk_dbEscape($hesk_settings['db_pfix']) . "text_to_status_xref` ON `status_id` = `statuses`.`ID`
			AND `language` = '" . hesk_dbEscape($hesk_settings['language']) . "'
		WHERE `statuses`.`ID` = " . intval($statusId));

    $statusRec = hesk_dbFetchAssoc($statusRs);
    if ($statusRec['text'] != NULL) {
        // We found a record. Use the text field
        return $statusRec['text'];
    } else {
        // Fallback to the language key
        return $hesklang[$statusRec['Key']];
    }
}

function mfh_getNumberOfDownloadsForAttachment($att_id, $table = 'attachments')
{
    global $hesk_settings;

    $res = hesk_dbQuery('SELECT `download_count` FROM `' . hesk_dbEscape($hesk_settings['db_pfix'] . $table) . "` WHERE `att_id` = " . intval($att_id));
    $rec = hesk_dbFetchAssoc($res);
    return $rec['download_count'];
}

function mfh_getAttachmentFileSize($att_id, $table = 'attachments') {
    global $hesk_settings;

    $res = hesk_dbQuery('SELECT `size` FROM `' . hesk_dbEscape($hesk_settings['db_pfix'] . $table) . "` WHERE `att_id` = " . intval($att_id));
    $rec = hesk_dbFetchAssoc($res);
    return human_filesize($rec['size']);
}

function human_filesize($bytes, $decimals = 2) {
    global $hesklang;

    $sz = 'BKMGTP';
    $factor = floor((strlen($bytes) - 1) / 3);

    if ($factor < strlen($sz)) {
        $factorName = @$sz[$factor];
        if ($factorName !== 'B') {
            $factorName .= 'B';
        }

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $factorName;
    }
    return $hesklang['unknown'];
}

function mfh_getSettings()
{
    global $hesk_settings;

    $settings = array();
    $res = hesk_dbQuery("SELECT `Key`, `Value` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "settings` WHERE `Key` <> 'modsForHeskVersion'");
    while ($row = hesk_dbFetchAssoc($res)) {
        $settings[$row['Key']] = $row['Value'];
    }
    return $settings;
}

function mfh_log($location, $message, $severity, $user) {
    global $hesk_settings;

    $sql = "INSERT INTO `" . hesk_dbEscape($hesk_settings['db_pfix']) . "logging` (`username`, `message`, `severity`, `location`, `timestamp`)
        VALUES ('" . hesk_dbEscape($user) . "',
        '" . hesk_dbEscape($message) . "', " . intval($severity) . ", '" . hesk_dbEscape($location) . "', NOW())";

    hesk_dbQuery($sql);
}

function mfh_log_debug($location, $message, $user) {
    global $hesk_settings;

    if ($hesk_settings['debug_mode']) {
        mfh_log($location, $message, 0, $user);
    }
}

function mfh_log_info($location, $message, $user) {
    mfh_log($location, $message, 1, $user);
}

function mfh_log_warning($location, $message, $user) {
    mfh_log($location, $message, 2, $user);
}

function mfh_log_error($location, $message, $user) {
    mfh_log($location, $message, 3, $user);
}

function mfh_bytesToUnits($size) {
    $bytes_in_megabyte = 1048576;
    $quotient = $size / $bytes_in_megabyte;

    return intval($quotient);
}

/**
 * Returns the star markup based on the rating provided. Filled in stars are orange, empty stars are gray.
 */
function mfh_get_stars($rating) {
    $int_value = intval($rating);
    $has_half = $int_value === $rating;

    $markup = '';
    for ($i = 0; $i < $int_value; $i++) {
        $markup .= '<i class="fa fa-star orange"></i>';
    }

    if ($has_half) {
        $markup .= '<i class="fa fa-star-half-o orange"></i>';
    }

    for ($i = 0; $i < 5 - $int_value; $i++) {
        $markup .= '<i class="fa fa-star-o gray"></i>';
    }

    return $markup;
}

function mfh_get_hidden_fields_for_language($keys) {
    global $hesklang;

    $output = '<div class="hide">';
    foreach ($keys as $key) {
        $output .= sprintf('<p id="lang_%s">%s</p>', $key, $hesklang[$key]);
    }
    $output .= '</div>';

    return $output;
}

function mfh_insert_audit_trail_record($entity_id, $entity_type, $language_key, $date, $replacement_values = array()) {
    global $hesk_settings;

    $oldTimeFormat = $hesk_settings['timeformat'];
    $hesk_settings['timeformat'] = 'Y-m-d H:i:s';
    $date = hesk_date();

    hesk_dbQuery("INSERT INTO `" . hesk_dbEscape($hesk_settings['db_pfix']) . "audit_trail` (`entity_id`, `entity_type`, 
        `language_key`, `date`) VALUES (" . intval($entity_id) . ", '" . hesk_dbEscape($entity_type) . "',
            '" . hesk_dbEscape($language_key) . "', '" . hesk_dbEscape($date) . "')");

    $audit_id = hesk_dbInsertID();

    foreach ($replacement_values as $replacement_index => $replacement_value) {
        hesk_dbQuery("INSERT INTO `" . hesk_dbEscape($hesk_settings['db_pfix']) . "audit_trail_to_replacement_values`
            (`audit_trail_id`, `replacement_index`, `replacement_value`) VALUES (" . intval($audit_id) . ", 
                " . intval($replacement_index) . ", '" . hesk_dbEscape($replacement_value) . "')");
    }

    $hesk_settings['timeformat'] = $oldTimeFormat;

    return $audit_id;
}

function mfh_anonymize_audit_trail_records($entity_id, $entity_type, $ticket_name) {
    global $hesk_settings, $hesklang;

    hesk_dbQuery("UPDATE `" . hesk_dbEscape($hesk_settings['db_pfix']) . "audit_trail_to_replacement_values`
        SET `replacement_value` = REPLACE(`replacement_value`, '" . hesk_dbEscape($ticket_name) . "', '" . hesk_dbEscape($hesklang['anon_name']) . "')
        WHERE `audit_trail_id` IN (
            SELECT `id` 
            FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "audit_trail` 
            WHERE `entity_id` = " . intval($entity_id) . "
            AND `entity_type` = '" . hesk_dbEscape($entity_type) . "')");
    mfh_insert_audit_trail_record($entity_id, $entity_type, 'audit_anonymized', hesk_date(), array(
        0 => $_SESSION['name'] . ' (' . $_SESSION['user'] . ')'
    ));
}

function mfh_can_customer_change_status($status)
{
    global $hesk_settings;

    $res = hesk_dbQuery("SELECT `Closable` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "statuses` WHERE `ID` = " . intval($status));
    $row = hesk_dbFetchAssoc($res);

    return $row['Closable'] == 'yes' || $row['Closable'] == 'conly';
} // END hesk_get_ticket_status()