<?php

/**
 * ActiveSync configuration user interface builder
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2013, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_activesync_ui
{
    private $rc;
    private $plugin;
    public  $device = array();

    const SETUP_URL = 'http://docs.kolab.org/client-configuration';


    public function __construct($plugin)
    {
        $this->plugin    = $plugin;
        $this->rc        = rcube::get_instance();
        $skin_path       = $this->plugin->local_skin_path() . '/';
        $this->skin_path = 'plugins/kolab_activesync/' . $skin_path;

        $this->plugin->include_stylesheet($skin_path . 'config.css');
    }

    public function device_list($attrib = array())
    {
        $attrib += array('id' => 'devices-list');

        $devices = $this->plugin->list_devices();
        $table   = new html_table();

        foreach ($devices as $id => $device) {
            $name = $device['ALIAS'] ? $device['ALIAS'] : $id;
            $table->add_row(array('id' => 'rcmrow' . $id));
            $table->add(null, html::span('devicealias', Q($name)) . html::span('devicetype', Q($device['TYPE'])));
        }

        $this->rc->output->add_gui_object('devicelist', $attrib['id']);
        $this->rc->output->set_env('devicecount', count($devices));

        $this->rc->output->include_script('list.js');

        return $table->show($attrib);
    }


    public function device_config_form($attrib = array())
    {
        $table = new html_table(array('cols' => 2));

        $field_id = 'config-device-alias';
        $input = new html_inputfield(array('name' => 'devicealias', 'id' => $field_id, 'size' => 40));
        $table->add('title', html::label($field_id, $this->plugin->gettext('devicealias')));
        $table->add(null, $input->show($this->device['ALIAS'] ? $this->device['ALIAS'] : $this->device['_id']));

        // read-only device information
        $info = $this->plugin->device_info($this->device['ID']);

        if (!empty($info)) {
            foreach ($info as $key => $value) {
                if ($value) {
                    $table->add('title', Q($this->plugin->gettext($key)));
                    $table->add(null, Q($value));
                }
            }
        }

        if ($attrib['form']) {
            $this->rc->output->add_gui_object('editform', $attrib['form']);
        }

        return $table->show($attrib);
    }


    public function folder_subscriptions($attrib = array())
    {
        if (!$attrib['id'])
            $attrib['id'] = 'foldersubscriptions';

        // group folders by type (show only known types)
        $folder_groups = array('mail' => array(), 'contact' => array(), 'event' => array(), 'task' => array(), 'note' => array());
        $folder_types  = kolab_storage::folders_typedata();
        $imei          = $this->device['_id'];
        $subscribed    = array();

        if ($imei) {
            $folder_meta = $this->plugin->folder_meta();
        }

        foreach ($this->plugin->list_folders() as $folder) {
            if ($folder_types[$folder]) {
                list($type, ) = explode('.', $folder_types[$folder]);
            }
            else {
                $type = 'mail';
            }

            if (is_array($folder_groups[$type])) {
                $folder_groups[$type][] = $folder;

                if (!empty($folder_meta) && ($meta = $folder_meta[$folder])
                    && $meta['FOLDER'] && $meta['FOLDER'][$imei]['S']
                ) {
                    $subscribed[$folder] = intval($meta['FOLDER'][$imei]['S']);
                }
            }
        }

        // build block for every folder type
        foreach ($folder_groups as $type => $group) {
            if (empty($group)) {
                continue;
            }
            $attrib['type'] = $type;
            $html .= html::div('subscriptionblock',
                html::tag('h3', $type, $this->plugin->gettext($type)) .
                $this->folder_subscriptions_block($group, $attrib, $subscribed));
        }

        $this->rc->output->add_gui_object('subscriptionslist', $attrib['id']);

        return html::div($attrib, $html);
    }

    public function folder_subscriptions_block($a_folders, $attrib, $subscribed)
    {
        $alarms = ($attrib['type'] == 'event' || $attrib['type'] == 'task');

        $table = new html_table(array('cellspacing' => 0));
        $table->add_header(array('class' => 'subscription', 'title' => $this->plugin->gettext('synchronize'), 'tabindex' => 0),
            $attrib['syncicon'] ? html::img(array('src' => $this->skin_path . $attrib['syncicon'])) :
                $this->plugin->gettext('synchronize'));
        if ($alarms) {
            $table->add_header(array('class' => 'alarm', 'title' => $this->plugin->gettext('withalarms'), 'tabindex' => 0),
                $attrib['alarmicon'] ? html::img(array('src' => $this->skin_path . $attrib['alarmicon'])) :
                    $this->plugin->gettext('withalarms'));
        }
        $table->add_header('foldername', $this->plugin->gettext('folder'));

        $checkbox_sync  = new html_checkbox(array('name' => 'subscribed[]', 'class' => 'subscription'));
        $checkbox_alarm = new html_checkbox(array('name' => 'alarm[]', 'class' => 'alarm'));

        $names = array();
        foreach ($a_folders as $folder) {
            $foldername = $origname = preg_replace('/^INBOX &raquo;\s+/', '', kolab_storage::object_name($folder));

            // find folder prefix to truncate (the same code as in kolab_addressbook plugin)
            for ($i = count($names)-1; $i >= 0; $i--) {
                if (strpos($foldername, $names[$i].' &raquo; ') === 0) {
                    $length = strlen($names[$i].' &raquo; ');
                    $prefix = substr($foldername, 0, $length);
                    $count  = count(explode(' &raquo; ', $prefix));
                    $foldername = str_repeat('&nbsp;&nbsp;', $count-1) . '&raquo; ' . substr($foldername, $length);
                    break;
                }
            }

            $folder_id = 'rcmf' . html_identifier($folder);
            $names[] = $origname;
            $classes = array('mailbox');

            if ($folder_class = $this->rc->folder_classname($folder)) {
                $foldername = html::quote($this->rc->gettext($folder_class));
                $classes[] = $folder_class;
            }

            $table->add_row();
            $table->add('subscription', $checkbox_sync->show(
                !empty($subscribed[$folder]) ? $folder : null,
                array('value' => $folder, 'id' => $folder_id)));

            if ($alarms) {
                $table->add('alarm', $checkbox_alarm->show(
                    intval($subscribed[$folder]) > 1 ? $folder : null,
                    array('value' => $folder, 'id' => $folder_id.'_alarm')));
            }

            $table->add(join(' ', $classes), html::label($folder_id, $foldername));
        }

        return $table->show();
    }

    public function folder_options_table($folder_name, $devices, $type)
    {
        $alarms      = $type == 'event' || $type == 'task';
        $meta        = $this->plugin->folder_meta();
        $folder_data = (array) ($meta[$folder_name] ? $meta[$folder_name]['FOLDER'] : null);

        $table = new html_table(array('cellspacing' => 0, 'id' => 'folder-sync-options', 'class' => 'records-table'));

        // table header
        $table->add_header(array('class' => 'device'), $this->plugin->gettext('devicealias'));
        $table->add_header(array('class' => 'subscription'), $this->plugin->gettext('synchronize'));
        if ($alarms) {
            $table->add_header(array('class' => 'alarm'), $this->plugin->gettext('withalarms'));
        }

        // table records
        foreach ($devices as $id => $device) {
            $info     = $this->plugin->device_info($device['ID']);
            $name     = $id;
            $title    = '';
            $checkbox = new html_checkbox(array('name' => "_subscriptions[$id]", 'value' => 1,
                'onchange' => 'return activesync_object.update_sync_data(this)'));

            if (!empty($info)) {
                $_name = trim($info['friendlyname'] . ' ' . $info['os']);
                $title = $info['useragent'];

                if ($_name) {
                    $name .= " ($_name)";
                }
            }

            $table->add_row();
            $table->add(array('class' => 'device', 'title' => $title), $name);
            $table->add('subscription', $checkbox->show(!empty($folder_data[$id]['S']) ? 1 : 0));

            if ($alarms) {
                $checkbox_alarm = new html_checkbox(array('name' => "_alarms[$id]", 'value' => 1,
                    'onchange' => 'return activesync_object.update_sync_data(this)'));

                $table->add('alarm', $checkbox_alarm->show($folder_data[$id]['S'] > 1 ? 1 : 0));
            }
        }

        return $table->show();
    }

    /**
     * Displays initial page (when no devices are registered)
     */
    function init_message()
    {
        $this->plugin->load_config();

        $this->rc->output->add_handlers(array(
                'initmessage' => array($this, 'init_message_content')
        ));

        $this->rc->output->send('kolab_activesync.configempty');
    }

    /**
     * Handler for initmessage template object
     */
    function init_message_content()
    {
        $url  = $this->rc->config->get('activesync_setup_url', self::SETUP_URL);
        $vars = array('url' => $url);
        $msg  = $this->plugin->gettext(array('name' => 'nodevices', 'vars' => $vars));

        return $msg;
    }
}
