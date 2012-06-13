<?php

/**
 * Kolab calendar storage class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
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


class kolab_calendar
{
  const COLOR_KEY_SHARED = '/shared/vendor/kolab/color';
  const COLOR_KEY_PRIVATE = '/shared/vendor/kolab/color';
  
  public $id;
  public $ready = false;
  public $readonly = true;
  public $attachments = true;
  public $alarms = false;
  public $categories = array();
  public $storage;

  private $cal;
  private $events = array();
  private $imap_folder = 'INBOX/Calendar';
  private $search_fields = array('title', 'description', 'location', '_attendees');
  private $sensitivity_map = array('public', 'private', 'confidential');


  /**
   * Default constructor
   */
  public function __construct($imap_folder, $calendar)
  {
    $this->cal = $calendar;

    if (strlen($imap_folder))
      $this->imap_folder = $imap_folder;

    // ID is derrived from folder name
    $this->id = kolab_storage::folder_id($this->imap_folder);

    // fetch objects from the given IMAP folder
    $this->storage = kolab_storage::get_folder($this->imap_folder);
    $this->ready = $this->storage && !PEAR::isError($this->storage);

    // Set readonly and alarms flags according to folder permissions
    if ($this->ready) {
      if ($this->get_owner() == $_SESSION['username']) {
        $this->readonly = false;
        $this->alarms = true;
      }
      else {
        $rights = $this->storage->get_myrights();
        if ($rights && !PEAR::isError($rights)) {
          if (strpos($rights, 'i') !== false)
            $this->readonly = false;
        }
      }
      
      // user-specific alarms settings win
      $prefs = $this->cal->rc->config->get('kolab_calendars', array());
      if (isset($prefs[$this->id]['showalarms']))
        $this->alarms = $prefs[$this->id]['showalarms'];
    }
  }


  /**
   * Getter for a nice and human readable name for this calendar
   * See http://wiki.kolab.org/UI-Concepts/Folder-Listing for reference
   *
   * @return string Name of this calendar
   */
  public function get_name()
  {
    $folder = kolab_storage::object_name($this->imap_folder, $this->namespace);
    return $folder;
  }


  /**
   * Getter for the IMAP folder name
   *
   * @return string Name of the IMAP folder
   */
  public function get_realname()
  {
    return $this->imap_folder;
  }


  /**
   * Getter for the IMAP folder owner
   *
   * @return string Name of the folder owner
   */
  public function get_owner()
  {
    return $this->storage->get_owner();
  }


  /**
   * Getter for the name of the namespace to which the IMAP folder belongs
   *
   * @return string Name of the namespace (personal, other, shared)
   */
  public function get_namespace()
  {
    return $this->storage->get_namespace();
  }


  /**
   * Getter for the top-end calendar folder name (not the entire path)
   *
   * @return string Name of this calendar
   */
  public function get_foldername()
  {
    $parts = explode('/', $this->imap_folder);
    return rcube_charset::convert(end($parts), 'UTF7-IMAP');
  }

  /**
   * Return color to display this calendar
   */
  public function get_color()
  {
    // color is defined in folder METADATA
    $metadata = $this->storage->get_metadata(array(self::COLOR_KEY_PRIVATE, self::COLOR_KEY_SHARED));
    if (($color = $metadata[self::COLOR_KEY_PRIVATE]) || ($color = $metadata[self::COLOR_KEY_SHARED])) {
      return $color;
    }

    // calendar color is stored in user prefs (temporary solution)
    $prefs = $this->cal->rc->config->get('kolab_calendars', array());

    if (!empty($prefs[$this->id]) && !empty($prefs[$this->id]['color']))
      return $prefs[$this->id]['color'];

    return 'cc0000';
  }

  /**
   * Return the corresponding kolab_storage_folder instance
   */
  public function get_folder()
  {
    return $this->storage;
  }


  /**
   * Getter for a single event object
   */
  public function get_event($id)
  {
    // directly access storage object
    if (!$this->events[$id] && ($record = $this->storage->get_object($id)))
        $this->events[$id] = $this->_to_rcube_event($record);

    // event not found, maybe a recurring instance is requested
    if (!$this->events[$id]) {
      $master_id = preg_replace('/-\d+$/', '', $id);
      if ($record = $this->storage->get_object($master_id))
        $this->events[$master_id] = $this->_to_rcube_event($record);

      if (($master = $this->events[$master_id]) && $master['recurrence']) {
        $this->_get_recurring_events($master, $master['start'], $master['start'] + 86400 * 365 * 10, $id);
      }
    }

    return $this->events[$id];
  }


  /**
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @param  boolean Include virtual events (optional)
   * @param  array   Additional parameters to query storage
   * @return array A list of event records
   */
  public function list_events($start, $end, $search = null, $virtual = 1, $query = array())
  {
    // query Kolab storage
    $query[] = array('dtstart', '<=', $end);
    $query[] = array('dtend',   '>=', $start);

    if (!empty($search)) {
        $search = mb_strtolower($search);
        foreach (rcube_utils::normalize_string($search, true) as $word) {
            $query[] = array('words', 'LIKE', $word);
        }
    }

    foreach ((array)$this->storage->select($query) as $record) {
      $event = $this->_to_rcube_event($record);
      $this->events[$event['id']] = $event;
    }

    $events = array();
    foreach ($this->events as $id => $event) {
      // remember seen categories
      if ($event['categories'])
        $this->categories[$event['categories']]++;
      
      // filter events by search query
      if (!empty($search)) {
        $hit = false;
        foreach ($this->search_fields as $col) {
          $sval = is_array($col) ? $event[$col[0]][$col[1]] : $event[$col];
          if (empty($sval))
            continue;
          
          // do a simple substring matching (to be improved)
          $val = mb_strtolower($sval);
          if (strpos($val, $search) !== false) {
            $hit = true;
            break;
          }
        }
        
        if (!$hit)  // skip this event if not match with search term
          continue;
      }
      
      // list events in requested time window
      if ($event['start'] <= $end && $event['end'] >= $start) {
        unset($event['_attendees']);
        $events[] = $event;
      }
      
      // resolve recurring events
      if ($event['recurrence'] && $virtual == 1) {
        unset($event['_attendees']);
        $events = array_merge($events, $this->_get_recurring_events($event, $start, $end));
      }
    }

    return $events;
  }


  /**
   * Create a new event record
   *
   * @see calendar_driver::new_event()
   * 
   * @return mixed The created record ID on success, False on error
   */
  public function insert_event($event)
  {
    if (!is_array($event))
      return false;

    //generate new event from RC input
    $object = $this->_from_rcube_event($event);
    $saved = $this->storage->save($object, 'event');
    
    if (!$saved || PEAR::isError($saved)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error saving event object to Kolab server:" . $saved->getMessage()),
        true, false);
      $saved = false;
    }
    else {
      $event['id'] = $event['uid'];
      $this->events[$event['uid']] = $event;
    }
    
    return $saved;
  }

  /**
   * Update a specific event record
   *
   * @see calendar_driver::new_event()
   * @return boolean True on success, False on error
   */

  public function update_event($event)
  {
    $updated = false;
    $old = $this->storage->get_object($event['id']);
    if (!$old || PEAR::isError($old))
      return false;

    $old['recurrence'] = '';  # clear old field, could have been removed in new, too
    $object = $this->_from_rcube_event($event, $old);
    $saved = $this->storage->save($object, 'event', $event['id']);

    if (!$saved || PEAR::isError($saved)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error saving event object to Kolab server:" . $saved->getMessage()),
        true, false);
    }
    else {
      $updated = true;
      $this->events[$event['id']] = $this->_to_rcube_event($object);
    }

    return $updated;
  }

  /**
   * Delete an event record
   *
   * @see calendar_driver::remove_event()
   * @return boolean True on success, False on error
   */
  public function delete_event($event, $force = true)
  {
    $deleted = $this->storage->delete($event['id'], $force);

    if (!$deleted || PEAR::isError($deleted)) {
      raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Error deleting event object from Kolab server"),
        true, false);
    }

    return $deleted;
  }

  /**
   * Restore deleted event record
   *
   * @see calendar_driver::undelete_event()
   * @return boolean True on success, False on error
   */
  public function restore_event($event)
  {
    if ($this->storage->undelete($event['id'])) {
        return true;
    }
    else {
        raise_error(array(
          'code' => 600, 'type' => 'php',
          'file' => __FILE__, 'line' => __LINE__,
          'message' => "Error undeleting a contact object $uid from the Kolab server"),
        true, false);
    }

    return false;
  }


  /**
   * Create instances of a recurring event
   */
  public function _get_recurring_events($event, $start, $end, $event_id = null)
  {
    // include library class
    require_once($this->cal->home . '/lib/calendar_recurrence.php');
    
    $recurrence = new calendar_recurrence($this->cal, $event);
    
    $events = array();
    $duration = $event['end'] - $event['start'];
    $i = 0;
    while ($rec_start = $recurrence->next_start()) {
      $rec_end = $rec_start + $duration;
      $rec_id = $event['id'] . '-' . ++$i;
      
      // add to output if in range
      if (($rec_start <= $end && $rec_end >= $start) || ($event_id && $rec_id == $event_id)) {
        $rec_event = $event;
        $rec_event['id'] = $rec_id;
        $rec_event['recurrence_id'] = $event['id'];
        $rec_event['start'] = $rec_start;
        $rec_event['end'] = $rec_end;
        $rec_event['_instance'] = $i;
        $events[] = $rec_event;
        
        if ($rec_id == $event_id) {
          $this->events[$rec_id] = $rec_event;
          break;
        }
      }
      else if ($rec_start > $end)  // stop loop if out of range
        break;
    }
    
    return $events;
  }

  /**
   * Convert from Kolab_Format to internal representation
   */
  private function _to_rcube_event($record)
  {
    $record['id'] = $record['uid'];
    $record['calendar'] = $this->id;

    // convert from DateTime to unix timestamp
    if (is_a($record['start'], 'DateTime'))
      $record['start'] = $record['start']->format('U');
    if (is_a($record['end'], 'DateTime'))
      $record['end'] = $record['end']->format('U');

    // all-day events go from 12:00 - 13:00
    if ($record['end'] <= $record['start'] && $record['allday'])
      $record['end'] = $record['start'] + 3600;

    if (!empty($record['_attachments'])) {
      foreach ($record['_attachments'] as $key => $attachment) {
        if ($attachment !== false) {
          if (!$attachment['name'])
            $attachment['name'] = $key;
          $attachments[] = $attachment;
        }
      }

      $record['attachments'] = $attachments;
    }

    $sensitivity_map = array_flip($this->sensitivity_map);
    $record['sensitivity'] = intval($sensitivity_map[$record['sensitivity']]);

    // Roundcube only supports one category assignment
    if (is_array($record['categories']))
      $record['categories'] = $record['categories'][0];

    // remove internals
    unset($record['_mailbox'], $record['_msguid'], $record['_formatobj'], $record['_attachments']);

    return $record;
  }

   /**
   * Convert the given event record into a data structure that can be passed to Kolab_Storage backend for saving
   * (opposite of self::_to_rcube_event())
   */
  private function _from_rcube_event($event, $old = array())
  {
    $object = &$event;

    // in kolab_storage attachments are indexed by content-id
    $object['_attachments'] = array();
    if (is_array($event['attachments'])) {
      $collisions = array();
      foreach ($event['attachments'] as $idx => $attachment) {
        $key = null;
        // Roundcube ID has nothing to do with the storage ID, remove it
        if ($attachment['content']) {
          unset($attachment['id']);
        }
        else {
          foreach ((array)$old['_attachments'] as $cid => $oldatt) {
            if ($attachment['id'] == $oldatt['id'])
              $key = $cid;
          }
        }

        // flagged for deletion => set to false
        if ($attachment['_deleted']) {
          $object['_attachments'][$key] = false;
        }
        // replace existing entry
        else if ($key) {
          $object['_attachments'][$key] = $attachment;
        }
        // append as new attachment
        else {
          $object['_attachments'][] = $attachment;
        }
      }

      unset($event['attachments']);
    }

    // translate sensitivity property
    $event['sensitivity'] = $this->sensitivity_map[$event['sensitivity']];

    // set current user as ORGANIZER
    $identity = $this->cal->rc->user->get_identity();
    if (empty($event['attendees']) && $identity['email'])
      $event['attendees'] = array(array('role' => 'ORGANIZER', 'name' => $identity['name'], 'email' => $identity['email']));

    $event['_owner'] = $identity['email'];

    // copy meta data (starting with _) from old object
    foreach ((array)$old as $key => $val) {
      if (!isset($event[$key]) && $key[0] == '_')
        $event[$key] = $val;
    }

    return $event;
  }


}
