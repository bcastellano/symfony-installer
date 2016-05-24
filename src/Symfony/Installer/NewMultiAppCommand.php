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
     * @override
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
     * @override
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
     * @override
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

        // update parameters files
        unset($contents["autoload"]["classmap"]);
        $contents['extra']['incenteev-parameters'] = [
            ['file' => "apps/{$this->coreBundleName}/config/parameters.yml"]
        ];

        // create command list for composer events
        $commands = [];

        foreach ($this->apps as $app) {
            // add param for each app
            $contents['extra']['incenteev-parameters'][] = ['file' => "apps/$app/config/parameters.yml"];

            $commands[] = "php bin/$app cache:clear --ansi";
            $commands[] = "php bin/$app assets:install web/$app --ansi";
        }

        // create command list for composer events
        $commands = array_merge([
            "Incenteev\\ParameterHandler\\ScriptHandler::buildParameters",
            "php vendor/sensio/distribution-bundle/Resources/bin/build_bootstrap.php var apps/$app --use-new-directory-structure"
        ], $commands);

        // and command list to composer events
        $contents['scripts']['post-install-cmd'] = $commands;
        $contents['scripts']['post-update-cmd'] = $commands;

        // update app and web dirs
        unset(
            $contents['extra']['symfony-app-dir'],
            $contents['extra']['symfony-web-dir'],
            $contents['extra']['symfony-bin-dir'],
            $contents['extra']['symfony-var-dir'],
            $contents['extra']['symfony-tests-dir'],
            $contents['extra']['symfony-assets-install']
        );

        // save composer.json
        file_put_contents($filename, json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        // call parent to modify composer.json and update composer.lock
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
     * Insert lines in text file after, in same place or before a specified line
     *
     * @param string $file File to insert in
     * @param string $line Line to find
     * @param int $position Position to insert lines. -1 before, 0 same line (and replace) or 1 to insert after
     * @param array $newLines
     */
    private function insertLinesIn($file, $line, $position, array $newLines)
    {
        $contents = explode("\n", file_get_contents($file));
        foreach ($contents as  $pos=>$l) {
            if ($l == $line) {

                $length = 0;
                $insertPos = $pos;
                if ($position == 0) {
                    $length = 1;
                }
                elseif ($position < 0) {
                    $insertPos--;
                }
                else {
                    $insertPos++;
                }

                array_splice($contents, $insertPos, $length, $newLines);

                break;
            }
        }

        $this->fs->dumpFile($file, implode("\n", $contents));
    }

    /**
     * Replace file lines by it position
     *
     * @param string $file File to modify
     * @param int $offset Line index to start
     * @param int $length Number of lines to replace
     * @param array $newLines
     */
    private function replaceLines($file, $offset, $length, array $newLines)
    {
        $contents = explode("\n", file_get_contents($file));
        array_splice($contents, $offset, $length, $newLines);
        $this->fs->dumpFile($file, implode("\n", $contents));
    }

    /**
     * Replace patterns and replace by text in files
     *
     * @param mixed $files Array (with file names) or string file name
     * @param mixed $pattern
     * @param mixed $replacement
     */
    private function replaceInFiles($files, $pattern, $replacement)
    {
        if (is_string($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $contents = preg_replace($pattern, $replacement, $contents);

            $this->fs->dumpFile($file, $contents);
        }
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

    /**
     * Rename temporally dirs to copy before delete it
     */
    protected function renameOriginalFiles()
    {
        $this->fs->rename(
            $this->projectDir . "/web",
            $this->projectDir . "/web_original");
    }

    /**
     * Create common app resources
     *
     * @param string $coreBundle Core bundle name
     */
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

        // delete first import for common config
        $this->replaceLines($this->projectDir . "/apps/$coreBundle/config/config.yml", 0, 4, []);
    }

    /**
     * Create app (kernel root dir) resources
     *
     * @param string $app App name
     */
    protected function createApp($app)
    {
        // copy dir
        $this->fs->mirror(
            $this->projectDir . "/app",
            $this->projectDir . "/apps/$app");


        $appKernel = ucfirst($app) . "Kernel";
        $appCache = ucfirst($app) . "Cache";
        $bundleName = ucfirst($app)."Bundle";

        // rename kernel files
        $this->fs->rename(
            $this->projectDir . "/apps/$app/AppKernel.php",
            $this->projectDir . "/apps/$app/$appKernel.php");
        $this->fs->rename(
            $this->projectDir . "/apps/$app/AppCache.php",
            $this->projectDir . "/apps/$app/$appCache.php");

        // modify kernel and cache files
        $this->replaceInFiles([
                $this->projectDir . "/apps/$app/$appKernel.php",
                $this->projectDir . "/apps/$app/$appCache.php"
            ],
            ["/AppBundle/", "/AppKernel/", "/AppCache/"],
            [$bundleName, $appKernel, $appCache]);

        if ($this->isSymfony3()) {
            $this->replaceInFiles([$this->projectDir . "/apps/$app/$appKernel.php"],
                ["/\\/var\\//"],
                ["/../var/$app/"]);
        } else {
            $this->replaceLines($this->projectDir . "/apps/$app/$appKernel.php", -2, 0, [
                '    public function getCacheDir()',
                '    {',
                '        return $this->getRootDir()."/../../var/'.$app.'/cache/".$this->environment;',
                '    }',
                '',
                '    public function getLogDir()',
                '    {',
                '        return $this->getRootDir()."/../../var/'.$app.'/logs";',
                '    }',
            ]);
        }

        // modify autoload.php
        $this->replaceInFiles($this->projectDir . "/apps/$app/autoload.php", "/\\.\\.\\/vendor/", "../../vendor");

        // modify routing.yml
        $this->replaceInFiles($this->projectDir . "/apps/$app/config/routing.yml", "/@AppBundle/", "@$bundleName");

        // modify config.yml
        $this->replaceInFiles($this->projectDir . "/apps/$app/config/config.yml", "/\\/var\\//", "/../var/$app/");

        // modify config yml
        $this->replaceLines($this->projectDir . "/apps/$app/config/config.yml", 1, 1, [
            "    - { resource: ../../{$this->coreBundleName}/config/parameters.yml }",
            "    - { resource: parameters.yml }",
            "    - { resource: ../../{$this->coreBundleName}/config/config.yml }",
        ]);
    }

    /**
     * Create bundle for app
     *
     * @param string $app App name
     */
    protected function createBundle($app)
    {
        $bundleName = ucfirst($app) . "Bundle";

        $this->fs->mirror(
            $this->projectDir . "/src/AppBundle",
            $this->projectDir . "/src/$bundleName");

        $this->fs->rename(
            $this->projectDir . "/src/$bundleName/AppBundle.php",
            $this->projectDir . "/src/$bundleName/$bundleName.php");

        $this->replaceInFiles([
            $this->projectDir . "/src/$bundleName/$bundleName.php",
            $this->projectDir . "/src/$bundleName/Controller/DefaultController.php"
        ], "/AppBundle/", "$bundleName");
    }

    /**
     * Create console related resources
     *
     * @param string $app App name
     */
    protected function createConsole($app)
    {
        if ($this->isSymfony3()) {
            $this->fs->copy(
                $this->projectDir . "/bin/console",
                $this->projectDir . "/bin/$app");

            $appKernel = ucfirst($app) . "Kernel";

            $this->replaceInFiles([
                    $this->projectDir . "/bin/$app"
                ],
                ["/AppKernel/", "/\\/app\\//"],
                [$appKernel, "/apps/$app/"]);

            // insert require kernel file
            $this->insertLinesIn($this->projectDir . "/bin/$app", "\$kernel = new $appKernel(\$env, \$debug);", -1, [
                "",
                "require_once __DIR__.'/../apps/$app/$appKernel.php';"
            ]);
        }
    }

    /**
     * Create cache and log dirs
     *
     * @param string $app App name
     */
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

    /**
     * Create public dir
     *
     * @param string $app App name
     */
    protected function createPublicDir($app)
    {
        $this->fs->mirror(
            $this->projectDir . "/web_original",
            $this->projectDir . "/web/$app");

        $appKernel = ucfirst($app) . "Kernel";
        $appCache = ucfirst($app) . "Cache";
        $bundleName = ucfirst($app)."Bundle";

        $this->replaceInFiles([
                $this->projectDir . "/web/$app/app.php",
                $this->projectDir . "/web/$app/app_dev.php"
            ],
            ["/AppBundle/", "/AppKernel/", "/AppCache/", "/\\/app\\//", "/\\/var\\//"],
            [$bundleName, $appKernel, $appCache, "/../apps/$app/", "/../var/"]);

        if ($this->isSymfony3()) {
            // insert require kernel file
            $this->insertLinesIn($this->projectDir . "/web/$app/app.php", "\$kernel = new $appKernel('prod', false);", -1, [
                "",
                "require_once __DIR__.'/../../apps/$app/$appKernel.php';"
            ]);

            // insert require kernel file
            $this->insertLinesIn($this->projectDir . "/web/$app/app_dev.php", "\$kernel = new $appKernel('dev', true);", -1, [
                "",
                "require_once __DIR__.'/../../apps/$app/$appKernel.php';"
            ]);
        }
    }

    /**
     * Remove original single app dirs
     */
    protected function removeOriginalDirs()
    {
        $this->fs->remove([
            $this->projectDir . "/app",
            $this->projectDir . "/src/AppBundle",
            $this->projectDir . "/bin/console",
            $this->projectDir . "/var/cache",
            $this->projectDir . "/var/logs",
            $this->projectDir . "/var/sessions",
            $this->projectDir . "/web_original",
            $this->projectDir . "/tests/AppBundle"
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
        $appDir = 'apps/{app_name}';

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

        $this->output->writeln("    * Applications installed:");
        $pos = 1;
        foreach ($this->apps as $app) {
            $this->output->writeln(sprintf("        %s. Name: <comment>%s</comment>", $pos, $app));
            $pos++;
        }
        $this->output->writeln('');

        if ('.' !== $this->projectDir) {
            $this->output->writeln(sprintf(
                "    * Change your current directory to <comment>%s</comment>\n", $this->projectDir
            ));
        }

        $console = ($this->isSymfony3() ? "bin/$app" : "$appDir/console");

        $this->output->writeln(sprintf(
            "    * Configure your application in <comment>$appDir/config/parameters.yml</comment> file.\n\n".
            "    * Run your application:\n".
            "        1. Execute the <comment>php %s server:run --docroot web/{app_name}</comment> command.\n".
            "        2. Browse to the <comment>http://localhost:8000</comment> URL.\n\n".
            "    * Read the documentation at <comment>http://symfony.com/doc</comment>\n",
            $console
        ));

        return $this;
    }
}
