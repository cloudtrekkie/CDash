<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

include_once dirname(__DIR__) . '/config/config.php';
require_once 'include/common.php';
require_once 'include/login_functions.php';
require_once 'include/version.php';
redirect_to_https();

require_once 'include/cdashmail.php';
require_once 'include/pdo.php';

use CDash\Config;
use CDash\Model\User;

/** Authentication function */
function register(&$registration_error)
{
    $user = new User();

    if (isset($_GET['key'])) {
        if ($user->Register($_GET['key'])) {
            return true;
        } else {
            $registration_error = 'The key is invalid.';
            return false;
        }
    } elseif (isset($_POST['sent'])) {
        // arrive from register form

        $url = $_POST['url'];
        if ($url != 'catchbot') {
            $registration_error = 'Bots are not allowed to obtain CDash accounts!';
            return false;
        }
        $email = $_POST['email'];
        $passwd = $_POST['passwd'];
        $passwd2 = $_POST['passwd2'];
        if (!($passwd == $passwd2)) {
            $registration_error = 'Passwords do not match!';
            return false;
        }

        $config = Config::getInstance();

        $complexity = getPasswordComplexity($passwd);
        if ($complexity < $config->get('CDASH_MINIMUM_PASSWORD_COMPLEXITY')) {
            if ($config->get('CDASH_PASSWORD_COMPLEXITY_COUNT') > 1) {
                $registration_error = "Your password must contain at least {$config->get('CDASH_PASSWORD_COMPLEXITY_COUNT')} characters from {$config->get('CDASH_MINIMUM_PASSWORD_COMPLEXITY')} of the following types: uppercase, lowercase, numbers, and symbols.";
            } else {
                $registration_error = "Your password must contain at least {$config->get('CDASH_MINIMUM_PASSWORD_COMPLEXITY')} of the following: uppercase, lowercase, numbers, and symbols.";
            }
            return false;
        }

        if (strlen($passwd) < $config->get('CDASH_MINIMUM_PASSWORD_LENGTH')) {
            $registration_error = "Your password must be at least {$config->get('CDASH_MINIMUM_PASSWORD_LENGTH')} characters.";
            return false;
        }

        $fname = $_POST['fname'];
        $lname = $_POST['lname'];
        $institution = $_POST['institution'];
        if ($email && $passwd && $passwd2 && $fname && $lname && $institution) {
            $user->Email = $email;
            if ($user->Exists()) {
                $registration_error = "$email is already registered.";
                return false;
            }

            if ($user->TempExists()) {
                $registration_error = "$email is already registered. Check your email if you haven't received the link to activate yet.";
                return false;
            }

            $passwordHash = User::PasswordHash($passwd);
            if ($passwordHash === false) {
                $registration_error = 'Failed to hash password.';
                return false;
            }
            $user->Email = $email;
            $user->Password = $passwordHash;
            $user->FirstName = $fname;
            $user->LastName = $lname;
            $user->Institution = $institution;


            if ($config->get('CDASH_REGISTRATION_EMAIL_VERIFY')) {
                $key = generate_password(40);
                $date = date(FMT_DATETIME);
                if ($user->SaveTemp($key, $date)) {
                    // Send the registration email.
                    $currentURI = get_server_URI();

                    $emailtitle = 'Welcome to CDash!';
                    $emailbody = 'Hello ' . $fname . ",\n\n";
                    $emailbody .= "Welcome to CDash! In order to validate your registration please follow this link: \n";
                    $emailbody .= $currentURI . '/register.php?key=' . $key . "\n";

                    $serverName = $config->get('CDASH_SERVER_NAME');
                    if (strlen($serverName) == 0) {
                        $serverName = $_SERVER['SERVER_NAME'];
                    }
                    $emailbody .= "\n-CDash on " . $serverName . "\n";

                    if (cdashmail("$email", $emailtitle, $emailbody)) {
                        add_log('email sent to: ' . $email, 'Registration');
                    } else {
                        add_log('cannot send email to: ' . $email, 'Registration', LOG_ERR);
                    }

                    $registration_error = "A confirmation email has been sent. Check your email (including your spam folder) to confirm your registration!\n";
                    $registration_error .= 'You need to activate your account within 24 hours.';
                    return false;
                } else {
                    $registration_error = 'Error registering user';
                    return false;
                }
            } else {
                return $user->Save();
            }
        } else {
            $registration_error = 'Please fill in all of the required fields';
            return false;
        }
    }
    return false;
}

/** Login Form function */
function RegisterForm($regerror)
{
    $config = Config::getInstance();

    if ($config->get('CDASH_NO_REGISTRATION') == 1) {
        die("You cannot access this page. Contact your administrator if you think that's an error.");
    }

    $xml = begin_XML_for_XSLT();
    $xml .= '<title>CDash - Registration</title>';
    $xml .= '<error>' . $regerror . '</error>';
    if (isset($_GET['firstname'])) {
        $xml .= '<firstname>' . $_GET['firstname'] . '</firstname>';
    } else {
        $xml .= '<firstname></firstname>';
    }
    if (isset($_GET['lastname'])) {
        $xml .= '<lastname>' . $_GET['lastname'] . '</lastname>';
    } else {
        $xml .= '<lastname></lastname>';
    }
    if (isset($_GET['email'])) {
        $xml .= '<email>' . $_GET['email'] . '</email>';
    } else {
        $xml .= '<email></email>';
    }
    $xml .= '</cdash>';

    generate_XSLT($xml, 'register');
}

// --------------------------------------------------------------------------------------
// main
// --------------------------------------------------------------------------------------
$registration_error = '';
if (!register($registration_error)) {
    // Registration failed.
    // Re-display register form with error message.
    RegisterForm($registration_error);
} else {
    return \redirect('user.php?note=register');
}
