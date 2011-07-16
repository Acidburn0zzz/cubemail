/*
 +-------------------------------------------------------------------------+
 | Javascript for the Calendar Plugin                                      |
 | Version 0.3 beta                                                        |
 |                                                                         |
 | This program is free software; you can redistribute it and/or modify    |
 | it under the terms of the GNU General Public License version 2          |
 | as published by the Free Software Foundation.                           |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Lazlo Westerhof <hello@lazlo.me>                                |
 |         Thomas Bruederli <roundcube@gmail.com>                          |
 +-------------------------------------------------------------------------+
*/

/* calendar plugin printing code */
window.rcmail && rcmail.addEventListener('init', function(evt) {

  // quote html entities
  var Q = function(str)
  {
    return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  };
  
  var rc_loading;
  var showdesc = true;
  var settings = rcmail.env.calendar_settings;
  
  // create list of event sources AKA calendars
  var src, event_sources = [];
  var add_url = (rcmail.env.search ? '&q='+escape(rcmail.env.search) : '');
  for (var id in rcmail.env.calendars) {
    source = $.extend({
      url: "./?_task=calendar&_action=load_events&source=" + escape(id) + add_url,
      className: 'fc-event-cal-'+id,
      id: id
    }, rcmail.env.calendars[id]);

    event_sources.push(source);
  }
  
  var viewdate = new Date();
  if (rcmail.env.date)
    viewdate.setTime(rcmail.env.date * 1000);

  // initalize the fullCalendar plugin
  var fc = $('#calendar').fullCalendar({
    header: {
      left: '',
      center: 'title',
      right: 'agendaDay,agendaWeek,month,table'
    },
    aspectRatio: 0.85,
    ignoreTimezone: true,  // will treat the given date strings as in local (browser's) timezone
    date: viewdate.getDate(),
    month: viewdate.getMonth(),
    year: viewdate.getFullYear(),
    defaultView: rcmail.env.view,
    eventSources: event_sources,
    monthNames : settings['months'],
    monthNamesShort : settings['months_short'],
    dayNames : settings['days'],
    dayNamesShort : settings['days_short'],
    firstDay : settings['first_day'],
    firstHour : settings['first_hour'],
    slotMinutes : 60/settings['timeslots'],
    timeFormat: {
      '': settings['time_format'],
      agenda: settings['time_format'] + '{ - ' + settings['time_format'] + '}',
      list: settings['time_format'] + '{ - ' + settings['time_format'] + '}',
      table: settings['time_format'] + '{ - ' + settings['time_format'] + '}'
    },
    axisFormat : settings['time_format'],
    columnFormat: {
      month: 'ddd', // Mon
      week: 'ddd ' + settings['date_short'], // Mon 9/7
      day: 'dddd ' + settings['date_short'],  // Monday 9/7
      list: settings['date_agenda'],
      table: settings['date_agenda']
    },
    titleFormat: {
      month: 'MMMM yyyy',
      week: settings['date_long'].replace(/ yyyy/, '[ yyyy]') + "{ '&mdash;' " + settings['date_long'] + "}",
      day: 'dddd ' + settings['date_long'],
      list: settings['date_long'],
      table: settings['date_long']
    },
    listSections: 'smart',
    listRange: 60,  // show 60 days in list view
    tableCols: ['handle', 'date', 'time', 'title', 'location'],
    allDayText: rcmail.gettext('all-day', 'calendar'),
    buttonText: {
      day: rcmail.gettext('day', 'calendar'),
      week: rcmail.gettext('week', 'calendar'),
      month: rcmail.gettext('month', 'calendar'),
      table: rcmail.gettext('agenda', 'calendar')
    },
    loading: function(isLoading) {
      rc_loading = rcmail.set_busy(isLoading, 'loading', rc_loading);
    },
    // event rendering
    eventRender: function(event, element, view) {
      if (view.name != 'month') {
        var cont = element.find('div.fc-event-title');
        if (event.location) {
          cont.after('<div class="fc-event-location">@&nbsp;' + Q(event.location) + '</div>');
          cont = cont.next();
        }
        if (event.description && showdesc) {
          cont.after('<div class="fc-event-description">' + Q(event.description) + '</div>');
        }
/* TODO: create icons black on white
        if (event.recurrence)
          element.find('div.fc-event-time').append('<i class="fc-icon-recurring"></i>');
        if (event.alarms)
          element.find('div.fc-event-time').append('<i class="fc-icon-alarms"></i>');
*/
      }
      if (view.name == 'table' && event.description && showdesc) {
        var cols = element.children().css('border', 0).length;
        element.after('<tr class="fc-event-row-secondary fc-event"><td colspan="'+cols+'" class="fc-event-description">' + Q(event.description) + '</td></tr>');
      }
    },
    viewDisplay: function(view) {
      // remove hard-coded hight and make contents visible
      window.setTimeout(function(){
        if (view.name == 'table') {
          $('div.fc-list-content').css('overflow', 'visible').height('auto');
        }
        else {
          $('div.fc-agenda-divider')
            .next().css('overflow', 'visible').height('auto')
            .children('div').css('overflow', 'visible').height('auto');
          }
          // adjust fixed height if vertical day slots
          var h = $('table.fc-agenda-slots:visible').height() + $('table.fc-agenda-allday:visible').height() + 4;
          if (h) $('table.fc-agenda-days td.fc-widget-content').children('div').height(h);
         }, 20);
    }
  });
  
  // activate settings form
  $('#propdescription').change(function(){
    showdesc = this.checked;
    fc.fullCalendar('render');
  });

});
