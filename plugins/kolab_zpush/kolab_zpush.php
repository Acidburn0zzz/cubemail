<?php

/**
 * Z-Push configuration utility for Kolab accounts
 *
 * @version 0.1
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

class kolab_zpush extends rcube_plugin
{
    public $task = 'settings';
    public $urlbase;
    
    private $rc;
    private $ui;
    private $cache;
    private $devices;
    private $folders;
    private $folders_meta;
    private $root_meta;
    
    const ROOT_MAILBOX = 'INBOX';
    const CTYPE_KEY = '/shared/vendor/kolab/folder-type';
    const ACTIVESYNC_KEY = '/private/vendor/kolab/activesync';

    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();
        
        $this->require_plugin('jqueryui');
        $this->add_texts('localization/', true);
        
        $this->include_script('kolab_zpush.js');
        
        $this->register_action('plugin.zpushconfig', array($this, 'config_view'));
        $this->register_action('plugin.zpushjson', array($this, 'json_command'));
    }


    /**
     * Establish IMAP connection
     */
    public function init_imap()
    {
        $this->rc->imap_connect();
        $this->cache = $this->rc->get_cache('zpush', 'db', 900);
        $this->cache->expunge();

        if ($meta = $this->rc->imap->get_metadata(self::ROOT_MAILBOX, self::ACTIVESYNC_KEY)) {
            // clear cache if device config changed
            if (($oldmeta = $this->cache->read('devicemeta')) && $oldmeta != $meta)
                $this->cache->remove();

            $this->root_meta = $this->unserialize_metadata($meta[self::ROOT_MAILBOX][self::ACTIVESYNC_KEY]);
            $this->cache->remove('devicemeta');
            $this->cache->write('devicemeta', $meta);
        }
    }


    /**
     * Handle JSON requests
     */
    public function json_command()
    {
        $cmd = get_input_value('cmd', RCUBE_INPUT_GPC);
        $device_id = get_input_value('id', RCUBE_INPUT_GPC);

        switch ($cmd) {
        case 'load':
            $result = array();
            $this->init_imap();
            $devices = $this->list_devices();
            if ($device = $devices[$device_id]) {
                $result['id'] = $device_id;
                $result['devicealias'] = $device['ALIAS'];
                $result['syncmode'] = intval($device['MODE']);
                $result['laxpic'] = intval($device['LAXPIC']);
                $result['subscribed'] = array();

                foreach ($this->folders_meta() as $folder => $meta) {
                    if ($meta[$device_id]['S'])
                        $result['subscribed'][$folder] = intval($meta[$device_id]['S']);
                }

                $this->rc->output->command('plugin.zpush_data_ready', $result);
            }
            else {
                $this->rc->output->show_message($this->gettext('devicenotfound'), 'error');
            }
            break;

        case 'save':
            $this->init_imap();
            $devices = $this->list_devices();
            $syncmode = get_input_value('syncmode', RCUBE_INPUT_POST);
            $devicealias = get_input_value('devicealias', RCUBE_INPUT_POST);
            $subsciptions = get_input_value('subscribed', RCUBE_INPUT_POST);
            $err = false;
            
            if ($device = $devices[$device_id]) {
                // update device config if changed
                if ($devicealias != $this->root_meta['DEVICE'][$device_id]['ALIAS'] ||
                       $syncmode != $this->root_meta['DEVICE'][$device_id]['MODE'] ||
                       $subsciptions[self::ROOT_MAILBOX] != $this->root_meta['FOLDER'][$device_id]['S']) {
                    $this->root_meta['DEVICE'][$device_id]['MODE'] = $syncmode;
                    $this->root_meta['DEVICE'][$device_id]['ALIAS'] = $devicealias;
                    $this->root_meta['FOLDER'][$device_id]['S'] = intval($subsciptions[self::ROOT_MAILBOX]);

                    $err = !$this->rc->imap->set_metadata(self::ROOT_MAILBOX,
                        array(self::ACTIVESYNC_KEY => $this->serialize_metadata($this->root_meta)));
                }
                // iterate over folders list and update metadata if necessary
                foreach ($this->folders_meta() as $folder => $meta) {
                    // skip root folder (already handled above)
                    if ($folder == self::ROOT_MAILBOX)
                        continue;
                    
                    if ($subsciptions[$folder] != $meta[$device_id]['S']) {
                        $meta[$device_id]['S'] = intval($subsciptions[$folder]);
                        $this->folders_meta[$folder] = $meta;
                        unset($meta['TYPE']);
                        
                        // read metadata first
                        $folderdata = $this->rc->imap->get_metadata($folder, array(self::ACTIVESYNC_KEY));
                        if ($asyncdata = $folderdata[$folder][self::ACTIVESYNC_KEY])
                            $metadata = $this->unserialize_metadata($asyncdata);
                        $metadata['FOLDER'] = $meta;

                        $err |= !$this->rc->imap->set_metadata($folder, array(self::ACTIVESYNC_KEY => $this->serialize_metadata($metadata)));
                    }
                }
                
                // update cache
                $this->cache->remove('folders');
                $this->cache->write('folders', $this->folders_meta);
                
                $this->rc->output->command('plugin.zpush_save_complete', array('success' => !$err, 'id' => $device_id, 'devicename' => Q($devicealias)));
            }
            
            if ($err)
                $this->rc->output->show_message($this->gettext('savingerror'), 'error');
            else
                $this->rc->output->show_message($this->gettext('successfullysaved'), 'confirmation');
            
            break;
        }

        $this->rc->output->send();
    }


    /**
     * Render main UI for device configuration
     */
    public function config_view()
    {
        require_once($this->home . '/kolab_zpush_ui.php');
        
        $this->init_imap();
        
        // checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
        if ($this->rc->imap->get_capability('METADATA') || $this->rc->imap->get_capability('ANNOTATEMORE') || $this->rc->imap->get_capability('ANNOTATEMORE2')) {
            $this->list_devices();
        }
        else {
            $this->rc->output->show_message($this->gettext('notsupported'), 'error');
        }
        
        $this->ui = new kolab_zpush_ui($this);
        
        $this->register_handler('plugin.devicelist', array($this->ui, 'device_list'));
        $this->register_handler('plugin.deviceconfigform', array($this->ui, 'device_config_form'));
        $this->register_handler('plugin.foldersubscriptions', array($this->ui, 'folder_subscriptions'));
        
        $this->rc->output->set_env('devicecount', count($this->list_devices()));
        $this->rc->output->send('kolab_zpush.config');
    }


    /**
     * List known devices
     *
     * @return array Device list as hash array
     */
    public function list_devices()
    {
        if (!isset($this->devices)) {
            $this->devices = (array)$this->root_meta['DEVICE'];
        }
        
        return $this->devices;
    }


    /**
     * Get list of all folders available for sync
     *
     * @return array List of mailbox folders
     */
    public function list_folders()
    {
        if (!isset($this->folders)) {
            // read cached folder meta data
            if ($cached_folders = $this->cache->read('folders')) {
                $this->folders_meta = $cached_folders;
                $this->folders = array_keys($this->folders_meta);
            }
            // fetch folder data from server
            else {
                $this->folders = $this->rc->imap->list_unsubscribed();
                foreach ($this->folders as $folder) {
                    $folderdata = $this->rc->imap->get_metadata($folder, array(self::ACTIVESYNC_KEY, self::CTYPE_KEY));
                    $foldertype = explode('.', $folderdata[$folder][self::CTYPE_KEY]);

                    if ($asyncdata = $folderdata[$folder][self::ACTIVESYNC_KEY]) {
                        $metadata = $this->unserialize_metadata($asyncdata);
                        $this->folders_meta[$folder] = $metadata['FOLDER'];
                    }
                    $this->folders_meta[$folder]['TYPE'] = !empty($foldertype[0]) ? $foldertype[0] : 'mail';
                }
                
                // cache it!
                $this->cache->write('folders', $this->folders_meta);
            }
        }

        return $this->folders;
    }

    /**
     * Getter for folder metadata
     *
     * @return array Hash array with meta data for each folder
     */
    public function folders_meta()
    {
        if (!isset($this->folders_meta))
            $this->list_folders();
        
        return $this->folders_meta;
    }

    /**
     * Helper method to decode saved IMAP metadata
     */
    private function unserialize_metadata($str)
    {
        if (!empty($str))
            return @json_decode(base64_decode($str), true);

        return null;
    }

    /**
     * Helper method to encode IMAP metadata for saving
     */
    private function serialize_metadata($data)
    {
        if (is_array($data))
            return base64_encode(json_encode($data));

        return '';
    }

}