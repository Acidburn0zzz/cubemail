<?php
/*
 +-------------------------------------------------------------------------+
 | Calendar plugin for Roundcube                                           |
 | Version 0.3 beta                                                        |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 |                                                                         |
 | You should have received a copy of the GNU General Public License along |
 | with this program; if not, write to the Free Software Foundation, Inc., |
 | 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.             |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Lazlo Westerhof <hello@lazlo.me>                                |
 |         Thomas Bruederli <roundcube@gmail.com>                          |
 +-------------------------------------------------------------------------+
*/

class calendar extends rcube_plugin
{
  public $task = '?(?!login|logout).*';
  public $rc;
  public $driver;

  public $ical;
  public $ui;

  /**
   * Plugin initialization.
   */
  function init()
  {
    $this->rc = rcmail::get_instance();
    
    $this->register_task('calendar', 'calendar');
    
    // load calendar configuration
    if(file_exists($this->home . "/config.inc.php")) {
      $this->load_config('config.inc.php');
    } else {
      $this->load_config('config.inc.php.dist');
    }
    
    // load localizations
    $this->add_texts('localization/', true);

    // load Calendar user interface which includes jquery-ui
    $this->require_plugin('jqueryui');
    
    require($this->home . '/lib/calendar_ui.php');
    $this->ui = new calendar_ui($this);
    $this->ui->init();

    $skin = $this->rc->config->get('skin');
    $this->include_stylesheet('skins/' . $skin . '/calendar.css');

    if ($this->rc->task == 'calendar') {
      $this->load_driver();

      // load iCalendar functions
      require($this->home . '/lib/calendar_ical.php');
      $this->ical = new calendar_ical($this->rc, $this->driver);

      // register calendar actions
      $this->register_action('index', array($this, 'calendar_view'));
      $this->register_action('plugin.calendar', array($this, 'calendar_view'));
      $this->register_action('plugin.load_events', array($this, 'load_events'));
      $this->register_action('plugin.event', array($this, 'event'));
      $this->register_action('plugin.export_events', array($this, 'export_events'));
      $this->add_hook('keep_alive', array($this, 'keep_alive'));
      
      // set user's timezone
      if ($this->rc->config->get('timezone') === 'auto')
        $this->timezone = isset($_SESSION['timezone']) ? $_SESSION['timezone'] : date('Z');
      else
        $this->timezone = ($this->rc->config->get('timezone') + intval($this->rc->config->get('dst_active')));

      $this->gmt_offset = $this->timezone * 3600;
    } 
    else if ($this->rc->task == 'settings') {
      $this->load_driver();

      // add hooks for Calendar settings
      $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
      $this->add_hook('preferences_list', array($this, 'preferences_list'));
      $this->add_hook('preferences_save', array($this, 'preferences_save')); 
    }
  }

  private function load_driver()
  {
    $driver_name = $this->rc->config->get('calendar_driver', 'database');
    $driver_class = $driver_name . '_driver';

    require_once($this->home . '/drivers/calendar_driver.php');
    require_once($this->home . '/drivers/' . $driver_name . '/' . $driver_class . '.php');

    switch ($driver_name) {
      case "kolab":
        $this->require_plugin('kolab_core');
      default:
        $this->driver = new $driver_class($this);
        break;
      }
  }

  /**
   * Render the main calendar view from skin template
   */
  function calendar_view()
  {
    $this->rc->output->set_pagetitle($this->gettext('calendar'));

    // Add CSS stylesheets to the page header
    $this->ui->addCSS();

    // Add JS files to the page header
    $this->ui->addJS();
      
    $this->register_handler('plugin.calendar_css', array($this->ui, 'calendar_css'));
    $this->register_handler('plugin.calendar_list', array($this->ui, 'calendar_list'));
    $this->register_handler('plugin.calendar_select', array($this->ui, 'calendar_select'));
    $this->register_handler('plugin.category_select', array($this->ui, 'category_select'));
    $this->register_handler('plugin.freebusy_select', array($this->ui, 'freebusy_select'));
    $this->register_handler('plugin.priority_select', array($this->ui, 'priority_select'));
    $this->register_handler('plugin.alarm_select', array($this->ui, 'alarm_select'));
    $this->register_handler('plugin.snooze_select', array($this->ui, 'snooze_select'));
    $this->register_handler('plugin.recurrence_form', array($this->ui, 'recurrence_form'));
    
    $this->rc->output->set_env('calendar_settings', $this->load_settings());
    $this->rc->output->add_label('low','normal','high');

    $this->rc->output->send("calendar.calendar");
  }
  
  /**
   * Handler for preferences_sections_list hook.
   * Adds Calendar settings sections into preferences sections list.
   *
   * @param array Original parameters
   * @return array Modified parameters
   */
  function preferences_sections_list($p)
  {
    $p['list']['calendar'] = array(
      'id' => 'calendar', 'section' => $this->gettext('calendar'),
    );

    return $p;
  }

  /**
   * Handler for preferences_list hook.
   * Adds options blocks into Calendar settings sections in Preferences.
   *
   * @param array Original parameters
   * @return array Modified parameters
   */
  function preferences_list($p)
  {
    if ($p['section'] == 'calendar') {
      $p['blocks']['view']['name'] = $this->gettext('mainoptions');
 
      $field_id = 'rcmfd_default_view';
      $select = new html_select(array('name' => '_default_view', 'id' => $field_id));
      $select->add($this->gettext('day'), "agendaDay");
      $select->add($this->gettext('week'), "agendaWeek");
      $select->add($this->gettext('month'), "month");
      $p['blocks']['view']['options']['default_view'] = array(
        'title' => html::label($field_id, Q($this->gettext('default_view'))),
        'content' => $select->show($this->rc->config->get('calendar_default_view', "agendaWeek")),
      );
      
      $field_id = 'rcmfd_time_format';
      $choices = array('HH:mm', 'H:mm', 'h:mmt');
      $select = new html_select(array('name' => '_time_format', 'id' => $field_id));
      $select->add($choices);
      $p['blocks']['view']['options']['time_format'] = array(
        'title' => html::label($field_id, Q($this->gettext('time_format'))),
        'content' => $select->show($this->rc->config->get('calendar_time_format', "HH:mm")),
      );
      
      $field_id = 'rcmfd_timeslot';
      $choices = array('1', '2', '3', '4', '6');
      $select = new html_select(array('name' => '_timeslots', 'id' => $field_id));
      $select->add($choices);
      $p['blocks']['view']['options']['timeslots'] = array(
        'title' => html::label($field_id, Q($this->gettext('timeslots'))),
        'content' => $select->show($this->rc->config->get('calendar_timeslots', 2)),
      );
      
      $field_id = 'rcmfd_firstday';
      $select = new html_select(array('name' => '_first_day', 'id' => $field_id));
      $select->add(rcube_label('sunday'), '0');
      $select->add(rcube_label('monday'), '1');
      $select->add(rcube_label('tuesday'), '2');
      $select->add(rcube_label('wednesday'), '3');
      $select->add(rcube_label('thursday'), '4');
      $select->add(rcube_label('friday'), '5');
      $select->add(rcube_label('saturday'), '6');
      $p['blocks']['view']['options']['first_day'] = array(
        'title' => html::label($field_id, Q($this->gettext('first_day'))),
        'content' => $select->show($this->rc->config->get('calendar_first_day', 1)),
      );
      
      $field_id = 'rcmfd_alarm';
      $select_type = new html_select(array('name' => '_alarm_type', 'id' => $field_id));
      $select_type->add($this->gettext('none'), '');
      $select_type->add($this->gettext('alarmdisplayoption'), 'DISPLAY');
      $select_type->add($this->gettext('alarmemailoption'), 'EMAIL');
      
      $input_value = new html_inputfield(array('name' => '_alarm_value', 'id' => $field_id . 'value', 'size' => 3));
      $select_offset = new html_select(array('name' => '_alarm_offset', 'id' => $field_id . 'offset'));
      foreach (array('-M','-H','-D','+M','+H','+D') as $trigger)
        $select_offset->add($this->gettext('trigger' . $trigger), $trigger);
      
      $p['blocks']['view']['options']['alarmtype'] = array(
        'title' => html::label($field_id, Q($this->gettext('defaultalarmtype'))),
        'content' => $select_type->show($this->rc->config->get('calendar_default_alarm_type', '')),
      );
      $preset = self::parse_alaram_value($this->rc->config->get('calendar_default_alarm_offset', '-15M'));
      $p['blocks']['view']['options']['alarmoffset'] = array(
        'title' => html::label($field_id . 'value', Q($this->gettext('defaultalarmoffset'))),
        'content' => $input_value->show($preset[0]) . ' ' . $select_offset->show($preset[1]),
      );
      
      
      // category definitions
      if (!$this->driver->categoriesimmutable) {
        $p['blocks']['categories']['name'] = $this->gettext('categories');

        $categories = $this->rc->config->get('calendar_categories', array());
        $categories_list = '';
        foreach ($categories as $name => $color){
          $key = md5($name);
          $field_class = 'rcmfd_category_' . str_replace(' ', '_', $name);
          $category_remove = new html_inputfield(array('type' => 'button', 'value' => 'X', 'class' => 'button', 'onclick' => '$(this).parent().remove()', 'title' => $this->gettext('remove_category')));
          $category_name  = new html_inputfield(array('name' => "_categories[$key]", 'class' => $field_class, 'size' => 30));
          $category_color = new html_inputfield(array('name' => "_colors[$key]", 'class' => $field_class, 'size' => 6));
          $categories_list .= html::div(null, $category_name->show($name) . '&nbsp;' . $category_color->show($color) . '&nbsp;' . $category_remove->show());
        }

        $p['blocks']['categories']['options']['category_' . $name] = array(
          'content' => html::div(array('id' => 'calendarcategories'), $categories_list),
        );

        $field_id = 'rcmfd_new_category';
        $new_category = new html_inputfield(array('name' => '_new_category', 'id' => $field_id, 'size' => 30));
        $add_category = new html_inputfield(array('type' => 'button', 'class' => 'button', 'value' => $this->gettext('add_category'),  'onclick' => "rcube_calendar_add_category()"));
        $p['blocks']['categories']['options']['categories'] = array(
          'content' => $new_category->show('') . '&nbsp;' . $add_category->show(),
        );
      
        $this->rc->output->add_script('function rcube_calendar_add_category(){
          var name = $("#rcmfd_new_category").val();
          if (name.length) {
            var input = $("<input>").attr("type", "text").attr("name", "_categories[]").attr("size", 30).val(name);
            var color = $("<input>").attr("type", "text").attr("name", "_colors[]").attr("size", 6).val("000000");
            var button = $("<input>").attr("type", "button").attr("value", "X").addClass("button").click(function(){ $(this).parent().remove() });
            $("<div>").append(input).append("&nbsp;").append(color).append("&nbsp;").append(button).appendTo("#calendarcategories");
          }
        }');
      }
    }

    return $p;
  }

  /**
   * Handler for preferences_save hook.
   * Executed on Calendar settings form submit.
   *
   * @param array Original parameters
   * @return array Modified parameters
   */
  function preferences_save($p)
  {
    if ($p['section'] == 'calendar') {
      // compose default alarm preset value
      $alarm_offset = get_input_value('_alarm_offset', RCUBE_INPUT_POST);
      $default_alam = $alarm_offset[0] . intval(get_input_value('_alarm_value', RCUBE_INPUT_POST)) . $alarm_offset[1];

      $p['prefs'] = array(
        'calendar_default_view' => get_input_value('_default_view', RCUBE_INPUT_POST),
        'calendar_time_format'  => get_input_value('_time_format', RCUBE_INPUT_POST),
        'calendar_timeslots'    => get_input_value('_timeslots', RCUBE_INPUT_POST),
        'calendar_first_day'    => get_input_value('_first_day', RCUBE_INPUT_POST),
        'calendar_default_alarm_type'   => get_input_value('_alarm_type', RCUBE_INPUT_POST),
        'calendar_default_alarm_offset' => $default_alam,
      );
      
      // categories
      if (!$this->driver->categoriesimmutable) {
        $old_categories = $new_categories = array();
        foreach ($this->driver->list_categories() as $name => $color) {
          $old_categories[md5($name)] = $name;
        }
        $categories = get_input_value('_categories', RCUBE_INPUT_POST);
        $colors = get_input_value('_colors', RCUBE_INPUT_POST);
        foreach ($categories as $key => $name) {
          $color = preg_replace('/^#/', '', strval($colors[$key]));
        
          // rename categories in existing events -> driver's job
          if ($oldname = $old_categories[$key]) {
            $this->driver->replace_category($oldname, $name, $color);
            unset($old_categories[$key]);
          }
          else
            $this->driver->add_category($name, $color);
        
          $new_categories[$name] = $color;
        }

        // these old categories have been removed, alter events accordingly -> driver's job
        foreach ((array)$old_categories[$key] as $key => $name) {
          $this->driver->remove_category($name);
        }
        
        $p['prefs']['calendar_categories'] = $new_categories;
      }
    }

    return $p;
  }

  /**
   * Dispatcher for event actions initiated by the client
   */
  function event()
  {
    $action = get_input_value('action', RCUBE_INPUT_POST);
    $event = get_input_value('e', RCUBE_INPUT_POST);
    $success = $reload = false;
    
    switch ($action) {
      case "new":
        // create UID for new event
        $events['uid'] = strtoupper(md5(time() . uniqid(rand())) . '-' . substr(md5($this->rc->user->get_username()), 0, 16));
        $success = $this->driver->new_event($event);
        $reload = true;
        break;
      case "edit":
        $success = $this->driver->edit_event($event);
        $reload = true;
        break;
      case "resize":
        $success = $this->driver->resize_event($event);
        $reload = true;
        break;
      case "move":
        $success = $this->driver->move_event($event);
        $reload = true;
        break;
      case "remove":
        $success = $this->driver->remove_event($event);
        $reload = true;
        break;
      case "dismiss":
        foreach (explode(',', $event['id']) as $id)
          $success |= $this->driver->dismiss_alarm($id, $event['snooze']);
        break;
    }
    
    if (!$success) {
      $this->rc->output->show_message('calendar.errorsaving', 'error');
    }
    else if ($reload) {
      $this->rc->output->command('plugin.reload_calendar', array());
    }
  }
  
  /**
   * Handler for load-requests from fullcalendar
   * This will return pure JSON formatted output
   */
  function load_events()
  {
    $events = $this->driver->load_events(get_input_value('start', RCUBE_INPUT_GET), get_input_value('end', RCUBE_INPUT_GET), get_input_value('source', RCUBE_INPUT_GET));
    echo $this->encode($events);
    exit;
  }
  
  /**
   * Handler for keep-alive requests
   * This will check for pending notifications and pass them to the client
   */
  function keep_alive($attr)
  {
    $alarms = $this->driver->pending_alarms(time());
    if ($alarms)
      $this->rc->output->command('plugin.display_alarms', $this->_alarms_output($alarms));
  }
  
  /**
   *
   */
  function export_events()
  {
    $start = get_input_value('start', RCUBE_INPUT_GET);
    $end = get_input_value('end', RCUBE_INPUT_GET);
    if (!$start) $start = mktime(0, 0, 0, 1, date('n'), date('Y')-1);
    if (!$end) $end = mktime(0, 0, 0, 31, 12, date('Y')+10);
    $events = $this->driver->load_events($start, $end, get_input_value('source', RCUBE_INPUT_GET));

    header("Content-Type: text/calendar");
    header("Content-Disposition: inline; filename=calendar.ics");
    
    echo $this->ical->export($events);
    exit;
  }

  /**
   *
   */
  function load_settings()
  {
    $settings = array();
    
    // configuration
    $settings['default_view'] = (string)$this->rc->config->get('calendar_default_view', "agendaWeek");
    $settings['date_format'] = (string)$this->rc->config->get('calendar_date_format', "yyyy/MM/dd");
    $settings['date_short'] = (string)$this->rc->config->get('calendar_date_short', "M/d");
    $settings['time_format'] = (string)$this->rc->config->get('calendar_time_format', "HH:mm");
    $settings['timeslots'] = (int)$this->rc->config->get('calendar_timeslots', 2);
    $settings['first_day'] = (int)$this->rc->config->get('calendar_first_day', 1);
    $settings['first_hour'] = (int)$this->rc->config->get('calendar_first_hour', 6);
    $settings['timezone'] = $this->timezone;

    // localization
    $settings['days'] = array(
      rcube_label('sunday'),   rcube_label('monday'),
      rcube_label('tuesday'),  rcube_label('wednesday'),
      rcube_label('thursday'), rcube_label('friday'),
      rcube_label('saturday')
    );
    $settings['days_short'] = array(
      rcube_label('sun'), rcube_label('mon'),
      rcube_label('tue'), rcube_label('wed'),
      rcube_label('thu'), rcube_label('fri'),
      rcube_label('sat')
    );
    $settings['months'] = array(
      $this->rc->gettext('longjan'), $this->rc->gettext('longfeb'),
      $this->rc->gettext('longmar'), $this->rc->gettext('longapr'),
      $this->rc->gettext('longmay'), $this->rc->gettext('longjun'),
      $this->rc->gettext('longjul'), $this->rc->gettext('longaug'),
      $this->rc->gettext('longsep'), $this->rc->gettext('longoct'),
      $this->rc->gettext('longnov'), $this->rc->gettext('longdec')
    );
    $settings['months_short'] = array(
      $this->rc->gettext('jan'), $this->rc->gettext('feb'),
      $this->rc->gettext('mar'), $this->rc->gettext('apr'),
      $this->rc->gettext('may'), $this->rc->gettext('jun'),
      $this->rc->gettext('jul'), $this->rc->gettext('aug'),
      $this->rc->gettext('sep'), $this->rc->gettext('oct'),
      $this->rc->gettext('nov'), $this->rc->gettext('dec')
    );
    $settings['today'] = rcube_label('today');

    return $settings;
  }

  /**
   * Convert the given time stamp to a GMT date string
   */
  function toGMT($time, $user_tz = true)
  {
    $tz = $user_tz ? $this->gmt_offset : date('Z');
    return date('Y-m-d H:i:s', $time - $tz);
  }

  /**
   * Shift the given time stamo to a GMT time zone
   */
  function toGMTTS($time, $user_tz = true)
  {
    $tz = $user_tz ? $this->gmt_offset : date('Z');
    return $time - $tz;
  }

  /**
   * Convert the given date string into a GMT-based time stamp
   */
  function fromGMT($datetime, $user_tz = true)
  {
    $tz = $user_tz ? $this->gmt_offset : date('Z');
    return strtotime($datetime) + $tz;
  }

  /**
   * Encode events as JSON
   *
   * @param  array  Events as array
   * @return string JSON encoded events
   */
  function encode($events)
  {
    $json = array();
    foreach ($events as $event) {
      // compose a human readable strings for alarms_text and recurrence_text
      if ($event['alarms'])
        $event['alarms_text'] = $this->_alarms_text($event['alarms']);
      if ($event['recurrence'])
        $event['recurrence_text'] = $this->_recurrence_text($event['recurrence']);
      
      $json[] = array(
        'start' => date('c', $event['start']), // ISO 8601 date (added in PHP 5)
        'end'   => date('c', $event['end']), // ISO 8601 date (added in PHP 5)
        'description' => $event['description'],
        'location'    => $event['location'],
        'className'   => 'cat-' . asciiwords($event['categories'], true),
        'allDay'      => ($event['all_day'] == 1)?true:false,
      ) + $event;
    }
    return json_encode($json);
  }


  /**
   * Generate reduced and streamlined output for pending alarms
   */
  private function _alarms_output($alarms)
  {
    $out = array();
    foreach ($alarms as $alarm) {
      $out[] = array(
        'id'       => $alarm['id'],
        'start'    => $alarm['start'],
        'end'      => $alarm['end'],
        'allDay'   => ($event['all_day'] == 1)?true:false,
        'title'    => $alarm['title'],
        'location' => $alarm['location'],
        'calendar' => $alarm['calendar'],
      );
    }
    
    return $out;
  }

  /**
   * Render localized text for alarm settings
   */
  private function _alarms_text($alarm)
  {
    list($trigger, $action) = explode(':', $alarm);
    
    $text = '';
    switch ($action) {
      case 'EMAIL':
        $text = $this->gettext('alarmemail');
        break;
      case 'DISPLAY':
        $text = $this->gettext('alarmdisplay');
        break;
    }
    
    if (preg_match('/@(\d+)/', $trigger, $m)) {
      $text .= ' ' . $this->gettext(array('name' => 'alarmat', 'vars' => array('datetime' => format_date($m[1]))));
    }
    else if ($val = self::parse_alaram_value($trigger)) {
      $text .= ' ' . intval($val[0]) . ' ' . $this->gettext('trigger' . $val[1]);
    }
    else
      return false;
    
    return $text;
  }

  /**
   * Render localized text describing the recurrence rule of an event
   */
  private function _recurrence_text($rrule)
  {
    // TODO: implement this
  }

  /**
   * Helper function to convert alarm trigger strings
   * into two-field values (e.g. "-45M" => 45, "-M")
   */
  public static function parse_alaram_value($val)
  {
    if ($val[0] == '@')
      return array(substr($val, 1));
    else if (preg_match('/([+-])(\d+)([HMD])/', $val, $m))
      return array($m[2], $m[1].$m[3]);
    
    return false;
  }

}
