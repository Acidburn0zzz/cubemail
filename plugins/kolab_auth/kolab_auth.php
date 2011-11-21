<?php

/**
 * Kolab Authentication (based on ldap_authentication plugin)
 *
 * Authenticates on LDAP server, finds canonized authentication ID for IMAP
 * and for new users creates identity based on LDAP information.
 *
 * Supports impersonate feature (login as another user). To use this feature
 * imap_auth_type/smtp_auth_type must be set to DIGEST-MD5 or PLAIN.
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_auth extends rcube_plugin
{
    private $ldap;
    private $data = array();

    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->add_hook('authenticate', array($this, 'authenticate'));
        $this->add_hook('user_create', array($this, 'user_create'));

        // Hooks related to "Login As" feature
        $this->add_hook('template_object_loginform', array($this, 'login_form'));
        $this->add_hook('imap_connect', array($this, 'imap_connect'));
        $this->add_hook('managesieve_connect', array($this, 'imap_connect'));
        $this->add_hook('smtp_connect', array($this, 'smtp_connect'));

        $this->add_hook('write_log', array($this, 'write_log'));

        if ($rcmail->config->get('kolab_auth_auditlog', false)) {
            $rcmail->config->set('debug_level', 1);
            $rcmail->config->set('devel_mode', true);
            $rcmail->config->set('smtp_log', true);
            $rcmail->config->set('log_logins', true);
            $rcmail->config->set('log_session', true);
            $rcmail->config->set('sql_debug', true);
            $rcmail->config->set('memcache_debug', true);
            $rcmail->config->set('imap_debug', true);
            $rcmail->config->set('ldap_debug', true);
            $rcmail->config->set('smtp_debug', true);

        }

    }

    public function write_log($args) {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->config->get('kolab_auth_auditlog', false)) {
            return $args;
        }

        $args['abort'] = true;

        if ($rcmail->config->get('log_driver') == 'syslog') {
            $prio = $args['name'] == 'errors' ? LOG_ERR : LOG_INFO;
            syslog($prio, $args['line']);
            return $args;
        } else {
            $line = sprintf("[%s]: %s\n", $args['date'], $args['line']);

            // log_driver == 'file' is assumed here
            $log_dir = $rcmail->config->get('log_dir', INSTALL_PATH . 'logs');

            // Append original username + target username
            if (!is_dir($log_dir.'/'.strtolower($_SESSION['kolab_auth_admin']).'/'.strtolower($_SESSION['username']))) {
                // Attempt to create the directory
                if (@mkdir($log_dir.'/'.strtolower($_SESSION['kolab_auth_admin']).'/'.strtolower($_SESSION['username']), 0750, true)) {
                    $log_dir = $log_dir.'/'.strtolower($_SESSION['kolab_auth_admin']).'/'.strtolower($_SESSION['username']);
                }
            } else {
                $log_dir = $log_dir.'/'.strtolower($_SESSION['kolab_auth_admin']).'/'.strtolower($_SESSION['username']);
            }

            // try to open specific log file for writing
            $logfile = $log_dir.'/'.$args['name'];

            if ($fp = fopen($logfile, 'a')) {
                fwrite($fp, $line);
                fflush($fp);
                fclose($fp);
                return $args;
            }
            else
                trigger_error("Error writing to log file $logfile; Please check permissions", E_USER_WARNING);
        }

        return $args;
    }

    /**
     * Sets defaults for new user.
     */
    public function user_create($args)
    {
        if (!empty($this->data['user_email']))
            $args['user_email'] = $this->data['user_email'];
        if (!empty($this->data['user_name']))
            $args['user_name'] = $this->data['user_name'];
        if (!empty($this->data['user_alias']))
            $args['user_alias'] = $this->data['user_alias'];

        return $args;
    }

    /**
     * Modifies login form adding additional "Login As" field
     */
    public function login_form($args)
    {
        $this->load_config();
        $this->add_texts('localization/');

        $rcmail      = rcmail::get_instance();
        $admin_login = $rcmail->config->get('kolab_auth_admin_login');
        $group       = $rcmail->config->get('kolab_auth_group');
        $role_attr   = $rcmail->config->get('kolab_auth_role');

        // Show "Login As" input
        if (empty($admin_login) || (empty($group) && empty($role_attr))) {
            return $args;
        }

        $input = new html_inputfield(array('name' => '_loginas', 'id' => 'rcmloginas',
            'type' => 'text', 'autocomplete' => 'off'));
        $row = html::tag('tr', null,
            html::tag('td', 'title', html::label('rcmloginas', Q($this->gettext('loginas'))))
            . html::tag('td', 'input', $input->show(trim(get_input_value('_loginas', RCUBE_INPUT_POST))))
        );
        $args['content'] = preg_replace('/<\/tbody>/i', $row . '</tbody>', $args['content']);

        return $args;
    }

    /**
     * Find user credentials In LDAP.
     */
    public function authenticate($args)
    {
        $this->load_config();

        if (!$this->init_ldap()) {
            return $args;
        }

        $rcmail      = rcmail::get_instance();
        $admin_login = $rcmail->config->get('kolab_auth_admin_login');
        $admin_pass  = $rcmail->config->get('kolab_auth_admin_password');
        $login_attr  = $rcmail->config->get('kolab_auth_login');
        $alias_attr  = $rcmail->config->get('kolab_auth_alias');
        $name_attr   = $rcmail->config->get('kolab_auth_name');

        // get username and host
        $host    = rcube_parse_host($args['host']);
        $user    = $args['user'];
        $pass    = $args['pass'];
        $loginas = trim(get_input_value('_loginas', RCUBE_INPUT_POST));

        if (empty($user) || empty($pass)) {
            return $args;
        }

        // Find user record in LDAP
        $record = $this->get_user_record($user, $host);

        if (empty($record)) {
            return $args;
        }

        // Login As...
        if (!empty($loginas) && $admin_login) {
            // Authenticate to LDAP
            $dn     = $this->ldap->dn_decode($record['ID']);
            $result = $this->ldap->bind($dn, $pass);

            if (!$result) {
                return $args;
            }

            // check if the original user has/belongs to administrative role/group
            $isadmin   = false;
            $group     = $rcmail->config->get('kolab_auth_group');
            $role_attr = $rcmail->config->get('kolab_auth_role');
            $role_dn   = $rcmail->config->get('kolab_auth_role_value');

            // check role attribute
            if (!empty($role_attr) && !empty($role_dn) && !empty($record[$role_attr])) {
                $role_dn = $this->parse_vars($role_dn, $user, $host);
                foreach ((array)$record[$role_attr] as $role) {
                    if ($role == $role_dn) {
                        $isadmin = true;
                        break;
                    }
                }
            }

            // check group
            if (!$isadmin && !empty($group)) {
                $groups = $this->ldap->get_record_groups($record['ID']);
                foreach ($groups as $g) {
                    if ($group == $this->ldap->dn_decode($g)) {
                        $isadmin = true;
                        break;
                    }
                }

            }

            // Save original user login for log (see below)
            if ($login_attr)
                $origname = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
            else
                $origname = $user;

            $record = null;

            // user has the privilage, get "login as" user credentials
            if ($isadmin) {
                $record = $this->get_user_record($loginas, $host);
            }

            if (empty($record)) {
                $args['valid'] = false;
                return $args;
            }

            $args['user'] = $loginas;

            // Mark session to use SASL proxy for IMAP authentication
            $_SESSION['kolab_auth_admin']    = strtolower($origname);
            $_SESSION['kolab_auth_login']    = $rcmail->encrypt($admin_login);
            $_SESSION['kolab_auth_password'] = $rcmail->encrypt($admin_pass);
        }

        // Set credentials
        if ($record) {
            if ($login_attr)
                $this->data['user_login'] = is_array($record[$login_attr]) ? $record[$login_attr][0] : $record[$login_attr];
            if ($alias_attr)
                $this->data['user_alias'] = is_array($record[$alias_attr]) ? $record[$alias_attr][0] : $record[$alias_attr];
            if ($name_attr)
                $this->data['user_name'] = is_array($record[$name_attr]) ? $record[$name_attr][0] : $record[$name_attr];

            if ($this->data['user_login'])
                $args['user'] = $this->data['user_login'];
        }

        // Log "Login As" usage
        if (!empty($origname)) {
            write_log('userlogins', sprintf('Admin login for %s by %s from %s',
                $args['user'], $origname, rcmail_remote_ip()));
        }

        return $args;
    }

    /**
     * Sets SASL Proxy login/password for IMAP and Managesieve auth
     */
    public function imap_connect($args)
    {
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $rcmail      = rcmail::get_instance();
            $admin_login = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $admin_pass  = $rcmail->decrypt($_SESSION['kolab_auth_password']);

            $args['auth_cid'] = $admin_login;
            $args['auth_pw']  = $admin_pass;
        }

        return $args;
    }

    /**
     * Sets SASL Proxy login/password for SMTP auth
     */
    public function smtp_connect($args)
    {
        if (!empty($_SESSION['kolab_auth_admin'])) {
            $rcmail      = rcmail::get_instance();
            $admin_login = $rcmail->decrypt($_SESSION['kolab_auth_login']);
            $admin_pass  = $rcmail->decrypt($_SESSION['kolab_auth_password']);

            $args['options']['smtp_auth_cid'] = $admin_login;
            $args['options']['smtp_auth_pw']  = $admin_pass;
        }

        return $args;
    }

    /**
     * Initializes LDAP object and connects to LDAP server
     */
    private function init_ldap()
    {
        if ($this->ldap)
            return $this->ldap->ready;

        $rcmail = rcmail::get_instance();

        $addressbook = $rcmail->config->get('kolab_auth_addressbook');

        if (!is_array($addressbook)) {
            $ldap_config = (array)$rcmail->config->get('ldap_public');
            $addressbook = $ldap_config[$addressbook];
        }

        if (empty($addressbook)) {
            return false;
        }

        $this->ldap = new kolab_auth_ldap_backend(
            $addressbook,
            $rcmail->config->get('ldap_debug'),
            $rcmail->config->mail_domain($_SESSION['imap_host'])
        );

        return $this->ldap->ready;
    }

    /**
     * Fetches user data from LDAP addressbook
     */
    private function get_user_record($user, $host)
    {
        $rcmail = rcmail::get_instance();
        $filter = $rcmail->config->get('kolab_auth_filter');

        $filter = $this->parse_vars($filter, $user, $host);

        // reset old result
        $this->ldap->reset();

        // get record
        $this->ldap->set_filter($filter);
        $results = $this->ldap->list_records();

        if (count($results->records) == 1)
            return $results->records[0];
    }

    /**
     * Prepares filter query for LDAP search
     */
    private function parse_vars($str, $user, $host)
    {
        $rcmail = rcmail::get_instance();
        $domain = $rcmail->config->get('username_domain');

        if (!empty($domain) && strpos($user, '@') === false) {
            if (is_array($domain) && isset($domain[$host]))
                $user .= '@'.rcube_parse_host($domain[$host], $host);
            else if (is_string($domain))
                $user .= '@'.rcube_parse_host($domain, $host);
        }

        // replace variables in filter
        list($u, $d) = explode('@', $user);
        $dc = 'dc='.strtr($d, array('.' => ',dc=')); // hierarchal domain string
        $replaces = array('%dc' => $dc, '%d' => $d, '%fu' => $user, '%u' => $u);

        return strtr($str, $replaces);
    }
}

/**
 * Wrapper class for rcube_ldap addressbook
 */
class kolab_auth_ldap_backend extends rcube_ldap
{
    function set_filter($filter)
    {
        if ($filter)
            $this->prop['filter'] = $filter;
    }
}
