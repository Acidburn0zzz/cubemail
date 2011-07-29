<?php
/*
 +-------------------------------------------------------------------------+
 | iCalendar functions for the Calendar Plugin                             |
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
 |         Bogomil "Bogo" Shopov <shopov@kolabsys.com>                     |
 +-------------------------------------------------------------------------+
*/


/**
 * Class to parse and build vCalendar (iCalendar) files
 *
 * Uses the Horde:iCalendar class for parsing. To install:
 * > pear channel-discover pear.horde.org
 * > pear install horde/Horde_Icalendar
 *
 */
class calendar_ical
{
  const EOL = "\r\n";
  
  private $rc;
  private $cal;


  function __construct($cal)
  {
    $this->cal = $cal;
    $this->rc = $cal->rc;
  }

  /**
   * Import events from iCalendar format
   *
   * @param  string vCalendar input
   * @return array List of events extracted from the input
   */
  public function import($vcal)
  {
    // use Horde:iCalendar to parse vcalendar file format
    require_once 'Horde/iCalendar.php';
    
    $parser = new Horde_iCalendar;
    $parser->parsevCalendar($vcal);
    $events = array();
    if ($data = $parser->getComponents()) {
      foreach ($data as $comp) {
        if ($comp->getType() == 'vEvent')
          $events[] = $this->_to_rcube_format($comp);
      }
    }
    
    return $events;
  }

  /**
   * Convert the given File_IMC_Parse_Vcalendar_Event object to the internal event format
   */
  private function _to_rcube_format($ve)
  {
    $event = array(
      'uid' => $ve->getAttributeDefault('UID'),
      'changed' => $ve->getAttributeDefault('DTSTAMP', 0),
      'title' => $ve->getAttributeDefault('SUMMARY'),
      'start' => $ve->getAttribute('DTSTART'),
      'end' => $ve->getAttribute('DTEND'),
      // set defaults
      'free_busy' => 'busy',
      'priority' => 1,
    );
    
    // check for all-day dates
    if (is_array($event['start'])) {
      $event['start'] = gmmktime(0, 0, 0, $event['start']['month'], $event['start']['mday'], $event['start']['year']) + $this->cal->gmt_offset;
      $event['allday'] = true;
    }
    if (is_array($event['end'])) {
      $event['end'] = gmmktime(0, 0, 0, $event['end']['month'], $event['end']['mday'], $event['end']['year']) + $this->cal->gmt_offset - 60;
    }
    
    // map other attributes to internal fields
    $_attendees = array();
    foreach ($ve->getAllAttributes() as $attr) {
      switch ($attr['name']) {
        case 'ORGANIZER':
          $organizer = array(
            'name' => $attr['params']['CN'],
            'email' => preg_replace('/^mailto:/', '', $attr['value']),
            'role' => 'ORGANIZER',
            'status' => 'ACCEPTED',
          );
          if (isset($_attendees[$organizer['email']])) {
            $i = $_attendees[$organizer['email']];
            $event['attendees'][$i]['role'] = $organizer['role'];
          }
          break;
        
        case 'ATTENDEE':
          $attendee = array(
            'name' => $attr['params']['CN'],
            'email' => preg_replace('/^mailto:/', '', $attr['value']),
            'role' => $attr['params']['ROLE'] ? $attr['params']['ROLE'] : 'REQ-PARTICIPANT',
            'status' => $attr['params']['PARTSTAT'],
            'rsvp' => $attr['params']['RSVP'] == 'TRUE',
          );
          if ($organizer && $organizer['email'] == $attendee['email'])
            $attendee['role'] = 'ORGANIZER';
          
          $event['attendees'][] = $attendee;
          $_attendees[$attendee['email']] = count($event['attendees']) - 1;
          break;
          
        case 'TRANSP':
          $event['free_busy'] = $attr['value'] == 'TRANSPARENT' ? 'free' : 'busy';
          break;
        
        case 'STATUS':
          if ($attr['value'] == 'TENTATIVE')
            $event['free_busy'] == 'tentative';
          break;
        
        case 'PRIORITY':
          if (is_numeric($attr['value'])) {
            $event['priority'] = $attr['value'] <= 4 ? 2 /* high */ :
              ($attr['value'] == 5 ? 1 /* normal */ : 0 /* low */);
          }
          break;
        
        case 'RRULE':
          // parse recurrence rule attributes
          foreach (explode(';', $attr['value']) as $par) {
            list($k, $v) = explode('=', $par);
            $params[$k] = $v;
          }
          if ($params['UNTIL'])
            $params['UNTIL'] = $ve->_parseDateTime($params['UNTIL']);
          if (!$params['INTERVAL'])
            $params['INTERVAL'] = 1;
          
          $event['recurrence'] = $params;
          break;
        
        case 'EXDATE':
          break;
        
        case 'DESCRIPTION':
        case 'LOCATION':
          $event[strtolower($attr['name'])] = $attr['value'];
          break;
        
        case 'CLASS':
        case 'X-CALENDARSERVER-ACCESS':
          $sensitivity_map = array('PUBLIC' => 0, 'PRIVATE' => 1, 'CONFIDENTIAL' => 2);
          $event['sensitivity'] = $sensitivity_map[$attr['value']];
          break;

        case 'X-MICROSOFT-CDO-BUSYSTATUS':
          if ($attr['value'] == 'OOF')
            $event['free_busy'] == 'outofoffice';
          else if (in_array($attr['value'], array('FREE', 'BUSY', 'TENTATIVE')))
            $event['free_busy'] = strtolower($attr['value']);
          break;
      }
    }
    
    // add organizer to attendees list if not already present
    if ($organizer && !isset($_attendees[$organizer['email']]))
      array_unshift($event['attendees'], $organizer);

    // make sure the event has an UID
    if (!$event['uid'])
      $event['uid'] = $this->cal->$this->generate_uid();
    
    return $event;
  }


  /**
   * Export events to iCalendar format
   *
   * @param  array   Events as array
   * @param  string  VCalendar method to advertise
   * @param  boolean Directly send data to stdout instead of returning
   * @return string  Events in iCalendar format (http://tools.ietf.org/html/rfc5545)
   */
  public function export($events, $method = null, $write = false)
  {
    if (!empty($this->rc->user->ID)) {
      $ical = "BEGIN:VCALENDAR" . self::EOL;
      $ical .= "VERSION:2.0" . self::EOL;
      $ical .= "PRODID:-//Roundcube Webmail " . RCMAIL_VERSION . "//NONSGML Calendar//EN" . self::EOL;
      $ical .= "CALSCALE:GREGORIAN" . self::EOL;
      
      if ($method)
        $ical .= "METHOD:" . strtoupper($method) . self::EOL;
        
      if ($write) {
        echo $ical;
        $ical = '';
      }
      
      foreach ($events as $event) {
        $vevent = "BEGIN:VEVENT" . self::EOL;
        $vevent .= "UID:" . self::escpape($event['uid']) . self::EOL;
        $vevent .= "DTSTAMP:" . gmdate('Ymd\THis\Z', $event['changed'] ? $event['changed'] : time()) . self::EOL;
        // correctly set all-day dates
        if ($event['allday']) {
          $vevent .= "DTSTART;VALUE=DATE:" . gmdate('Ymd', $event['start'] + $this->cal->gmt_offset) . self::EOL;
          $vevent .= "DTEND;VALUE=DATE:" . gmdate('Ymd', $event['end'] + $this->cal->gmt_offset + 60) . self::EOL;  // ends the next day
        }
        else {
          $vevent .= "DTSTART:" . gmdate('Ymd\THis\Z', $event['start']) . self::EOL;
          $vevent .= "DTEND:" . gmdate('Ymd\THis\Z', $event['end']) . self::EOL;
        }
        $vevent .= "SUMMARY:" . self::escpape($event['title']) . self::EOL;
        $vevent .= "DESCRIPTION:" . self::escpape($event['description']) . self::EOL;
        
        if (!empty($event['attendees'])){
          $vevent .= $this->_get_attendees($event['attendees']);
        }

        if (!empty($event['location'])) {
          $vevent .= "LOCATION:" . self::escpape($event['location']) . self::EOL;
        }
        if ($event['recurrence']) {
          $vevent .= "RRULE:" . calendar::to_rrule($event['recurrence'], self::EOL) . self::EOL;
        }
        if(!empty($event['categories'])) {
          $vevent .= "CATEGORIES:" . self::escpape(strtoupper($event['categories'])) . self::EOL;
        }
        if ($event['sensitivity'] > 0) {
          $vevent .= "CLASS:" . ($event['sensitivity'] == 2 ? 'CONFIDENTIAL' : 'PRIVATE') . self::EOL;
        }
        if ($event['alarms']) {
          list($trigger, $action) = explode(':', $event['alarms']);
          $val = calendar::parse_alaram_value($trigger);
          
          $vevent .= "BEGIN:VALARM\n";
          if ($val[1]) $vevent .= "TRIGGER:" . preg_replace('/^([-+])(.+)/', '\\1PT\\2', $trigger) . self::EOL;
          else         $vevent .= "TRIGGER;VALUE=DATE-TIME:" . gmdate('Ymd\THis\Z', $val[0]) . self::EOL;
          if ($action) $vevent .= "ACTION:" . self::escpape(strtoupper($action)) . self::EOL;
          $vevent .= "END:VALARM\n";
        }
        
        $vevent .= "TRANSP:" . ($event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE') . self::EOL;
        
        if ($event['cancelled'])
          $vevent .= "STATUS:CANCELLED" . self::EOL;
        else if ($event['free_busy'] == 'tentative')
          $vevent .= "STATUS:TENTATIVE" . self::EOL;
        
        // TODO: export attachments
        
        $vevent .= "END:VEVENT" . self::EOL;
        
        if ($write)
          echo rcube_vcard::rfc2425_fold($vevent);
        else
          $ical .= $vevent;
      }
      
      $ical .= "END:VCALENDAR" . self::EOL;
      
      if ($write) {
        echo $ical;
        return true;
      }

      // fold lines to 75 chars
      return rcube_vcard::rfc2425_fold($ical);
    }
  }
  
  private function escpape($str)
  {
    return preg_replace('/(?<!\\\\)([\:\;\,\\n\\r])/', '\\\$1', $str);
  }

  /**
  * Construct the orginizer of the event.
  * @param Array Attendees and roles
  *
  */
  private function _get_attendees($ats)
  {
    $organizer = "";
    $attendees = "";
    foreach ($ats as $at) {
      if ($at['role']=="ORGANIZER") {
        //I am an orginizer
        $organizer .= "ORGANIZER;";
        if (!empty($at['name']))
          $organizer .= 'CN="' . $at['name'] . '"';
        $organizer .= ":mailto:". $at['email'] . self::EOL;
      }
      else {
        //I am an attendee 
        $attendees .= "ATTENDEE;ROLE=" . $at['role'] . ";PARTSTAT=" . $at['status'];
        if (!empty($at['name']))
          $attendees .= ';CN="' . $at['name'] . '"';
        $attendees .= ":mailto:" . $at['email'] . self::EOL;
      }
    }

    return $organizer . $attendees;
  }

}