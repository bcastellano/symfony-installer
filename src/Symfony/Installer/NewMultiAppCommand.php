<?php

namespace Symfony\Installer;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Installer\Exception\AbortException;

/**
 * This command creates new Symfony projects with some options.
 *
 * @author Blas Castellano Moreno <b.castellano.moreno@gmail.com>
 */
class NewMultiAppCommand extends NewCommand
{
    /**
     * @var array List name of apps
     */
    protected $apps;

    /**
     * @var string core bundle name
     */
    protected $coreBundleName;

    protected function configure()
    {
        $this
            ->setName('new:multi-app')
            ->setDescription('Creates a new Symfony project with multiple application.')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory where the new project will be created.')
            ->addArgument('version', InputArgument::OPTIONAL, 'The Symfony version to be installed (defaults to the latest stable version).', 'latest')

            ->addOption('apps', 'a', InputOption::VALUE_REQUIRED, 'Number of applications', 2)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $question = $this->getHelper('question');

        // ask for multiple apps
        $numberApps = (int)$input->getOption('apps');
        $this->apps = [];
        do {
            $appName = strtolower(trim($question->ask($input, $output, new Question("<question>Set name for app #".(count($this->apps)+1)."?</question>: "))));

            if ($input->getOption('no-interaction') && !$appName) {
                $appName = 'app'.(count($this->apps)+1);
            }

            if (!empty($appName)) {
                $this->apps[$appName] = $appName;
            }
        }
        while (count($this->apps) < $numberApps);

        // set core bundle name
        $this->coreBundleName = trim($question->ask($input, $output, new Question('<question>Enter the name of the main shared bundle</question> (CoreBundle):', 'CoreBundle')));
        $this->coreBundleName = str_replace('bundle', '', strtolower($this->coreBundleName));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this
                ->checkInstallerVersion()
                ->checkProjectName()
                ->checkSymfonyVersionIsInstallable()
                ->checkPermissions()
                ->download()
                ->extract()
                ->cleanUp()
                ->checkSymfonyRequirements()

                ->multipleApps()

                ->dumpReadmeFile()
                ->updateParameters()
                ->updateComposerJson()
                ->createGitIgnore()
                ->displayInstallationResult()
            ;
        } catch (AbortException $e) {
            aborted:

            $output->writeln('');
            $output->writeln('<error>Aborting download and cleaning up temporary directories.</>');

            $this->cleanUp();

            return 1;
        } catch (\Exception $e) {
            // Guzzle can wrap the AbortException in a GuzzleException
            if ($e->getPrevious() instanceof AbortException) {
                goto aborted;
            }

            $this->cleanUp();
            throw $e;
        }
    }

    /**
     * Dump a basic README.md file.
     *
     * @return $this
     */
    protected function dumpReadmeFile()
    {
        $readmeContents = sprintf("%s\n%s\n\nA Symfony multi-project created on %s.\n", $this->projectName, str_repeat('=', strlen($this->projectName)), date('F j, Y, g:i a'));
        try {
            $this->fs->dumpFile($this->projectDir.'/README.md', $readmeContents);
        } catch (\Exception $e) {
            // don't throw an exception in case the file could not be created,
            // because this is just an enhancement, not something mandatory
            // for the project
        }

        return $this;
    }

    /**
     * Updates the Symfony parameters.yml file to replace default configuration
     * values with better generated values.
     *
     * @return $this
     */
    protected function updateParameters()
    {
        foreach ($this->apps as $app) {
            $filename = "{$this->projectDir}/apps/$app/config/parameters.yml";

            if (!is_writable($filename)) {
                if ($this->output->isVerbose()) {
                    $this->output->writeln(sprintf(
                        " <comment>[WARNING]</comment> The value of the <info>secret</info> configuration option cannot be updated because\n".
                        " the <comment>%s</comment> file is not writable.\n",
                        $filename
                    ));
                }

                return $this;
            }

            $ret = str_replace('ThisTokenIsNotSoSecretChangeIt', $this->generateRandomSecret(), file_get_contents($filename));
            file_put_contents($filename, $ret);
        }

        return $this;
    }

    /**
     * Updates the composer.json file to provide better values for some of the
     * default configuration values.
     *
     * @return $this
     */
    protected function updateComposerJson()
    {
        $filename = $this->projectDir.'/composer.json';

        if (!is_writable($filename)) {
            if ($this->output->isVerbose()) {
                $this->output->writeln(sprintf(
                    " <comment>[WARNING]</comment> Project name cannot be configured because\n".
                    " the <comment>%s</comment> file is not writable.\n",
                    $filename
                ));
            }

            return $this;
        }

        $contents = json_decode(file_get_contents($filename), true);

        // TODO do stuff

        file_put_contents($filename, json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        parent::updateComposerJson();

        return $this;
    }

    /**
     * Append resource to .gitignore file
     *
     * @override
     * @return $this
     */
    protected function createGitIgnore()
    {
        parent::createGitIgnore();

        $path = $this->projectDir.'/.gitignore';

        $content = file_get_contents($path);

        // TODO append to $content

        $this->fs->dumpFile($path, $content);

        return $this;
    }

    /**
     * Modify standard installation to create multiple app structure
     *
     * @return $this
     */
    protected function multipleApps()
    {
        $this->renameOriginalFiles();
        $this->createCommon($this->coreBundleName);
        foreach ($this->apps as $app) {
            $this->createApp($app);
            $this->createBundle($app);
            $this->createConsole($app);
            $this->createCacheAndLog($app);
            $this->createPublicDir($app);
        }

        $this->removeOriginalDirs();

        return $this;
    }

    protected function renameOriginalFiles()
    {
        $this->fs->rename(
            $this->projectDir . "/web",
            $this->projectDir . "/web_original");
    }

    protected function createCommon($coreBundle)
    {
        $this->createBundle($coreBundle);

        $this->fs->copy(
            $this->projectDir . "/app/config/config.yml",
            $this->projectDir . "/apps/$coreBundle/config/config.yml");

        $this->fs->copy(
            $this->projectDir . "/app/config/parameters.yml.dist",
            $this->projectDir . "/apps/$coreBundle/config/parameters.yml.dist");

        $this->fs->copy(
            $this->projectDir . "/app/config/parameters.yml",
            $this->projectDir . "/apps/$coreBundle/config/parameters.yml");
    }

    protected function createApp($app)
    {
        $this->fs->mirror(
            $this->projectDir . "/app",
            $this->projectDir . "/apps/$app");
    }

    protected function createBundle($app)
    {
        $this->fs->mirror(
            $this->projectDir . "/src/AppBundle",
            $this->projectDir . "/src/" . ucfirst($app) . "Bundle");
    }

    protected function createConsole($app)
    {
        if ($this->isSymfony3()) {
            $this->fs->copy(
                $this->projectDir . "/bin/console",
                $this->projectDir . "/bin/$app/console");
        }
    }

    protected function createCacheAndLog($app)
    {
        if ($this->isSymfony3()) {
            $this->fs->mirror(
                $this->projectDir . "/var/cache",
                $this->projectDir . "/var/$app/cache");
            $this->fs->mirror(
                $this->projectDir . "/var/logs",
                $this->projectDir . "/var/$app/logs");
            $this->fs->mirror(
                $this->projectDir . "/var/sessions",
                $this->projectDir . "/var/$app/sessions");
        }
    }

    protected function createPublicDir($app)
    {
        $this->fs->mirror(
            $this->projectDir . "/web_original",
            $this->projectDir . "/web/$app");
    }

    protected function removeOriginalDirs()
    {
        $this->fs->remove([
            $this->projectDir . "/app",
            $this->projectDir . "/src/AppBundle",
            $this->projectDir . "/bin/console",
            $this->projectDir . "/var/cache",
            $this->projectDir . "/var/logs",
            $this->projectDir . "/var/sessions",
            $this->projectDir . "/web_original"
        ]);
    }

    /**
     * It displays the message with the result of installing Symfony
     * and provides some pointers to the user.
     *
     * @return $this
     */
    protected function displayInstallationResult()
    {
        $appDir = 'apps/'.implode('|', $this->apps);

        if (empty($this->requirementsErrors)) {
            $this->output->writeln(sprintf(
                " <info>%s</info>  Symfony %s was <info>successfully installed</info>. Now you can:\n",
                defined('PHP_WINDOWS_VERSION_BUILD') ? 'OK' : '✔',
                $this->getInstalledSymfonyVersion()
            ));
        } else {
            $this->output->writeln(sprintf(
                " <comment>%s</comment>  Symfony %s was <info>successfully installed</info> but your system doesn't meet its\n".
                "     technical requirements! Fix the following issues before executing\n".
                "     your Symfony application:\n",
                defined('PHP_WINDOWS_VERSION_BUILD') ? 'FAILED' : '✕',
                $this->getInstalledSymfonyVersion()
            ));

            foreach ($this->requirementsErrors as $helpText) {
                $this->output->writeln(' * '.$helpText);
            }

            $checkFile = $this->isSymfony3() ? 'bin/symfony_requirements' : $appDir . "/check.php";

            $this->output->writeln(sprintf(
                " After fixing these issues, re-check Symfony requirements executing this command:\n\n".
                "   <comment>php %s/%s</comment>\n\n".
                " Then, you can:\n",
                $this->projectName, $checkFile
            ));
        }

        if ('.' !== $this->projectDir) {
            $this->output->writeln(sprintf(
                "    * Change your current directory to <comment>%s</comment>\n", $this->projectDir
            ));
        }

        $consoleDir = ($this->isSymfony3() ? 'bin' : $appDir);

        $this->output->writeln(sprintf(
            "    * Configure your application in <comment>$appDir/config/parameters.yml</comment> file.\n\n".
            "    * Run your application:\n".
            "        1. Execute the <comment>php %s/console server:run</comment> command.\n".
            "        2. Browse to the <comment>http://localhost:8000</comment> URL.\n\n".
            "    * Read the documentation at <comment>http://symfony.com/doc</comment>\n",
            $consoleDir
        ));

        return $this;
    }
}
