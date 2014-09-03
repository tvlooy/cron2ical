<?php

namespace Ctors;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Cron\CronExpression;
use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;

class Cron2iCalCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('cron2ical')
            ->setDescription('Convert a crontab file to an iCalendar file')
            ->addArgument(
                'crontab',
                InputArgument::REQUIRED,
                'crontab file tot input'
            )
            ->addArgument(
                'ical',
                InputArgument::REQUIRED,
                'iCalendar file to output'
            )
            ->addArgument(
                'day',
                InputArgument::REQUIRED,
                'Day to generate for (format dd-mm-yyyy)'
            )
            ->addArgument(
                'command_filter',
                InputArgument::OPTIONAL,
                'File with strings to filter from commands (one string per line)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $crontabFileName = $input->getArgument('crontab');
        $icalFileName = $input->getArgument('ical');
        $startDate = \DateTime::createFromFormat('d-m-Y H:i:s', $input->getArgument('day') . '00:00:00');

        if ($startDate === false) {
            $output->writeln("<error>Given date is invalid!</error>");
            return;
        }

        if (!is_file($crontabFileName)) {
            $output->writeln("<error>Given crontab file is not a file!</error>");
            return;
        }

        if (!is_dir(dirname($icalFileName))) {
            $output->writeln("<error>Given ical filename is in invalid directory!</error>");
            return;
        }

        $crontabFileHandle = fopen($crontabFileName, 'r');
        if (!$crontabFileHandle) {
            $output->writeln("<error>Can't read crontab file!</error>");
            return;
        }

        $filterStrings = array();
        $filterStringsFile = $input->getArgument('command_filter');
        if (is_file($filterStringsFile)) {
            $filterStrings = file($filterStringsFile, FILE_IGNORE_NEW_LINES);
        }

        $totalEvents = 0;
        $endDate = clone $startDate;
        $endDate->add(new \DateInterval('P1D'));

        $calendar = new Calendar('Cron calendar');

        while (($crontabLine = fgets($crontabFileHandle)) !== false) {
            $crontabLine = trim($crontabLine, "\t \n");
            if (strlen($crontabLine) <= 10 || substr($crontabLine, 0, 1) == '#') {
                continue;
            }

            preg_match_all('/ /', $crontabLine, $splitpos, PREG_OFFSET_CAPTURE);
            $splitpos = $splitpos[0][4][1];

            $cronTime = substr($crontabLine, 0, $splitpos);
            $cronCommand = trim(str_replace($filterStrings, '', substr($crontabLine, $splitpos + 1)));

            $cronExpression = CronExpression::factory($cronTime);

            $cronEvents = 0;
            $nextRundate = $cronExpression->getNextRunDate($startDate);
            while ($nextRundate < $endDate) {
                $cronEvent = new Event();

                $endTime = clone $nextRundate;
                $endTime->add(new \DateInterval('PT1M'));
                $cronEvent
                    ->setDtStart($nextRundate)
                    ->setDtEnd($endTime)
                    ->setSummary($cronCommand)
                    ->setUseTimezone(true)
                ;

                $calendar->addComponent($cronEvent);
                $totalEvents++;
                $cronEvents++;
                $nextRundate = $cronExpression->getNextRunDate($nextRundate);
            }
            $cronEvents = str_pad($cronEvents, 5, ' ', STR_PAD_LEFT);
            $output->writeln("<info>$cronEvents events for $cronCommand</info>");
        }

        fclose($crontabFileHandle);

        $output->writeln("\n<info>Done! Saving file ...</info>");
        file_put_contents($icalFileName, $calendar->render());

        $output->writeln("<info>Saved $totalEvents events to $icalFileName!</info>");
    }
}
