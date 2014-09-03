# Crontab to iCalendar

Convert a crontab file to iCalender format. Generate schedule for a given day.

    php cron2ical.php cron2ical /var/spool/cron/crontabs/someone ~/Desktop/file.ics 01-09-2014 command_filter.txt

The command_filter.txt file should contain strings that you want to strip from your commands. Could be anything. For example:

    >/dev/null
    /usr/bin/php

