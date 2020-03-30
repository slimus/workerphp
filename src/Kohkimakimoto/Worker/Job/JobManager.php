<?php
namespace Kohkimakimoto\Worker\Job;

use Kohkimakimoto\Worker\Worker;
use Symfony\Component\Process\Process;

class JobManager
{
    protected $jobs = [];

    protected $worker;

    protected $dispatcher;

    protected $config;

    protected $output;

    protected $eventLoop;

    public function __construct($worker, $dispatcher, $config, $output, $eventLoop)
    {
        $this->worker = $worker;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
        $this->output = $output;
        $this->eventLoop = $eventLoop;
    }

    public function register($name, $command)
    {
        // checks if the same name exists.
        if (array_key_exists($name, $this->jobs)) {
            throw new \InvalidArgumentException("'$name' is already registered as a job.");
        }

        $id = count($this->jobs);
        $this->jobs[$name] = new Job($id, $name, $command, $this->config);

        return $this;
    }

    public function boot()
    {
        // All registered jobs is initialized.
        $bootTime = new \DateTime();
        foreach ($this->jobs as $job) {
            $this->output->writeln("<info>Initializing job:</info> <comment>".$job->getName()."</comment> (job_id: <comment>".$job->getId()."</comment>)");
            $job->setLastRunTime($bootTime);
            $job->initInfoFile();
            if ($job->hasCronTime()) {
                $this->addJobTimer($job);
            }
        }
    }

    protected function addJobTimer($job)
    {
        $job->updateNextRunTime();
        $secondsOfTimer = $job->secondsUntilNextRuntime();

        $self = $this;
        $this->eventLoop->addTimer($secondsOfTimer, function () use ($self, $job) {
            $self->executeJob($job);
        });

        if ($this->output->isDebug()) {
            $this->output->writeln("[debug] Added new timer: '".$job->getNextRunTime()->format('Y-m-d H:i:s')."' (after ".$secondsOfTimer." seconds) (job: ".$job->getName().").");
        }
    }

    public function executeJob($job, $oneTime = false, $passdArguments = array())
    {
        $id = $job->getId();
        $name = $job->getName();
        $output = $this->output;

        $now = new \DateTime();

        if ($output->isDebug()) {
            $output->writeln("[debug] Try running a job: $name at ".$now->format('Y-m-d H:i:s'));
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Error
            throw new \RuntimeException("pcntl_fork error.");
        } elseif ($pid) {
            // Parent process
            $status = null;
            $pid = pcntl_wait($status);
            if (!$pid) {
                throw new \RuntimeException("pcntl_wait error.");
            }

            $job->setLastRunTime($now);

            // add next timer
            if ($job->hasCronTime() && !$oneTime) {
                $this->addJobTimer($job);
            }
        } else {
            // Child process
            // Remove tty to ignore signals from tty.
            posix_setsid();

            // Stops copied event loop.
            $this->eventLoop->stop();
            unset($this->eventLoop);

            $this->dispatcher->dispatch(JobEvents::FORKED_PROCESS, new JobForkedProcessEvent($this->worker, $job));

            // Forks it one more time to prevent to be zombie process.
            $pid = pcntl_fork();
            if ($pid === -1) {
                // Error
                throw new \RuntimeException("pcntl_fork error.");
            } elseif ($pid) {
                $s = null;
                $pid = pcntl_wait($s);
                if (!$pid) {
                    throw new \RuntimeException("pcntl_wait error.");
                }
                exit;
            }

            if ($output->isDebug()) {
                $output->writeln("[debug] Forked process for: $name (pid:".posix_getpid().")");
            }

            if ($job->isLimitOfProcesses()) {
                $output->writeln("<fg=magenta>Skip the job '$name' due to limit of max processes: ".$job->getMaxProcesses()." at ".(new \DateTime())->format('Y-m-d H:i:s')."</fg=magenta>");
                exit;
            }

            $output->writeln("<info>Runs job:</info> <comment>$name</comment> (pid: ".posix_getpid().") at ".(new \DateTime())->format('Y-m-d H:i:s'));
            $job->addRuntimeEntryToJobInfo($pid);

            $command = $job->getCommand();
            if ($command instanceof \Closure) {
                // command is a closure
                $arguments = array();
                $parameters = $job->getCommandParameters();
                if ($parameters) {
                    foreach ($parameters as $parameter) {
                        $class = $parameter->getClass();
                        if ($class && $class->getName() === 'Kohkimakimoto\Worker\Worker') {
                            $arguments[] = $this->worker;
                            continue;
                        }

                        $argName = $parameter->getName();
                        if (isset($passdArguments[$argName])) {
                            $arguments[] = $passdArguments[$argName];
                        } else {
                            $isOptional = $parameter->isOptional();
                            if (!$isOptional) {
                                $arguments[] = null;
                            }
                        }
                    }
                }
                call_user_func_array($command, $arguments);
            } elseif (is_string($command)) {
                // command is a string
                $process = new Process($command);
                $process->setTimeout(null);

                $process->run(function ($type, $buffer) use ($output) {
                    $output->write($buffer);
                });
            } else {
                throw new \RuntimeException("Unsupported operation.");
            }

            $job->deleteRuntimeEntryToJobInfo($pid);
            $output->writeln("<info>Finished job:</info> <comment>$name</comment> (pid: ".posix_getpid().") at ".(new \DateTime())->format('Y-m-d H:i:s'));

            exit;
        }
    }

    public function getJobs()
    {
        return $this->jobs;
    }

    public function getJob($name)
    {
        return isset($this->jobs[$name]) ? $this->jobs[$name] : null;
    }
}
