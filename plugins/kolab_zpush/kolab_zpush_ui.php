<?php

/**
 * Z-Push configuration user interface builder
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

class kolab_zpush_ui
{
    private $rc;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
        $this->rc = rcmail::get_instance();

        $skin = $this->rc->config->get('skin');
        $this->config->include_stylesheet('skins/' . $skin . '/config.css');
        $this->rc->output->include_script('list.js');
        $this->skin_path = $this->config->urlbase . 'skins/' . $skin . '/';
    }


    public function device_list($attrib = array())
    {
        $attrib += array('id' => 'devices-list');

        $devices = $this->config->list_devices();
        $table = new html_table();

        foreach ($devices as $id => $device) {
            $name = $device['ALIAS'] ? $device['ALIAS'] : $id;
            $table->add_row(array('id' => 'rcmrow' . $id));
            $table->add(null, html::span('devicealias', Q($name)) . html::span('devicetype', Q($device['TYPE'])));
        }

        $this->rc->output->add_gui_object('devicelist', $attrib['id']);
        $this->rc->output->set_env('devices', $devices);

        return $table->show($attrib);
    }


    public function device_config_form($attrib = array())
    {
        $table = new html_table(array('cols' => 2));

        $field_id = 'config-device-alias';
        $input = new html_inputfield(array('name' => 'devicealias', 'id' => $field_id, 'size' => 40));
        $table->add('title', html::label($field_id, $this->config->gettext('devicealias')));
        $table->add(null, $input->show());
        
        $field_id = 'config-device-mode';
        $select = new html_select(array('name' => 'syncmode', 'id' => $field_id));
        $select->add(array($this->config->gettext('modeauto'), $this->config->gettext('modeflat'), $this->config->gettext('modefolder')), array('-1', '0', '1'));
        $table->add('title', html::label($field_id, $this->config->gettext('syncmode')));
        $table->add(null, $select->show('-1'));

        return $table->show($attrib);
    }


    public function folder_subscriptions($attrib = array())
    {
        if (!$attrib['id'])
            $attrib['id'] = 'foldersubscriptions';

        $table = new html_table();
        $table->add_header('foldername', $this->config->gettext('folder'));
        $table->add_header('subscription', $attrib['syncicon'] ? html::img(array('src' => $this->skin_path . $attrib['syncicon'], 'title' => $this->config->gettext('synchronize'))) : '');
        $table->add_header('alarm', $attrib['alarmicon'] ? html::img(array('src' => $this->skin_path . $attrib['alarmicon'], 'title' => $this->config->gettext('withalarms'))) : '');

        $folders_tree = array();
        $delimiter    = $this->rc->imap->get_hierarchy_delimiter();
        foreach ($this->config->list_folders() as $folder)
            rcmail_build_folder_tree($folders_tree, $folder, $delimiter);

        $this->render_folders($folders_tree, $table, 0);

        $this->rc->output->add_gui_object('subscriptionslist', $attrib['id']);

        return $table->show($attrib);
    }

    /**
     * Recursively compose folders table
     */
    private function render_folders($a_folders, $table, $level = 0)
    {
        $idx = 0;
        $checkbox_sync = new html_checkbox(array('name' => 'subscribed[]', 'class' => 'subscription'));
        $checkbox_alarm = new html_checkbox(array('name' => 'alarm[]', 'class' => 'alarm', 'disabled' => true));
        $folders_meta = $this->config->folders_meta();

        foreach ($a_folders as $key => $folder) {
            $classes = array('mailbox');

            if ($folder_class = rcmail_folder_classname($folder['id'])) {
                $foldername = rcube_label($folder_class);
                $classes[] = $folder_class;
            }
            else
                $foldername = $folder['name'];

            // visualize folder type
            if ($type = $folders_meta[$folder['id']]['TYPE'])
                $classes[] = $type;

            if ($folder['virtual'])
                $classes[] = 'virtual';

            $folder_id = 'rcmf' . html_identifier($folder['id']);
            $padding = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);

            $table->add_row(array('class' => (($level+1) * $idx++) % 2 == 0 ? 'even' : 'odd'));
            $table->add(join(' ', $classes), html::label($folder_id, $padding . Q($foldername)));
            $table->add('subscription', $folder['virtual'] ? '' : $checkbox_sync->show('', array('value' => $folder['id'], 'id' => $folder_id)));

            if (($type == 'event' || $type == 'task') && !$folder['virtual'])
                $table->add('alarm', $checkbox_alarm->show('', array('value' => $folder['id'], 'id' => $folder_id.'_alarm')));
            else
                $table->add('alarm', '');

            if (!empty($folder['folders']))
                $this->render_folders($folder['folders'], $table, $level+1);
        }
        
    }

}

