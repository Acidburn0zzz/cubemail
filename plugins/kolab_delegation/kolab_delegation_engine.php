<?php

/**
 * Kolab Delegation Engine
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_delegation_engine
{
    private $rc;
    private $ldap_filter;
    private $ldap_delegate_field;
    private $ldap_login_field;
    private $ldap_name_field;
    private $ldap_email_field;
    private $ldap_dn;
    private $cache = array();
    private $folder_types = array('mail', 'event', 'task');

    const ACL_READ  = 1;
    const ACL_WRITE = 2;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->rc = rcube::get_instance();

        // Default filter of LDAP queries
        $this->ldap_filter = $this->rc->config->get('kolab_delegation_filter');
        // Name of the LDAP field for delegates list
        $this->ldap_delegate_field = $this->rc->config->get('kolab_delegation_delegate_field');
        // Name of the LDAP field with authentication ID
        $this->ldap_login_field = $this->rc->config->get('kolab_delegation_login_field');
        // Name of the LDAP field with user name used for identities
        $this->ldap_name_field = $this->rc->config->get('kolab_delegation_name_field');
        // Name of the LDAP field with email addresses used for identities
        $this->ldap_email_field = $this->rc->config->get('kolab_delegation_email_field');
        // Encoded LDAP DN of current user, set on login by kolab_auth plugin
        $this->ldap_dn = $_SESSION['kolab_dn'];
    }

    /**
     * Add delegate
     *
     * @param string|array $delegate Delegate DN (encoded) or delegate data (result of delegate_get())
     * @param array        $acl      List of folder->right map
     */
    public function delegate_add($delegate, $acl)
    {
        if (!is_array($delegate)) {
            $delegate = $this->delegate_get($delegate);
        }
        $dn       = $delegate['ID'];
        $list     = $this->list_delegates();
        $user     = $this->user();
        $ldap     = $this->ldap();

        if (empty($delegate) || empty($dn)) {
            return false;
        }

        // add delegate to the list
        $list = array_keys((array)$list);
        $list = array_filter($list);
        if (!in_array($dn, $list)) {
            $list[] = $dn;
        }
        $list = array_map(array('rcube_ldap', 'dn_decode'), $list);
        $user[$this->ldap_delegate_field] = $list;

        // update user record
        $result = $this->user_update($user);

        // Set ACL on folders
        if ($result && !empty($acl)) {
            $this->delegate_acl_update($delegate['uid'], $acl);
        }

        return $result;
    }

    /**
     * Set/Update ACL on delegator's folders
     *
     * @param string $uid    Delegate authentication identifier
     * @param array  $acl    List of folder->right map
     * @param bool   $update Update (remove) old rights
     */
    public function delegate_acl_update($uid, $acl, $update = false)
    {
        $storage     = $this->rc->get_storage();
        $right_types = $this->right_types();
        $folders     = $update ? $this->list_folders($uid) : array();

        foreach ($acl as $folder_name => $rights) {
            $r = $right_types[$rights];
            if ($r) {
                $storage->set_acl($folder_name, $uid, $r);
            }

            if (!empty($folders) && isset($folders[$folder_name])) {
                unset($folders[$folder_name]);
            }
        }

        foreach ($folders as $folder_name => $folder) {
            if ($folder['rights']) {
                $storage->delete_acl($folder_name, $uid);
            }
        }

        return true;
    }

    /**
     * Delete delgate
     *
     * @param string $dn      Delegate DN (encoded)
     * @param bool   $acl_del Enable ACL deletion on delegator folders
     */
    public function delegate_delete($dn, $acl_del = false)
    {
        $delegate = $this->delegate_get($dn);
        $list     = $this->list_delegates();
        $user     = $this->user();
        $ldap     = $this->ldap();

        if (empty($delegate) || !isset($list[$dn])) {
            return false;
        }

        // remove delegate from the list
        unset($list[$dn]);
        $list = array_keys($list);
        $list = array_map(array('rcube_ldap', 'dn_decode'), $list);
        $user[$this->ldap_delegate_field] = $list;

        // update user record
        $result = $this->user_update($user);

        // remove ACL
        if ($result && $acl_del) {
            $this->delegate_acl_update($delegate['uid'], array(), true);
        }

        return $result;
    }

    /**
     * Return delegate data
     *
     * @param string $dn Delegate DN (encoded)
     *
     * @return array Delegate record (ID, name, uid, imap_uid)
     */
    public function delegate_get($dn)
    {
        $ldap = $this->ldap();

        if (!$ldap) {
            return array();
        }

        $ldap->reset();

        // Get delegate
        $user = $ldap->get_record($dn, true);

        if (empty($user)) {
            return array();
        }

        $delegate = $this->parse_ldap_record($user);
        $delegate['ID'] = $dn;

        return $delegate;
    }

    /**
     * Return delegate data
     *
     * @param string $login Delegate name (the 'uid' returned in get_users())
     *
     * @return array Delegate record (ID, name, uid, imap_uid)
     */
    public function delegate_get_by_name($login)
    {
        $ldap = $this->ldap();

        if (!$ldap || empty($login)) {
            return array();
        }

        $ldap->reset();

        $list = $ldap->search($this->ldap_login_field, $login, 1);

        if ($list->count == 1) {
            $user = $list->next();
            return $this->parse_ldap_record($user);
        }
    }

    /**
     * LDAP object getter
     */
    private function ldap()
    {
        $ldap = kolab_auth::ldap();

        if (!$ldap || !$ldap->ready) {
            return null;
        }

        $ldap->set_filter($this->ldap_filter);

        return $ldap;
    }

    /**
     * List current user delegates
     */
    public function list_delegates()
    {
        $result = array();

        $ldap = $this->ldap();
        $user = $this->user();

        if (empty($ldap) || empty($user)) {
            return array();
        }

        // Get delegates of current user
        $delegates = $user[$this->ldap_delegate_field];

        if (!empty($delegates)) {
            foreach ((array)$delegates as $dn) {
                $ldap->reset();
                $delegate = $ldap->get_record(rcube_ldap::dn_encode($dn), true);
                $data     = $this->parse_ldap_record($delegate);

                if (!empty($data) && !empty($data['name'])) {
                    $result[$delegate['ID']] = $data['name'];
                }
            }
        }

        return $result;
    }

    /**
     * List current user delegators
     *
     * @return array List of delegators
     */
    public function list_delegators()
    {
        $result = array();

        $ldap = $this->ldap();

        if (empty($ldap) || empty($this->ldap_dn)) {
            return array();
        }

        $ldap->reset();
        $list = $ldap->search($this->ldap_delegate_field, rcube_ldap::dn_decode($this->ldap_dn), 1);

        while ($delegator = $list->iterate()) {
            $result[$delegator['ID']] = $this->parse_ldap_record($delegator);
        }

        return $result;
    }

    /**
     * Get all folders to which current user has admin access
     *
     * @param string $delegate IMAP user identifier
     *
     * @return array Folder type/rights
     */
    public function list_folders($delegate = null)
    {
        $storage  = $this->rc->get_storage();
        $folders  = $storage->list_folders();
        $metadata = $storage->get_metadata('*', array(kolab_storage::CTYPE_KEY, kolab_storage::CTYPE_KEY_PRIVATE));
        $result   = array();

        if (!is_array($metadata)) {
            return $result;
        }

        $metadata = array_map(array('kolab_storage', 'folder_select_metadata'), $metadata);

        // Definition of read and write ACL
        $right_types = $this->right_types();

        foreach ($folders as $folder) {
            // get only folders in personal namespace
            if ($storage->folder_namespace($folder) != 'personal') {
                continue;
            }

            $rights = null;
            $type   = $metadata[$folder] ?: 'mail';
            list($class, $subclass) = explode('.', $type);

            if (!in_array($class, $this->folder_types)) {
                continue;
            }

            // in edit mode, get folder ACL
            if ($delegate) {
                // @TODO: cache ACL
                $acl = $storage->get_acl($folder);
                if ($acl = $acl[$delegate]) {
                    if ($this->acl_compare($acl, $right_types[self::ACL_WRITE])) {
                        $rights = self::ACL_WRITE;
                    }
                    else if ($this->acl_compare($acl, $right_types[self::ACL_READ])) {
                        $rights = self::ACL_READ;
                    }
                }
            }
            else if ($folder == 'INBOX' || $subclass == 'default' || $subclass == 'inbox') {
                $rights = self::ACL_WRITE;
            }

            $result[$folder] = array(
                'type'   => $class,
                'rights' => $rights,
            );
        }

        return $result;
    }

    /**
     * Returns list of users for autocompletion
     *
     * @param string $search Search string
     *
     * @return array Users list
     */
    public function list_users($search)
    {
        $ldap = $this->ldap();

        if (empty($ldap) || $search === '' || $search === null) {
            return array();
        }

        $max    = (int) $this->rc->config->get('autocomplete_max', 15);
        $mode   = (int) $this->rc->config->get('addressbook_search_mode');
        $fields = array_unique(array_filter(array_merge((array)$this->ldap_name_field, (array)$this->ldap_login_field)));
        $users  = array();

        $ldap->reset();
        $ldap->set_pagesize($max);
        $result = $ldap->search($fields, $search, $mode, true, false, (array)$this->ldap_login_field);

        foreach ($result->records as $record) {
            $user = $this->parse_ldap_record($record);

            if ($user['name']) {
                $users[] = $user['name'];
            }
        }

        sort($users, SORT_LOCALE_STRING);

        return $users;
    }

    /**
     * Extract delegate identifiers and pretty name from LDAP record
     */
    private function parse_ldap_record($data)
    {
        $email = array();
        $uid   = $data[$this->ldap_login_field];

        if (is_array($uid)) {
            $uid = array_filter($uid);
            $uid = $uid[0];
        }

        // User name for identity
        foreach ((array)$this->ldap_name_field as $field) {
            $name = is_array($data[$field]) ? $data[$field][0] : $data[$field];
            if (!empty($name)) {
                break;
            }
        }

        // User email(s) for identity
        foreach ((array)$this->ldap_email_field as $field) {
            $user_email = is_array($data[$field]) ? array_filter($data[$field]) : $data[$field];
            if (!empty($user_email)) {
                $email = array_merge((array)$email, (array)$user_email);
            }
        }

        if ($uid && $name) {
            $name .= ' (' . $uid . ')';
        }
        else {
            $name = $uid;
        }

        // get IMAP uid - identifier used in shared folder hierarchy
        $imap_uid = $uid;
        if ($pos = strpos($imap_uid, '@')) {
            $imap_uid = substr($imap_uid, 0, $pos);
        }

        return array(
            'uid'      => $uid,
            'name'     => $name,
            'imap_uid' => $imap_uid,
            'email'    => $email,
            'ID'       => $data['ID'],
        );
    }

    /**
     * Returns LDAP record of current user
     *
     * @return array User data
     */
    public function user($parsed = false)
    {
        if (!isset($this->cache['user'])) {
            $ldap = $this->ldap();

            if (!$ldap) {
                return array();
            }

            $ldap->reset();

            // Get current user record
            $this->cache['user'] = $ldap->get_record($this->ldap_dn, true);
        }

        return $parsed ? $this->parse_ldap_record($this->cache['user']) : $this->cache['user'];
    }

    /**
     * Update LDAP record of current user
     *
     * @param array User data
     */
    public function user_update($user)
    {
        $ldap = $this->ldap();

        if (!$ldap) {
            return false;
        }

        $dn   = rcube_ldap::dn_decode($this->ldap_dn);
        $pass = $this->rc->decrypt($_SESSION['password']);

        // need to bind as self for sufficient privilages
        if (!$ldap->bind($dn, $pass)) {
            return false;
        }

        unset($this->cache['user']);
        // update user record
        return $ldap->update($this->ldap_dn, $user);
    }

    /**
     * Compares two ACLs (according to supported rights)
     *
     * @todo: this is stolen from acl plugin, move to rcube_storage/rcube_imap
     *
     * @param array $acl1 ACL rights array (or string)
     * @param array $acl2 ACL rights array (or string)
     *
     * @param int Comparision result, 2 - full match, 1 - partial match, 0 - no match
     */
    function acl_compare($acl1, $acl2)
    {
        if (!is_array($acl1)) $acl1 = str_split($acl1);
        if (!is_array($acl2)) $acl2 = str_split($acl2);

        $rights = $this->rights_supported();

        $acl1 = array_intersect($acl1, $rights);
        $acl2 = array_intersect($acl2, $rights);
        $res  = array_intersect($acl1, $acl2);

        $cnt1 = count($res);
        $cnt2 = count($acl2);

        if ($cnt1 == $cnt2)
            return 2;
        else if ($cnt1)
            return 1;
        else
            return 0;
    }

    /**
     * Get list of supported access rights (according to RIGHTS capability)
     *
     * @todo: this is stolen from acl plugin, move to rcube_storage/rcube_imap
     *
     * @return array List of supported access rights abbreviations
     */
    public function rights_supported()
    {
        if ($this->supported !== null) {
            return $this->supported;
        }

        $storage = $this->rc->get_storage();
        $capa    = $storage->get_capability('RIGHTS');

        if (is_array($capa)) {
            $rights = strtolower($capa[0]);
        }
        else {
            $rights = 'cd';
        }

        return $this->supported = str_split('lrswi' . $rights . 'pa');
    }

    private function right_types()
    {
        // Get supported rights and build column names
        $supported = $this->rights_supported();

        // depending on server capability either use 'te' or 'd' for deleting msgs
        $deleteright = implode(array_intersect(str_split('ted'), $supported));

        return array(
            self::ACL_READ  => 'lrs',
            self::ACL_WRITE => 'lrswi'.$deleteright,
        );
    }
}
