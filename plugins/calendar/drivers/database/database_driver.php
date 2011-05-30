<?php
/*
 +-------------------------------------------------------------------------+
 | Database driver for the Calendar Plugin                                 |
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

class database_driver extends calendar_driver
{
  // features this backend supports
  public $alarms = true;
  public $attendees = true;
  public $attachments = true;
  public $alarm_types = array('DISPLAY','EMAIL');

  private $rc;
  private $cal;
  private $calendars = array();
  private $calendar_ids = '';
  private $free_busy_map = array('free' => 0, 'busy' => 1, 'out-of-office' => 2, 'outofoffice' => 2);
  
  private $db_events = 'events';
  private $db_calendars = 'calendars';
  private $db_attachments = 'attachments';
  private $sequence_events = 'event_ids';
  private $sequence_calendars = 'calendar_ids';
  private $sequence_attachments = 'attachment_ids';


  /**
   * Default constructor
   */
  public function __construct($cal)
  {
    $this->cal = $cal;
    $this->rc = $cal->rc;
    
    // load library classes
    require_once($this->cal->home . '/lib/Horde_Date_Recurrence.php');
    
    // read database config
    $this->db_events = $this->rc->config->get('db_table_events', $this->db_events);
    $this->db_calendars = $this->rc->config->get('db_table_calendars', $this->db_calendars);
    $this->db_attachments = $this->rc->config->get('db_table_attachments', $this->db_attachments);
    $this->sequence_events = $this->rc->config->get('db_sequence_events', $this->sequence_events);
    $this->sequence_calendars = $this->rc->config->get('db_sequence_calendars', $this->sequence_calendars);
    $this->sequence_attachments = $this->rc->config->get('db_sequence_attachments', $this->sequence_attachments);
    
    $this->_read_calendars();
  }

  /**
   * Read available calendars for the current user and store them internally
   */
  private function _read_calendars()
  {
    if (!empty($this->rc->user->ID)) {
      $calendar_ids = array();
      $result = $this->rc->db->query(
        "SELECT * FROM " . $this->db_calendars . "
         WHERE user_id=?",
         $this->rc->user->ID
      );
      while ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
        $this->calendars[$arr['calendar_id']] = $arr;
        $calendar_ids[] = $this->rc->db->quote($arr['calendar_id']);
      }
      $this->calendar_ids = join(',', $calendar_ids);
    }
  }

  /**
   * Get a list of available calendars from this source
   */
  public function list_calendars()
  {
    // attempt to create a default calendar for this user
    if (empty($this->calendars)) {
      if ($this->create_calendar(array('name' => 'Default', 'color' => 'cc0000')))
        $this->_read_calendars();
    }
    
    return $this->calendars;
  }
  
  /**
   * Create a new calendar assigned to the current user
   *
   * @param array Hash array with calendar properties
   *    name: Calendar name
   *   color: The color of the calendar
   * @return mixed ID of the calendar on success, False on error
   */
  public function create_calendar($prop)
  {
    $result = $this->rc->db->query(
      "INSERT INTO " . $this->db_calendars . "
       (user_id, name, color)
       VALUES (?, ?, ?)",
       $this->rc->user->ID,
       $prop['name'],
       $prop['color']
    );
    
    if ($result)
      return $this->rc->db->insert_id($this->$sequence_calendars);
    
    return false;
  }

  /**
   * Add a single event to the database
   *
   * @param array Hash array with event properties
   * @see Driver:new_event()
   */
  public function new_event($event)
  {
    if (!empty($this->calendars)) {
      if ($event['calendar'] && !$this->calendars[$event['calendar']])
        return false;
      if (!$event['calendar'])
        $event['calendar'] = reset(array_keys($this->calendars));
      
      $event = $this->_save_preprocess($event);
      $query = $this->rc->db->query(sprintf(
        "INSERT INTO " . $this->db_events . "
         (calendar_id, created, changed, uid, start, end, all_day, recurrence, title, description, location, categories, free_busy, priority, sensitivity, alarms, notifyat)
         VALUES (?, %s, %s, ?, %s, %s, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          $this->rc->db->now(),
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['calendar'],
        strval($event['uid']),
        intval($event['allday']),
        $event['recurrence'],
        strval($event['title']),
        strval($event['description']),
        strval($event['location']),
        strval($event['categories']),
        intval($event['free_busy']),
        intval($event['priority']),
        intval($event['sensitivity']),
        $event['alarms'],
        $event['notifyat']
      );
      
      if ($success = $this->rc->db->insert_id($this->sequence_events))
        $this->_update_recurring($event);
      
      return $success;
    }
    
    return false;
  }

  /**
   * Update an event entry with the given data
   *
   * @param array Hash array with event properties
   * @see Driver:new_event()
   */
  public function edit_event($event)
  {
    if (!empty($this->calendars)) {
      $event = $this->_save_preprocess($event);
      $query = $this->rc->db->query(sprintf(
        "UPDATE " . $this->db_events . "
         SET   changed=%s, start=%s, end=%s, all_day=?, recurrence=?, title=?, description=?, location=?, categories=?, free_busy=?, priority=?, sensitivity=?, alarms=?, notifyat=?
         WHERE event_id=?
         AND   calendar_id IN (" . $this->calendar_ids . ")",
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        intval($event['allday']),
        $event['recurrence'],
        strval($event['title']),
        strval($event['description']),
        strval($event['location']),
        strval($event['categories']),
        intval($event['free_busy']),
        intval($event['sensitivity']),
        intval($event['priority']),
        $event['alarms'],
        $event['notifyat'],
        $event['id']
      );
      
      if ($success = $this->rc->db->affected_rows($query))
        $this->_update_recurring($event);
      
      return $success;
    }
    
    return false;
  }

  /**
   * Convert save data to be used in SQL statements
   */
  private function _save_preprocess($event)
  {
    // compose vcalendar-style recurrencue rule from structured data
    $rrule = $event['recurrence'] ? calendar::to_rrule($event['recurrence']) : '';
    $event['recurrence'] = rtrim($rrule, ';');
    $event['free_busy'] = intval($this->free_busy_map[strtolower($event['free_busy'])]);
    $event['allday'] = $event['allday'] ? 1 : 0;
    
    // compute absolute time to notify the user
    $event['notifyat'] = $this->_get_notification($event);
    
    return $event;
  }

  /**
   * Compute absolute time to notify the user
   */
  private function _get_notification($event)
  {
    if ($event['alarms']) {
      list($trigger, $action) = explode(':', $event['alarms']);
      $notify = calendar::parse_alaram_value($trigger);
      if (!empty($notify[1])){  // offset
        $mult = 1;
        switch ($notify[1]) {
          case '-M': $mult =    -60; break;
          case '+M': $mult =     60; break;
          case '-H': $mult =  -3600; break;
          case '+H': $mult =   3600; break;
          case '-D': $mult = -86400; break;
          case '+D': $mult =  86400; break;
        }
        $offset = $notify[0] * $mult;
        $refdate = $mult > 0 ? $event['end'] : $event['start'];
        $notify_at = $refdate + $offset;
      }
      else {  // absolute timestamp
        $notify_at = $notify[0];
      }
      
      if ($notify_at > time())
        return date('Y-m-d H:i:s', $notify_at);
    }
    
    return null;
  }

  /**
   * Insert "fake" entries for recurring occurences of this event
   */
  private function _update_recurring($event)
  {
    if (empty($this->calendars))
      return;
    
    // clear existing recurrence copies
    $this->rc->db->query(
      "DELETE FROM " . $this->db_events . "
       WHERE recurrence_id=?
       AND calendar_id IN (" . $this->calendar_ids . ")",
       $event['id']
    );
    
    // create new fake entries
    if ($event['recurrence']) {
      // TODO: replace Horde classes with something that has less than 6'000 lines of code
      $recurrence = new Horde_Date_Recurrence($event['start']);
      $recurrence->fromRRule20($event['recurrence']);
      
      $duration = $event['end'] - $event['start'];
      $next = new Horde_Date($event['start']);
      while ($next = $recurrence->nextActiveRecurrence(array('year' => $next->year, 'month' => $next->month, 'mday' => $next->mday + 1, 'hour' => $next->hour, 'min' => $next->min, 'sec' => $next->sec))) {
        $next_ts = $next->timestamp();
        $notify_at = $this->_get_notification(array('alarms' => $event['alarms'], 'start' => $next_ts, 'end' => $next_ts + $duration));
        $query = $this->rc->db->query(sprintf(
          "INSERT INTO " . $this->db_events . "
           (calendar_id, recurrence_id, created, changed, uid, start, end, all_day, recurrence, title, description, location, categories, free_busy, priority, sensitivity, alarms, notifyat)
            SELECT calendar_id, ?, %s, %s, uid, %s, %s, all_day, recurrence, title, description, location, categories, free_busy, priority, sensitivity, alarms, ?
            FROM  " . $this->db_events . " WHERE event_id=? AND calendar_id IN (" . $this->calendar_ids . ")",
            $this->rc->db->now(),
            $this->rc->db->now(),
            $this->rc->db->fromunixtime($next_ts),
            $this->rc->db->fromunixtime($next_ts + $duration)
          ),
          $event['id'],
          $notify_at,
          $event['id']
        );
        
        if (!$this->rc->db->affected_rows($query))
          break;
        
        // stop adding events for inifinite recurrence after 20 years
        if (++$count > 999 || (!$recurrence->recurEnd && !$recurrence->recurCount && $next->year > date('Y') + 20))
          break;
      }
    }
    
  }

  /**
   * Move a single event
   *
   * @param array Hash array with event properties
   * @see Driver:move_event()
   */
  public function move_event($event)
  {
    if (!empty($this->calendars)) {
      $event = $this->_save_preprocess($event + (array)$this->get_event($event['id']));
      $query = $this->rc->db->query(sprintf(
        "UPDATE " . $this->db_events . "
         SET   changed=%s, start=%s, end=%s, all_day=?, notifyat=?
         WHERE event_id=?
         AND calendar_id IN (" . $this->calendar_ids . ")",
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['allday'] ? 1 : 0,
        $event['notifyat'],
        $event['id']
      );
      
      if ($success = $this->rc->db->affected_rows($query))
        $this->_update_recurring($event);
      
      return $success;
    }
    
    return false;
  }

  /**
   * Resize a single event
   *
   * @param array Hash array with event properties
   * @see Driver:resize_event()
   */
  public function resize_event($event)
  {
    if (!empty($this->calendars)) {
      $event = $this->_save_preprocess($event + (array)$this->get_event($event['id']));
      $query = $this->rc->db->query(sprintf(
        "UPDATE " . $this->db_events . "
         SET   changed=%s, start=%s, end=%s, notifyat=?
         WHERE event_id=?
         AND calendar_id IN (" . $this->calendar_ids . ")",
          $this->rc->db->now(),
          $this->rc->db->fromunixtime($event['start']),
          $this->rc->db->fromunixtime($event['end'])
        ),
        $event['notifyat'],
        $event['id']
      );
      
      if ($success = $this->rc->db->affected_rows($query))
        $this->_update_recurring($event);
      
      return $success;
    }
    
    return false;
  }

  /**
   * Remove a single event from the database
   *
   * @param array Hash array with event properties
   * @see Driver:remove_event()
   */
  public function remove_event($event)
  {
    if (!empty($this->calendars)) {
      $query = $this->rc->db->query(
        "DELETE FROM " . $this->db_events . "
         WHERE (event_id=? OR recurrence_id=?)
         AND calendar_id IN (" . $this->calendar_ids . ")",
         $event['id'],
         $event['id']
      );
      return $this->rc->db->affected_rows($query);
    }
    
    return false;
  }

  /**
   * Return data of a specific event
   * @param string Event ID
   * @return array Hash array with event properties
   */
  public function get_event($id)
  {
    $result = $this->rc->db->query(sprintf(
      "SELECT * FROM " . $this->db_events . "
       WHERE calendar_id IN (%s)
       AND event_id=?",
       $this->calendar_ids
      ),
      $id);

    if ($result && ($event = $this->rc->db->fetch_assoc($result)))
      return $this->_read_postprocess($event);

    return false;
  }

  /**
   * Get event data
   *
   * @see Driver:load_events()
   */
  public function load_events($start, $end, $calendars = null)
  {
    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);
    
    // only allow to select from calendars of this use
    $calendar_ids = array_intersect($calendars, array_keys($this->calendars));
    array_walk($calendar_ids, array($this->rc->db, 'quote'));
    
    $events = array();
    if (!empty($calendar_ids)) {
      $result = $this->rc->db->query(sprintf(
        "SELECT * FROM " . $this->db_events . "
         WHERE calendar_id IN (%s)
         AND start <= %s AND end >= %s",
         join(',', $calendar_ids),
         $this->rc->db->fromunixtime($end),
         $this->rc->db->fromunixtime($start)
       ));

      while ($result && ($event = $this->rc->db->fetch_assoc($result))) {
        $events[] = $this->_read_postprocess($event);
      }
    }
    
    return $events;
  }

  /**
   * Convert sql record into a rcube style event object
   */
  private function _read_postprocess($event)
  {
    $free_busy_map = array_flip($this->free_busy_map);
    
    $event['id'] = $event['event_id'];
    $event['start'] = strtotime($event['start']);
    $event['end'] = strtotime($event['end']);
    $event['free_busy'] = $free_busy_map[$event['free_busy']];
    $event['calendar'] = $event['calendar_id'];
    
    // parse recurrence rule
    if ($event['recurrence'] && preg_match_all('/([A-Z]+)=([^;]+);?/', $event['recurrence'], $m, PREG_SET_ORDER)) {
      $event['recurrence'] = array();
      foreach ($m as $rr) {
        if (is_numeric($rr[2]))
          $rr[2] = intval($rr[2]);
        else if ($rr[1] == 'UNTIL')
          $rr[2] = strtotime($rr[2]);
        $event['recurrence'][$rr[1]] = $rr[2];
      }
    }
    
    unset($event['event_id'], $event['calendar_id']);
    return $event;
  }

  /**
   * Search events
   *
   * @see Driver:search_events()
   */
  public function search_events($start, $end, $query, $calendars = null)
  {
    
  }

  /**
   * Get a list of pending alarms to be displayed to the user
   *
   * @see Driver:pending_alarms()
   */
  public function pending_alarms($time, $calendars = null)
  {
    if (empty($calendars))
      $calendars = array_keys($this->calendars);
    else if (is_string($calendars))
      $calendars = explode(',', $calendars);
    
    // only allow to select from calendars of this use
    $calendar_ids = array_intersect($calendars, array_keys($this->calendars));
    array_walk($calendar_ids, array($this->rc->db, 'quote'));
    
    $alarms = array();
    if (!empty($calendar_ids)) {
      $result = $this->rc->db->query(sprintf(
        "SELECT * FROM " . $this->db_events . "
         WHERE calendar_id IN (%s)
         AND notifyat <= %s",
         join(',', $calendar_ids),
         $this->rc->db->fromunixtime($time)
       ));

      while ($result && ($event = $this->rc->db->fetch_assoc($result)))
        $alarms[] = $this->_read_postprocess($event);
    }

    return $alarms;
  }

  /**
   * Feedback after showing/sending an alarm notification
   *
   * @see Driver:dismiss_alarm()
   */
  public function dismiss_alarm($event_id, $snooze = 0)
  {
    // set new notifyat time or unset if not snoozed
    $notify_at = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;
    
    $query = $this->rc->db->query(sprintf(
      "UPDATE " . $this->db_events . "
       SET   changed=%s, notifyat=?
       WHERE event_id=?
       AND calendar_id IN (" . $this->calendar_ids . ")",
        $this->rc->db->now()),
      $notify_at,
      $event_id
    );
    
    return $this->rc->db->affected_rows($query);
  }

  /**
   * Save an attachment related to the given event
   */
  public function add_attachment($attachment, $event_id)
  {
    // TBD.
    return false;
  }

  /**
   * Remove a specific attachment from the given event
   */
  public function remove_attachment($attachment, $event_id)
  {
    // TBD.
    return false;
  }

  /**
   * Remove the given category
   */
  public function remove_category($name)
  {
    // TBD. alter events accordingly
    return false;
  }

  /**
   * Update/replace a category
   */
  public function replace_category($oldname, $name, $color)
  {
    // TBD. alter events accordingly
    return false;
  }

}
