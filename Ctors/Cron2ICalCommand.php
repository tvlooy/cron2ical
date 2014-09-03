<?php

namespace Ctors;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Cron\CronExpression;
use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;

class Cron2ICalCommand extends Command
{
    private $filterStrings = array();
    private $output;

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
        $this->output = $output;

        $crontabFileName = $input->getArgument('crontab');
        $iCalFileName = $input->getArgument('ical');
        $startDate = \DateTime::createFromFormat('d-m-Y H:i:s', $input->getArgument('day') . '00:00:00');

        if (!$this->ensureValidStartAndEndDate($startDate, $endDate)) return;
        if (!$this->ensureValidCrontabFile($crontabFileName)) return;
        if (!$this->ensureValidICalOutputDirectory($iCalFileName)) return;
        if (!$this->ensureOpenCrontabFile($crontabFileName, $crontabFileHandle)) return;
        if (!$this->ensureReadFilterStringsFromFile($input->getArgument('command_filter'))) return;

        $calendar = new Calendar('Cron calendar');
        $totalEvents = 0;
        while (($crontabLine = fgets($crontabFileHandle)) !== false) {
            if (!$this->ensureValidCrontabLine($crontabLine)) continue;

            list($cronTime, $cronCommand) = $this->splitCrontabLine($crontabLine);
            $cronExpression = CronExpression::factory($cronTime);

            $nextRundate = $cronExpression->getNextRunDate($startDate);
            $cronEvents = 0;
            while ($nextRundate < $endDate) {
                $calendar->addComponent($this->newEvent($nextRundate, $cronCommand));

                $totalEvents++;
                $cronEvents++;

                $nextRundate = $cronExpression->getNextRunDate($nextRundate);
            }
            $this->logEventsForCommand($cronEvents, $cronCommand);
        }

        fclose($crontabFileHandle);
        $this->writeICalFile($iCalFileName, $calendar);
        $output->writeln("<info>Saved $totalEvents events to $iCalFileName!</info>");
    }

    /**
     * @param $iCalFileName
     * @param $calendar
     */
    private function writeICalFile($iCalFileName, $calendar)
    {
        $this->output->writeln("\n<info>Done! Saving file ...</info>");
        file_put_contents($iCalFileName, $calendar->render());
    }

    /**
     * @param $cronEvents
     * @param $cronCommand
     */
    private function logEventsForCommand($cronEvents, $cronCommand)
    {
        $cronEvents = str_pad($cronEvents, 5, ' ', STR_PAD_LEFT);
        $this->output->writeln("<info>$cronEvents events for $cronCommand</info>");
    }

    /**
     * @param $filterStringsFile
     * @return bool
     */
    private function ensureReadFilterStringsFromFile($filterStringsFile)
    {
        if ($filterStringsFile !== null) {
            if (!is_file($filterStringsFile)) {
                $this->output->writeln("<error>Given command_filter file is invalid!</error>");
                return false;
            } else {
                $this->filterStrings = file($filterStringsFile, FILE_IGNORE_NEW_LINES);
            }
        }

        return true;
    }

    /**
     * @param $crontabFileName
     * @param $crontabFileHandle
     * @return bool
     */
    private function ensureOpenCrontabFile($crontabFileName, &$crontabFileHandle)
    {
        $crontabFileHandle = fopen($crontabFileName, 'r');
        if (!$crontabFileHandle) {
            $this->output->writeln("<error>Can't read crontab file!</error>");
            return false;
        }

        return true;
    }

    /**
     * @param $iCalFileName
     * @return bool
     */
    private function ensureValidICalOutputDirectory($iCalFileName)
    {
        if (!is_dir(dirname($iCalFileName))) {
            $this->output->writeln("<error>Given ical filename is in invalid directory!</error>");
            return false;
        }

        return true;
    }

    /**
     * @param $crontabFileName
     * @return bool
     */
    private function ensureValidCrontabFile($crontabFileName)
    {
        if (!is_file($crontabFileName)) {
            $this->output->writeln("<error>Given crontab file is not a file!</error>");
            return false;
        }

        return true;
    }

    /**
     * @param $startDate
     * @param $endDate
     * @return bool
     */
    private function ensureValidStartAndEndDate($startDate, &$endDate)
    {
        if ($startDate === false) {
            $this->output->writeln("<error>Given date is invalid!</error>");
            return false;
        }

        $endDate = clone $startDate;
        $endDate->add(new \DateInterval('P1D'));

        return true;
    }

    /**
     * @param $rundate
     * @param $cronCommand
     * @return Event
     */
    private function newEvent($rundate, $cronCommand)
    {
        $cronEvent = new Event();

        $endTime = clone $rundate;
        $endTime->add(new \DateInterval('PT1M'));

        $cronEvent
            ->setDtStart($rundate)
            ->setDtEnd($endTime)
            ->setSummary($cronCommand)
            ->setUseTimezone(true)
        ;

        return $cronEvent;
    }

    /**
     * @param $crontabLine
     * @return bool
     */
    private function ensureValidCrontabLine(&$crontabLine) {
        $crontabLine = trim($crontabLine, "\t \n");

        // Line too short or in comment
        if (strlen($crontabLine) <= 10 || substr($crontabLine, 0, 1) == '#') {
            return false;
        }

        return true;
    }

    /**
     * @param $crontabLine
     * @return array
     */
    private function splitCrontabLine($crontabLine)
    {
        $splitPosition = $this->getSplitPosition($crontabLine);

        $cronTime = substr($crontabLine, 0, $splitPosition);
        $cronCommand = trim(str_replace($this->filterStrings, '', substr($crontabLine, $splitPosition + 1)));

        return [$cronTime, $cronCommand];
    }

    /**
     * @param $crontabLine
     * @return mixed
     */
    private function getSplitPosition($crontabLine)
    {
        preg_match_all('/ /', $crontabLine, $splitPosition, PREG_OFFSET_CAPTURE);
        return $splitPosition[0][4][1];
    }
}
