<?php

/**
 * The kolab_storage_folder class represents an IMAP folder on the Kolab server.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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
class kolab_storage_folder
{
    const KTYPE_PREFIX = 'application/x-vnd.kolab.';

    /**
     * The folder name.
     *
     * @var string
     */
    public $name;

    /**
     * The type of this folder.
     *
     * @var string
     */
    public $type;

    private $type_annotation;
    private $subpath;
    private $imap;
    private $info;
    private $owner;
    private $objcache = array();
    private $uid2msg = array();


    /**
     * Default constructor
     */
    function __construct($name, $imap = null)
    {
        $this->imap = is_object($imap) ? $imap : rcmail::get_instance()->get_storage();
        $this->imap->set_options(array('skip_deleted' => false));
        $this->set_folder($name);
    }


    /**
     * Set the IMAP folder name this instance connects to
     *
     * @param string The folder name/path
     */
    public function set_folder($name)
    {
        $this->name = $name;
        $this->imap->set_folder($this->name);

        $metadata = $this->imap->get_metadata($this->name, array(kolab_storage::CTYPE_KEY));
        $this->type_annotation = $metadata[$this->name][kolab_storage::CTYPE_KEY];
        $this->type = reset(explode('.', $this->type_annotation));
    }


    /**
     *
     */
    private function get_folder_info()
    {
        if (!isset($this->info))
            $this->info = $this->imap->folder_info($this->name);

        return $this->info;
    }


    /**
     * Returns IMAP metadata/annotations (GETMETADATA/GETANNOTATION)
     *
     * @param array List of metadata keys to read
     * @return array Metadata entry-value hash array on success, NULL on error
     */
    public function get_metadata($keys)
    {
        $metadata = $this->imap->get_metadata($this->name, (array)$keys);
        return $metadata[$this->name];
    }


    /**
     * Sets IMAP metadata/annotations (SETMETADATA/SETANNOTATION)
     *
     * @param array  $entries Entry-value array (use NULL value as NIL)
     * @return boolean True on success, False on failure
     */
    public function set_metadata($entries)
    {
        return $this->imap->set_metadata($this->name, $entries);
    }


    /**
     * Returns the owner of the folder.
     *
     * @return string  The owner of this folder.
     */
    public function get_owner()
    {
        // return cached value
        if (isset($this->owner))
            return $this->owner;

        $info = $this->get_folder_info();
        $rcmail = rcmail::get_instance();

        switch ($info['namespace']) {
        case 'personal':
            $this->owner = $rcmail->user->get_username();
            break;

        case 'shared':
            $this->owner = 'anonymous';
            break;

        default:
            $owner = '';
            list($prefix, $user) = explode($this->imap->get_hierarchy_delimiter(), $info['name']);
            if (strpos($user, '@') === false) {
                $domain = strstr($rcmail->user->get_username(), '@');
                if (!empty($domain))
                    $user .= $domain;
            }
            $this->owner = $user;
            break;
        }

        return $this->owner;
    }


    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        return $this->imap->folder_namespace($this->name);
    }


    /**
     * Get IMAP ACL information for this folder
     *
     * @return string  Permissions as string
     */
    public function get_myrights()
    {
        $rights = $this->info['rights'];

        if (!is_array($rights))
            $rights = $this->imap->my_rights($this->name);

        return join('', (array)$rights);
    }


    /**
     * Check subscription status of this folder
     *
     * @param string Subscription type (kolab_storage::SERVERSIDE_SUBSCRIPTION or kolab_storage::CLIENTSIDE_SUBSCRIPTION)
     * @return boolean True if subscribed, false if not
     */
    public function is_subscribed($type = 0)
    {
        static $subscribed;  // local cache

        if ($type == kolab_storage::SERVERSIDE_SUBSCRIPTION) {
            if (!$subscribed)
                $subscribed = $this->imap->list_folders_subscribed();

            return in_array($this->name, $subscribed);
        }
        else if (kolab_storage::CLIENTSIDE_SUBSCRIPTION) {
            // TODO: implement this
            return true;
        }

        return false;
    }

    /**
     * Change subscription status of this folder
     *
     * @param boolean The desired subscription status: true = subscribed, false = not subscribed
     * @param string  Subscription type (kolab_storage::SERVERSIDE_SUBSCRIPTION or kolab_storage::CLIENTSIDE_SUBSCRIPTION)
     * @return True on success, false on error
     */
    public function subscribe($subscribed, $type = 0)
    {
        if ($type == kolab_storage::SERVERSIDE_SUBSCRIPTION) {
            return $subscribed ? $this->imap->subscribe($this->name) : $this->imap->unsubscribe($this->name);
        }
        else {
          // TODO: implement this
        }

        return false;
    }


    /**
     * Get number of objects stored in this folder
     *
     * @param string  $type Object type (e.g. contact, event, todo, journal, note, configuration)
     * @return integer The number of objects of the given type
     */
    public function count($type = null)
    {
        if (!$type) $type = $this->type;

        // search by object type
        $ctype  = self::KTYPE_PREFIX . $type;
        $index = $this->imap->search_once($this->name, 'UNDELETED HEADER X-Kolab-Type ' . $ctype);

        return $index->count();
    }

    /**
     * List all Kolab objects of the given type
     *
     * @param string  $type Object type (e.g. contact, event, todo, journal, note, configuration)
     * @return array  List of Kolab data objects (each represented as hash array)
     */
    public function get_objects($type = null)
    {
        if (!$type) $type = $this->type;

        $ctype  = self::KTYPE_PREFIX . $type;
        $results = array();

        // use 'list' for folder's default objects
        if ($type == $this->type) {
            $index = $this->imap->index($this->name);
        }
        else {  // search by object type
            $search = 'UNDELETED HEADER X-Kolab-Type ' . $ctype;
            $index = $this->imap->search_once($this->name, $search);
        }

        // fetch all messages from IMAP
        foreach ($index->get() as $msguid) {
            if ($object = $this->read_object($msguid, $type)) {
                $results[] = $object;
                $this->uid2msg[$object['uid']] = $msguid;
            }
        }

        // TODO: write $this->uid2msg to cache

        return $results;
    }


    /**
     * Getter for a single Kolab object, identified by its UID
     *
     * @param string Object UID
     * @return array The Kolab object represented as hash array
     */
    public function get_object($uid)
    {
        $msguid = $this->uid2msguid($uid);
        if ($msguid && ($object = $this->read_object($msguid)))
            return $object;

        return false;
    }


    /**
     * Fetch a Kolab object attachment which is stored in a separate part
     * of the mail MIME message that represents the Kolab record.
     *
     * @param string  Object's UID
     * @param string  The attachment's mime number
     * @param string  IMAP folder where message is stored;
     *                If set, that also implies that the given UID is an IMAP UID
     * @return mixed  The attachment content as binary string
     */
    public function get_attachment($uid, $part, $mailbox = null)
    {
        if ($msguid = ($mailbox ? $uid : $this->uid2msguid($uid))) {
            if ($mailbox)
                $this->imap->set_folder($mailbox);

            return $this->imap->get_message_part($msguid, $part);
        }

        return null;
    }


    /**
     * Fetch the mime message from the storage server and extract
     * the Kolab groupware object from it
     *
     * @param string The IMAP message UID to fetch
     * @param string The object type expected
     * @param string The folder name where the message is stored
     * @return mixed Hash array representing the Kolab object, a kolab_format instance or false if not found
     */
    private function read_object($msguid, $type = null, $folder = null)
    {
        if (!$type) $type = $this->type;
        if (!$folder) $folder = $this->name;
        $ctype = self::KTYPE_PREFIX . $type;

        // requested message in local cache
        if ($this->objcache[$msguid])
            return $this->objcache[$msguid];

        $this->imap->set_folder($folder);

        // check ctype header and abort on mismatch
        $headers = $this->imap->get_message_headers($msguid);
        if ($headers->others['x-kolab-type'] != $ctype)
            return false;

        $message = new rcube_message($msguid);
        $attachments = array();

        // get XML part
        foreach ((array)$message->attachments as $part) {
            if (!$xml && ($part->mimetype == $ctype || preg_match('!application/([a-z]+\+)?xml!', $part->mimetype))) {
                $xml = $part->body ? $part->body : $message->get_part_content($part->mime_id);
            }
            else if ($part->filename) {
                $attachments[$part->filename] = array(
                    'key' => $part->mime_id,
                    'type' => $part->mimetype,
                    'size' => $part->size,
                );
            }
        }

        if (!$xml) {
            raise_error(array(
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "Could not find Kolab data part in message $msguid ($this->name).",
            ), true);
            return false;
        }

        $format = kolab_format::factory($type);

        // check kolab format version
        if (strpos($xml, '<' . $type) !== false) {
            // old Kolab 2.0 format detected
            $handler = Horde_Kolab_Format::factory('XML', $type);
            if (is_object($handler) && is_a($handler, 'PEAR_Error')) {
                continue;
            }

            // XML-to-array
            $object = $handler->load($xml);
            $format->fromkolab2($object);
        }
        else {
            // load Kolab 3 format using libkolabxml
            $format->load($xml);
        }

        if ($format->is_valid()) {
            if ($formatobj)
                return $format;

            $object = $format->to_array();
            $object['_msguid'] = $msguid;
            $object['_mailbox'] = $this->name;
            $object['_attachments'] = $attachments;
            $object['_formatobj'] = $format;

            $this->objcache[$msguid] = $object;
            return $object;
        }

        return false;
    }


    /**
     * Save an object in this folder.
     *
     * @param array  $object    The array that holds the data of the object.
     * @param string $type      The type of the kolab object.
     * @param string $uid       The UID of the old object if it existed before
     * @return boolean          True on success, false on error
     */
    public function save(&$object, $type = null, $uid = null)
    {
        if (!$type)
            $type = $this->type;

        // copy attachments from old message
        if (!empty($object['_msguid']) && ($old = $this->read_object($object['_msguid'], $type, $object['_mailbox']))) {
            foreach ($old['_attachments'] as $name => $att) {
                if (!isset($object['_attachments'][$name])) {
                    $object['_attachments'][$name] = $old['_attachments'][$name];
                }
                // load photo.attachment from old Kolab2 format to be directly embedded in xcard block
                if ($name == 'photo.attachment' && !isset($object['photo']) && !$object['_attachments'][$name]['content'] && $att['key']) {
                    $object['photo'] = $this->get_attachment($object['_msguid'], $att['key'], $object['_mailbox']);
                    unset($object['_attachments'][$name]);
                }
            }
        }

        if ($raw_msg = $this->build_message($object, $type)) {
            $result = $this->imap->save_message($this->name, $raw_msg, '', false);

            // delete old message
            if ($result && !empty($object['_msguid']) && !empty($object['_mailbox'])) {
                $this->imap->delete_message($object['_msguid'], $object['_mailbox']);
            }
            else if ($result && $uid && ($msguid = $this->uid2msguid($uid))) {
                $this->imap->delete_message($msguid, $this->name);
            }

            // TODO: update cache with new UID
            $this->uid2msg[$object['uid']] = $result;
        }
        
        return $result;
    }


    /**
     * Delete the specified object from this folder.
     *
     * @param  mixed   $object  The Kolab object to delete or object UID
     * @param  boolean $expunge Should the folder be expunged?
     * @param  boolean $trigger Should the folder update be triggered?
     *
     * @return boolean True if successful, false on error
     */
    public function delete($object, $expunge = true, $trigger = true)
    {
        $msguid = is_array($object) ? $object['_msguid'] : $this->uid2msguid($object);

        if ($msguid && $expunge) {
            return $this->imap->delete_message($msguid, $this->name);
        }
        else if ($msguid) {
            return $this->imap->set_flag($msguid, 'DELETED', $this->name);
        }

        return false;
    }


    /**
     *
     */
    public function delete_all()
    {
        return $this->imap->clear_folder($this->name);
    }


    /**
     * Restore a previously deleted object
     *
     * @param string Object UID
     * @return mixed Message UID on success, false on error
     */
    public function undelete($uid)
    {
        if ($msguid = $this->uid2msguid($uid, true)) {
            if ($this->imap->set_flag($msguid, 'UNDELETED', $this->name)) {
                return $msguid;
            }
        }

        return false;
    }


    /**
     * Move a Kolab object message to another IMAP folder
     *
     * @param string Object UID
     * @param string IMAP folder to move object to
     * @return boolean True on success, false on failure
     */
    public function move($uid, $target_folder)
    {
        if ($msguid = $this->uid2msguid($uid)) {
            if ($success = $this->imap->move_message($msguid, $target_folder, $this->name)) {
                // TODO: update cache
                return true;
            }
            else {
                raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Failed to move message $msguid to $target_folder: " . $this->imap->get_error_str(),
                ), true);
            }
        }

        return false;
    }


    /**
     * Resolve an object UID into an IMAP message UID
     */
    private function uid2msguid($uid, $deleted = false)
    {
        if (!isset($this->uid2msg[$uid])) {
            // use IMAP SEARCH to get the right message
            $index = $this->imap->search_once($this->name, ($deleted ? '' : 'UNDELETED ') . 'HEADER SUBJECT ' . $uid);
            $results = $index->get();
            $this->uid2msg[$uid] = $results[0];

            // TODO: cache this lookup
        }

        return $this->uid2msg[$uid];
    }


    /**
     * Creates source of the configuration object message
     */
    private function build_message(&$object, $type)
    {
        // load old object to preserve data we don't understand/process
        if (is_object($object['_formatobj']))
            $format = $object['_formatobj'];
        else if ($object['_msguid'] && ($old = $this->read_object($object['_msguid'], $type, $object['_mailbox'])))
            $format = $old['_formatobj'];

        // create new kolab_format instance
        if (!$format)
            $format = kolab_format::factory($type);

        $format->set($object);
        $xml = $format->write();
        $object['uid'] = $format->uid;  // get read UID from format

        if (!$format->is_valid() || empty($object['uid'])) {
            return false;
        }

        $mime = new Mail_mime("\r\n");
        $rcmail = rcmail::get_instance();
        $headers = array();

        if ($ident = $rcmail->user->get_identity()) {
            $headers['From'] = $ident['email'];
            $headers['To'] = $ident['email'];
        }
        $headers['Date'] = date('r');
        $headers['X-Kolab-Type'] = self::KTYPE_PREFIX . $type;
        $headers['Subject'] = $object['uid'];
//        $headers['Message-ID'] = rcmail_gen_message_id();
        $headers['User-Agent'] = $rcmail->config->get('useragent');

        $mime->headers($headers);
        $mime->setTXTBody('This is a Kolab Groupware object. '
            . 'To view this object you will need an email client that understands the Kolab Groupware format. '
            . "For a list of such email clients please visit http://www.kolab.org/\n\n");

        $mime->addAttachment($xml,
            $format->CTYPE,
            'kolab.xml',
            false, '8bit', 'attachment', RCMAIL_CHARSET, '', '',
            $rcmail->config->get('mime_param_folding') ? 'quoted-printable' : null,
            $rcmail->config->get('mime_param_folding') == 2 ? 'quoted-printable' : null,
            '', RCMAIL_CHARSET
        );

        // save object attachments as separate parts
        // TODO: optimize memory consumption by using tempfiles for transfer
        foreach ((array)$object['_attachments'] as $name => $att) {
            if (empty($att['content']) && !empty($att['key'])) {
                $msguid = !empty($object['_msguid']) ? $object['_msguid'] : $object['uid'];
                $att['content'] = $this->get_attachment($msguid, $att['key'], $object['_mailbox']);
            }
            if (!empty($att['content'])) {
                $mime->addAttachment($att['content'], $att['type'], $name, false);
            }
        }

        return $mime->getMessage();
    }


    /**
     * Triggers any required updates after changes within the
     * folder. This is currently only required for handling free/busy
     * information with Kolab.
     *
     * @return boolean|PEAR_Error True if successfull.
     */
    public function trigger()
    {
        $owner = $this->get_owner();
        $result = false;

        switch($this->type) {
        case 'event':
            if ($this->get_namespace() == 'personal') {
                $result = $this->trigger_url(
                    sprintf('%s/trigger/%s/%s.pfb', kolab_storage::get_freebusy_server(), $owner, $this->imap->mod_folder($this->name)),
                    $this->imap->options['user'],
                    $this->imap->options['password']
                );
            }
            break;

        default:
            return true;
        }

        if ($result && is_a($result, 'PEAR_Error')) {
            return PEAR::raiseError(sprintf("Failed triggering folder %s. Error was: %s",
                                            $this->name, $result->getMessage()));
        }

        return $result;
    }

    /**
     * Triggers a URL.
     *
     * @param string $url          The URL to be triggered.
     * @param string $auth_user    Username to authenticate with
     * @param string $auth_passwd  Password for basic auth
     * @return boolean|PEAR_Error  True if successfull.
     */
    private function trigger_url($url, $auth_user = null, $auth_passwd = null)
    {
        require_once('HTTP/Request.php');

        $request = new HTTP_Request($url);

        // set authentication credentials
        if ($auth_user && $auth_passwd)
            $request->setBasicAuth($auth_user, $auth_passwd);

        $result = $request->sendRequest(true);
        // rcube::write_log('trigger', $request->getResponseBody());

        return $result;
    }


    /* Legacy methods to keep compatibility with the old Horde Kolab_Storage classes */

    /**
     * Compatibility method
     */
    public function getOwner()
    {
        console("Call to deprecated method kolab_storage_folder::getOwner()");
        return $this->get_owner();
    }

    /**
     * Get IMAP ACL information for this folder
     */
    public function getMyRights()
    {
        PEAR::raiseError("Call to deprecated method kolab_storage_folder::getMyRights()");
        return $this->get_myrights();
    }

    /**
     * NOP to stay compatible with the formerly used Horde classes
     */
    public function getData()
    {
        PEAR::raiseError("Call to deprecated method kolab_storage_folder::getData()");
        return $this;
    }

    /**
     * List all Kolab objects of the given type
     */
    public function getObjects($type = null)
    {
        PEAR::raiseError("Call to deprecated method kolab_storage_folder::getObjects()");
        return $this->get_objects($type);
    }

    /**
     * Getter for a single Kolab object, identified by its UID
     */
    public function getObject($uid)
    {
        PEAR::raiseError("Call to deprecated method kolab_storage_folder::getObject()");
        return $this->get_object($uid);
    }

    /**
     *
     */
    public function getAttachment($key)
    {
        PEAR::raiseError("Call to deprecated method not returning anything.");
        return null;
    }

    /**
     * Alias function of delete()
     */
    public function deleteMessage($id, $trigger = true, $expunge = true)
    {
        PEAR::raiseError("Call to deprecated method kolab_storage_folder::deleteMessage()");
        return $this->delete(array('_msguid' => $id), $trigger, $expunge);
    }

    /**
     *
     */
    public function deleteAll()
    {
        PEAR::raiseError("Call to deprecated method kolab_storage_folder::deleteAll()");
        return $this->delete_all();
    }


}

