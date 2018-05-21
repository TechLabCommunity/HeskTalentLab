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

define('IN_SCRIPT', 1);
define('HESK_PATH', '../');
define('PAGE_TITLE', 'LOGIN');

/* Get all the required files and functions */
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
require(HESK_PATH . 'inc/admin_functions.inc.php');
hesk_load_database_functions();

hesk_session_start();
hesk_dbConnect();
$modsForHesk_settings = mfh_getSettings();

/* What should we do? */
$action = hesk_REQUEST('a');

switch ($action) {
    case 'do_login':
        do_login();
        break;
    case 'login':
        print_login();
        break;
    case 'logout':
        logout();
        break;
    default:
        hesk_autoLogin();
        print_login();
}
exit();

/*** START FUNCTIONS ***/
function do_login()
{
    global $hesk_settings, $hesklang, $modsForHesk_settings;

    $hesk_error_buffer = array();

    $user = hesk_input(hesk_POST('user'));
    if (empty($user)) {
        $myerror = $hesk_settings['list_users'] ? $hesklang['select_username'] : $hesklang['enter_username'];
        $hesk_error_buffer['user'] = $myerror;
    }
    define('HESK_USER', $user);

    $pass = hesk_input(hesk_POST('pass'));
    if (empty($pass)) {
        $hesk_error_buffer['pass'] = $hesklang['enter_pass'];
    }

    if ($hesk_settings['secimg_use'] == 2 && !isset($_SESSION['img_a_verified'])) {
        // Using ReCaptcha?
        if ($hesk_settings['recaptcha_use']) {
            require(HESK_PATH . 'inc/recaptcha/recaptchalib_v2.php');

            $resp = null;
            $reCaptcha = new ReCaptcha($hesk_settings['recaptcha_private_key']);

            // Was there a reCAPTCHA response?
            if (isset($_POST["g-recaptcha-response"])) {
                $resp = $reCaptcha->verifyResponse(hesk_getClientIP(), hesk_POST("g-recaptcha-response"));
            }

            if ($resp != null && $resp->success) {
                $_SESSION['img_a_verified'] = true;
            } else {
                $hesk_error_buffer['mysecnum'] = $hesklang['recaptcha_error'];
            }
        } // Using PHP generated image
        else {
            $mysecnum = intval(hesk_POST('mysecnum', 0));

            if (empty($mysecnum)) {
                $hesk_error_buffer['mysecnum'] = $hesklang['sec_miss'];
            } else {
                require(HESK_PATH . 'inc/secimg.inc.php');
                $sc = new PJ_SecurityImage($hesk_settings['secimg_sum']);
                if (isset($_SESSION['checksum']) && $sc->checkCode($mysecnum, $_SESSION['checksum'])) {
                    $_SESSION['img_a_verified'] = true;
                } else {
                    $hesk_error_buffer['mysecnum'] = $hesklang['sec_wrng'];
                }
            }
        }
    }

    /* Any missing fields? */
    if (count($hesk_error_buffer) != 0) {
        $_SESSION['a_iserror'] = array_keys($hesk_error_buffer);

        $tmp = '';
        foreach ($hesk_error_buffer as $error) {
            $tmp .= "<li>$error</li>\n";
        }
        $hesk_error_buffer = $tmp;

        $hesk_error_buffer = $hesklang['pcer'] . '<br /><br /><ul>' . $hesk_error_buffer . '</ul>';
        hesk_process_messages($hesk_error_buffer, 'NOREDIRECT');
        print_login();
        exit();
    } elseif (isset($_SESSION['img_a_verified'])) {
        unset($_SESSION['img_a_verified']);
    }

    /* User entered all required info, now lets limit brute force attempts */
    hesk_limitBfAttempts();

    $result = hesk_dbQuery("SELECT * FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "users` WHERE `user` = '" . hesk_dbEscape($user) . "' LIMIT 1");
    if (hesk_dbNumRows($result) != 1) {
        hesk_session_stop();
        $_SESSION['a_iserror'] = array('user', 'pass');
        hesk_process_messages($hesklang['wrong_user'], 'NOREDIRECT');
        print_login();
        exit();
    }

    $res = hesk_dbFetchAssoc($result);
    foreach ($res as $k => $v) {
        $_SESSION[$k] = $v;
    }

    /* Check password */
    if (hesk_Pass2Hash($pass) != $_SESSION['pass']) {
        hesk_session_stop();
        $_SESSION['a_iserror'] = array('pass');
        hesk_process_messages($hesklang['wrong_pass'], 'NOREDIRECT');
        print_login();
        exit();
    }

    $pass_enc = hesk_Pass2Hash($_SESSION['pass'].hesk_mb_strtolower($user).$_SESSION['pass']);

    /* Check if default password */
    if ($_SESSION['pass'] == '499d74967b28a841c98bb4baaabaad699ff3c079') {
        hesk_process_messages($hesklang['chdp'], 'NOREDIRECT', 'NOTICE');
    }

    // Set a tag that will be used to expire sessions after username or password change
    $_SESSION['session_verify'] = hesk_activeSessionCreateTag($user, $_SESSION['pass']);

    // We don't need the password hash anymore
    unset($_SESSION['pass']);


    /* Login successful, clean brute force attempts */
    hesk_cleanBfAttempts();

    /* Make sure our user is active */
    if (!$_SESSION['active']) {
        hesk_session_stop();
        $_SESSION['a_iserror'] = array('active');
        hesk_process_messages($hesklang['inactive_user'], 'NOREDIRECT');
        print_login();
        exit();
    }

    /* Regenerate session ID (security) */
    hesk_session_regenerate_id();

    /* Remember username? */
    if ($hesk_settings['autologin'] && hesk_POST('remember_user') == 'AUTOLOGIN') {
        hesk_setcookie('hesk_username', "$user", strtotime('+1 year'));
        hesk_setcookie('hesk_p', "$pass_enc", strtotime('+1 year'));
    } elseif (hesk_POST('remember_user') == 'JUSTUSER') {
        hesk_setcookie('hesk_username', "$user", strtotime('+1 year'));
        hesk_setcookie('hesk_p', '');
    } else {
        // Expire cookie if set otherwise
        hesk_setcookie('hesk_username', '');
        hesk_setcookie('hesk_p', '');
    }

    /* Close any old tickets here so Cron jobs aren't necessary */
    if ($hesk_settings['autoclose']) {
        $dt = date('Y-m-d H:i:s', time() - $hesk_settings['autoclose'] * 86400);


        $closedStatusRs = hesk_dbQuery('SELECT `ID`, `Closable` FROM `' . hesk_dbEscape($hesk_settings['db_pfix']) . 'statuses` WHERE `IsDefaultStaffReplyStatus` = 1');
        $closedStatus = hesk_dbFetchAssoc($closedStatusRs);
        // Are we allowed to close tickets in this status?
        if ($closedStatus['Closable'] == 'yes' || $closedStatus['Closable'] == 'sonly') {

            $result = hesk_dbQuery("SELECT * FROM `" . $hesk_settings['db_pfix'] . "tickets` WHERE `status` = " . $closedStatus['ID'] . " AND `lastchange` <= '" . hesk_dbEscape($dt) . "' ");
            if (hesk_dbNumRows($result) > 0) {
                global $ticket;

                // Load required functions?
                if (!function_exists('hesk_notifyCustomer')) {
                    require(HESK_PATH . 'inc/email_functions.inc.php');
                }

                while ($ticket = hesk_dbFetchAssoc($result)) {
                    $ticket['dt'] = hesk_date($ticket['dt'], true);
                    $ticket['lastchange'] = hesk_date($ticket['lastchange'], true);
                    $ticket = hesk_ticketToPlain($ticket, 1, 0);
                    mfh_insert_audit_trail_record($ticket['id'], 'TICKET', 'audit_automatically_closed', hesk_date(), array());

                    // Notify customer of closed ticket?
                    if ($hesk_settings['notify_closed']) {
                        // Get list of tickets
                        hesk_notifyCustomer($modsForHesk_settings, 'ticket_closed');
                    }
                }
            }

            // Update ticket statuses and history in database if we're allowed to do so
            $defaultCloseRs = hesk_dbQuery('SELECT `ID` FROM `' . hesk_dbEscape($hesk_settings['db_pfix']) . 'statuses` WHERE `IsAutocloseOption` = 1');
            $defaultCloseStatus = hesk_dbFetchAssoc($defaultCloseRs);
            hesk_dbQuery("UPDATE `" . $hesk_settings['db_pfix'] . "tickets` SET `status`=" . intval($defaultCloseStatus['ID']) . ", `closedat`=NOW(), `closedby`='-1' WHERE `status` = " . $closedStatus['ID'] . " AND `lastchange` <= '" . hesk_dbEscape($dt) . "' ");
        }
    }

    /* Redirect to the destination page */
    header('Location: ' . hesk_verifyGoto());
    exit();
} // End do_login()


function print_login()
{
	global $hesk_settings, $hesklang, $modsForHesk_settings;

    // Tell header to load reCaptcha API if needed
    if ($hesk_settings['recaptcha_use'])
    {
        define('RECAPTCHA',1);
    }

    $hesk_settings['tmp_title'] = $hesk_settings['hesk_title'] . ' - ' .$hesklang['admin_login'];
	require_once(HESK_PATH . 'inc/headerAdmin.inc.php');

	if ( hesk_isREQUEST('notice') )
	{
    	hesk_process_messages($hesklang['session_expired'],'NOREDIRECT');
	}

    if (!isset($_SESSION['a_iserror']))
    {
    	$_SESSION['a_iserror'] = array();
    }

	?>
<div class="login-box">
    <div class="login-box-container">
        <div class="login-box-background"></div>
        <div class="login-box-body">
            <div class="loginError">
                <?php
                /* This will handle error, success and notice messages */
                hesk_handle_messages();

                // Service messages
                $service_messages = mfh_get_service_messages('STAFF_LOGIN');
                foreach ($service_messages as $sm) {
                    hesk_service_message($sm);
                }
                ?>
            </div>
            <div class="login-logo">
                <?php if ($modsForHesk_settings['login_box_header'] == 'image'): ?>
                    <img src="<?php echo HESK_PATH . $hesk_settings['cache_dir'] . '/lbh_' . $modsForHesk_settings['login_box_header_image']; ?>"
                         style="height: 75px">
                <?php else:
                    echo $hesk_settings['hesk_title'];
                endif; ?>
            </div>
            <h4 class="login-box-msg">
                <?php echo $hesklang['staff_login_title']; ?>
            </h4>
            <form class="form-horizontal" role="form" action="index.php" method="post" name="form1" id="form1">
                <?php
                $has_error = '';
                if (in_array('pass',$_SESSION['a_iserror'])) {
                    $has_error = 'has-error';
                }
                ?>
                <div class="form-group <?php echo $has_error; ?>">
                    <label for="user" class="col-sm-4 control-label">
                        <?php echo $hesklang['username']; ?>
                    </label>
                    <div class="col-sm-8">
                        <?php
                        if (defined('HESK_USER')) {
                            $savedUser = HESK_USER;
                        } else {
                            $savedUser = hesk_htmlspecialchars(hesk_COOKIE('hesk_username'));
                        }

                        $is_1 = '';
                        $is_2 = '';
                        $is_3 = '';

                        $remember_user = hesk_POST('remember_user');

                        if ($hesk_settings['autologin'] && (isset($_COOKIE['hesk_p']) || $remember_user == 'AUTOLOGIN')) {
                            $is_1 = 'checked';
                        } elseif (isset($_COOKIE['hesk_username']) || $remember_user == 'JUSTUSER') {
                            $is_2 = 'checked';
                        } else {
                            $is_3 = 'checked';
                        }

                        if ($hesk_settings['list_users']) :
                            $res = hesk_dbQuery("SELECT `user` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "users` WHERE `active` = '1' ORDER BY `user` ASC");
                            ?>
                            <select class="form-control" name="user">
                                <?php
                                while ($row = hesk_dbFetchAssoc($res)):
                                    $sel = (hesk_mb_strtolower($savedUser) == hesk_mb_strtolower($row['user'])) ? 'selected="selected"' : '';
                                    ?>
                                    <option value="<?php echo $row['user']; ?>" <?php echo $sel; ?>>
                                        <?php echo $row['user']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        <?php else: ?>
                            <input class="form-control" type="text" name="user" size="35"
                                   placeholder="<?php echo htmlspecialchars($hesklang['username']); ?>"
                                   value="<?php echo $savedUser; ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                $has_error = '';
                if (in_array('pass',$_SESSION['a_iserror'])) {
                    $has_error = 'has-error';
                }
                ?>
                <div class="form-group <?php echo $has_error; ?>">
                    <label for="pass" class="col-sm-4 control-label">
                        <?php echo $hesklang['pass']; ?>
                    </label>
                    <div class="col-sm-8">
                        <input type="password" class="form-control" id="pass" name="pass" size="35" placeholder="<?php echo htmlspecialchars($hesklang['pass']); ?>">
                    </div>
                </div>
                <?php
                if ($hesk_settings['secimg_use'] == 2 && $hesk_settings['recaptcha_use'] != 1)
                {

                    // SPAM prevention verified for this session
                    if (isset($_SESSION['img_a_verified']))
                    {
                        echo '<img src="'.HESK_PATH.'img/success.png" width="16" height="16" border="0" alt="" style="vertical-align:text-bottom" /> '.$hesklang['vrfy'];
                    }
                    // Use reCaptcha API v2?
                    elseif ($hesk_settings['recaptcha_use'] == 2)
                    {
                    ?>
                        <div class="form-group">
                            <div class="col-md-8 col-md-offset-4">
                                <div class="g-recaptcha" data-sitekey="<?php echo $hesk_settings['recaptcha_public_key']; ?>"></div>
                            </div>
                        </div>
                        <?php
                    }
                    // At least use some basic PHP generated image (better than nothing)
                    else
                    {
                        echo '<div class="form-group"><div class="col-md-8 col-md-offset-4">';
                        $cls = in_array('mysecnum',$_SESSION['a_iserror']) ? ' class="isError" ' : '';

                        echo $hesklang['sec_enter'].'<br><br><img src="'.HESK_PATH.'print_sec_img.php?'.rand(10000,99999).'" width="150" height="40" alt="'.$hesklang['sec_img'].'" title="'.$hesklang['sec_img'].'" border="1" name="secimg" style="vertical-align:text-bottom"> '.
                            '<a href="javascript:void(0)" onclick="javascript:document.form1.secimg.src=\''.HESK_PATH.'print_sec_img.php?\'+ ( Math.floor((90000)*Math.random()) + 10000);"><img src="'.HESK_PATH.'img/reload.png" height="24" width="24" alt="'.$hesklang['reload'].'" title="'.$hesklang['reload'].'" border="0" style="vertical-align:text-bottom"></a>'.
                            '<br><br><input type="text" name="mysecnum" size="20" maxlength="5" '.$cls.'>';
                        echo '</div></div>';
                    }
                } // End if $hesk_settings['secimg_use'] == 2

                if ($hesk_settings['autologin'])
                {
                    ?>
                    <div class="form-group">
                        <div class="col-md-offset-4 col-md-8">
                            <div class="radio">
                                <label><input type="radio" name="remember_user" value="AUTOLOGIN" <?php echo $is_1; ?>> <?php echo $hesklang['autologin']; ?></label>
                            </div>
                            <div class="radio">
                                <label><input type="radio" name="remember_user" value="JUSTUSER" <?php echo $is_2; ?>> <?php echo $hesklang['just_user']; ?></label>
                            </div>
                            <div class="radio">
                                <label><input type="radio" name="remember_user" value="NOTHANKS" <?php echo $is_3; ?>> <?php echo $hesklang['nothx']; ?></label>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                else
                {
                    ?>
                    <div class="form-group">
                        <div class="col-md-offset-4 col-md-8">
                            <div class="checkbox">
                                <label><input type="checkbox" name="remember_user" value="JUSTUSER" <?php echo $is_2; ?> /> <?php echo $hesklang['remember_user']; ?></label>
                            </div>
                        </div>
                    </div>
                    <?php
                } // End if $hesk_settings['autologin']
                ?>
                <div class="form-group">
                    <div class="col-md-offset-4 col-md-8">
                        <input type="submit" value="<?php echo $hesklang['click_login']; ?>" class="btn btn-default" id="recaptcha-submit">
                        <input type="hidden" name="a" value="do_login">
                        <?php
                        if ( hesk_isREQUEST('goto') && $url=hesk_REQUEST('goto') )
                        {
                            echo '<input type="hidden" name="goto" value="'.$url.'">';
                        }

                        // Do we allow staff password reset?
                        if ($hesk_settings['reset_pass'])
                        {
                            echo '<br><br><a href="password.php" class="smaller">'.$hesklang['fpass'].'</a>';
                        }
                        ?>
                    </div>
                </div>

                <?php
                // Use Invisible reCAPTCHA?
                if ($hesk_settings['secimg_use'] == 2 && $hesk_settings['recaptcha_use'] == 1 && ! isset($_SESSION['img_a_verified'])) {
                ?>
                <div class="g-recaptcha" data-sitekey="<?php echo $hesk_settings['recaptcha_public_key']; ?>" data-bind="recaptcha-submit" data-callback="recaptcha_submitForm"></div>
                <?php
                }
                ?>
            </form>
            <a class="btn btn-default" href="<?php echo $hesk_settings['hesk_url']; ?>">
                <i class="fa fa-chevron-left"></i> <?php echo $hesklang['back']; ?>
            </a>
        </div>
    </div>
</div>
<?php
	hesk_cleanSessionVars('a_iserror');

    exit();
} // End print_login()


function logout()
{
    global $hesk_settings, $hesklang;

    if (!hesk_token_check('GET', 0)) {
        print_login();
        exit();
    }

    /* Delete from Who's online database */
    if ($hesk_settings['online']) {
        require(HESK_PATH . 'inc/users_online.inc.php');
        hesk_setOffline($_SESSION['id']);
    }
    /* Destroy session and cookies */
    hesk_session_stop();

    /* If we're using the security image for admin login start a new session */
    if ($hesk_settings['secimg_use'] == 2) {
        hesk_session_start();
    }

    /* Show success message and reset the cookie */
    hesk_process_messages($hesklang['logout_success'], 'NOREDIRECT', 'SUCCESS');
    hesk_setcookie('hesk_p', '');

    /* Print the login form */
    print_login();
    exit();
} // End logout()

?>
